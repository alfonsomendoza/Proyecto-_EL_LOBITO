<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Servicio de inventario (Etapa 2).
 *
 * Registra de forma automática las salidas y reposiciones de stock cuando se
 * registran consumos, se realizan anulaciones parciales o se cancela una venta.
 *
 * Reglas clave del módulo:
 *  - El control de inventario es OPCIONAL por producto (productos.controla_stock).
 *    Si está desactivado, el comportamiento de la venta no cambia en absoluto.
 *  - El inventario es INFORMATIVO: la falta de existencias NUNCA bloquea la
 *    venta ni el servicio. Se permite stock negativo.
 *  - No se reconstruye inventario desde ventas históricas: una venta anterior a
 *    la activación del control no genera reposición.
 *
 * CONVENCIÓN DE "cantidad" (variación FIRMADA del stock, no magnitud absoluta):
 *  - VENTA           -> negativa (salida)      ej: -3
 *  - ANULACION       -> positiva (reposición)  ej: +1
 *  - CANCELACION     -> positiva (reposición)  ej: +2
 *  - CARGA_INICIAL   -> positiva                ej: +10
 *  - AJUSTE_ENTRADA  -> positiva                ej: +5
 *  - AJUSTE_SALIDA   -> negativa                ej: -2
 * El stock se actualiza aplicando el delta firmado al valor actual, de modo
 * que "stock_actual" equivale a la suma de las cantidades de sus movimientos.
 *
 * CONTRATO TRANSACCIONAL: este servicio NO abre su propia transacción. Debe
 * invocarse DENTRO del DB::transaction del evento de origen para que el
 * movimiento, la actualización de stock y el evento se confirmen o reviertan
 * juntos. Tampoco captura excepciones: cualquier error se propaga y hace que
 * la transacción del llamador haga rollback completo.
 *
 * ORDEN DE BLOQUEO (respeta el orden global del proyecto):
 *   ventas -> cuentas(id ASC) -> venta_detalles(id ASC) -> inventario_producto(producto_id ASC)
 * Los productos se procesan siempre en orden ascendente de producto_id.
 */
class InventarioService
{
    // Tipos de movimiento (deben coincidir con el esquema aprobado).
    public const TIPO_VENTA = 'VENTA';

    public const TIPO_ANULACION = 'ANULACION';

    public const TIPO_CANCELACION = 'CANCELACION';

    public const TIPO_CARGA_INICIAL = 'CARGA_INICIAL';

    public const TIPO_AJUSTE_ENTRADA = 'AJUSTE_ENTRADA';

    public const TIPO_AJUSTE_SALIDA = 'AJUSTE_SALIDA';

    /**
     * Registra la salida de stock de las líneas de una venta recién creada.
     *
     * Solo genera movimientos para los productos con controla_stock = 1. Si el
     * producto no controla inventario, no se registra nada.
     *
     * @param  array<int,array{venta_detalle_id:int,producto_id:int,cantidad:int|float}>  $lineas
     */
    public function registrarVenta(array $lineas, ?int $usuarioId = null): void
    {
        if (! $lineas || ! $this->disponible()) {
            return;
        }

        // Se consulta el control en el servidor: el llamador no decide.
        $idsProducto = array_values(array_unique(array_map(
            static fn ($linea) => (int) $linea['producto_id'],
            $lineas
        )));

        $controlados = DB::table('productos')
            ->whereIn('id', $idsProducto)
            ->where('controla_stock', 1)
            ->pluck('id')
            ->map(static fn ($id) => (int) $id)
            ->all();

        if (! $controlados) {
            return;
        }

        $controlados = array_flip($controlados);

        $movimientos = [];

        foreach ($lineas as $linea) {
            $productoId = (int) $linea['producto_id'];
            $cantidad = (float) $linea['cantidad'];

            if ($cantidad <= 0 || ! isset($controlados[$productoId])) {
                continue;
            }

            // Un movimiento por cada línea de venta, identificable por el id
            // real de venta_detalles. Variación negativa (salida).
            $movimientos[] = [
                'producto_id' => $productoId,
                'tipo' => self::TIPO_VENTA,
                'cantidad' => -$cantidad,
                'origen_tipo' => self::TIPO_VENTA,
                'origen_id' => (int) $linea['venta_detalle_id'],
                'usuario_id' => $usuarioId,
                'observacion' => null,
            ];
        }

        $this->registrarMovimientos($movimientos);
    }

