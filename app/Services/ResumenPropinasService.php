<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Resumen de PROPINAS (solo lectura).
 *
 * QUÉ CALCULA
 * -----------
 * Suma las propinas realmente guardadas en `cuentas.propina` para las cuentas
 * COBRADAS (estado PAGADA) dentro de un RANGO de fechas (Desde/Hasta, ambos
 * extremos incluidos), y devuelve el desglose por factura/cuenta para
 * contrastar contra los vouchers.
 *
 * FILTROS (se combinan entre sí)
 * ------------------------------
 *  - `desde` / `hasta`: rango de fechas del cobro (facturas.fecha_emision).
 *  - `mesero`: usuarios con rol MESERO que enviaron comandas de la venta, o el
 *    valor `pendiente` para ver solo lo que no se pudo atribuir.
 *  - `abrio`: usuario que abrió la mesa (`ventas.usuario_id`).
 *  - `mesa`: mesa de la venta (`ventas.mesa_id`).
 *  - `apertura`: turno/caja del cobro (`cuentas.apertura_id`).
 *  - `cobro`: usuario que cobró la cuenta (`cuentas.usuario_cobro`).
 * Los filtros se aplican sobre el conjunto ya atribuido, así los totales, el
 * resumen individual y la conciliación siempre describen el MISMO conjunto.
 * Las opciones de los selectores se calculan antes de filtrar: al cambiar un
 * filtro no desaparecen las demás opciones.
 *
 * ATRIBUCIÓN DE USUARIOS (son datos DISTINTOS)
 * --------------------------------------------
 *   - `ventas.usuario_id`    = quién ABRIÓ la venta/mesa en el POS.
 *   - `comandas.usuario_id`  = quién ENVIÓ los pedidos (quién atendió).
 *   - `cuentas.usuario_cobro` = quién COBRÓ la cuenta.
 *   - `facturas.usuario_id`  = quién EMITIÓ/COBRÓ la factura.
 *
 * REGLAS DE ATRIBUCIÓN (negocio)
 * ------------------------------
 *  1. Solo pueden recibir propinas los usuarios con rol MESERO. ADMIN,
 *     GERENTE, CAJERO y COCINERO se excluyen SIEMPRE, aunque hayan abierto la
 *     mesa, enviado comandas o cobrado la factura. Abrir o cobrar NO atribuye
 *     la propina al que ejecutó la acción.
 *  2. Quién atendió se determina ÚNICAMENTE con las comandas.
 *  3. Si la venta tiene UN SOLO mesero (rol MESERO) en sus comandas, la
 *     propina se le atribuye completa: es el único caso verificable.
 *  4. Si hay VARIOS meseros en la misma venta NO se reparte: el esquema no
 *     registra porcentajes ni quién cobró qué, así que la propina queda
 *     PENDIENTE DE ATRIBUCIÓN. Se cuenta UNA sola vez (nunca una vez por
 *     mesero), por eso los reportes individuales no duplican importes.
 *  5. Si no hay ninguna comanda de un usuario MESERO (datos históricos, o la
 *     comanda la envió otro rol), también queda PENDIENTE. Nunca se completa
 *     el dato inventando un mesero.
 *
 * CONCILIACIÓN
 * ------------
 * `total` = `atribuido` + `pendiente` de forma EXACTA: `pendiente` se calcula
 * como `total - atribuido` sobre el mismo conjunto de cuentas. Cada propina
 * entra una sola vez (o está atribuida, o está pendiente), así que la suma
 * individual de los meseros tampoco puede exceder el total general.
 *
 * QUÉ FECHA SE USA (día del cobro)
 * --------------------------------
 * La propina se cobra EN EL MOMENTO en que se emite la factura: el cobro
 * escribe `cuentas.estado = PAGADA` y, en la MISMA transacción, FacturaService
 * inserta la factura con `fecha_emision = now()`. Por eso el día real del cobro
 * es `facturas.fecha_emision`, NO `cuentas.updated_at`.
 *
 * `cuentas.updated_at` es la fecha de la ÚLTIMA modificación de la fila y NO
 * sirve como fecha de cobro: cualquier operación posterior (renombrar la
 * cuenta, recalcular su total, anular un producto, un traslado, etc.) la
 * reescribe. Si se filtrara por esa columna, una propina antigua podría
 * desplazarse a otro día o desaparecer del día en que realmente se cobró.
 * `facturas.fecha_emision` es inmutable y es la fuente fiable.
 *
 * CÓMO SE EVITA DUPLICAR IMPORTES
 * -------------------------------
 * La propina vive SOLO en `cuentas.propina` y se lee una vez por cuenta. La
 * unión `cuentas` → `facturas` es 1:1 (cada cobro emite UNA factura por
 * cuenta), así que no multiplica filas. NO se une a `cuenta_detalles` ni a
 * `pagos_cuenta`, porque esas uniones sí multiplicarían filas y sumarían la
 * propina varias veces. El desglose por producto no participa del cálculo.
 *
 * Además:
 *  - Solo cuentan facturas con estado `EMITIDA`: una factura `ANULADA` deja la
 *    cuenta reabierta y un nuevo cobro emite OTRA factura para la misma
 *    cuenta. Si se contaran las anuladas, la propina del recobro aparecería
 *    dos veces (una por cada fecha).
 *  - Se toma únicamente la ÚLTIMA factura EMITIDA de cada cuenta, para que un
 *    dato duplicado (dos EMITIDA por el mismo `cuenta_id`) tampoco multiplique
 *    el importe.
 *
 * Solo LEE: nunca modifica facturas, cuentas ni propinas históricas. Tampoco
 * marca propinas como pagadas (el esquema NO registra el pago de propinas al
 * personal; ver `total_propinas` / `propinas_pagadas`).
 */
class ResumenPropinasService
{
    /** Único rol que puede recibir propinas. */
    public const ROL_MESERO = 'MESERO';

    /** Valor del filtro `mesero` para ver lo no atribuido. */
    public const SIN_ATRIBUIR = 'pendiente';

    /**
     * Resumen de propinas de un solo día (Y-m-d).
     *
     * Se conserva como atajo de `resumenDelRango` para no romper a los
     * consumidores que ya lo usaban con una única fecha.
     *
     * @param  array{mesero?: int|string|null, abrio?: int|null, mesa?: int|null, apertura?: int|null, cobro?: int|null}  $filtros
     * @return array<string, mixed>
     */
    public function resumenDelDia(string $fecha, array $filtros = []): array
    {
        $resumen = $this->resumenDelRango($fecha, $fecha, $filtros);

        // Compatibilidad: los consumidores antiguos esperan la clave 'fecha'.
        if (($resumen['exito'] ?? false) === false && ! isset($resumen['fecha'])) {
            $resumen['fecha'] = $fecha;
        }

        return $resumen;
    }

    /**
     * Resumen de propinas de un rango de fechas (ambos extremos incluidos).
     *
     * El criterio sigue siendo la FECHA DE EMISIÓN de la factura
     * (`facturas.fecha_emision`), que se escribe en la misma transacción del
     * cobro y NO cambia después (ver docblock de la clase).
     *
     * El día Hasta se incluye COMPLETO: el rango es semiabierto
     * `>= inicio(desde)` y `< inicio(hasta + 1 día)`, así una propina cobrada
     * a las 23:59 del último día también entra y no se pierde por la hora.
     *
     * @param  array{mesero?: int|string|null, abrio?: int|null, mesa?: int|null, apertura?: int|null, cobro?: int|null}  $filtros
     * @return array<string, mixed>
     */
    public function resumenDelRango(string $desde, string $hasta, array $filtros = []): array
    {
        if (! $this->esFechaValida($desde) || ! $this->esFechaValida($hasta)) {
            return $this->respuestaVacia($desde, $hasta, 'Las fechas indicadas no son válidas.', $filtros);
        }

        // Un rango invertido generaría un resumen vacío engañoso: se rechaza
        // con un mensaje claro en vez de devolver cifras incorrectas.
        if ($desde > $hasta) {
            return $this->respuestaVacia($desde, $hasta, 'La fecha "Desde" no puede ser posterior a la fecha "Hasta".', $filtros);
        }

        // ESTRUCTURA MÍNIMA: `Schema::hasTable()` NO garantiza que la tabla
        // tenga todas sus columnas. Una instalación con la migración de
        // propinas pendiente tiene `cuentas` pero SIN `propina`/`descuento`, y
        // la consulta fallaría con un error SQLSTATE en pantalla. Se devuelve
        // un aviso controlado (mismo patrón que ConfiguracionService).
        if (($faltante = $this->esquemaFaltante()) !== null) {
            return $this->respuestaVacia(
                $desde,
                $hasta,
                'El resumen de propinas no está disponible: '.$faltante.'. Aplica las migraciones pendientes.',
                $filtros
            );
        }

        // Límites semiabiertos: [inicio del día Desde, inicio del día siguiente
        // a Hasta). Incluye todo el día Hasta aunque tenga hora. Se usan
        // comparaciones de rango (no funciones sobre la columna) para no
        // impedir el uso de índices.
        $inicio = Carbon::parse($desde)->startOfDay();
        $finExclusivo = Carbon::parse($hasta)->startOfDay()->addDay();

        // Unión 1:1 cuentas → facturas: cada cuenta cobrada tiene UNA factura
        // vigente. La propina vive SOLO en `cuentas.propina` y se lee una vez
        // por cuenta; no se une a `cuenta_detalles` ni a `pagos_cuenta` (esas
        // uniones duplicarían el importe).
        //
        // `consumo_detalle` es la MISMA fuente que usa el cobro
        // (`VentaController::pagarCuenta`) y la factura (`FacturaService`):
        // la suma de `cuenta_detalles.subtotal` no eliminados. Se trae como
        // subconsulta correlacionada para no multiplicar filas.
        // Las uniones opcionales se añaden solo si la tabla existe: el módulo
        // debe seguir funcionando (sin datos de mesa/detalle) en esquemas
        // mínimos o despliegues parciales, en lugar de fallar con SQL.
        $tieneMesas = $this->tieneTablaYColumnas('mesas', ['id', 'numero']);
        $tieneDetalle = $this->tieneTablaYColumnas('cuenta_detalles', ['cuenta_id', 'subtotal', 'eliminado']);

        $consulta = DB::table('cuentas as c')
            ->join('facturas as f', 'f.cuenta_id', '=', 'c.id')
            ->leftJoin('ventas as v', 'v.id', '=', 'c.venta_id');

        if ($tieneMesas) {
            $consulta->leftJoin('mesas as m', 'm.id', '=', 'v.mesa_id');
        }

        // Asignación explícita de responsable (snapshot en la cuenta). Si el
        // esquema aún no tiene la columna, se ignora y se conserva la
        // atribución histórica basada en comandas.
        $tieneMeseroResp = Schema::hasColumn('cuentas', 'mesero_responsable_id');

        $columnas = [
            'c.id as cuenta_id',
            'c.nombre as cuenta_nombre',
            'c.propina',
            'c.descuento',
            'c.total as total_cuenta',
            'c.venta_id',
            'c.usuario_cobro',
            'c.updated_at as cuenta_actualizada',
            'c.metodo_pago',
            'c.apertura_id',
            'c.created_at as cuenta_creada',
            'f.numero_factura',
            'f.fecha_emision',
            'f.usuario_id as factura_usuario_id',
            'f.total as factura_total',
            'v.usuario_id as venta_usuario_id',
            'v.fecha as venta_fecha',
            'v.mesa_id',
        ];

        if ($tieneMeseroResp) {
            $columnas[] = 'c.mesero_responsable_id';
        }

        $columnas[] = $tieneMesas
            ? 'm.numero as mesa_numero'
            : DB::raw('NULL as mesa_numero');

        // Consumo real: la MISMA fuente que usa el cobro. Es una subconsulta
        // correlacionada para no multiplicar filas (no se une a los detalles).
        $columnas[] = $tieneDetalle
            ? DB::raw('(select coalesce(sum(cd.subtotal), 0) from cuenta_detalles as cd where cd.cuenta_id = c.id and cd.eliminado = 0) as consumo_detalle')
            : DB::raw('NULL as consumo_detalle');

        $cuentas = $consulta
            ->where('c.estado', 'PAGADA')
            ->where('c.propina', '>', 0)
            ->where('f.estado', 'EMITIDA')
            // Solo la última factura EMITIDA de cada cuenta (evita doble
            // conteo si existieran dos EMITIDA para el mismo cuenta_id).
            ->whereIn('f.id', function ($sub) {
                $sub->select(DB::raw('MAX(f2.id)'))
                    ->from('facturas as f2')
                    ->where('f2.estado', 'EMITIDA')
                    ->whereNotNull('f2.cuenta_id')
                    ->groupBy('f2.cuenta_id');
            })
            ->where('f.fecha_emision', '>=', $inicio)
            ->where('f.fecha_emision', '<', $finExclusivo)
            ->orderBy('c.id')
            ->get($columnas);

        // Etiquetas legibles del turno/caja de cada cuenta (`cuentas.apertura_id`).
        // Es solo presentación: la caja no altera ningún cálculo.
        $aperturaIds = $cuentas->pluck('apertura_id')->filter()->unique()->values()->all();
        $turnos = [];
        if (! empty($aperturaIds) && $this->tieneTablaYColumnas('caja_apertura', ['id', 'fecha'])) {
            $tieneCajero = Schema::hasColumn('caja_apertura', 'cajero');
            $filasTurno = DB::table('caja_apertura')
                ->whereIn('id', $aperturaIds)
                ->get($tieneCajero ? ['id', 'fecha', 'cajero'] : ['id', 'fecha']);

            foreach ($filasTurno as $filaTurno) {
                $etiqueta = 'Turno #'.$filaTurno->id;

                if ($filaTurno->fecha !== null) {
                    try {
                        $etiqueta .= ' — '.Carbon::parse($filaTurno->fecha)->format('d/m/Y H:i');
                    } catch (\Throwable $e) {
                        // Fecha ilegible: se conserva solo el número de turno.
                    }
                }

                if ($tieneCajero && ($filaTurno->cajero ?? '') !== '') {
                    $etiqueta .= ' · '.$filaTurno->cajero;
                }

                $turnos[(int) $filaTurno->id] = $etiqueta;
            }
        }

        // Usuarios que enviaron comandas por venta (puede haber varios).
        $ventaIds = $cuentas->pluck('venta_id')->filter()->unique()->values()->all();
        $comandasPorVenta = [];
        if (! empty($ventaIds) && $this->tieneTablaYColumnas('comandas', ['venta_id', 'usuario_id'])) {
            $comandas = DB::table('comandas')
                ->whereIn('venta_id', $ventaIds)
                ->whereNotNull('usuario_id')
                ->orderBy('id')
                ->get(['venta_id', 'usuario_id']);

            foreach ($comandas as $comanda) {
                $comandasPorVenta[(int) $comanda->venta_id][] = (int) $comanda->usuario_id;
            }

            foreach ($comandasPorVenta as $ventaId => $ids) {
                $comandasPorVenta[$ventaId] = array_values(array_unique($ids));
            }
        }

        // Usuarios implicados + rol de cada uno (para excluir a quien no es
        // MESERO de la atribución de propinas).
        $usuarioIds = $cuentas->pluck('venta_usuario_id')
            ->merge($cuentas->pluck('usuario_cobro'))
            ->merge($cuentas->pluck('factura_usuario_id'))
            ->merge($cuentas->pluck('mesero_responsable_id'))
            ->filter()
            ->unique()
            ->values()
            ->all();
        foreach ($comandasPorVenta as $idsAtendieron) {
            foreach ($idsAtendieron as $idAtendio) {
                $usuarioIds[] = $idAtendio;
            }
        }

        $nombres = [];
        $roles = [];
        $activos = [];
        if ($this->tieneTablaYColumnas('usuarios', ['id', 'nombre']) && $usuarioIds !== []) {
            $usuarios = DB::table('usuarios')->whereIn('usuarios.id', array_values(array_unique($usuarioIds)));
            $columnasUsuarios = ['usuarios.id as id', 'usuarios.nombre as nombre'];
            // El rol es imprescindible para atribuir: si el esquema no lo tiene
            // se deja en NULL y ninguna propina se atribuye (nunca se adivina).
            if ($this->tieneTablaYColumnas('roles', ['id', 'nombre']) && Schema::hasColumn('usuarios', 'rol_id')) {
                $usuarios->leftJoin('roles as r', 'r.id', '=', 'usuarios.rol_id');
                $columnasUsuarios[] = 'r.nombre as rol';
            }
            if (Schema::hasColumn('usuarios', 'activo')) {
                $columnasUsuarios[] = 'usuarios.activo as activo';
            }
            foreach ($usuarios->get($columnasUsuarios) as $usuario) {
                // Un nombre NULL o vacío NUNCA se muestra: se usa un texto
                // honesto («Usuario no identificado»). El nombre se guarda
                // saneado: sin espacios extremos y sin valor en blanco.
                $nombreLimpio = trim((string) ($usuario->nombre ?? ''));
                $nombres[(int) $usuario->id] = $nombreLimpio !== '' ? $nombreLimpio : 'Usuario no identificado';
                $roles[(int) $usuario->id] = strtoupper((string) ($usuario->rol ?? ''));
                $activos[(int) $usuario->id] = isset($usuario->activo) ? (int) $usuario->activo : 1;
            }
        }

        $detalle = $cuentas->map(function ($cuenta) use ($comandasPorVenta, $nombres, $roles, $turnos) {
            // Asignación histórica de responsable (snapshot). Prioridad 1 sobre
            // la atribución por comandas: se reconoce aunque el mesero haya sido
            // desactivado posteriormente (la atribución histórica no depende del
            // estado actual del usuario).
            $meseroRespId = isset($cuenta->mesero_responsable_id) ? (int) $cuenta->mesero_responsable_id : null;

            if ($meseroRespId !== null
                && ($meseroRespId <= 0
                    || ($roles[$meseroRespId] ?? '') !== self::ROL_MESERO)) {
                // No es un usuario MESERO válido: la atribución histórica se
                // descarta y queda pendiente.
                $meseroRespId = null;
            }

            // Quién atendió = usuarios MESERO que enviaron comandas de la
            // venta. Los demás roles se descartan por completo: abrir la mesa
            // o cobrar la factura NO atribuye la propina.
            $idsAtendieron = $comandasPorVenta[(int) $cuenta->venta_id] ?? [];
            $meseros = [];
            foreach ($idsAtendieron as $idAtendio) {
                if (($roles[$idAtendio] ?? '') === self::ROL_MESERO) {
                    $meseros[] = $idAtendio;
                }
            }
            $meseros = array_values(array_unique($meseros));

            // Nombres de TODOS los meseros implicados (los que enviaron
            // comandas Y el responsable explícito de la cuenta). El
            // responsable puede no estar en comandas: sin este mapa completo,
            // los desplegables/chips mostraban «Usuario #N» aunque el nombre
            // existía. Si el usuario no tiene nombre (o ya no existe), se
            // muestra «Usuario no identificado», nunca un ID inventado.
            $meserosNombresCompletos = [];
            foreach (\array_values(\array_unique(\array_merge($meseros, $meseroRespId !== null ? [$meseroRespId] : []))) as $idMeseroCompleto) {
                $meserosNombresCompletos[$idMeseroCompleto] = $nombres[$idMeseroCompleto] ?? 'Usuario no identificado';
            }

            $nombresPorMesero = [];
            foreach ($meseros as $idMesero) {
                $nombresPorMesero[$idMesero] = $meserosNombresCompletos[$idMesero];
            }
            $nombresAtendieron = array_values(array_unique(array_values($nombresPorMesero)));

            // PRIORIDAD 1: responsable asignado explícitamente (snapshot en la
            // cuenta, de un único MESERO activo). En su ausencia, se atribuye
            // solo si la venta tiene EXACTAMENTE UN mesero en comandas.
            $meseroAtribuidoId = $meseroRespId
                ?? (count($meseros) === 1 ? $meseros[0] : null);

            $motivoPendiente = null;
            if ($meseroAtribuidoId === null) {
                $motivoPendiente = $meseros === [] ? 'sin_mesero' : 'varios_meseros';
            }

            $usuarioAbreId = $cuenta->venta_usuario_id ? (int) $cuenta->venta_usuario_id : null;

            // CONSUMO: se recalcula con la fuente real del cobro
            // (`cuenta_detalles`). Si no hay detalle histórico se usan, en
            // orden, `cuentas.total` (que el cobro guarda como consumo) y el
            // respaldo `factura.total - propina + descuento`. Así el consumo
            // nunca aparece en cero cuando existe consumo registrado.
            $consumoDetalle = (float) ($cuenta->consumo_detalle ?? 0);
            $totalCuenta = (float) ($cuenta->total_cuenta ?? 0);
            $descuento = (float) ($cuenta->descuento ?? 0);
            $facturaTotal = (float) ($cuenta->factura_total ?? 0);

            $montoConsumo = $consumoDetalle > 0
                ? $consumoDetalle
                : ($totalCuenta > 0
                    ? $totalCuenta
                    : max(0.0, $facturaTotal - (float) $cuenta->propina + $descuento));

            $nombreAbre = $usuarioAbreId === null
                ? null
                : ($nombres[$usuarioAbreId] ?? 'Usuario no identificado');
            $nombreCobro = $cuenta->usuario_cobro
                ? ($nombres[(int) $cuenta->usuario_cobro] ?? 'Usuario no identificado')
                : null;

            return [
                'cuenta_id' => (int) $cuenta->cuenta_id,
                'cuenta_nombre' => (string) $cuenta->cuenta_nombre,
                'venta_id' => (int) $cuenta->venta_id,
                'numero_factura' => $cuenta->numero_factura !== null ? (string) $cuenta->numero_factura : null,
                'propina' => round((float) $cuenta->propina, 2),
                // Fecha de cobro real = emisión de la factura (inmutable).
                'fecha' => $cuenta->fecha_emision,
                'fecha_pago' => $cuenta->fecha_emision,
                // Se conserva la última modificación de la cuenta solo como
                // dato informativo para detectar ediciones posteriores.
                'cuenta_actualizada' => $cuenta->cuenta_actualizada,
                'mesa_id' => $cuenta->mesa_id ? (int) $cuenta->mesa_id : null,
                'mesa_numero' => $cuenta->mesa_numero,
                'mesa_nombre' => $cuenta->mesa_numero ? (string) $cuenta->mesa_numero : null,
                // Solo los MESERO cuentan como «mesero que atendió».
                'mesero_atendio' => $nombresAtendieron === [] ? null : implode(', ', $nombresAtendieron),
                'mesero_atendio_id' => $meseroAtribuidoId,
                'meseros_atendieron' => $nombresAtendieron,
                // Participantes (para filtros): meseros que enviaron comandas
                // más el responsable asignado explícitamente.
                'meseros_ids' => \array_values(\array_unique(\array_merge($meseros, $meseroRespId !== null ? [$meseroRespId] : []))),
                'meseros_nombres' => $meserosNombresCompletos,
                // Atribución de la propina (una sola vez por cuenta).
                'atribucion' => $meseroAtribuidoId === null ? 'PENDIENTE' : 'ATRIBUIDA',
                'mesero_atribuido_id' => $meseroAtribuidoId,
                'mesero_atribuido' => $meseroAtribuidoId === null
                    ? null
                    : ($nombres[$meseroAtribuidoId] ?? 'Usuario no identificado'),
                'motivo_pendiente' => $motivoPendiente,
                'usuario_abrio_mesa' => $nombreAbre,
                'usuario_abrio_mesa_id' => $usuarioAbreId,
                'usuario_abre_mesa' => $nombreAbre ?? 'No disponible',
                'usuario_cobro' => $nombreCobro,
                'usuario_cobro_id' => $cuenta->usuario_cobro ? (int) $cuenta->usuario_cobro : null,
                'realizado_por' => $nombreCobro,
                'metodo_pago' => $cuenta->metodo_pago,
                // Turno/caja del cobro (solo informativo: no entra en cálculos).
                'apertura_id' => $cuenta->apertura_id ? (int) $cuenta->apertura_id : null,
                'turno' => $cuenta->apertura_id
                    ? ($turnos[(int) $cuenta->apertura_id] ?? ('Turno #'.$cuenta->apertura_id))
                    : null,
                'venta_fecha' => $cuenta->venta_fecha,
                'fecha_apertura' => $cuenta->venta_fecha,
                'descuento' => round($descuento, 2),
                'monto_consumo' => round($montoConsumo, 2),
                'total_facturado' => round($facturaTotal, 2),
            ];
        });

        // Opciones de los selectores: se calculan sobre el período COMPLETO
        // (antes de filtrar) para que cambiar un filtro no oculte opciones.
        $opciones = $this->opcionesDesde($detalle);

        // Filtros combinados (todos opcionales y acumulativos).
        $detalle = $this->aplicarFiltros($detalle, $filtros);

        $total = round((float) $detalle->sum('propina'), 2);
        $atribuido = round((float) $detalle->where('atribucion', 'ATRIBUIDA')->sum('propina'), 2);
        // Se deriva del total para garantizar la conciliación EXACTA
        // atribuido + pendiente = total (sin descuadres de centavos).
        $pendiente = round($total - $atribuido, 2);

        $meserosConPropina = $detalle->where('atribucion', 'ATRIBUIDA')
            ->pluck('mesero_atribuido_id')
            ->filter()
            ->unique()
            ->count();

        $meserosParticipantes = $detalle->flatMap(fn ($linea) => $linea['meseros_ids'])->unique()->count();

        // PROPIEDAD IMPORTANTE: la propina se cobra al cliente en el MISMO
        // cobro en que se emite la factura, y no existe ninguna columna/tabla
        // que registre su entrega posterior al personal. Por eso NO hay dos
        // importes distintos que sumar: «generada» y «cobrada» son el mismo
        // hecho. Se expone UNA sola cifra y `propinas_pagadas` queda en null
        // para que ninguna vista la presente como un segundo total.
        return [
            'exito' => true,
            'desde' => $desde,
            'hasta' => $hasta,
            'total' => $total,
            'cantidad' => $detalle->count(),
            'detalle' => $detalle->values(),
            // Conciliación: atribuido + pendiente === total.
            'atribuido' => $atribuido,
            'pendiente' => $pendiente,
            'cantidad_atribuidas' => $detalle->where('atribucion', 'ATRIBUIDA')->count(),
            'cantidad_pendientes' => $detalle->where('atribucion', 'PENDIENTE')->count(),
            'meseros_participantes' => $meserosParticipantes,
            'meseros_con_propina' => $meserosConPropina,
            // Solo indicador: no altera `total` ni la conciliación.
            'cuentas_sin_factura' => $this->contarCuentasSinFactura($inicio, $finExclusivo),
            'resumen_meseros' => $this->resumenPorMesero($detalle),
            'opciones' => $opciones,
            'filtros' => $this->filtrosNormalizados($filtros),
            'propinas_generadas' => $total,
            'propinas_cobradas' => $total,
            // El esquema no registra el pago de propinas al personal.
            'propinas_pagadas' => null,
            'total_propinas' => $total,
            'pago_propinas_registrado' => false,
        ];
    }