    /**
     * Registra la reposición de una anulación parcial.
     *
     * Solo repone si la línea tiene un movimiento original VENTA (criterio
     * determinante; no se usa productos.controla_stock para decidir). La
     * cantidad se guarda positiva y el origen es el id real de "anulaciones".
     */
    public function registrarAnulacion(
        int $ventaDetalleId,
        int $anulacionId,
        int|float $cantidad,
        ?int $usuarioId = null
    ): void {
        $cantidad = (float) $cantidad;

        if ($cantidad <= 0 || ! $this->disponible()) {
            return;
        }

        // Movimiento original de la línea. Si no existe, no se repone.
        $venta = DB::table('movimientos_inventario')
            ->where('origen_tipo', self::TIPO_VENTA)
            ->where('origen_id', $ventaDetalleId)
            ->first();

        if (! $venta) {
            return;
        }

        $this->registrarMovimientos([[
            'producto_id' => (int) $venta->producto_id,
            'tipo' => self::TIPO_ANULACION,
            'cantidad' => $cantidad,
            'origen_tipo' => self::TIPO_ANULACION,
            'origen_id' => $anulacionId,
            'usuario_id' => $usuarioId,
            'observacion' => null,
        ]]);
    }

    /**
     * Registra la reposición pendiente por la cancelación completa de una venta.
     *
     * Para cada línea se repone únicamente la parte de las salidas originales
     * que todavía no fue compensada por movimientos ANULACION o CANCELACION.
     * El cálculo se hace SOLO desde el historial de movimientos.
     *
     * @param  array<int,int>  $ventaDetalleIds  ids de las líneas (ya bloqueadas)
     */
    public function registrarCancelacion(array $ventaDetalleIds, ?int $usuarioId = null): void
    {
        if (! $ventaDetalleIds || ! $this->disponible()) {
            return;
        }

        $movimientos = [];

        foreach ($ventaDetalleIds as $ventaDetalleId) {
            $ventaDetalleId = (int) $ventaDetalleId;

            // 1. Salida original de la línea. Sin ella, no se repone.
            $venta = DB::table('movimientos_inventario')
                ->where('origen_tipo', self::TIPO_VENTA)
                ->where('origen_id', $ventaDetalleId)
                ->first();

            if (! $venta) {
                continue;
            }

            // 2. Ya compensado por anulaciones parciales de ESTA línea. Como los
            //    movimientos ANULACION se guardan con anulaciones.id, se traduce
            //    por la tabla "anulaciones".
            $anulacionIds = DB::table('anulaciones')
                ->where('venta_detalle_id', $ventaDetalleId)
                ->pluck('id')
                ->all();

            $reponidoAnulaciones = 0.0;
            if ($anulacionIds) {
                $reponidoAnulaciones = (float) DB::table('movimientos_inventario')
                    ->where('origen_tipo', self::TIPO_ANULACION)
                    ->whereIn('origen_id', $anulacionIds)
                    ->sum('cantidad');
            }

            // 3. Ya compensado por una cancelación previa de la misma línea.
            $reponidoCancelaciones = (float) DB::table('movimientos_inventario')
                ->where('origen_tipo', self::TIPO_CANCELACION)
                ->where('origen_id', $ventaDetalleId)
                ->sum('cantidad');

            // Salida original = magnitud del movimiento VENTA (es negativo).
            $salidaOriginal = abs((float) $venta->cantidad);
            $pendiente = $salidaOriginal - $reponidoAnulaciones - $reponidoCancelaciones;

            // Ya compensada o anulada por completo: nada que reponer.
            if ($pendiente <= 0) {
                continue;
            }

            $movimientos[] = [
                'producto_id' => (int) $venta->producto_id,
                'tipo' => self::TIPO_CANCELACION,
                'cantidad' => $pendiente,
                'origen_tipo' => self::TIPO_CANCELACION,
                'origen_id' => $ventaDetalleId,
                'usuario_id' => $usuarioId,
                'observacion' => null,
            ];
        }

        $this->registrarMovimientos($movimientos);
    }