    /**
     * Resumen diario individual: una fila por fecha + mesero (más una fila por
     * fecha para lo no atribuido). La suma de `propinas` de todas las filas
     * coincide con el total general.
     *
     * @param  Collection<int, array<string, mixed>>  $detalle
     * @return Collection<int, array<string, mixed>>
     */
    private function resumenPorMesero(Collection $detalle): Collection
    {
        $grupos = [];

        foreach ($detalle as $linea) {
            $dia = Carbon::parse($linea['fecha'])->format('Y-m-d');
            $atribuida = $linea['atribucion'] === 'ATRIBUIDA';
            $clave = $dia.'|'.($atribuida ? 'm'.$linea['mesero_atribuido_id'] : self::SIN_ATRIBUIR);

            if (! isset($grupos[$clave])) {
                $grupos[$clave] = [
                    'fecha' => $dia,
                    'mesero_id' => $atribuida ? $linea['mesero_atribuido_id'] : null,
                    'mesero' => $atribuida
                        ? $linea['mesero_atribuido']
                        : 'Pendiente de atribución',
                    'pendiente' => ! $atribuida,
                    'mesas' => [],
                    'cuentas_cobradas' => 0,
                    'consumo' => 0.0,
                    'propinas' => 0.0,
                ];
            }

            // Una mesa se cuenta una vez aunque tenga varias cuentas cobradas.
            $grupos[$clave]['mesas'][] = $linea['mesa_id']
                ? 'm'.$linea['mesa_id']
                : 'v'.$linea['venta_id'];
            $grupos[$clave]['cuentas_cobradas']++;
            $grupos[$clave]['consumo'] += (float) $linea['monto_consumo'];
            $grupos[$clave]['propinas'] += (float) $linea['propina'];
        }

        return collect($grupos)
            ->map(fn (array $grupo) => [
                'fecha' => $grupo['fecha'],
                'mesero_id' => $grupo['mesero_id'],
                'mesero' => $grupo['mesero'],
                'pendiente' => $grupo['pendiente'],
                'mesas_atendidas' => count(array_unique($grupo['mesas'])),
                'cuentas_cobradas' => $grupo['cuentas_cobradas'],
                'consumo' => round($grupo['consumo'], 2),
                'propinas' => round($grupo['propinas'], 2),
            ])
            ->sortBy([
                ['fecha', 'asc'],
                ['mesero', 'asc'],
            ])
            ->values();
    }

    /**
     * Aplica los filtros al conjunto de cuentas. Todos son opcionales y se
     * combinan con AND.
     *
     * @param  Collection<int, array<string, mixed>>  $detalle
     * @param  array<string, mixed>  $filtros
     * @return Collection<int, array<string, mixed>>
     */
    private function aplicarFiltros(Collection $detalle, array $filtros): Collection
    {
        $filtros = $this->filtrosNormalizados($filtros);

        return $detalle
            ->filter(function (array $linea) use ($filtros) {
                // Filtro por mesero que atendió: basta con que haya participado
                // en la venta (aunque la propina quede pendiente por ser varios).
                if ($filtros['mesero'] === self::SIN_ATRIBUIR) {
                    if ($linea['atribucion'] !== 'PENDIENTE') {
                        return false;
                    }
                } elseif ($filtros['mesero'] !== null) {
                    if (! in_array((int) $filtros['mesero'], $linea['meseros_ids'], true)) {
                        return false;
                    }
                }

                if ($filtros['abrio'] !== null && (int) $linea['usuario_abrio_mesa_id'] !== (int) $filtros['abrio']) {
                    return false;
                }

                if ($filtros['mesa'] !== null && (int) $linea['mesa_id'] !== (int) $filtros['mesa']) {
                    return false;
                }

                // Turno/caja del cobro (`cuentas.apertura_id`).
                if ($filtros['apertura'] !== null && (int) $linea['apertura_id'] !== (int) $filtros['apertura']) {
                    return false;
                }

                // Usuario que cobró la cuenta (`cuentas.usuario_cobro`).
                if ($filtros['cobro'] !== null && (int) $linea['usuario_cobro_id'] !== (int) $filtros['cobro']) {
                    return false;
                }

                return true;
            })
            ->values();
    }