    /**
     * Registra la carga inicial de existencias de un producto (CARGA_INICIAL).
     *
     * La cantidad es una magnitud positiva. Solo se permite UNA carga inicial
     * por producto.
     *
     * LIMITACIÓN CONOCIDA: el índice único (origen_tipo, origen_id) no protege
     * la carga inicial porque su origen_id es NULL y MySQL/SQLite no comparan
     * NULL en un índice único. Para no cambiar el esquema ya aprobado, la
     * unicidad se garantiza aquí: se bloquea la fila de inventario del producto
     * (X-lock) y, dentro de ese bloqueo, se comprueba si ya existe la carga.
     * Con el contrato transaccional del proyecto (todo dentro de DB::transaction)
     * dos cargas concurrentes del mismo producto se serializan en esa fila.
     *
     * REQUISITO: debe invocarse DENTRO de un DB::transaction() del llamador.
     * El servicio no abre transacción propia; si el llamador no la envuelve,
     * la comprobación de duplicado y la inserción no son atómicas y dos
     * peticiones concurrentes podrían registrar dos cargas iniciales.
     *
     * @throws \DomainException si el producto ya tiene una carga inicial registrada
     */
    public function registrarCargaInicial(
        int $productoId,
        int|float $cantidad,
        ?int $usuarioId = null,
        ?string $observacion = null
    ): void {
        $cantidad = (float) $cantidad;

        if ($cantidad <= 0 || ! $this->disponible()) {
            return;
        }

        // 1. Asegura y bloquea la fila del producto para serializar por producto.
        $this->crearFilaSiNoExiste($productoId);
        DB::table('inventario_producto')
            ->where('producto_id', $productoId)
            ->lockForUpdate()
            ->first();

        // 2. Lectura bloqueante: ¿ya existe una carga inicial para el producto?
        $yaExiste = DB::table('movimientos_inventario')
            ->where('producto_id', $productoId)
            ->where('tipo', self::TIPO_CARGA_INICIAL)
            ->lockForUpdate()
            ->first();

        if ($yaExiste) {
            throw new \DomainException('Este producto ya tiene una carga inicial registrada.');
        }

        // 3. Se reutiliza el motor común de movimientos.
        $this->registrarMovimientos([[
            'producto_id' => $productoId,
            'tipo' => self::TIPO_CARGA_INICIAL,
            'cantidad' => $cantidad,
            'origen_tipo' => self::TIPO_CARGA_INICIAL,
            'origen_id' => null,
            'usuario_id' => $usuarioId,
            'observacion' => $observacion,
        ]]);
    }

    /**
     * Registra un ajuste manual de existencias (AJUSTE_ENTRADA o AJUSTE_SALIDA).
     *
     * La cantidad recibida es siempre una magnitud positiva y el signo lo aplica
     * este método según el tipo. A diferencia de la carga inicial, los ajustes
     * pueden repetirse: su origen_id es NULL y no se valida unicidad.
     */
    public function registrarAjuste(
        int $productoId,
        string $tipo,
        int|float $cantidad,
        ?int $usuarioId = null,
        ?string $observacion = null
    ): void {
        $cantidad = (float) $cantidad;

        if ($cantidad <= 0 || ! $this->disponible()) {
            return;
        }

        if (! in_array($tipo, [self::TIPO_AJUSTE_ENTRADA, self::TIPO_AJUSTE_SALIDA], true)) {
            return;
        }

        $firmado = $tipo === self::TIPO_AJUSTE_SALIDA ? -$cantidad : $cantidad;

        $this->registrarMovimientos([[
            'producto_id' => $productoId,
            'tipo' => $tipo,
            'cantidad' => $firmado,
            'origen_tipo' => $tipo,
            'origen_id' => null,
            'usuario_id' => $usuarioId,
            'observacion' => $observacion,
        ]]);
    }

    /**
     * Asegura que exista la fila de inventario del producto sin tocar su stock.
     * La usa el controlador para configurar mínimo/unidad de un producto que
     * todavía no tiene movimientos.
     */
    public function asegurarFila(int $productoId): void
    {
        if ($this->disponible()) {
            $this->crearFilaSiNoExiste($productoId);
        }
    }

    /**
     * Indica si el esquema de inventario ya está migrado. El controlador lo usa
     * para mostrar un aviso en lugar de fallar cuando las migraciones siguen
     * pendientes (no las ejecuta por su cuenta).
     */
    public function esquemaDisponible(): bool
    {
        return $this->disponible();
    }