    /**
     * Opciones válidas para los selectores del formulario.
     *
     * @param  Collection<int, array<string, mixed>>  $detalle
     * @return array{meseros: array<int, string>, usuarios: array<int, string>, mesas: array<int, string>, turnos: array<int, string>, cobradores: array<int, string>}
     */
    private function opcionesDesde(Collection $detalle): array
    {
        $meseros = [];
        $usuarios = [];
        $mesas = [];
        $turnos = [];
        $cobradores = [];

        foreach ($detalle as $linea) {
            foreach ($linea['meseros_ids'] as $idMesero) {
                $meseros[$idMesero] = $linea['meseros_nombres'][$idMesero] ?? 'Usuario no identificado';
            }

            if ($linea['usuario_abrio_mesa_id'] !== null) {
                $usuarios[$linea['usuario_abrio_mesa_id']] = $linea['usuario_abrio_mesa'];
            }

            if ($linea['mesa_id'] !== null) {
                $mesas[$linea['mesa_id']] = (string) $linea['mesa_nombre'];
            }

            if ($linea['apertura_id'] !== null) {
                $turnos[$linea['apertura_id']] = (string) ($linea['turno'] ?? ('Turno #'.$linea['apertura_id']));
            }

            if ($linea['usuario_cobro_id'] !== null) {
                $cobradores[$linea['usuario_cobro_id']] = $linea['usuario_cobro']
                    ?? 'Usuario no identificado';
            }
        }

        ksort($meseros);
        ksort($usuarios);
        ksort($mesas);
        ksort($turnos);
        ksort($cobradores);

        return [
            'meseros' => $meseros,
            'usuarios' => $usuarios,
            'mesas' => $mesas,
            'turnos' => $turnos,
            'cobradores' => $cobradores,
        ];
    }

    /**
     * Normaliza los filtros a `int|null` (o `pendiente` para el mesero).
     *
     * @param  array<string, mixed>  $filtros
     * @return array{mesero: int|string|null, abrio: int|null, mesa: int|null, apertura: int|null, cobro: int|null}
     */
    private function filtrosNormalizados(array $filtros): array
    {
        $mesero = $filtros['mesero'] ?? null;
        if (is_string($mesero) && $mesero === self::SIN_ATRIBUIR) {
            $mesero = self::SIN_ATRIBUIR;
        } elseif (is_numeric($mesero) && (int) $mesero > 0) {
            $mesero = (int) $mesero;
        } else {
            $mesero = null;
        }

        $entero = static function ($valor): ?int {
            return (is_numeric($valor) && (int) $valor > 0) ? (int) $valor : null;
        };

        return [
            'mesero' => $mesero,
            'abrio' => $entero($filtros['abrio'] ?? null),
            'mesa' => $entero($filtros['mesa'] ?? null),
            'apertura' => $entero($filtros['apertura'] ?? null),
            'cobro' => $entero($filtros['cobro'] ?? null),
        ];
    }

    /**
     * Cuentas PAGADAS con propina que NO tienen ninguna factura EMITIDA dentro
     * del período (según la última actualización de la cuenta, que es cuando se
     * cobró: `pagarCuenta` escribe `updated_at`).
     *
     * Es un INDICADOR DE CONSISTENCIA: sirve para detectar cobros sin respaldo
     * documental. NUNCA se suma al total de propinas (el total solo cuenta
     * cuentas con factura EMITIDA).
     */
    private function contarCuentasSinFactura(Carbon $inicio, Carbon $finExclusivo): int
    {
        $desde = $inicio->toDateTimeString();
        $hasta = $finExclusivo->toDateTimeString();

        return (int) DB::table('cuentas as c')
            ->where('c.estado', 'PAGADA')
            ->where('c.propina', '>', 0)
            ->whereRaw('COALESCE(c.updated_at, c.created_at) >= ?', [$desde])
            ->whereRaw('COALESCE(c.updated_at, c.created_at) < ?', [$hasta])
            ->whereNotExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('facturas as f2')
                    ->whereColumn('f2.cuenta_id', 'c.id')
                    ->where('f2.estado', 'EMITIDA');
            })
            ->count();
    }

    /**
     * Respuesta controlada cuando no se puede calcular (fechas inválidas,
     * rango invertido o esquema incompleto). Mantiene TODAS las claves para que
     * las vistas no tengan que protegerse contra índices inexistentes y
     * conserva los filtros recibidos para que el formulario no los pierda.
     *
     * @param  array<string, mixed>  $filtros
     * @return array<string, mixed>
     */
    public function respuestaVacia(string $desde, string $hasta, ?string $error, array $filtros = []): array
    {
        return [
            'exito' => false,
            'error' => $error,
            'desde' => $desde,
            'hasta' => $hasta,
            'total' => 0.0,
            'cantidad' => 0,
            'detalle' => collect(),
            'atribuido' => 0.0,
            'pendiente' => 0.0,
            'cantidad_atribuidas' => 0,
            'cantidad_pendientes' => 0,
            'meseros_participantes' => 0,
            'meseros_con_propina' => 0,
            'cuentas_sin_factura' => 0,
            'resumen_meseros' => collect(),
            'opciones' => ['meseros' => [], 'usuarios' => [], 'mesas' => [], 'turnos' => [], 'cobradores' => []],
            'filtros' => $this->filtrosNormalizados($filtros),
            'propinas_generadas' => 0.0,
            'propinas_cobradas' => 0.0,
            'propinas_pagadas' => null,
            'total_propinas' => 0.0,
            'pago_propinas_registrado' => false,
        ];
    }

    /**
     * ¿La tabla existe Y tiene TODAS las columnas indicadas?
     *
     * Se usa para las uniones OPCIONALES (mesas, cuenta_detalles, comandas,
     * usuarios): si falta la tabla o alguna columna, esa parte se omite en
     * lugar de romper la consulta.
     *
     * @param  array<int, string>  $columnas
     */
    private function tieneTablaYColumnas(string $tabla, array $columnas): bool
    {
        if (! Schema::hasTable($tabla)) {
            return false;
        }

        foreach ($columnas as $columna) {
            if (! Schema::hasColumn($tabla, $columna)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Estructura OBLIGATORIA del cálculo. Devuelve la primera carencia
     * encontrada (`falta la tabla ...` / `falta la columna ...`), o null si
     * todo está disponible.
     */
    private function esquemaFaltante(): ?string
    {
        $requeridas = [
            'cuentas' => ['id', 'nombre', 'venta_id', 'total', 'estado', 'propina', 'descuento', 'usuario_cobro', 'metodo_pago', 'created_at', 'updated_at'],
            'facturas' => ['id', 'cuenta_id', 'numero_factura', 'fecha_emision', 'total', 'usuario_id', 'estado'],
            'ventas' => ['id', 'usuario_id', 'mesa_id', 'fecha'],
        ];

        foreach ($requeridas as $tabla => $columnas) {
            if (! Schema::hasTable($tabla)) {
                return 'falta la tabla `'.$tabla.'`';
            }

            foreach ($columnas as $columna) {
                if (! Schema::hasColumn($tabla, $columna)) {
                    return 'falta la columna `'.$tabla.'.'.$columna.'`';
                }
            }
        }

        return null;
    }

    private function esFechaValida(string $fecha): bool
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            return false;
        }

        [$anio, $mes, $dia] = array_map('intval', explode('-', $fecha));

        return checkdate($mes, $dia, $anio);
    }
}