    /**
     * Red de seguridad previa a la migración: si el esquema de inventario aún
     * no existe (las migraciones de la Etapa 1 están pendientes), el servicio
     * no hace nada. Así la venta NUNCA se rompe por el inventario, ni siquiera
     * en la ventana entre desplegar el código y aplicar las migraciones.
     */
    private function disponible(): bool
    {
        return Schema::hasTable('movimientos_inventario')
            && Schema::hasTable('inventario_producto')
            && Schema::hasColumn('productos', 'controla_stock');
    }

    /**
     * Inserta los movimientos y aplica la variación de stock de forma atómica.
     *
     * Agrupa los deltas por producto y procesa los productos en orden ascendente
     * de producto_id para respetar el orden de bloqueo y evitar interbloqueos.
     *
     * @param  array<int,array<string,mixed>>  $movimientos
     */
    private function registrarMovimientos(array $movimientos): void
    {
        if (! $movimientos) {
            return;
        }

        // 1. Agrupar el delta total por producto.
        $deltas = [];
        foreach ($movimientos as $movimiento) {
            $productoId = (int) $movimiento['producto_id'];
            $deltas[$productoId] = ($deltas[$productoId] ?? 0.0) + (float) $movimiento['cantidad'];
        }

        // 2. Orden ascendente y actualización atómica fila por fila.
        ksort($deltas, SORT_NUMERIC);

        foreach ($deltas as $productoId => $delta) {
            $productoId = (int) $productoId;

            // La fila se crea con stock 0 la primera vez que se controla.
            $this->crearFilaSiNoExiste($productoId);

            // Bloquear ANTES de actualizar: serializa actualizaciones concurrentes.
            $fila = DB::table('inventario_producto')
                ->where('producto_id', $productoId)
                ->lockForUpdate()
                ->first();

            $nuevoStock = (float) $fila->stock_actual + (float) $delta;

            DB::table('inventario_producto')
                ->where('producto_id', $productoId)
                ->update([
                    'stock_actual' => $nuevoStock,
                    'updated_at' => now(),
                ]);
        }

        // 3. Un movimiento por cada línea de origen. El índice único
        //    (origen_tipo, origen_id) impide duplicar un mismo evento.
        foreach ($movimientos as $movimiento) {
            DB::table('movimientos_inventario')->insert([
                'producto_id' => (int) $movimiento['producto_id'],
                'tipo' => $movimiento['tipo'],
                'cantidad' => (float) $movimiento['cantidad'],
                'origen_tipo' => $movimiento['origen_tipo'],
                'origen_id' => $movimiento['origen_id'],
                'usuario_id' => $movimiento['usuario_id'],
                'observacion' => $movimiento['observacion'] ?? null,
                'creado_en' => now(),
            ]);
        }
    }

    /**
     * Asegura que exista la fila de inventario del producto (stock inicial 0) y
     * deja esa fila bloqueada de forma EXCLUSIVA para el resto de la transacción.
     *
     * Por qué upsert y NO insertOrIgnore:
     *  - insertOrIgnore, ante un duplicado, toma un bloqueo COMPARTIDO (S) sobre
     *    la fila existente. El SELECT ... FOR UPDATE posterior intenta subir ese
     *    bloqueo (S -> X); si varias transacciones hacen la misma subida a la
     *    vez, InnoDB puede provocar un interbloqueo y abortar una venta legítima.
     *  - ON DUPLICATE KEY UPDATE (MySQL) / ON CONFLICT DO UPDATE (SQLite) toma
     *    directamente un bloqueo EXCLUSIVO (X) sobre la fila duplicada, SIN
     *    subida de bloqueo. Las transacciones se serializan esperando ese X, en
     *    lugar de chocar subiendo de S a X, y se evita el interbloqueo.
     *    La actualización en conflicto es un no-op sobre producto_id: NO toca
     *    stock_actual, por lo que jamás reinicia las existencias ya registradas.
     *
     * El índice UNIQUE(producto_id) garantiza una sola fila por producto.
     */
    private function crearFilaSiNoExiste(int $productoId): void
    {
        DB::table('inventario_producto')->upsert(
            [[
                'producto_id' => $productoId,
                'stock_actual' => 0,
                'stock_minimo' => 0,
                'unidad' => 'unidad',
                'created_at' => now(),
                'updated_at' => now(),
            ]],
            ['producto_id'],
            ['producto_id'] // no-op: solo fuerza el bloqueo exclusivo; no altera datos.
        );
    }
}
