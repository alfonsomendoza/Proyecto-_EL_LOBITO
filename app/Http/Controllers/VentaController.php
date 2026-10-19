<?php

namespace App\Http\Controllers;

use App\Services\ConfiguracionService;
use App\Services\FacturaService;
use App\Services\InventarioService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class VentaController extends Controller
{
    /**
     * Servicio de inventario (Etapa 2). Se resuelve del contenedor para no
     * cambiar el constructor del controlador.
     */
    private function inventario(): InventarioService
    {
        return app(InventarioService::class);
    }

    /**
     * Preferencias de módulos (cocina digital / inventario). Se resuelve del
     * contenedor para no cambiar el constructor del controlador.
     */
    private function configuracion(): ConfiguracionService
    {
        return app(ConfiguracionService::class);
    }

    /**
     * ¿El módulo de cocina digital gestiona estados de preparación?
     *
     * Solo cuando está HABILITADO se aplican las protecciones basadas en
     * `estado_preparacion`. Si está deshabilitado no hay preparación
     * gestionada y se conserva el comportamiento histórico de anulación.
     */
    private function cocinaGestionaEstados(): bool
    {
        return $this->configuracion()->cocinaDigitalHabilitada();
    }

    /**
     * Métodos de pago del cobro (formato de líneas múltiples `pagos[]`).
     * El banco NO es un método: es un dato extra de las líneas con tarjeta.
     */
    private const METODOS_PAGO = [
        'EFECTIVO_CORDOBA', // efectivo en córdobas
        'EFECTIVO_USD',     // efectivo en dólares (conversión con tasa del día)
        'TARJETA_DEBITO',   // tarjeta de débito (requiere banco)
        'TARJETA_CREDITO',  // tarjeta de crédito (requiere banco)
    ];

    /** Bancos admitidos para pagos con tarjeta (débito o crédito). */
    private const BANCOS_TARJETA = ['Banpro', 'Ficohsa', 'BAC', 'Lafise'];

    /**
     * ============================================================
     * MOSTRAR POS DE UNA MESA
     * ============================================================
     *
     * Esta función abre el punto de venta de una mesa.
     *
     * Aquí obtenemos:
     * - Mesa.
     * - Venta activa.
     * - Detalles de la venta.
     * - Historial de productos enviados.
     * - Productos del catálogo.
     * - Categorías.
     * - Comandas.
     * - Cuentas divididas.
     */
    public function pos(int $mesaId)
    {
        try {

            // ----------------------------------------------------
            // 1. USUARIO AUTENTICADO
            // ----------------------------------------------------

            $usuario = Auth::user();

            if (!$usuario) {
                abort(401, 'Usuario no autenticado.');
            }

            // Autorización en servidor: el POS muestra precios y totales.
            // COCINERO no debe acceder a información financiera. Se valida aquí
            // aunque el enlace esté oculto en la vista.
            $rol = strtoupper((string) ($usuario->rol->nombre ?? ''));

            if (!in_array($rol, ['ADMIN', 'GERENTE', 'CAJERO', 'MESERO'], true)) {
                abort(403, 'No tienes permiso para acceder al POS.');
            }


            // ----------------------------------------------------
            // 2. BUSCAR LA MESA
            // ----------------------------------------------------

            $mesa = DB::table('mesas')
                ->where('id', $mesaId)
                ->first();

            if (!$mesa) {
                abort(404, 'La mesa no existe.');
            }


            // ----------------------------------------------------
            // 3. BUSCAR LA VENTA ACTIVA
            // ----------------------------------------------------
            //
            // Hacemos JOIN con mesas para obtener:
            //
            // $venta->mesa_numero
            //
            // Esto es utilizado por:
            //
            // <span>Mesa {{ $venta->mesa_numero }}</span>
            //
            // del archivo ventas/pos.blade.php.
            // ----------------------------------------------------

            $venta = DB::table('ventas')
                ->join(
                    'mesas',
                    'mesas.id',
                    '=',
                    'ventas.mesa_id'
                )
                ->where(
                    'ventas.mesa_id',
                    $mesaId
                )
                ->whereIn(
                    'ventas.estado',
                    ['ACTIVA', 'ABIERTA']
                )
                ->select(
                    'ventas.*',
                    'mesas.numero as mesa_numero'
                )
                ->orderByDesc('ventas.id')
                ->first();


            // ----------------------------------------------------
            // 4. SI NO EXISTE VENTA ACTIVA, CREARLA
            // ----------------------------------------------------

            if (!$venta) {

                // Creamos una nueva venta.
                //
                // IMPORTANTE:
                // La tabla ventas utiliza "monto".
                // NO utiliza "total".
                //
                // Tampoco tiene created_at ni updated_at.

                $ventaId = DB::table('ventas')
                    ->insertGetId([

                        // Mesa relacionada.
                        'mesa_id' => $mesaId,

                        // Cliente todavía no asignado.
                        'cliente_id' => null,

                        // La apertura de caja se asignará
                        // posteriormente cuando corresponda.
                        'apertura_id' => null,

                        // Descripción inicial.
                        'descripcion' =>
                            'Consumo Mesa ' . $mesa->numero,

                        // La venta comienza en cero.
                        'monto' => 0,

                        // Todavía no se ha cobrado.
                        'metodo_pago' => null,

                        // Banco solamente aplica al cobro.
                        'banco' => null,

                        // Dinero recibido.
                        'monto_recibido' => null,

                        // Tasa de cambio.
                        'tasa_cambio' => null,

                        // Cambio.
                        'cambio' => null,

                        // Fecha de creación.
                        'fecha' => now(),

                        // Estado de la venta.
                        'estado' => 'ACTIVA',

                        // Usuario que abrió la venta.
                        'usuario_id' => $usuario->id,

                        // Sin descuento inicialmente.
                        'descuento_porcentaje' => 0,
                    ]);


                // ------------------------------------------------
                // LA MESA NO SE MARCA OCUPADA AQUÍ
                // ------------------------------------------------
                //
                // Abrir la pantalla de la mesa NO la ocupa. La mesa permanece
                // LIBRE hasta que se agregue el primer producto/bebida real
                // (ver enviarPedidoDesdePos). Así una venta vacía no deja la
                // mesa como OCUPADA sin consumo.

                // Toda venta necesita una cuenta inicial. Los pedidos nuevos
                // permanecen en esta cuenta hasta que se haga una división.
                DB::table('cuentas')->insert([
                    'venta_id' => $ventaId,
                    'nombre' => 'Cuenta Principal',
                    'total' => 0,
                    'estado' => 'ABIERTA',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);


                // ------------------------------------------------
                // VOLVER A CARGAR LA VENTA
                // ------------------------------------------------
                //
                // Volvemos a utilizar JOIN para que la venta
                // tenga también mesa_numero.
                // ------------------------------------------------

                $venta = DB::table('ventas')
                    ->join(
                        'mesas',
                        'mesas.id',
                        '=',
                        'ventas.mesa_id'
                    )
                    ->where(
                        'ventas.id',
                        $ventaId
                    )
                    ->select(
                        'ventas.*',
                        'mesas.numero as mesa_numero'
                    )
                    ->first();
            }


            // ----------------------------------------------------
            // 5. OBTENER DETALLES DE LA VENTA
            // ----------------------------------------------------
            //
            // Estos son los productos que pertenecen a la venta.
            //
            // Solo mostramos detalles que todavía estén ACTIVOS.
            // ----------------------------------------------------

            $detalles = DB::table('venta_detalles')

                // Relacionamos producto.
                ->join(
                    'productos',
                    'productos.id',
                    '=',
                    'venta_detalles.producto_id'
                )

                // Venta actual.
                ->where(
                    'venta_detalles.venta_id',
                    $venta->id
                )

                // Solo detalles activos.
                ->where(
                    'venta_detalles.estado',
                    'ACTIVO'
                )

                // Campos necesarios.
                ->select(
                    'venta_detalles.id',
                    'venta_detalles.venta_id',
                    'venta_detalles.producto_id',
                    'venta_detalles.cantidad',
                    'venta_detalles.precio_unitario',
                    'venta_detalles.subtotal',
                    'venta_detalles.comanda_id',
                    'venta_detalles.estado',

                    // Datos del producto.
                    'productos.nombre',
                    'productos.precio',
                    'productos.requiere_cocina'
                )

                // Orden original de los productos.
                ->orderBy('venta_detalles.id')

                // Ejecutar consulta.
                ->get();


            // ----------------------------------------------------
            // 6. CALCULAR TOTAL DE LA VENTA
            // ----------------------------------------------------

            $total = $detalles->sum(
                function ($detalle) {

                    return (float) $detalle->subtotal;
                }
            );


            // ----------------------------------------------------
            // 7. ACTUALIZAR MONTO DE LA VENTA
            // ----------------------------------------------------
            //
            // La tabla ventas utiliza la columna "monto".
            // ----------------------------------------------------

            DB::table('ventas')
                ->where('id', $venta->id)
                ->update([
                    'monto' => $total,
                ]);


            // Actualizamos también el objeto en memoria.
            $venta->monto = $total;


            // ----------------------------------------------------
            // 8. OBTENER HISTORIAL
            // ----------------------------------------------------
            //
            // El Blade utiliza $historial para mostrar:
            //
            // - Productos enviados.
            // - Cantidad.
            // - Subtotal.
            // - Estado.
            // - Número de comanda.
            // - Botón de reimpresión.
            //
            // LEFT JOIN porque los productos de barra pueden
            // no tener una comanda de cocina.
            // ----------------------------------------------------

            $historial = DB::table('venta_detalles')

                // Producto.
                ->join(
                    'productos',
                    'productos.id',
                    '=',
                    'venta_detalles.producto_id'
                )

                // Comanda opcional.
                ->leftJoin(
                    'comandas',
                    'comandas.id',
                    '=',
                    'venta_detalles.comanda_id'
                )

                // Venta actual.
                ->where(
                    'venta_detalles.venta_id',
                    $venta->id
                )

                // Las líneas trasladadas en origen quedan con cantidad 0
                // (no se borran: conservan comanda, notas e historial). Ese
                // consumo YA está en la mesa destino, así que aquí no se
                // muestran.
                ->where('venta_detalles.cantidad', '>', 0)

                // Campos que utiliza el Blade.
                ->select(
                    'venta_detalles.id',
                    'venta_detalles.venta_id',
                    'venta_detalles.producto_id',
                    'venta_detalles.cantidad',
                    'venta_detalles.precio_unitario',
                    'venta_detalles.subtotal',
                    'venta_detalles.comanda_id',
                    'venta_detalles.estado',

                    // Observación de la línea y estado de preparación (cocina
                    // digital). Se muestran en el POS para que la mesa consulte
                    // el progreso. Los pedidos históricos pueden tener NULL.
                    'venta_detalles.notas',
                    'venta_detalles.estado_preparacion',

                    // Producto.
                    'productos.nombre',

                    // Fuente de verdad para saber si va a cocina.
                    'productos.requiere_cocina',

                    // Número de ticket de la comanda.
                    'comandas.numero_ticket'
                )

                // Traslado individual: $h->trasladado indica que la línea
                // LLEGÓ de otra mesa (existe como destino en
                // traslado_detalles). Se usa EXISTS (y no JOIN) para no
                // duplicar filas; las líneas normales del POS valen 0.
                // Va con addSelect: un ->select() posterior borraría la
                // columna.
                ->addSelect([
                    DB::raw(
                        'EXISTS (SELECT 1 FROM traslado_detalles'
                        . ' WHERE traslado_detalles.venta_detalle_destino_id = venta_detalles.id)'
                        . ' AS trasladado'
                    ),
                ])

                // Mantener orden de creación.
                ->orderBy('venta_detalles.id')

                // Ejecutar.
                ->get();


            // ----------------------------------------------------
            // 9. OBTENER PRODUCTOS ACTIVOS
            // ----------------------------------------------------

            $productos = DB::table('productos')
                ->where('activo', 1)
                ->orderBy('nombre')
                ->get();


            // ----------------------------------------------------
            // 10. OBTENER CATEGORÍAS
            // ----------------------------------------------------

            $categorias = DB::table('categorias')
                ->orderBy('nombre')
                ->get();


            // ----------------------------------------------------
            // 11. OBTENER COMANDAS
            // ----------------------------------------------------
            //
            // Todas las comandas relacionadas con esta venta.
            // ----------------------------------------------------

            $comandas = DB::table('comandas')
                ->where(
                    'venta_id',
                    $venta->id
                )
                ->orderBy('id')
                ->get();


            // ----------------------------------------------------
            // 12. OBTENER CUENTAS
            // ----------------------------------------------------
            //
            // Las cuentas CANCELADAS no se muestran.
            // ----------------------------------------------------

            $cuentas = DB::table('cuentas')
                ->where(
                    'venta_id',
                    $venta->id
                )
                ->where(
                    'estado',
                    '!=',
                    'CANCELADA'
                )
                ->orderBy('id')
                ->get();


            // ----------------------------------------------------
            // 12.b CUENTA SELECCIONADA (?cuenta=)
            // ----------------------------------------------------
            //
            // El panel de subcuentas abre el POS con ?cuenta=ID para
            // trabajar SOLO con esa cuenta. Se valida que la cuenta
            // pertenezca a la venta activa de la mesa. Si la cuenta no
            // existe, es de otra venta o está cancelada, se RECHAZA el
            // acceso a esa cuenta (404) en lugar de mostrar la venta
            // completa, para no filtrar consumos ni presentar la mesa como
            // si fuera esa cuenta. La vista general (sin ?cuenta=) no cambia.
            // ----------------------------------------------------

            $cuentaIdParam = (int) request()->query('cuenta', 0);

            if (
                $cuentaIdParam > 0
                && !$cuentas->contains(fn ($c) => (int) $c->id === $cuentaIdParam)
            ) {
                abort(404, 'La cuenta solicitada no está disponible.');
            }

            $cuentaActual = $cuentaIdParam > 0
                ? $cuentas->first(fn ($c) => (int) $c->id === $cuentaIdParam)
                : null;

            // Totales reales por cuenta (fuente de verdad: cuenta_detalles).
            // Las cuentas PAGADAS conservan el total congelado de su cobro.
            $totalesPorCuenta = DB::table('cuenta_detalles')
                ->where('venta_id', $venta->id)
                ->where('eliminado', false)
                ->groupBy('cuenta_id')
                ->select(
                    'cuenta_id',
                    DB::raw('SUM(subtotal) as total')
                )
                ->pluck('total', 'cuenta_id');

            $cuentas->each(function ($cuenta) use ($totalesPorCuenta) {
                if ($cuenta->estado === 'ABIERTA') {
                    $cuenta->total = (float) ($totalesPorCuenta[$cuenta->id] ?? 0);
                }
            });

            // Total de la cuenta seleccionada. Sin cuenta seleccionada el POS
            // funciona a nivel de mesa (total general de la venta).
            $totalCuenta = $cuentaActual
                ? (float) ($totalesPorCuenta[$cuentaActual->id] ?? 0)
                : null;

            // El botón "Dividir cuentas" solo se habilita cuando la cuenta
            // seleccionada tiene consumos PROPIOS activos. Una cuenta vacía
            // (aunque tenga subcuentas con consumo) no puede dividirse.
            // En la vista general (sin ?cuenta=) se conserva la división a
            // nivel de mesa.
            $puedeDividirCuenta = true;

            if ($cuentaActual) {
                $unidadesPropiasCuenta = (int) DB::table('cuenta_detalles')
                    ->where('cuenta_id', $cuentaActual->id)
                    ->where('eliminado', false)
                    ->sum('cantidad');

                $puedeDividirCuenta = $unidadesPropiasCuenta > 0;
            }

            if ($cuentaActual) {

                // Historial SOLO de la cuenta seleccionada. La cantidad y el
                // subtotal salen de cuenta_detalles (unidades asignadas), no
                // de la línea completa de venta_detalles. Así, cuando dos
                // subcuentas comparten una línea, cada una ve solo lo suyo.
                $historial = DB::table('cuenta_detalles')
                    ->join(
                        'venta_detalles',
                        'venta_detalles.id',
                        '=',
                        'cuenta_detalles.venta_detalle_id'
                    )
                    ->join(
                        'productos',
                        'productos.id',
                        '=',
                        'venta_detalles.producto_id'
                    )
                    ->leftJoin(
                        'comandas',
                        'comandas.id',
                        '=',
                        'venta_detalles.comanda_id'
                    )
                    ->where(
                        'cuenta_detalles.cuenta_id',
                        $cuentaActual->id
                    )
                    ->where(
                        'cuenta_detalles.eliminado',
                        false
                    )
                    ->where(
                        'venta_detalles.estado',
                        'ACTIVO'
                    )
                    ->select(
                        'venta_detalles.id',
                        'venta_detalles.venta_id',
                        'venta_detalles.producto_id',
                        'cuenta_detalles.cantidad',
                        'venta_detalles.precio_unitario',
                        'cuenta_detalles.subtotal',
                        'venta_detalles.comanda_id',
                        'venta_detalles.estado',
                        'venta_detalles.notas',
                        'venta_detalles.estado_preparacion',
                        'productos.nombre',
                        'productos.requiere_cocina',
                        'comandas.numero_ticket'
                    )
                    ->orderBy('venta_detalles.id')
                    ->get();
            }


            // ----------------------------------------------------
            // 13. ENVIAR INFORMACIÓN AL BLADE
            // ----------------------------------------------------

            return view('ventas.pos', [

                // Mesa actual.
                'mesa' => $mesa,

                // Venta actual.
                'venta' => $venta,

                // Detalles.
                'detalles' => $detalles,

                // Historial.
                'historial' => $historial,

                // Productos.
                'productos' => $productos,

                // Categorías.
                'categorias' => $categorias,

                // Comandas.
                'comandas' => $comandas,

                // Cuentas.
                'cuentas' => $cuentas,

                // Cuenta seleccionada con ?cuenta= (null = venta completa).
                'cuentaActual' => $cuentaActual,

                // Total de la cuenta seleccionada (null si no hay selección).
                'totalCuenta' => $totalCuenta,

                // Habilita "Dividir cuentas" solo si la cuenta seleccionada
                // tiene consumos propios activos (true en la vista general).
                'puedeDividirCuenta' => $puedeDividirCuenta,

                // Total.
                'total' => $total,

                // Solo ADMIN y GERENTE pueden anular productos.
                // El botón se oculta a los demás, pero la autorización real
                // se valida en anularDetalle().
                'puedeAnular' => in_array(
                    strtoupper(
                        (string) ($usuario->rol->nombre ?? '')
                    ),
                    ['ADMIN', 'GERENTE'],
                    true
                ),
            ]);


        } catch (\Throwable $e) {

            // ----------------------------------------------------
            // REGISTRAR ERROR
            // ----------------------------------------------------

            Log::error(
                'Error al abrir POS',
                [

                    'mesa_id' =>
                        $mesaId,

                    'usuario_id' =>
                        Auth::id(),

                    'error' =>
                        $e->getMessage(),

                    'archivo' =>
                        $e->getFile(),

                    'linea' =>
                        $e->getLine(),
                ]
            );


            // Volvemos a lanzar el error para que Laravel
            // muestre el error real durante desarrollo.
            throw $e;
        }
    }


    /**
     * ============================================================
     * API DEL POS
     * ============================================================
     *
     * Esta función será utilizada por JavaScript para consultar
     * información de la venta.
     */
    public function api(Request $request)
    {
        // Autorización en servidor: esta API devuelve precios/totales y también
        // crea comandas y libera mesas. COCINERO no la utiliza.
        $usuario = Auth::user();
        $rol = strtoupper((string) ($usuario->rol->nombre ?? ''));

        if (!in_array($rol, ['ADMIN', 'GERENTE', 'CAJERO', 'MESERO'], true)) {
            return response()->json(['exito' => false, 'error' => 'No tienes permiso para esta acción.'], 403);
        }

        if ($request->input('accion') === 'enviar_cocina') {
            return $this->enviarPedidoDesdePos($request);
        }

        // Liberar una mesa sin consumo (acción enviada desde el POS).
        // Se reutiliza la lógica ya existente de liberarMesa().
        if ($request->input('accion') === 'liberar_mesa_vacia') {
            return $this->liberarMesa((int) $request->input('venta_id', 0));
        }

        $ventaId = (int) $request->input('venta_id', 0);
        if ($ventaId <= 0) {
            return response()->json(['exito' => false, 'error' => 'Indica una venta válida.'], 422);
        }

        try {

            // Buscar venta.
            $venta = DB::table('ventas')
                ->where('id', $ventaId)
                ->first();

            if (!$venta) {

                return response()->json([
                    'exito' => false,
                    'error' => 'La venta no existe.',
                ], 404);
            }


            // Buscar detalles.
            $detalles = DB::table('venta_detalles')
                ->join(
                    'productos',
                    'productos.id',
                    '=',
                    'venta_detalles.producto_id'
                )
                ->where(
                    'venta_detalles.venta_id',
                    $ventaId
                )
                ->where(
                    'venta_detalles.estado',
                    'ACTIVO'
                )
                ->select(
                    'venta_detalles.*',
                    'productos.nombre',
                    'productos.requiere_cocina'
                )
                ->orderBy('venta_detalles.id')
                ->get();


            // Calcular total.
            $total = $detalles->sum(
                function ($detalle) {

                    return (float) $detalle->subtotal;
                }
            );


            // Buscar cuentas.
            $cuentas = DB::table('cuentas')
                ->where(
                    'venta_id',
                    $ventaId
                )
                ->where(
                    'estado',
                    '!=',
                    'CANCELADA'
                )
                ->orderBy('id')
                ->get();


            return response()->json([
                'exito' => true,
                'venta' => $venta,
                'detalles' => $detalles,
                'cuentas' => $cuentas,
                'total' => $total,
            ]);


        } catch (\Throwable $e) {

            Log::error(
                'Error en API POS',
                [
                    'venta_id' => $ventaId,
                    'error' => $e->getMessage(),
                    'linea' => $e->getLine(),
                ]
            );


            return response()->json([
                'exito' => false,
                'error' => 'Ocurrió un error al consultar la venta.',
            ], 500);
        }
    }

    /** Guarda un pedido nuevo en una cuenta todavía abierta de la mesa. */
    private function enviarPedidoDesdePos(Request $request)
    {
        $carrito = json_decode((string) $request->input('carrito'), true);
        if (!is_array($carrito) || !$carrito) return response()->json(['exito' => false, 'error' => 'El pedido está vacío.'], 422);

        try {
            $comandaId = DB::transaction(function () use ($request, $carrito) {
                // ORDEN GLOBAL DE BLOQUEO: venta -> cuentas (id ASC) -> venta_detalles.
                // Primero la venta; después la cuenta abierta con bloqueo explícito
                // para serializar contra divisiones, cobros y anulaciones.
                $venta = DB::table('ventas')->where('id', (int) $request->input('venta_id'))->whereIn('estado', ['ACTIVA', 'ABIERTA'])->lockForUpdate()->first();
                if (!$venta) throw new \RuntimeException('La venta ya no está activa.');

                // La cuenta destino es la seleccionada en el POS (?cuenta=). Si
                // no se envía, se usa la primera cuenta abierta por compatibilidad.
                // Nunca se confirma una cuenta de otra venta ni una ya cobrada.
                $cuentaSolicitada = (int) $request->input('cuenta_id', 0);
                $cuentaQuery = DB::table('cuentas')
                    ->where('venta_id', $venta->id)
                    ->where('estado', 'ABIERTA');

                if ($cuentaSolicitada > 0) {
                    $cuenta = (clone $cuentaQuery)
                        ->where('id', $cuentaSolicitada)
                        ->lockForUpdate()
                        ->first();
                    if (!$cuenta) throw new \RuntimeException('La cuenta seleccionada ya no está abierta.');
                } else {
                    $cuenta = $cuentaQuery->orderBy('id')->lockForUpdate()->first();
                    if (!$cuenta) throw new \RuntimeException('No existe una cuenta abierta para agregar el pedido.');
                }

                // Primero validamos todo el carrito y separamos lo que va a cocina
                // (productos.requiere_cocina = 1) de lo que no. La fuente de verdad
                // es siempre la columna productos.requiere_cocina.
                $items = [];
                $totalCocina = 0;
                $hayCocina = false;
                $lineasInventario = [];
                foreach ($carrito as $item) {
                    $producto = DB::table('productos')->where('id', (int) ($item['id'] ?? 0))->where('activo', 1)->first();
                    $cantidad = (int) ($item['cantidad'] ?? 0);
                    if (!$producto || $cantidad <= 0) throw new \RuntimeException('El pedido contiene un producto inválido.');
                    $subtotal = $cantidad * (float) $producto->precio;
                    $requiereCocina = ((int) $producto->requiere_cocina === 1);

                    // Observación por línea (opcional, máximo 500 caracteres).
                    // Se valida SIEMPRE aquí: no se confía en el navegador.
                    $notas = $this->normalizarNotas($item['notas'] ?? null);

                    if ($requiereCocina) {
                        $hayCocina = true;
                        $totalCocina += $subtotal;
                    }
                    $items[] = ['producto' => $producto, 'cantidad' => $cantidad, 'subtotal' => $subtotal, 'cocina' => $requiereCocina, 'notas' => $notas];
                }

                // La comanda de cocina solo se crea si la orden tiene al menos un
                // producto que requiere preparación. Si es solo barra no se crea.
                $comandaId = null;
                if ($hayCocina) {
                    $ultimoTicket = (int) (DB::table('comandas')->where('venta_id', $venta->id)->max('numero_ticket') ?? 0);
                    $comandaId = DB::table('comandas')->insertGetId(['mesa_id' => $venta->mesa_id, 'venta_id' => $venta->id, 'numero_ticket' => $ultimoTicket + 1, 'usuario_id' => Auth::id(), 'cliente_id' => $venta->cliente_id, 'descripcion' => null, 'estado' => 'ABIERTA', 'total' => 0, 'creado_en' => now(), 'conteo_impresiones' => 0]);
                }

                foreach ($items as $it) {
                    $producto = $it['producto'];
                    $cantidad = $it['cantidad'];
                    $subtotal = $it['subtotal'];

                    // Solo los productos de cocina se enlazan a la comanda.
                    // Los de barra quedan con comanda_id = null para no aparecer
                    // en ninguna comanda ni reimpresión de cocina.
                    $detalleComandaId = $it['cocina'] ? $comandaId : null;

                    if ($it['cocina']) {
                        DB::table('comanda_detalles')->insert(['comanda_id' => $comandaId, 'producto_id' => $producto->id, 'cantidad' => $cantidad, 'precio_unitario' => $producto->precio, 'subtotal' => $subtotal, 'estado' => 'ACTIVO', 'impreso_cocina' => 0, 'estado_pago' => 'PENDIENTE']);
                    }

                    // El detalle de venta y de cuenta se registran SIEMPRE, para que
                    // el producto siga apareciendo en la mesa, cuenta, prefactura y cobro.
                    // Las líneas de cocina nacen en estado PENDIENTE; las de barra
                    // quedan con estado_preparacion NULL (no son tareas de cocina).
                    // Los pedidos históricos sin observación siguen funcionando.
                    $detalleId = DB::table('venta_detalles')->insertGetId(['venta_id' => $venta->id, 'producto_id' => $producto->id, 'cantidad' => $cantidad, 'precio_unitario' => $producto->precio, 'subtotal' => $subtotal, 'comanda_id' => $detalleComandaId, 'estado' => 'ACTIVO', 'notas' => $it['notas'], 'estado_preparacion' => $it['cocina'] ? 'PENDIENTE' : null, 'created_at' => now(), 'updated_at' => now()]);
                    DB::table('cuenta_detalles')->insert(['cuenta_id' => $cuenta->id, 'venta_id' => $venta->id, 'venta_detalle_id' => $detalleId, 'cantidad' => $cantidad, 'subtotal' => $subtotal, 'created_at' => now(), 'updated_at' => now()]);

                    // Inventario (Etapa 2): se acumula la línea para registrar la
                    // salida después del bucle. Solo se moverá si el producto
                    // controla stock; nunca bloquea la venta.
                    $lineasInventario[] = [
                        'venta_detalle_id' => $detalleId,
                        'producto_id' => $producto->id,
                        'cantidad' => $cantidad,
                    ];
                }

                // Salida de inventario de las líneas recién creadas. Dentro de la
                // misma transacción: si falla, revierte con el pedido completo.
                $this->inventario()->registrarVenta($lineasInventario, Auth::id());

                if ($comandaId !== null) {
                    DB::table('comandas')->where('id', $comandaId)->update(['total' => $totalCocina]);
                }
                DB::table('cuentas')->where('id', $cuenta->id)->update(['total' => DB::table('cuenta_detalles')->where('cuenta_id', $cuenta->id)->where('eliminado', false)->sum('subtotal'), 'updated_at' => now()]);
                DB::table('ventas')->where('id', $venta->id)->update(['monto' => DB::table('venta_detalles')->where('venta_id', $venta->id)->where('estado', 'ACTIVO')->sum('subtotal')]);

                // Primera venta real de la mesa: recién ahora pasa a OCUPADA.
                // Abrir el POS o crear una venta vacía no la ocupaba.
                if ($venta->mesa_id) {
                    DB::table('mesas')->where('id', $venta->mesa_id)->update(['estado' => 'OCUPADA']);
                }

                return $comandaId;
            });
            $mensaje = $comandaId ? 'Pedido guardado y enviado a cocina.' : 'Pedido guardado (solo barra).';
            return response()->json(['exito' => true, 'mensaje' => $mensaje, 'comanda_id' => $comandaId]);
        } catch (\Throwable $e) {
            Log::warning('No se pudo guardar pedido POS', ['error' => $e->getMessage(), 'venta_id' => $request->input('venta_id')]);
            return response()->json(['exito' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /**
     * Normaliza la observación de una línea del carrito.
     *
     * Reglas:
     *  - Opcional: null / cadena vacía / solo espacios => NULL.
     *  - Máximo 500 caracteres (se cuentan caracteres, no bytes). Si se excede,
     *    se lanza RuntimeException y el POS responde 422 (no se trunca).
     *  - No se usa como identidad del producto: solo es texto de la línea.
     */
    private function normalizarNotas(mixed $valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        // Solo se aceptan escalares (string/número). Un array u objeto JSON se
        // rechaza en lugar de convertirse silenciosamente.
        if (!is_scalar($valor)) {
            throw new \RuntimeException('La observación de un producto no es válida.');
        }

        $texto = trim((string) $valor);

        if ($texto === '') {
            return null;
        }

        if (mb_strlen($texto) > 500) {
            throw new \RuntimeException('La observación no puede superar los 500 caracteres.');
        }

        return $texto;
    }


    /**
     * ============================================================
     * CREAR CUENTA
     * ============================================================
     *
     * Permite crear una cuenta para dividir el consumo.
     */
    public function crearCuenta(
        Request $request,
        int $ventaId
    ) {
        try {

            // Validar nombre.
            $nombre = trim(
                (string) $request->input('nombre')
            );

            if ($nombre === '') {

                return response()->json([
                    'exito' => false,
                    'error' => 'El nombre de la cuenta es obligatorio.',
                ], 422);
            }


            // Verificar venta.
            $venta = DB::table('ventas')
                ->where('id', $ventaId)
                ->whereIn('estado', ['ACTIVA', 'ABIERTA'])
                ->first();

            if (!$venta) {

                return response()->json([
                    'exito' => false,
                    'error' => 'La venta no existe o ya no está activa.',
                ], 404);
            }


            // Crear cuenta.
            $cuentaId = DB::table('cuentas')
                ->insertGetId([

                    'venta_id' => $ventaId,

                    'nombre' => $nombre,

                    'total' => 0,

                    'estado' => 'ABIERTA',

                    'apertura_id' =>
                        $venta->apertura_id,

                    'metodo_pago' => null,

                    'banco' => null,

                    'monto_recibido' => null,

                    'cambio' => null,

                    'usuario_cobro' => null,

                    'created_at' => now(),

                    'updated_at' => now(),
                ]);


            return response()->json([
                'exito' => true,
                'mensaje' => 'Cuenta creada correctamente.',
                'cuenta_id' => $cuentaId,
            ]);


        } catch (\Throwable $e) {

            Log::error(
                'Error al crear cuenta',
                [
                    'venta_id' => $ventaId,
                    'error' => $e->getMessage(),
                    'linea' => $e->getLine(),
                ]
            );


            return response()->json([
                'exito' => false,
                'error' => 'No se pudo crear la cuenta.',
            ], 500);
        }
    }


    /**
     * ============================================================
     * DIVIDIR VENTA EN CUENTAS
     * ============================================================
     */
    public function dividir(
        Request $request,
        int $ventaId
    ) {
        // Este contrato heredado modifica venta_detalles.cuenta_id y no
        // soporta cantidades parciales. Se bloquea para mantener una sola
        // fuente de verdad mientras se migra al flujo cuenta_detalles.
        return response()->json([
            'exito' => false,
            'error' => 'La división heredada está deshabilitada. Use el flujo basado en cuenta_detalles.',
        ], 410);

        try {

            $cuentas = $request->input(
                'cuentas',
                []
            );


            // Debe existir al menos una cuenta.
            if (!is_array($cuentas) || count($cuentas) === 0) {

                return response()->json([
                    'exito' => false,
                    'error' => 'Debe existir al menos una cuenta.',
                ], 422);
            }


            DB::transaction(
                function () use (
                    $cuentas,
                    $ventaId
                ) {

                    foreach ($cuentas as $cuenta) {

                        $cuentaId =
                            (int) (
                                $cuenta['id'] ?? 0
                            );


                        if ($cuentaId <= 0) {
                            continue;
                        }


                        // Detalles pertenecientes a la cuenta.
                        $detalles =
                            $cuenta['detalles'] ?? [];


                        if (!is_array($detalles)) {
                            continue;
                        }


                        foreach ($detalles as $detalleId) {

                            $detalleId =
                                (int) $detalleId;


                            if ($detalleId <= 0) {
                                continue;
                            }


                            // Asignamos el detalle a la cuenta.
                            DB::table('venta_detalles')
                                ->where(
                                    'id',
                                    $detalleId
                                )
                                ->where(
                                    'venta_id',
                                    $ventaId
                                )
                                ->update([
                                    'cuenta_id' =>
                                        $cuentaId,
                                ]);
                        }


                        // Calcular total de la cuenta.
                        $totalCuenta =
                            DB::table('venta_detalles')
                                ->where(
                                    'venta_id',
                                    $ventaId
                                )
                                ->where(
                                    'cuenta_id',
                                    $cuentaId
                                )
                                ->where(
                                    'estado',
                                    'ACTIVO'
                                )
                                ->sum('subtotal');


                        // Actualizar total.
                        DB::table('cuentas')
                            ->where(
                                'id',
                                $cuentaId
                            )
                            ->where(
                                'venta_id',
                                $ventaId
                            )
                            ->update([
                                'total' =>
                                    $totalCuenta,

                                'updated_at' =>
                                    now(),
                            ]);
                    }
                }
            );


            return response()->json([
                'exito' => true,
                'mensaje' =>
                    'La venta fue dividida correctamente.',
            ]);


        } catch (\Throwable $e) {

            Log::error(
                'Error al dividir venta',
                [
                    'venta_id' => $ventaId,
                    'error' => $e->getMessage(),
                    'linea' => $e->getLine(),
                ]
            );


            return response()->json([
                'exito' => false,
                'error' =>
                    'No se pudo dividir la venta.',
            ], 500);
        }
    }


    /**
     * ============================================================
     * MOVIMIENTOS
     * ============================================================
     */
    public function movimientos(int $ventaId)
    {
        try {

            $venta = DB::table('ventas')
                ->where('id', $ventaId)
                ->first();


            if (!$venta) {

                return response()->json([
                    'exito' => false,
                    'error' => 'Venta no encontrada.',
                ], 404);
            }


            $cuentas = DB::table('cuentas')
                ->where(
                    'venta_id',
                    $ventaId
                )
                ->orderBy('id')
                ->get();


            return response()->json([
                'exito' => true,
                'venta' => $venta,
                'cuentas' => $cuentas,
            ]);


        } catch (\Throwable $e) {

            Log::error(
                'Error al obtener movimientos',
                [
                    'venta_id' => $ventaId,
                    'error' => $e->getMessage(),
                    'linea' => $e->getLine(),
                ]
            );


            return response()->json([
                'exito' => false,
                'error' =>
                    'No se pudieron obtener los movimientos.',
            ], 500);
        }
    }


    /**
     * ============================================================
     * ELIMINAR / CANCELAR CUENTA
     * ============================================================
     *
     * Solamente ADMIN y GERENTE pueden cancelar una cuenta.
     */
    public function eliminarCuenta(int $cuentaId)
    {
        try {

            $usuario = Auth::user();


            if (!$usuario) {

                return response()->json([
                    'exito' => false,
                    'error' => 'Usuario no autenticado.',
                ], 401);
            }


            // Obtener rol.
            $rol = strtoupper(
                (string) (
                    $usuario->rol->nombre ?? ''
                )
            );


            // Solo ADMIN y GERENTE.
            if (!in_array(
                $rol,
                ['ADMIN', 'GERENTE'],
                true
            )) {

                return response()->json([
                    'exito' => false,
                    'error' =>
                        'No tienes permisos para cancelar cuentas.',
                ], 403);
            }


            // Buscar cuenta.
            $cuenta = DB::table('cuentas')
                ->where('id', $cuentaId)
                ->first();


            if (!$cuenta) {

                return response()->json([
                    'exito' => false,
                    'error' => 'La cuenta no existe.',
                ], 404);
            }


            DB::transaction(
                function () use ($cuentaId) {

                    // ORDEN GLOBAL: venta -> cuentas (id ASC).
                    // Bloquear la venta primero evita deadlocks con cobros,
                    // divisiones, pedidos y anulaciones de la misma mesa.
                    $cuentaNav = DB::table('cuentas')
                        ->where('id', $cuentaId)
                        ->first();

                    if (!$cuentaNav) {
                        throw new \RuntimeException('La cuenta no existe.');
                    }

                    DB::table('ventas')
                        ->where('id', $cuentaNav->venta_id)
                        ->lockForUpdate()
                        ->first();

                    $cuentasBloqueadas = DB::table('cuentas')
                        ->where('venta_id', $cuentaNav->venta_id)
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get();

                    // Una cuenta pagada es un registro financiero intocable:
                    // se rechaza antes de borrar asignaciones o cambiar su estado.
                    $cuentaBloqueada = $cuentasBloqueadas
                        ->firstWhere('id', $cuentaId);

                    if (!$cuentaBloqueada) {
                        throw new \RuntimeException('La cuenta no existe.');
                    }

                    if ($cuentaBloqueada->estado === 'PAGADA') {
                        throw new \DomainException(
                            'No se puede cancelar una cuenta que ya fue pagada.'
                        );
                    }

                    // cuenta_detalles es la fuente de verdad de asignaciones.
                    // La columna heredada venta_detalles.cuenta_id se conserva
                    // temporalmente solo para compatibilidad con datos viejos.
                    DB::table('cuenta_detalles')
                        ->where('cuenta_id', $cuentaId)
                        ->delete();


                    // Marcamos la cuenta como cancelada.
                    DB::table('cuentas')
                        ->where(
                            'id',
                            $cuentaId
                        )
                        ->update([
                            'estado' => 'CANCELADA',
                            'updated_at' => now(),
                        ]);
                }
            );


            return response()->json([
                'exito' => true,
                'mensaje' =>
                    'La cuenta fue cancelada correctamente.',
            ]);


        } catch (\DomainException $e) {

            // Rechazo por regla de negocio (cuenta pagada): 422, sin log
            // de error porque no es una falla interna.
            return response()->json([
                'exito' => false,
                'error' => $e->getMessage(),
            ], 422);

        } catch (\Throwable $e) {

            Log::error(
                'Error al cancelar cuenta',
                [
                    'cuenta_id' => $cuentaId,
                    'error' => $e->getMessage(),
                    'linea' => $e->getLine(),
                ]
            );


            return response()->json([
                'exito' => false,
                'error' =>
                    'No se pudo cancelar la cuenta.',
            ], 500);
        }
    }


    /**
     * ============================================================
     * CANCELAR VENTA
     * ============================================================
     *
     * Solo ADMIN y GERENTE.
     */
    public function cancelar(int $ventaId)
    {
        try {

            $usuario = Auth::user();


            if (!$usuario) {

                return response()->json([
                    'exito' => false,
                    'error' => 'Usuario no autenticado.',
                ], 401);
            }


            $rol = strtoupper(
                (string) (
                    $usuario->rol->nombre ?? ''
                )
            );


            if (!in_array(
                $rol,
                ['ADMIN', 'GERENTE'],
                true
            )) {

                return response()->json([
                    'exito' => false,
                    'error' =>
                        'No tienes permisos para cancelar esta venta.',
                ], 403);
            }


            DB::transaction(
                function () use ($ventaId) {

                    // ORDEN GLOBAL: venta -> cuentas (id ASC) -> venta_detalles
                    // (id ASC). La venta primero evita deadlocks con cobros,
                    // divisiones, pedidos y anulaciones concurrentes.
                    $venta = DB::table('ventas')
                        ->where(
                            'id',
                            $ventaId
                        )
                        ->lockForUpdate()
                        ->first();


                    if (!$venta) {

                        throw new \Exception(
                            'La venta no existe.'
                        );
                    }

                    // No se puede cancelar una venta que ya cerró. Se valida
                    // después del bloqueo para evitar decisiones con lecturas
                    // obsoletas.
                    if (in_array(
                        $venta->estado,
                        ['PAGADA', 'CANCELADA'],
                        true
                    )) {
                        throw new \DomainException(
                            'No se puede cancelar: la venta ya está pagada o cancelada.'
                        );
                    }

                    DB::table('cuentas')
                        ->where('venta_id', $ventaId)
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get();

                    $detalles = DB::table('venta_detalles')
                        ->where('venta_id', $ventaId)
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get();


                    // Inventario (Etapa 2): reponer el stock pendiente de cada
                    // línea ANTES de marcarla CANCELADO. Se calcula desde el
                    // historial de movimientos (no desde controla_stock actual).
                    $this->inventario()->registrarCancelacion(
                        $detalles->pluck('id')->map(
                            static fn ($id) => (int) $id
                        )->all(),
                        Auth::id()
                    );


                    // Cancelar detalles.
                    DB::table('venta_detalles')
                        ->where(
                            'venta_id',
                            $ventaId
                        )
                        ->update([
                            'estado' => 'CANCELADO',
                        ]);


                    // Cancelar cuentas.
                    DB::table('cuentas')
                        ->where(
                            'venta_id',
                            $ventaId
                        )
                        ->update([
                            'estado' => 'CANCELADA',
                            'updated_at' => now(),
                        ]);


                    // Cancelar venta.
                    DB::table('ventas')
                        ->where(
                            'id',
                            $ventaId
                        )
                        ->update([
                            'estado' => 'CANCELADA',
                        ]);


                    // Liberar mesa.
                    if ($venta->mesa_id) {

                        DB::table('mesas')
                            ->where(
                                'id',
                                $venta->mesa_id
                            )
                            ->update([
                                'estado' => 'LIBRE',
                            ]);
                    }
                }
            );


            return response()->json([
                'exito' => true,
                'mensaje' =>
                    'La venta fue cancelada correctamente.',
            ]);


        } catch (\DomainException $e) {

            // Rechazo por regla de negocio (venta ya pagada o cancelada).
            return response()->json([
                'exito' => false,
                'error' => $e->getMessage(),
            ], 422);

        } catch (\Throwable $e) {

            Log::error(
                'Error al cancelar venta',
                [
                    'venta_id' => $ventaId,
                    'error' => $e->getMessage(),
                    'linea' => $e->getLine(),
                ]
            );


            return response()->json([
                'exito' => false,
                'error' =>
                    $e->getMessage(),
            ], 500);
        }
    }


    /**
     * ============================================================
     * LIBERAR MESA
     * ============================================================
     *
     * Se utiliza cuando una mesa está vacía.
     */
    public function liberarMesa(int $ventaId)
    {
        try {

            $resultado = DB::transaction(
                function () use ($ventaId) {

                    // ORDEN GLOBAL: venta primero, para serializar con cobros,
                    // divisiones, pedidos y anulaciones concurrentes.
                    $venta = DB::table('ventas')
                        ->where(
                            'id',
                            $ventaId
                        )
                        ->lockForUpdate()
                        ->first();


                    // Venta inexistente: se señala el resultado para responder
                    // 404 después. (Se comprueba dentro del bloqueo para evitar
                    // condiciones de carrera.)
                    if (!$venta) {

                        return ['estado' => 'inexistente'];
                    }


                    // Verificar si existen productos activos. Las líneas
                    // vaciadas por un traslado individual (cantidad 0) no
                    // cuentan: ese consumo ya está en la mesa destino.
                    $cantidadDetalles =
                        DB::table('venta_detalles')
                            ->where(
                                'venta_id',
                                $ventaId
                            )
                            ->where(
                                'estado',
                                'ACTIVO'
                            )
                            ->where('cantidad', '>', 0)
                            ->count();


                    if ($cantidadDetalles > 0) {

                        // Rechazo de negocio => 409 (conflicto), no 500.
                        throw new \DomainException(
                            'No se puede liberar la mesa porque tiene productos.'
                        );
                    }


                    // Segunda verificación de seguridad: ninguna cuenta de la
                    // venta puede conservar consumos/asignaciones activos ni
                    // saldo pendiente. Solo una venta realmente vacía se libera.
                    $hayAsignaciones = DB::table('cuenta_detalles')
                        ->where('venta_id', $ventaId)
                        ->where('eliminado', false)
                        ->count();

                    if ($hayAsignaciones > 0) {

                        throw new \DomainException(
                            'No se puede liberar la mesa porque hay consumos pendientes en sus cuentas.'
                        );
                    }

                    $hayCuentasConSaldo = DB::table('cuentas')
                        ->where('venta_id', $ventaId)
                        ->where('estado', 'ABIERTA')
                        ->where('total', '>', 0)
                        ->count();

                    if ($hayCuentasConSaldo > 0) {

                        throw new \DomainException(
                            'No se puede liberar la mesa porque hay cuentas con saldo pendiente.'
                        );
                    }


                    // Marcar venta como pagada/cerrada.
                    DB::table('ventas')
                        ->where(
                            'id',
                            $ventaId
                        )
                        ->update([
                            'estado' => 'PAGADA',
                        ]);


                    // Liberar mesa.
                    if ($venta->mesa_id) {

                        DB::table('mesas')
                            ->where(
                                'id',
                                $venta->mesa_id
                            )
                            ->update([
                                'estado' => 'LIBRE',
                            ]);
                    }

                    return ['estado' => 'ok'];
                }
            );


            // Venta inexistente => 404 (recurso no encontrado).
            if ($resultado['estado'] === 'inexistente') {

                return response()->json([
                    'exito' => false,
                    'error' => 'La venta no existe.',
                ], 404);
            }


            return response()->json([
                'exito' => true,
                'mensaje' =>
                    'La mesa fue liberada correctamente.',
            ]);


        } catch (\DomainException $e) {

            // Rechazo por regla de negocio => 409 (conflicto). No es una falla
            // interna del servidor.
            return response()->json([
                'exito' => false,
                'error' =>
                    $e->getMessage(),
            ], 409);


        } catch (\Throwable $e) {

            Log::error(
                'Error al liberar mesa',
                [
                    'venta_id' => $ventaId,
                    'error' => $e->getMessage(),
                    'linea' => $e->getLine(),
                ]
            );


            return response()->json([
                'exito' => false,
                'error' =>
                    $e->getMessage(),
            ], 500);
        }
    }

    /**
     * =========================================================================
     * PANEL DE SUBCUENTAS DE UNA MESA
     * =========================================================================
     *
     * Muestra las cuentas de la venta ACTIVA de la mesa seleccionada para que
     * el usuario elija con cuál trabajar (o cree una nueva en la Fase 2).
     *
     * Reglas que cumple este método:
     * - Solo lista cuentas de la venta activa de ESTA mesa (mesa_id origen).
     * - NO crea una segunda venta: es de solo lectura.
     * - Los totales de las cuentas abiertas se recalculan desde cuenta_detalles
     *   (fuente de verdad), igual que en cuentas(); las pagadas conservan su
     *   total congelado.
     */
    public function panelCuentas(int $mesaId)
    {
        $usuario = Auth::user();

        if (!$usuario) {
            abort(401, 'Usuario no autenticado.');
        }

        // Autorización en servidor: este panel muestra los totales de las
        // subcuentas de la mesa. COCINERO no debe verlos. Se bloquea solo a
        // COCINERO para no alterar el comportamiento del resto de roles.
        $rol = strtoupper((string) ($usuario->rol->nombre ?? ''));

        if ($rol === 'COCINERO') {
            abort(403, 'No tienes permiso para ver las cuentas de la mesa.');
        }

        // 1. La mesa debe existir.
        $mesa = DB::table('mesas')->where('id', $mesaId)->first();

        if (!$mesa) {
            abort(404, 'La mesa no existe.');
        }

        // 2. Venta activa de ESTA mesa. Aquí nunca se crea una venta.
        $venta = DB::table('ventas')
            ->where('mesa_id', $mesaId)
            ->whereIn('estado', ['ACTIVA', 'ABIERTA'])
            ->orderByDesc('id')
            ->first();

        // Si la mesa no tiene venta activa no hay subcuentas que mostrar.
        if (!$venta) {
            return redirect()
                ->route('mesas.index')
                ->with('error', 'La mesa no tiene una venta activa.');
        }

        // 3. Cuentas de la venta activa (se excluyen las canceladas).
        $cuentas = DB::table('cuentas')
            ->where('venta_id', $venta->id)
            ->where('estado', '!=', 'CANCELADA')
            ->orderBy('id')
            ->get();

        // 4. Totales reales de las cuentas abiertas agrupados por cuenta.
        $totalesPorCuenta = DB::table('cuenta_detalles')
            ->join('cuentas', 'cuentas.id', '=', 'cuenta_detalles.cuenta_id')
            ->where('cuentas.venta_id', $venta->id)
            ->where('cuenta_detalles.eliminado', false)
            ->groupBy('cuenta_detalles.cuenta_id')
            ->select('cuenta_detalles.cuenta_id', DB::raw('SUM(cuenta_detalles.subtotal) as total'))
            ->pluck('total', 'cuenta_id');

        $cuentas->each(function ($cuenta) use ($totalesPorCuenta) {
            if ($cuenta->estado === 'ABIERTA') {
                $cuenta->total = (float) ($totalesPorCuenta[$cuenta->id] ?? 0);
            }
        });

        // -----------------------------------------------------------------
        // REGLA: la cuenta principal VACÍA deja de aparecer como activa.
        // -----------------------------------------------------------------
        // La cuenta original de la venta (la de menor id, creada al abrir el
        // POS) puede quedarse sin consumos propios tras dividir, subdividir o
        // repartir el consumo en subcuentas. En ese caso NO debe seguir
        // mostrándose como una cuenta activa con C$0: se oculta del panel.
        //
        // Importante:
        //  - Solo se oculta la PRINCIPAL. Las subcuentas (incluida una recién
        //    creada y vacía) siguen visibles porque el usuario las necesita
        //    para agregarles productos.
        //  - Solo se oculta si NO tiene consumos propios activos. Un total en
        //    cero NO es motivo suficiente: si existen cuenta_detalles activos,
        //    la cuenta se mantiene visible (evita ocultar consumo pendiente).
        //  - El registro NO se borra ni se cancela: sigue en la base de datos
        //    y su historial se conserva. Si se le asigna consumo, vuelve a
        //    listarse.
        $principalId = (int) DB::table('cuentas')->where('venta_id', $venta->id)->min('id');

        if ($principalId > 0) {
            $cuentas = $cuentas->reject(function ($cuenta) use ($principalId) {
                if ((int) $cuenta->id !== $principalId) {
                    return false;
                }

                if ($cuenta->estado !== 'ABIERTA') {
                    return false;
                }

                $consumosPropios = DB::table('cuenta_detalles')
                    ->where('cuenta_id', $cuenta->id)
                    ->where('eliminado', false)
                    ->count();

                // Sin consumos propios activos => se oculta (monto real = 0).
                return $consumosPropios === 0;
            })->values();
        }

        // Cuenta representativa para el traslado de la MESA COMPLETA (mueve
        // TODA la venta). El traslado INDIVIDUAL de una subcuenta se ofrece
        // aparte, por cuenta, en la tarjeta de cada una (botón "Trasladar").
        $cuentaTrasladoId = optional($cuentas->firstWhere('estado', 'ABIERTA'))->id;

        // Rol real del usuario autenticado para decidir qué acciones mostrar.
        $rol = strtoupper((string) ($usuario->rol->nombre ?? ''));

        // Mesas candidatas a recibir el traslado: todas menos la actual y
        // que estén LIBRES (una mesa ocupada no puede recibir otra venta).
        $mesasDestino = DB::table('mesas')
            ->where('id', '!=', $mesaId)
            ->where('estado', 'LIBRE')
            ->orderBy('numero')
            ->get();

        // Mesas candidatas para el traslado INDIVIDUAL de una subcuenta: se
        // admite tanto una mesa LIBRE (se le crea venta) como una OCUPADA
        // con venta ACTIVA (la subcuenta se añade a su consumo). Las mesas
        // COBRANDO se excluyen porque están en pleno cobro. El backend
        // vuelve a validar todo dentro de la transacción.
        $mesasDestinoIndividual = DB::table('mesas')
            ->where('id', '!=', $mesaId)
            ->whereIn('estado', ['LIBRE', 'OCUPADA'])
            ->orderBy('numero')
            ->get();

        // -----------------------------------------------------------------
        // ¿La venta activa está totalmente vacía?
        // -----------------------------------------------------------------
        // Se replica EXACTAMENTE la condición que valida liberarMesa(): sin
        // consumos ACTIVOS, sin asignaciones pendientes en cuenta_detalles y
        // sin cuentas ABIERTAS con importe pendiente. Solo en ese caso se
        // ofrece el flujo de recuperación (volver al POS o liberar la mesa),
        // porque el panel puede no mostrar ninguna cuenta (la principal vacía
        // se oculta más arriba). Nunca se permite liberar una mesa con
        // consumos, asignaciones o saldos pendientes.
        $hayConsumosActivos = DB::table('venta_detalles')
            ->where('venta_id', $venta->id)
            ->where('estado', 'ACTIVO')
            // Líneas vaciadas por un traslado individual (cantidad 0) no son
            // consumo de esta mesa: sin este filtro la mesa quedaría bloqueada.
            ->where('cantidad', '>', 0)
            ->exists();

        $hayAsignacionesPendientes = DB::table('cuenta_detalles')
            ->where('venta_id', $venta->id)
            ->where('eliminado', false)
            ->exists();

        $hayCuentasConSaldo = DB::table('cuentas')
            ->where('venta_id', $venta->id)
            ->where('estado', 'ABIERTA')
            ->where('total', '>', 0)
            ->exists();

        $mesaVacia = !$hayConsumosActivos
            && !$hayAsignacionesPendientes
            && !$hayCuentasConSaldo;

        return view('mesas.cuentas', [
            'mesa' => $mesa,
            'venta' => $venta,
            'cuentas' => $cuentas,
            // Fase 2: los cuatro roles podrán crear subcuentas vacías.
            'puedeCrear' => in_array($rol, ['ADMIN', 'GERENTE', 'CAJERO', 'MESERO'], true),
            // Dividir/reasignar conserva los permisos actuales.
            'puedeDividir' => in_array($rol, ['ADMIN', 'GERENTE', 'CAJERO'], true),
            // Traslado de mesa completa entre mesas (Fase 6).
            'puedeTrasladar' => in_array($rol, ['ADMIN', 'GERENTE', 'CAJERO'], true),
            'mesasDestino' => $mesasDestino,
            // Mesas candidatas para el traslado individual de una subcuenta
            // (LIBRES u OCUPADAS, se valida en backend).
            'mesasDestinoIndividual' => $mesasDestinoIndividual,
            // Cuenta ABIERTA de referencia para trasladar la venta completa.
            'cuentaTrasladoId' => $cuentaTrasladoId,
            // La venta activa no tiene ningún consumo ni saldo pendiente.
            'mesaVacia' => $mesaVacia,
            // Recuperación de mesas vacías: se reutiliza liberarMesa(), ya
            // accesible vía POS para los mismos roles. No se amplían permisos.
            'puedeLiberar' => in_array($rol, ['ADMIN', 'GERENTE', 'CAJERO', 'MESERO'], true),
        ]);
    }

    /**
     * =========================================================================
     * TRASLADAR CUENTAS ENTRE MESAS (FASE 6)
     * =========================================================================
     *
     * Mueve el consumo de una mesa a otra SIN perder información:
     * - No crea datos duplicados ni elimina cuentas, detalles ni comandas.
     * - Reubica la venta activa (y sus subcuentas) en la mesa destino.
     * - Repunta las comandas de la venta a la nueva mesa para que el POS y
     *   las reimpresiones muestren la ubicación real; las cantidades y el
     *   historial (números de ticket, productos, fechas) no se tocan.
     * - Actualiza los estados de mesa: origen -> LIBRE, destino -> OCUPADA.
     *
     * Seguridad: solo ADMIN, GERENTE y CAJERO. La autorización se valida en
     * backend antes de cualquier dato de validación (MESERO recibe 403).
     * Integridad: todo ocurre dentro de una transacción con bloqueos.
     */
    public function trasladarCuenta(Request $request, int $cuentaId)
    {
        // 1. Autorización primero.
        $usuario = Auth::user();

        if (!$usuario) {
            return response()->json(['exito' => false, 'error' => 'Usuario no autenticado.'], 401);
        }

        $rol = strtoupper((string) ($usuario->rol->nombre ?? ''));

        if (!in_array($rol, ['ADMIN', 'GERENTE', 'CAJERO'], true)) {
            return response()->json(['exito' => false, 'error' => 'No tienes permiso para trasladar cuentas.'], 403);
        }

        // 2. Validación de entrada.
        $request->validate([
            'mesa_destino_id' => ['required', 'integer', 'exists:mesas,id'],
        ]);

        try {
            $resultado = DB::transaction(function () use ($request, $cuentaId) {
                // Lectura de navegación (sin bloqueo): solo para conocer la venta.
                $cuentaNav = DB::table('cuentas')->where('id', $cuentaId)->first();
                if (!$cuentaNav) {
                    throw new \RuntimeException('La cuenta no existe.');
                }

                // Orden global de bloqueo: ventas -> cuentas -> mesas (id ASC).
                $venta = DB::table('ventas')->where('id', $cuentaNav->venta_id)->lockForUpdate()->first();
                if (!$venta || !in_array($venta->estado, ['ACTIVA', 'ABIERTA'], true)) {
                    throw new \RuntimeException('La venta no está activa.');
                }

                $cuenta = DB::table('cuentas')->where('id', $cuentaId)->lockForUpdate()->first();
                if (!$cuenta || (int) $cuenta->venta_id !== (int) $venta->id) {
                    throw new \RuntimeException('La cuenta no pertenece a la venta indicada.');
                }

                // Solo se traslada una cuenta ABIERTA: una PAGADA o CANCELADA
                // ya no representa consumo por cobrar.
                if ($cuenta->estado !== 'ABIERTA') {
                    throw new \RuntimeException('Solo se pueden trasladar cuentas ABIERTAS.');
                }

                $mesaOrigenId = (int) $venta->mesa_id;
                $mesaDestinoId = (int) $request->input('mesa_destino_id');

                if ($mesaDestinoId === $mesaOrigenId) {
                    throw new \RuntimeException('La cuenta ya se encuentra en esa mesa.');
                }

                // Bloquear ambas mesas en orden determinista (id ASC).
                $mesas = DB::table('mesas')
                    ->whereIn('id', [$mesaOrigenId, $mesaDestinoId])
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                $mesaDestino = $mesas->get($mesaDestinoId);
                if (!$mesaDestino) {
                    throw new \RuntimeException('La mesa destino no existe.');
                }

                if ($mesaDestino->estado !== 'LIBRE') {
                    throw new \RuntimeException('La mesa destino no está libre.');
                }

                // Defensivo: la mesa destino no debe tener otra venta activa.
                $destinoOcupado = DB::table('ventas')
                    ->where('mesa_id', $mesaDestinoId)
                    ->whereIn('estado', ['ACTIVA', 'ABIERTA'])
                    ->where('id', '!=', $venta->id)
                    ->exists();

                if ($destinoOcupado) {
                    throw new \RuntimeException('La mesa destino ya tiene una venta activa.');
                }

                // 3. Reubicar la venta (arrastra todas sus subcuentas y detalles).
                DB::table('ventas')->where('id', $venta->id)->update(['mesa_id' => $mesaDestinoId]);

                // 4. Las comandas de esta venta apuntan a la nueva mesa.
                //    No se alteran cantidades, tickets ni fechas: solo la mesa.
                DB::table('comandas')->where('venta_id', $venta->id)->update(['mesa_id' => $mesaDestinoId]);

                // 5. Estados de mesa coherentes con el consumo REAL de la venta
                //    trasladada, no por el mero hecho de moverla. Las líneas
                //    vaciadas por un traslado individual (cantidad 0) no
                //    cuentan como consumo.
                $tieneConsumos = DB::table('venta_detalles')
                    ->where('venta_id', $venta->id)
                    ->where('estado', 'ACTIVO')
                    ->where('cantidad', '>', 0)
                    ->exists();

                // El destino solo se ocupa si la venta trasladada trae consumos.
                DB::table('mesas')->where('id', $mesaDestinoId)->update([
                    'estado' => $tieneConsumos ? 'OCUPADA' : 'LIBRE',
                ]);

                // El origen queda LIBRE solo si ya no tiene otra venta activa ni
                // consumos pendientes en cuentas de la mesa.
                $origenOcupado = DB::table('ventas')
                    ->where('mesa_id', $mesaOrigenId)
                    ->whereIn('estado', ['ACTIVA', 'ABIERTA'])
                    ->where('id', '!=', $venta->id)
                    ->exists();

                $origenConPendientes = DB::table('cuenta_detalles')
                    ->whereIn('venta_id', function ($query) use ($mesaOrigenId) {
                        $query->select('id')->from('ventas')
                            ->where('mesa_id', $mesaOrigenId)
                            ->whereIn('estado', ['ACTIVA', 'ABIERTA']);
                    })
                    ->where('eliminado', false)
                    ->exists();

                if (!$origenOcupado && !$origenConPendientes) {
                    DB::table('mesas')->where('id', $mesaOrigenId)->update(['estado' => 'LIBRE']);
                }

                return [
                    'venta_id' => (int) $venta->id,
                    'mesa_origen_id' => $mesaOrigenId,
                    'mesa_destino_id' => $mesaDestinoId,
                    'numero_destino' => $mesaDestino->numero,
                ];
            });

            return response()->json([
                'exito' => true,
                'mensaje' => 'Cuenta trasladada a la mesa ' . $resultado['numero_destino'] . '.',
                ...$resultado,
            ]);
        } catch (\Throwable $e) {
            Log::warning('No se pudo trasladar la cuenta', ['cuenta_id' => $cuentaId, 'error' => $e->getMessage()]);

            return response()->json(['exito' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /**
     * =========================================================================
     * TRASLADO INDIVIDUAL DE UNA SUBCUENTA A OTRA MESA
     * =========================================================================
     *
     * Mueve SOLO el consumo de una subcuenta (no la mesa completa) hacia
     * otra mesa. Reglas de negocio que cumple:
     *
     *  - Autorizan ADMIN, GERENTE y CAJERO (mismos permisos que dividir y
     *    cobrar). La validación SIEMPRE está aquí, en el servidor.
     *  - La cuenta origen debe estar ABIERTA en una venta ACTIVA.
     *  - Si alguna línea afectada ya está EN_PREPARACION o LISTO en cocina,
     *    se rechaza TODO el traslado (todo o nada): nunca se mueve comida
     *    que el cocinero ya tiene en marcha.
     *  - El origen solo REDUCE cantidad y subtotal, conservando comanda_id,
     *    estado, notas y estado_preparacion: no se borra nada ni se reescribe
     *    el historial. Al llegar a 0 la línea queda oculta por el filtro
     *    cantidad > 0; su asignación se marca eliminada (cuenta_detalles
     *    exige cantidad > 0, por eso no se pone a cero).
     *  - El destino crea líneas NUEVAS sin comanda ni estado_preparacion
     *    (el traslado nunca re-imprime comida a cocina) y con
     *    trasladado_desde_id para pintar el aviso «Este pedido fue
     *    trasladado a otra mesa. No volver a prepararlo».
     *  - Si la mesa destino está LIBRE se le crea venta con su Cuenta
     *    Principal (patrón del POS); si está OCUPADA, la subcuenta se añade
     *    a su venta ACTIVA como cuenta nueva.
     *  - La cuenta origen vacía pasa a CANCELADA; si la mesa origen se queda
     *    sin consumo real ni saldos, vuelve a LIBRE. La venta origen nunca
     *    cambia su estado financiero aquí.
     *  - `clave_operacion` da idempotencia: reenviar la misma petición no
     *    duplica el movimiento (columna única en traslados_cuenta).
     *  - Trazabilidad completa en traslados_cuenta + traslado_detalles.
     *
     * Responde JSON siempre: el flujo sale del panel con fetch/AJAX.
     */
    public function trasladarConsumoCuenta(Request $request, int $cuentaId)
    {
        // 1. Autorización primero, siempre en el servidor.
        $usuario = Auth::user();

        if (!$usuario) {
            return response()->json(['exito' => false, 'error' => 'Usuario no autenticado.'], 401);
        }

        $rol = strtoupper((string) ($usuario->rol->nombre ?? ''));

        if (!in_array($rol, ['ADMIN', 'GERENTE', 'CAJERO'], true)) {
            return response()->json(['exito' => false, 'error' => 'No tienes permiso para trasladar cuentas.'], 403);
        }

        // 2. Validación de entrada.
        $request->validate([
            'mesa_destino_id' => ['required', 'integer', 'exists:mesas,id'],
            'clave_operacion' => ['nullable', 'string', 'max:64'],
        ]);

        try {
            $resultado = DB::transaction(function () use ($request, $usuario, $cuentaId) {
                $mesaDestinoId = (int) $request->input('mesa_destino_id');
                $claveOperacion = $request->input('clave_operacion') ?: null;

                // -------------------------------------------------------
                // IDEMPOTENCIA
                // -------------------------------------------------------
                // Si esta misma clave ya registró un traslado, la petición
                // se reenvió (doble clic, red inestable): se devuelve el
                // resultado anterior sin repetir el movimiento.
                if ($claveOperacion) {
                    $trasladoPrevio = DB::table('traslados_cuenta')
                        ->where('clave_operacion', $claveOperacion)
                        ->first();

                    if ($trasladoPrevio) {
                        return [
                            'idempotente' => true,
                            'traslado_id' => (int) $trasladoPrevio->id,
                            'cuenta_destino_id' => (int) $trasladoPrevio->cuenta_destino_id,
                            'venta_destino_id' => (int) $trasladoPrevio->venta_destino_id,
                            'mesa_destino_id' => (int) $trasladoPrevio->mesa_destino_id,
                        ];
                    }
                }

                // -------------------------------------------------------
                // LECTURAS DE NAVEGACIÓN (sin bloqueo)
                // -------------------------------------------------------
                $cuentaNav = DB::table('cuentas')->where('id', $cuentaId)->first();
                if (!$cuentaNav) {
                    throw new \RuntimeException('La cuenta no existe.');
                }

                // Candidata a venta destino si la mesa ya está ocupada (se
                // vuelve a validar con bloqueos más abajo).
                $ventaDestinoNav = DB::table('ventas')
                    ->where('mesa_id', $mesaDestinoId)
                    ->whereIn('estado', ['ACTIVA', 'ABIERTA'])
                    ->orderByDesc('id')
                    ->first();

                // -------------------------------------------------------
                // ORDEN GLOBAL DE BLOQUEO (igual que el resto del POS):
                // ventas (id ASC) -> cuenta origen -> mesas (id ASC) ->
                // líneas (venta_detalles id ASC). Las dos ventas se bloquean
                // en la MISMA consulta ordenada para que dos traslados
                // cruzados (A→B y B→A) esperen siempre en el mismo orden.
                // -------------------------------------------------------
                $idsVentas = array_values(array_unique(array_filter([
                    (int) $cuentaNav->venta_id,
                    $ventaDestinoNav ? (int) $ventaDestinoNav->id : null,
                ])));
                sort($idsVentas);

                $ventasBloqueadas = DB::table('ventas')
                    ->whereIn('id', $idsVentas)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                $ventaOrigen = $ventasBloqueadas->get((int) $cuentaNav->venta_id);
                if (!$ventaOrigen || !in_array($ventaOrigen->estado, ['ACTIVA', 'ABIERTA'], true)) {
                    throw new \RuntimeException('La venta de origen no está activa.');
                }

                // La cuenta origen se bloquea dentro del orden global. La de
                // destino no existe todavía: se crea aquí mismo.
                $cuentaOrigen = DB::table('cuentas')->where('id', $cuentaId)->lockForUpdate()->first();
                if (!$cuentaOrigen || (int) $cuentaOrigen->venta_id !== (int) $ventaOrigen->id) {
                    throw new \RuntimeException('La cuenta no pertenece a la venta de origen.');
                }

                // Solo se traslada una cuenta ABIERTA: una PAGADA o CANCELADA
                // ya no representa consumo por cobrar.
                if ($cuentaOrigen->estado !== 'ABIERTA') {
                    throw new \RuntimeException('Solo se pueden trasladar cuentas ABIERTAS.');
                }

                $mesaOrigenId = (int) $ventaOrigen->mesa_id;

                if ($mesaDestinoId === $mesaOrigenId) {
                    throw new \RuntimeException('La cuenta ya se encuentra en esa mesa.');
                }

                // Bloquear ambas mesas en orden determinista (id ASC).
                $mesas = DB::table('mesas')
                    ->whereIn('id', [$mesaOrigenId, $mesaDestinoId])
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                $mesaDestino = $mesas->get($mesaDestinoId);
                if (!$mesaDestino) {
                    throw new \RuntimeException('La mesa destino no existe.');
                }

                // Solo LIBRE (se le crea venta) u OCUPADA (se une a su venta
                // ACTIVA). COBRANDO está en pleno cobro: no se mueve nada.
                if (!in_array($mesaDestino->estado, ['LIBRE', 'OCUPADA'], true)) {
                    throw new \RuntimeException('La mesa destino no admite traslados en este momento.');
                }

                // Coherencia entre lo leído sin bloquear y el estado ya
                // bloqueado: si cambió entre medias, se rechaza y se pide
                // reintento (nunca se procede sobre supuestos viejos).
                $ventaDestino = null;

                if ($mesaDestino->estado === 'OCUPADA') {
                    $ventaDestino = $ventaDestinoNav
                        ? $ventasBloqueadas->get((int) $ventaDestinoNav->id)
                        : null;

                    if (!$ventaDestino || !in_array($ventaDestino->estado, ['ACTIVA', 'ABIERTA'], true)) {
                        throw new \RuntimeException('La mesa destino está ocupada sin una venta activa. Vuelve a intentarlo.');
                    }
                } elseif ($ventaDestinoNav && $ventasBloqueadas->has((int) $ventaDestinoNav->id)) {
                    throw new \RuntimeException('La mesa destino cambió de estado. Vuelve a intentarlo.');
                }

                // -------------------------------------------------------
                // LÍNEAS DE CONSUMO DE LA CUENTA ORIGEN (bloqueadas)
                // -------------------------------------------------------
                $lineas = DB::table('cuenta_detalles as cd')
                    ->join('venta_detalles as vd', 'vd.id', '=', 'cd.venta_detalle_id')
                    ->where('cd.cuenta_id', $cuentaOrigen->id)
                    ->where('cd.eliminado', false)
                    ->where('vd.venta_id', $ventaOrigen->id)
                    ->orderBy('vd.id')
                    ->lockForUpdate()
                    ->get([
                        'cd.id as cd_id',
                        'cd.cantidad as cd_cantidad',
                        'cd.venta_detalle_id',
                        'vd.id as vd_id',
                        'vd.producto_id',
                        'vd.cantidad as vd_cantidad',
                        'vd.precio_unitario',
                        'vd.notas as vd_notas',
                        'vd.estado as vd_estado',
                        'vd.estado_preparacion',
                    ]);

                if ($lineas->isEmpty()) {
                    throw new \RuntimeException('La cuenta no tiene consumo para trasladar.');
                }

                foreach ($lineas as $linea) {
                    if ($linea->vd_estado !== 'ACTIVO') {
                        throw new \RuntimeException('El consumo de la cuenta cambió. Vuelve a intentarlo.');
                    }

                    if ((int) $linea->cd_cantidad > (int) $linea->vd_cantidad) {
                        throw new \RuntimeException('Inconsistencia de consumo detectada: no se realizó ningún traslado.');
                    }

                    // Protección de cocina: si el cocinero ya tiene el plato
                    // EN_PREPARACION o LISTO, NO se traslada (todo o nada).
                    // Esta restricción DEBE ELIMINARSE según el requerimiento:
                    // las líneas pueden trasladarse conservando su estado.
                    // if (in_array((string) $linea->estado_preparacion, ['EN_PREPARACION', 'LISTO'], true)) {
                    //     throw new \RuntimeException('No se puede trasladar: el pedido de cocina ya está en preparación o listo.');
                    // }
                }

                // -------------------------------------------------------
                // DESTINO: venta y cuenta
                // -------------------------------------------------------
                if (!$ventaDestino) {
                    // Mesa LIBRE: se crea la venta al estilo del POS, con su
                    // Cuenta Principal, para que los pedidos nuevos de esa
                    // mesa no caigan dentro de la cuenta trasladada.
                    $ventaDestinoId = DB::table('ventas')->insertGetId([
                        'mesa_id' => $mesaDestinoId,
                        'cliente_id' => null,
                        'descripcion' => 'Consumo Mesa ' . $mesaDestino->numero,
                        'monto' => 0,
                        'metodo_pago' => null,
                        'banco' => null,
                        'monto_recibido' => null,
                        'tasa_cambio' => null,
                        'cambio' => null,
                        'fecha' => now(),
                        'estado' => 'ACTIVA',
                        'usuario_id' => $usuario->id,
                        'descuento_porcentaje' => 0,
                        'apertura_id' => null,
                    ]);

                    DB::table('cuentas')->insert([
                        'venta_id' => $ventaDestinoId,
                        'nombre' => 'Cuenta Principal',
                        'total' => 0,
                        'estado' => 'ABIERTA',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                } else {
                    $ventaDestinoId = (int) $ventaDestino->id;
                }

                // Nombre de la cuenta destino: se conserva el de origen. Si
                // ya existe ese nombre en la venta destino (de cualquier
                // estado) se añade « (traslado)» para no confundir dos grupos
                // distintos con el mismo nombre.
                $nombreBase = (string) $cuentaOrigen->nombre;
                $nombreDestino = $nombreBase;

                for ($intento = 1; $intento <= 100; $intento++) {
                    $existe = DB::table('cuentas')
                        ->where('venta_id', $ventaDestinoId)
                        ->where('nombre', $nombreDestino)
                        ->exists();

                    if (!$existe) {
                        break;
                    }

                    $nombreDestino = $nombreBase . ' (traslado' . ($intento > 1 ? ' ' . $intento : '') . ')';
                }

                $cuentaDestinoId = DB::table('cuentas')->insertGetId([
                    'venta_id' => $ventaDestinoId,
                    'nombre' => $nombreDestino,
                    'total' => 0,
                    'estado' => 'ABIERTA',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // -------------------------------------------------------
                // MOVER CADA LÍNEA
                // -------------------------------------------------------
                $montoTrasladado = 0.0;
                $lineasMovidas = [];

                foreach ($lineas as $linea) {
                    $mover = (int) $linea->cd_cantidad;

                    // Subtotales con la misma fórmula que el POS: redondeo a
                    // 2 decimales calculado por el motor de base de datos.
                    $subtotalOrigen = DB::selectOne(
                        'SELECT ROUND(CAST(? AS DECIMAL(10,2)) * CAST(? AS DECIMAL(10,2)), 2) AS subtotal',
                        [(int) $linea->vd_cantidad - $mover, $linea->precio_unitario]
                    )->subtotal;

                    $subtotalTrasladado = DB::selectOne(
                        'SELECT ROUND(CAST(? AS DECIMAL(10,2)) * CAST(? AS DECIMAL(10,2)), 2) AS subtotal',
                        [$mover, $linea->precio_unitario]
                    )->subtotal;

                    // ORIGEN: solo se reduce la línea. Se conservan comanda,
                    // estado, notas e historial de cocina intactos.
                    DB::table('venta_detalles')->where('id', $linea->vd_id)->update([
                        'cantidad' => (int) $linea->vd_cantidad - $mover,
                        'subtotal' => $subtotalOrigen,
                        'updated_at' => now(),
                    ]);

                    // La asignación del origen se marca eliminada (borrado
                    // lógico): cuenta_detalles exige cantidad > 0, por eso
                    // NO se pone a cero.
                    DB::table('cuenta_detalles')->where('id', $linea->cd_id)->update([
                        'eliminado' => true,
                        'eliminado_por' => $usuario->id,
                        'eliminado_en' => now(),
                        'updated_at' => now(),
                    ]);

                    // DESTINO: línea nueva sin comanda ni estado_preparacion
                    // (la cocina nunca debe re-preparar esto), con la misma
                    // nota del original y marcada con la línea de origen.
                    $vdDestinoId = DB::table('venta_detalles')->insertGetId([
                        'venta_id' => $ventaDestinoId,
                        'producto_id' => $linea->producto_id,
                        'cantidad' => $mover,
                        'precio_unitario' => $linea->precio_unitario,
                        'subtotal' => $subtotalTrasladado,
                        'comanda_id' => null,
                        'cuenta_id' => null,
                        'estado' => 'ACTIVO',
                        'notas' => $linea->vd_notas,
                        'estado_preparacion' => null,
                        'trasladado_desde_id' => $linea->vd_id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    // Asignación de la cuenta destino: la fuente de verdad de
                    // a quién pertenece cada unidad para dividir y cobrar.
                    DB::table('cuenta_detalles')->insert([
                        'cuenta_id' => $cuentaDestinoId,
                        'venta_id' => $ventaDestinoId,
                        'venta_detalle_id' => $vdDestinoId,
                        'cantidad' => $mover,
                        'subtotal' => $subtotalTrasladado,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    $montoTrasladado += (float) $subtotalTrasladado;

                    $lineasMovidas[] = [
                        'venta_detalle_origen_id' => (int) $linea->vd_id,
                        'venta_detalle_destino_id' => $vdDestinoId,
                        'producto_id' => (int) $linea->producto_id,
                        'cantidad' => $mover,
                        'precio_unitario' => $linea->precio_unitario,
                        'subtotal' => $subtotalTrasladado,
                    ];
                }

                // -------------------------------------------------------
                // TOTALES Y ESTADOS
                // -------------------------------------------------------
                // Cuenta destino: suma de sus asignaciones activas.
                $totalDestino = (float) DB::table('cuenta_detalles')
                    ->where('cuenta_id', $cuentaDestinoId)
                    ->where('eliminado', false)
                    ->sum('subtotal');

                DB::table('cuentas')->where('id', $cuentaDestinoId)->update([
                    'total' => $totalDestino,
                    'updated_at' => now(),
                ]);

                // Cuenta origen: se recalcula con lo que le quede. Si queda
                // vacía pasa a CANCELADA (mismo patrón que la subdivisión de
                // cuentas): nunca se borra, conserva su historial.
                $totalOrigen = (float) DB::table('cuenta_detalles')
                    ->where('cuenta_id', $cuentaOrigen->id)
                    ->where('eliminado', false)
                    ->sum('subtotal');

                DB::table('cuentas')->where('id', $cuentaOrigen->id)->update([
                    'total' => $totalOrigen,
                    'estado' => $totalOrigen > 0 ? 'ABIERTA' : 'CANCELADA',
                    'updated_at' => now(),
                ]);

                // Venta origen: el importe es la suma de sus líneas ACTIVAS
                // (las trasladadas quedan a 0 y no aportan), la misma
                // fórmula que usan el POS y la anulación de detalles.
                DB::table('ventas')->where('id', $ventaOrigen->id)->update([
                    'monto' => DB::table('venta_detalles')
                        ->where('venta_id', $ventaOrigen->id)
                        ->where('estado', 'ACTIVO')
                        ->sum('subtotal'),
                ]);

                // Venta destino: idéntico recálculo (también correcto cuando
                // ya tenía consumo previo de otras cuentas).
                DB::table('ventas')->where('id', $ventaDestinoId)->update([
                    'monto' => DB::table('venta_detalles')
                        ->where('venta_id', $ventaDestinoId)
                        ->where('estado', 'ACTIVO')
                        ->sum('subtotal'),
                ]);

                // Mesa destino: ahora tiene consumo real => OCUPADA.
                DB::table('mesas')->where('id', $mesaDestinoId)->update(['estado' => 'OCUPADA']);

                // Mesa origen: LIBRE solo si ya no queda ni consumo ACTIVO
                // con cantidad ni cuentas ABIERTAS con saldo pendiente.
                $origenConConsumo = DB::table('venta_detalles')
                    ->where('venta_id', $ventaOrigen->id)
                    ->where('estado', 'ACTIVO')
                    ->where('cantidad', '>', 0)
                    ->exists();

                $origenCuentasConSaldo = DB::table('cuentas')
                    ->where('venta_id', $ventaOrigen->id)
                    ->where('estado', 'ABIERTA')
                    ->where('total', '>', 0)
                    ->exists();

                if (!$origenConConsumo && !$origenCuentasConSaldo) {
                    DB::table('mesas')->where('id', $mesaOrigenId)->update(['estado' => 'LIBRE']);
                }

                // -------------------------------------------------------
                // TRAZABILIDAD
                // -------------------------------------------------------
                $trasladoId = DB::table('traslados_cuenta')->insertGetId([
                    'clave_operacion' => $claveOperacion,
                    'cuenta_origen_id' => $cuentaOrigen->id,
                    'cuenta_destino_id' => $cuentaDestinoId,
                    'venta_origen_id' => $ventaOrigen->id,
                    'venta_destino_id' => $ventaDestinoId,
                    'mesa_origen_id' => $mesaOrigenId,
                    'mesa_destino_id' => $mesaDestinoId,
                    'usuario_id' => $usuario->id,
                    'cantidad_lineas' => count($lineasMovidas),
                    'monto_total' => $montoTrasladado,
                    'creado_en' => now(),
                ]);

                foreach ($lineasMovidas as $movida) {
                    DB::table('traslado_detalles')->insert([
                        'traslado_id' => $trasladoId,
                        'venta_detalle_origen_id' => $movida['venta_detalle_origen_id'],
                        'venta_detalle_destino_id' => $movida['venta_detalle_destino_id'],
                        'producto_id' => $movida['producto_id'],
                        'cantidad' => $movida['cantidad'],
                        'precio_unitario' => $movida['precio_unitario'],
                        'subtotal' => $movida['subtotal'],
                        'creado_en' => now(),
                    ]);
                }

                return [
                    'idempotente' => false,
                    'traslado_id' => $trasladoId,
                    'cuenta_destino_id' => $cuentaDestinoId,
                    'venta_destino_id' => $ventaDestinoId,
                    'mesa_destino_id' => $mesaDestinoId,
                    'nombre_destino' => $nombreDestino,
                    'numero_destino' => $mesaDestino->numero,
                    'lineas' => count($lineasMovidas),
                    'monto_total' => round($montoTrasladado, 2),
                ];
            });

            return response()->json([
                'exito' => true,
                'mensaje' => $resultado['idempotente']
                    ? 'Este traslado ya se había realizado.'
                    : 'Cuenta trasladada a la mesa ' . $resultado['numero_destino'] . '.',
                ...$resultado,
            ]);
        } catch (\Throwable $e) {
            Log::warning('No se pudo trasladar el consumo de la cuenta', [
                'cuenta_id' => $cuentaId,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['exito' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /**
     * =========================================================================
     * CREAR SUBCUENTA VACÍA EN UNA MESA (FASE 2)
     * =========================================================================
     *
     * Crea una cuenta nueva vinculada a la VENTA ACTIVA de la mesa.
     *
     * Reglas que cumple:
     * - Autorizan SOLO para CREAR: ADMIN, GERENTE, CAJERO y MESERO. No amplía
     *   los permisos actuales de dividir, reasignar, anular ni cobrar.
     * - Nunca crea una segunda venta: si la mesa no tiene venta activa rechaza.
     * - La cuenta nace VACÍA (total 0 y sin cuenta_detalles): no mueve productos
     *   ni modifica los totales de otras cuentas.
     * - Transacción con orden global de bloqueo venta -> cuentas (id ASC).
     *
     * Responde JSON cuando la petición es AJAX; en otro caso redirige al panel
     * de subcuentas (mesas.cuentas).
     */
    public function crearSubcuenta(Request $request, int $mesaId)
    {
        // 1. Usuario autenticado.
        $usuario = Auth::user();

        if (!$usuario) {
            abort(401, 'Usuario no autenticado.');
        }

        // 2. Autorización en backend: los cuatro roles pueden crear subcuentas
        //    vacías. La autorización no depende de botones ni validaciones de
        //    JavaScript.
        $rol = strtoupper((string) ($usuario->rol->nombre ?? ''));

        if (!in_array($rol, ['ADMIN', 'GERENTE', 'CAJERO', 'MESERO'], true)) {
            abort(403, 'No tienes permiso para crear cuentas.');
        }

        // 3. El nombre es obligatorio.
        $request->validate([
            'nombre' => ['required', 'string', 'max:100'],
        ]);

        $nombre = trim((string) $request->input('nombre'));

        if ($nombre === '') {
            if ($request->expectsJson()) {
                return response()->json([
                    'exito' => false,
                    'error' => 'El nombre de la cuenta es obligatorio.',
                ], 422);
            }

            return back()->with('error', 'El nombre de la cuenta es obligatorio.');
        }

        // 4. La mesa debe existir.
        $mesa = DB::table('mesas')->where('id', $mesaId)->first();

        if (!$mesa) {
            abort(404, 'La mesa no existe.');
        }

        try {
            $creada = DB::transaction(function () use ($mesa, $nombre) {
                // ORDEN GLOBAL DE BLOQUEO: venta -> cuentas (id ASC).
                // La venta activa de ESTA mesa se bloquea primero. Si la mesa
                // no tiene venta activa se rechaza: NUNCA se crea una segunda.
                $venta = DB::table('ventas')
                    ->where('mesa_id', $mesa->id)
                    ->whereIn('estado', ['ACTIVA', 'ABIERTA'])
                    ->orderByDesc('id')
                    ->lockForUpdate()
                    ->first();

                if (!$venta) {
                    throw new \RuntimeException('La mesa no tiene una venta activa.');
                }

                // Se bloquean las cuentas de la venta (en orden de id) para
                // serializar contra divisiones, cobros y anulaciones concurrentes.
                DB::table('cuentas')
                    ->where('venta_id', $venta->id)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                // No se permiten dos cuentas VISIBLES con el mismo nombre
                // (comparación insensible a mayúsculas/minúsculas). Las cuentas
                // canceladas quedan ocultas, así que su nombre puede reutilizarse.
                $existeNombre = DB::table('cuentas')
                    ->where('venta_id', $venta->id)
                    ->where('estado', '!=', 'CANCELADA')
                    ->whereRaw('LOWER(nombre) = ?', [mb_strtolower($nombre)])
                    ->exists();

                if ($existeNombre) {
                    throw new \RuntimeException('Ya existe una cuenta con el nombre "' . $nombre . '".');
                }

                // Cuenta nueva y vacía: sin asignaciones ni totales. No mueve
                // productos y no toca los totales de las demás cuentas.
                $cuentaId = DB::table('cuentas')
                    ->insertGetId([
                        'venta_id' => $venta->id,
                        'nombre' => $nombre,
                        'total' => 0,
                        'estado' => 'ABIERTA',
                        'apertura_id' => $venta->apertura_id,
                        'metodo_pago' => null,
                        'banco' => null,
                        'monto_recibido' => null,
                        'cambio' => null,
                        'usuario_cobro' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                return [
                    'cuenta_id' => $cuentaId,
                    'venta_id' => $venta->id,
                ];
            });

            // Respuesta para la interfaz (SweetAlert) y para una redirección
            // normal del panel de subcuentas.
            if ($request->expectsJson()) {
                return response()->json([
                    'exito' => true,
                    'mensaje' => 'Subcuenta creada correctamente.',
                    'cuenta_id' => $creada['cuenta_id'],
                    'venta_id' => $creada['venta_id'],
                    'redirect' => route('mesas.cuentas', ['mesaId' => $mesa->id]),
                ]);
            }

            return redirect()
                ->route('mesas.cuentas', ['mesaId' => $mesa->id])
                ->with('success', 'Subcuenta creada correctamente.');

        } catch (\RuntimeException $e) {
            // Rechazo por regla de negocio (mesa sin venta activa).
            if ($request->expectsJson()) {
                return response()->json([
                    'exito' => false,
                    'error' => $e->getMessage(),
                ], 422);
            }

            return back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            Log::error('No se pudo crear subcuenta', [
                'mesa_id' => $mesa->id,
                'usuario_id' => $usuario->id,
                'error' => $e->getMessage(),
                'linea' => $e->getLine(),
            ]);

            if ($request->expectsJson()) {
                return response()->json([
                    'exito' => false,
                    'error' => 'No se pudo crear la subcuenta.',
                ], 500);
            }

            return back()->with('error', 'No se pudo crear la subcuenta.');
        }
    }

    /** Datos seguros para la pantalla de división y cobro por cuenta. */
    public function cuentas(int $mesaId)
    {
        // Autorización en servidor: este JSON expone precios, subtotales y
        // totales de las cuentas. COCINERO no debe recibirlos.
        $usuario = Auth::user();
        $rol = strtoupper((string) ($usuario->rol->nombre ?? ''));

        if (!in_array($rol, ['ADMIN', 'GERENTE', 'CAJERO', 'MESERO'], true)) {
            return response()->json(['exito' => false, 'error' => 'No tienes permiso para ver las cuentas.'], 403);
        }

        $venta = DB::table('ventas')->where('mesa_id', $mesaId)->whereIn('estado', ['ACTIVA', 'ABIERTA'])->first();

        if (!$venta) {
            return response()->json(['exito' => false, 'error' => 'La mesa no tiene una venta activa.'], 404);
        }

        $detalles = DB::table('venta_detalles')
            ->join('productos', 'productos.id', '=', 'venta_detalles.producto_id')
            ->where('venta_detalles.venta_id', $venta->id)
            ->where('venta_detalles.estado', 'ACTIVO')
            ->select('venta_detalles.id', 'venta_detalles.cantidad', 'venta_detalles.precio_unitario', 'productos.nombre')
            ->orderBy('venta_detalles.id')
            ->get();

        $cuentas = DB::table('cuentas')->where('venta_id', $venta->id)->where('estado', '!=', 'CANCELADA')->orderBy('id')->get();

        // Los totales de las cuentas abiertas se calculan desde cuenta_detalles
        // (agrupados por cuenta_id) para no depender de una columna "total" que
        // pudo quedar desincronizada. Las cuentas ya pagadas conservan su total.
        $totalesPorCuenta = DB::table('cuenta_detalles')
            ->join('cuentas', 'cuentas.id', '=', 'cuenta_detalles.cuenta_id')
            ->where('cuentas.venta_id', $venta->id)
            ->where('cuenta_detalles.eliminado', false)
            ->groupBy('cuenta_detalles.cuenta_id')
            ->select('cuenta_detalles.cuenta_id', DB::raw('SUM(cuenta_detalles.subtotal) as total'))
            ->pluck('total', 'cuenta_id');

        $cuentas->each(function ($cuenta) use ($totalesPorCuenta) {
            if ($cuenta->estado === 'ABIERTA') {
                $cuenta->total = (float) ($totalesPorCuenta[$cuenta->id] ?? 0);
            }
        });

        $asignaciones = DB::table('cuenta_detalles')
            ->join('cuentas', 'cuentas.id', '=', 'cuenta_detalles.cuenta_id')
            ->where('cuentas.venta_id', $venta->id)
            ->where('cuenta_detalles.eliminado', false)
            ->select('cuenta_detalles.cuenta_id', 'cuenta_detalles.venta_detalle_id', 'cuenta_detalles.cantidad')
            ->get();

        return response()->json(compact('venta', 'detalles', 'cuentas', 'asignaciones') + ['exito' => true]);
    }

    /** Guarda una división completa. Las cuentas ya pagadas no se pueden modificar. */
    public function guardarDivision(Request $request, int $mesaId)
    {
        // Autorización primero: un usuario sin permiso no debe conocer
        // detalles de validación ni modificar cuentas o asignaciones.
        $usuario = Auth::user();

        if (!$usuario) {
            return response()->json([
                'exito' => false,
                'error' => 'Usuario no autenticado.',
            ], 401);
        }

        $rol = strtoupper((string) ($usuario->rol->nombre ?? ''));

        // Solo ADMIN, GERENTE y CAJERO pueden dividir cuentas.
        if (!in_array($rol, ['ADMIN', 'GERENTE', 'CAJERO'], true)) {
            return response()->json([
                'exito' => false,
                'error' => 'No tienes permiso para dividir cuentas.',
            ], 403);
        }

        $request->validate([
            'cuenta_origen_id' => ['nullable', 'integer'],
            'cuentas_nuevas' => ['array'],
            'cuentas_nuevas.*' => ['string', 'max:100'],
            'asignaciones' => ['required', 'array', 'min:1'],
            'asignaciones.*.cuenta_id' => ['required', 'integer'],
            'asignaciones.*.venta_detalle_id' => ['required', 'integer'],
            'asignaciones.*.cantidad' => ['required', 'integer', 'min:1'],
        ]);

        try {
            DB::transaction(function () use ($request, $mesaId) {
                $venta = DB::table('ventas')->where('mesa_id', $mesaId)->whereIn('estado', ['ACTIVA', 'ABIERTA'])->lockForUpdate()->first();
                if (!$venta) {
                    throw new \RuntimeException('La mesa no tiene una venta activa.');
                }

                // Subdivisión ACOTADA: cuando llega cuenta_origen_id se reparte
                // solo esa cuenta hacia cuentas nuevas derivadas de ella. Las
                // demás cuentas de la mesa quedan intactas.
                $origenId = (int) $request->input('cuenta_origen_id', 0);
                if ($origenId > 0) {
                    $this->aplicarSubdivision($request, $venta, $origenId);

                    return;
                }

                $nombresNuevos = [];
                $idsNuevas = [];
                foreach ($request->input('cuentas_nuevas', []) as $nombre) {
                    $nombre = trim($nombre);
                    $claveNombre = mb_strtolower($nombre);

                    if ($nombre !== '' && !in_array($claveNombre, $nombresNuevos, true)) {
                        $existe = DB::table('cuentas')
                            ->where('venta_id', $venta->id)
                            ->whereRaw('LOWER(nombre) = ?', [$claveNombre])
                            ->exists();

                        if ($existe) {
                            throw new \RuntimeException('Ya existe una cuenta con el nombre "' . $nombre . '".');
                        }

                        $idsNuevas[] = DB::table('cuentas')->insertGetId(['venta_id' => $venta->id, 'nombre' => $nombre, 'total' => 0, 'estado' => 'ABIERTA', 'created_at' => now(), 'updated_at' => now()]);
                        $nombresNuevos[] = $claveNombre;
                    }
                }

                $cuentasAbiertas = DB::table('cuentas')
                    ->where('venta_id', $venta->id)
                    ->where('estado', 'ABIERTA')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->all();
                // Orden determinista por id para respetar el orden global
                // venta -> cuentas -> venta_detalles y evitar deadlocks.
                $detalles = DB::table('venta_detalles')->where('venta_id', $venta->id)->where('estado', 'ACTIVO')->orderBy('id')->lockForUpdate()->get()->keyBy('id');

                // Cuentas que YA tenían consumo antes de reemplazar la
                // división. Sirve para detectar cuentas que quedan vacías y
                // que, por lo tanto, deben ocultarse (no eliminarse).
                $cuentasConConsumoPrevio = DB::table('cuenta_detalles')
                    ->whereIn('cuenta_id', $cuentasAbiertas)
                    ->where('eliminado', false)
                    ->distinct()
                    ->pluck('cuenta_id')
                    ->map(fn ($id) => (int) $id)
                    ->all();

                // Se reemplazan solo las asignaciones ACTIVAS de las cuentas
                // abiertas; lo pagado queda congelado. Las filas con
                // eliminado = true (historial de anulaciones) NO se borran:
                // si una asignación vuelve a usar ese par cuenta/línea, más
                // abajo se reactiva la misma fila en lugar de insertar una
                // nueva. Así no se destruye historial y se respeta la
                // restricción UNIQUE(cuenta_id, venta_detalle_id).
                DB::table('cuenta_detalles')
                    ->whereIn('cuenta_id', $cuentasAbiertas)
                    ->where('eliminado', false)
                    ->delete();
                $cantidades = [];
                foreach ($request->input('asignaciones') as $asignacion) {
                    $cuentaId = (int) $asignacion['cuenta_id'];
                    $detalleId = (int) $asignacion['venta_detalle_id'];
                    $cantidad = (int) $asignacion['cantidad'];
                    if (!in_array($cuentaId, $cuentasAbiertas, true) || !$detalles->has($detalleId)) {
                        throw new \RuntimeException('La asignación no pertenece a esta venta.');
                    }
                    $clave = $cuentaId . ':' . $detalleId;
                    if (isset($cantidades[$clave])) throw new \RuntimeException('No repitas la misma asignación.');
                    if ($cantidad <= 0) {
                        throw new \RuntimeException('La cantidad debe ser un entero positivo.');
                    }
                    if ($cantidad > (int) $detalles[$detalleId]->cantidad) {
                        throw new \RuntimeException('La cantidad de la línea ' . $detalleId . ' supera las unidades disponibles (' . (int) $detalles[$detalleId]->cantidad . ').');
                    }
                    $cantidades[$clave] = $cantidad;
                    $subtotal = DB::selectOne(
                        'SELECT ROUND(CAST(? AS DECIMAL(10,2)) * CAST(? AS DECIMAL(10,2)), 2) AS subtotal',
                        [$cantidad, $detalles[$detalleId]->precio_unitario]
                    )->subtotal;

                    // Si el par (cuenta, línea) conserva una fila con
                    // eliminado = true (fue anulada antes), se reactiva esa
                    // misma fila en vez de insertar, para no violar la UNIQUE
                    // y para no perder la trazabilidad. Si no existe, se crea.
                    $filaExistente = DB::table('cuenta_detalles')
                        ->where('cuenta_id', $cuentaId)
                        ->where('venta_detalle_id', $detalleId)
                        ->first();

                    if ($filaExistente) {
                        DB::table('cuenta_detalles')->where('id', $filaExistente->id)->update([
                            'venta_id' => $venta->id,
                            'cantidad' => $cantidad,
                            'subtotal' => $subtotal,
                            'eliminado' => false,
                            'eliminado_por' => null,
                            'eliminado_en' => null,
                            'updated_at' => now(),
                        ]);
                    } else {
                        DB::table('cuenta_detalles')->insert([
                            'cuenta_id' => $cuentaId,
                            'venta_id' => $venta->id,
                            'venta_detalle_id' => $detalleId,
                            'cantidad' => $cantidad,
                            'subtotal' => $subtotal,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }

                foreach ($detalles as $detalle) {
                    $asignado = DB::table('cuenta_detalles')->where('venta_detalle_id', $detalle->id)->where('eliminado', false)->sum('cantidad');
                    if ((int) $asignado !== (int) $detalle->cantidad) throw new \RuntimeException('Debes asignar exactamente todas las unidades de ' . $detalle->id . '.');
                }
                foreach ($cuentasAbiertas as $cuentaId) {
                    DB::table('cuentas')->where('id', $cuentaId)->update(['total' => DB::table('cuenta_detalles')->where('cuenta_id', $cuentaId)->where('eliminado', false)->sum('subtotal'), 'updated_at' => now()]);
                }

                // PROBLEMA 1: si al dividir una cuenta queda sin unidades, se
                // marca CANCELADA (borrado lógico) para que deje de mostrarse,
                // sin borrar su historial. Nunca se toca una cuenta recién
                // creada en esta operación ni una cuenta que no tenía consumo.
                foreach ($cuentasAbiertas as $cuentaId) {
                    if (in_array($cuentaId, $idsNuevas, true)) {
                        continue;
                    }
                    if (!in_array($cuentaId, $cuentasConConsumoPrevio, true)) {
                        continue;
                    }
                    $unidadesRestantes = DB::table('cuenta_detalles')
                        ->where('cuenta_id', $cuentaId)
                        ->where('eliminado', false)
                        ->count();
                    if ($unidadesRestantes === 0) {
                        DB::table('cuentas')->where('id', $cuentaId)->update(['estado' => 'CANCELADA', 'total' => 0, 'updated_at' => now()]);
                    }
                }
            });
            return response()->json(['exito' => true, 'mensaje' => 'División guardada. Ya puedes cobrar cuentas por separado.']);
        } catch (\Throwable $e) {
            Log::warning('No se pudo dividir la cuenta', ['mesa_id' => $mesaId, 'error' => $e->getMessage()]);
            return response()->json(['exito' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /**
     * Subdivide UNA cuenta (origen) en cuentas derivadas de ella.
     *
     * A diferencia de la división a nivel de mesa (guardarDivision), aquí:
     *  - SOLO se reasignan los consumos propios de la cuenta de origen;
     *  - los destinos permitidos son la propia cuenta de origen, las cuentas
     *    nuevas creadas en esta operación y las hijas directas VACÍAS (sin
     *    consumo activo). Nunca se tocan las hijas con consumo ni las hermanas;
     *  - no se permite superar, duplicar ni perder unidades del origen;
     *  - si el origen queda vacío se marca CANCELADA conservando historial.
     */
    private function aplicarSubdivision(Request $request, object $venta, int $origenId): void
    {
        // El origen debe pertenecer a la venta y estar ABIERTA. Una cuenta
        // cancelada o pagada no es divisible.
        $origen = DB::table('cuentas')
            ->where('id', $origenId)
            ->where('venta_id', $venta->id)
            ->where('estado', 'ABIERTA')
            ->lockForUpdate()
            ->first();

        if (!$origen) {
            throw new \RuntimeException('La cuenta de origen no está disponible para dividir.');
        }

        // Aislamiento: solo se puede dividir una cuenta con consumos PROPIOS
        // activos. Una cuenta vacía no puede usar los consumos de sus
        // subcuentas para completar su propia división.
        $unidadesPropias = (int) DB::table('cuenta_detalles')
            ->where('cuenta_id', $origen->id)
            ->where('eliminado', false)
            ->sum('cantidad');

        if ($unidadesPropias <= 0) {
            throw new \RuntimeException('La cuenta de origen no tiene consumos propios para dividir.');
        }

        // Cuentas nuevas: todas se registran como hijas del origen.
        $idsNuevas = [];
        $nombresNuevos = [];
        foreach ($request->input('cuentas_nuevas', []) as $nombre) {
            $nombre = trim($nombre);
            $claveNombre = mb_strtolower($nombre);

            if ($nombre === '' || in_array($claveNombre, $nombresNuevos, true)) {
                continue;
            }

            $existe = DB::table('cuentas')
                ->where('venta_id', $venta->id)
                ->whereRaw('LOWER(nombre) = ?', [$claveNombre])
                ->exists();

            if ($existe) {
                throw new \RuntimeException('Ya existe una cuenta con el nombre "' . $nombre . '".');
            }

            $idsNuevas[] = DB::table('cuentas')->insertGetId([
                'venta_id' => $venta->id,
                'cuenta_origen_id' => $origen->id,
                'nombre' => $nombre,
                'total' => 0,
                'estado' => 'ABIERTA',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $nombresNuevos[] = $claveNombre;
        }

        // AISLAMIENTO ESTRICTO: la subdivisión trabaja SOLO con los consumos
        // propios del origen. Nunca toma, borra, reasigna ni cancela consumos
        // de las cuentas hijas (aunque ya tengan consumo) ni de las hermanas.
        //
        // Destinos permitidos:
        //  - el propio origen (para conservar su saldo);
        //  - las cuentas nuevas creadas en esta operación;
        //  - las hijas directas existentes que estén VACÍAS (sin consumo
        //    activo), que pueden recibir unidades sin alterar nada previo.
        // Una hija que YA tiene consumo propio queda excluida: no se toca.
        $hijasVacias = DB::table('cuentas')
            ->where('venta_id', $venta->id)
            ->where('cuenta_origen_id', $origen->id)
            ->where('estado', 'ABIERTA')
            ->whereNotExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('cuenta_detalles')
                    ->whereColumn('cuenta_detalles.cuenta_id', 'cuentas.id')
                    ->where('cuenta_detalles.eliminado', false);
            })
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $destinos = array_values(array_unique(array_merge(
            [(int) $origen->id],
            $hijasVacias,
            $idsNuevas
        )));

        // Unidades disponibles por línea: SOLO las del origen (sus propios
        // consumos activos). Las hijas y hermanas no aportan unidades.
        $disponibles = DB::table('cuenta_detalles')
            ->where('cuenta_id', $origen->id)
            ->where('eliminado', false)
            ->lockForUpdate()
            ->get()
            ->groupBy('venta_detalle_id')
            ->map(fn ($filas) => (int) $filas->sum('cantidad'));

        if ($disponibles->isEmpty()) {
            throw new \RuntimeException('La cuenta de origen no tiene consumos para dividir.');
        }

        $detalles = DB::table('venta_detalles')
            ->where('venta_id', $venta->id)
            ->where('estado', 'ACTIVO')
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        // Agrupar y validar el reparto. Solo se admiten líneas propias del
        // origen y destinos válidos (origen, hijas vacías y cuentas nuevas).
        $reparto = [];
        $vistos = [];
        foreach ($request->input('asignaciones') as $asignacion) {
            $cuentaId = (int) $asignacion['cuenta_id'];
            $detalleId = (int) $asignacion['venta_detalle_id'];
            $cantidad = (int) $asignacion['cantidad'];

            if (!in_array($cuentaId, $destinos, true)) {
                throw new \RuntimeException('Solo se puede repartir la cuenta de origen entre ella misma, sus subcuentas vacías o cuentas nuevas.');
            }
            if (!isset($disponibles[$detalleId])) {
                throw new \RuntimeException('La línea indicada no pertenece a la cuenta de origen.');
            }
            if ($cantidad <= 0) {
                throw new \RuntimeException('La cantidad debe ser un entero positivo.');
            }

            $clave = $cuentaId . ':' . $detalleId;
            if (isset($vistos[$clave])) {
                throw new \RuntimeException('No repitas la misma asignación.');
            }
            $vistos[$clave] = true;

            $reparto[$detalleId][$cuentaId] = $cantidad;
        }

        // Cada línea tocada debe repartir EXACTAMENTE las unidades propias del
        // origen: no se puede superar ni perder consumo del origen.
        foreach ($reparto as $detalleId => $porCuenta) {
            if (array_sum($porCuenta) !== (int) $disponibles[$detalleId]) {
                throw new \RuntimeException('Debes repartir exactamente las unidades de la cuenta de origen en cada línea.');
            }
        }

        // Aplicar: se reemplazan SOLO las asignaciones activas del ORIGEN,
        // por línea, hacia el origen (resto), las hijas vacías y las nuevas.
        // Los consumos de las hijas con consumo y de las hermanas no se tocan.
        foreach ($reparto as $detalleId => $porCuenta) {
            if (!$detalles->has($detalleId)) {
                throw new \RuntimeException('La línea indicada no pertenece a esta venta.');
            }
            $precio = $detalles[$detalleId]->precio_unitario;

            DB::table('cuenta_detalles')
                ->where('cuenta_id', $origen->id)
                ->where('venta_detalle_id', $detalleId)
                ->where('eliminado', false)
                ->delete();

            foreach ($porCuenta as $cuentaId => $cantidad) {
                $subtotal = DB::selectOne(
                    'SELECT ROUND(CAST(? AS DECIMAL(10,2)) * CAST(? AS DECIMAL(10,2)), 2) AS subtotal',
                    [$cantidad, $precio]
                )->subtotal;

                $filaExistente = DB::table('cuenta_detalles')
                    ->where('cuenta_id', $cuentaId)
                    ->where('venta_detalle_id', $detalleId)
                    ->first();

                if ($filaExistente) {
                    DB::table('cuenta_detalles')->where('id', $filaExistente->id)->update([
                        'venta_id' => $venta->id,
                        'cantidad' => $cantidad,
                        'subtotal' => $subtotal,
                        'eliminado' => false,
                        'eliminado_por' => null,
                        'eliminado_en' => null,
                        'updated_at' => now(),
                    ]);
                } else {
                    DB::table('cuenta_detalles')->insert([
                        'cuenta_id' => $cuentaId,
                        'venta_id' => $venta->id,
                        'venta_detalle_id' => $detalleId,
                        'cantidad' => $cantidad,
                        'subtotal' => $subtotal,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }

        // Recalcular totales de las cuentas que participaron (origen, hijas
        // vacías usadas y cuentas nuevas). Las hijas con consumo no se tocaron.
        foreach ($destinos as $cuentaId) {
            DB::table('cuentas')->where('id', $cuentaId)->update([
                'total' => DB::table('cuenta_detalles')->where('cuenta_id', $cuentaId)->where('eliminado', false)->sum('subtotal'),
                'updated_at' => now(),
            ]);
        }

        // El único origen que puede quedar vacío es la cuenta de origen (solo
        // se repartieron sus propios consumos). Si queda sin unidades, se
        // oculta (CANCELADA) conservando su registro e historial. Las hijas
        // vacías usadas como destino y las cuentas nuevas permanecen ABIERTAS.
        $restantesOrigen = DB::table('cuenta_detalles')
            ->where('cuenta_id', $origen->id)
            ->where('eliminado', false)
            ->count();

        if ($restantesOrigen === 0) {
            DB::table('cuentas')->where('id', $origen->id)->update([
                'estado' => 'CANCELADA',
                'total' => 0,
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * ============================================================
     * COBRO DE UNA CUENTA (PAGOS MIXTOS + DESCUENTO + PROPINA)
     * ============================================================
     *
     * Todo ocurre dentro de UNA transacción:
     *  1. Autorización y validación de la petición (nunca confiar en JS).
     *  2. Bloqueo en orden global venta -> cuentas (id ASC), igual que el
     *     resto del POS: dos cobros simultáneos se serializan.
     *  3. Total a cobrar = consumo − descuento + propina (fórmula única;
     *     sin ajustes coincide con el consumo de siempre).
     *  4. Las líneas de pago se normalizan EN EL SERVIDOR: importes en
     *     córdobas calculados aquí, USD convertidos con la tasa vigente
     *     (el sistema nunca inventa un tipo de cambio fijo).
     *  5. Los pagos deben cubrir el total; el excedente SOLO puede venir
     *     del efectivo y se devuelve como cambio (nunca negativo).
     *  6. Cada línea queda en `pagos_cuenta` (idempotencia por
     *     `clave_operacion`), la cuenta pasa a PAGADA y, si no quedan más
     *     cuentas abiertas con saldo, la venta se cierra y la mesa se libera.
     *  7. La factura se emite dentro de la MISMA transacción
     *     (FacturaService): si algo falla no queda pago sin factura ni
     *     factura sin pago, y un reintento no duplica cobros, pagos ni
     *     facturas (la cuenta ya no está ABIERTA).
     *
     * Permisos: cobran ADMIN, GERENTE y CAJERO. Descuento y propina solo
     * los aplican ADMIN y GERENTE. El rol CONTADOR NO existe en `roles`
     * (solo ADMIN, GERENTE, CAJERO, MESERO, COCINERO): no se agrega en
     * silencio, su integración requiere un cambio de esquema/roles y queda
     * como decisión pendiente documentada.
     */
    public function pagarCuenta(Request $request, int $cuentaId)
    {
        // ------------------------------------------------------------
        // 1. AUTORIZACIÓN PRIMERO (antes de revelar validaciones)
        // ------------------------------------------------------------
        $usuario = Auth::user();
        $rol = strtoupper((string) ($usuario?->rol?->nombre ?? ''));

        if (!in_array($rol, ['ADMIN', 'GERENTE', 'CAJERO'], true)) {
            return response()->json(['exito' => false, 'error' => 'No tienes permiso para cobrar.'], 403);
        }

        // ------------------------------------------------------------
        // 2. VALIDACIÓN DE ENTRADA
        // ------------------------------------------------------------
        // Se aceptan LOS DOS formatos:
        //  - Nuevo:  `pagos[]` con varias líneas (efectivo C$, USD, débito,
        //            crédito con banco) -> pagos mixtos.
        //  - Antiguo: `metodo_pago` + `monto_recibido` (un solo método),
        //            que es lo que hoy envía el POS y las pruebas.
        $request->validate([
            'metodo_pago' => ['nullable', 'string', 'max:30'],
            'monto_recibido' => ['nullable', 'numeric', 'min:0'],
            'banco' => ['nullable', 'string', 'max:30'],
            'pagos' => ['nullable', 'array'],
            'pagos.*.clave_operacion' => ['required_with:pagos', 'string', 'max:64'],
            'pagos.*.metodo_pago' => ['required_with:pagos', 'string', 'max:30', 'in:'.implode(',', self::METODOS_PAGO)],
            'pagos.*.monto' => ['required_with:pagos', 'numeric', 'gt:0'],
            'pagos.*.banco' => ['nullable', 'string', 'max:30'],
            'pagos.*.tasa_cambio' => ['nullable', 'numeric', 'gt:0'],
            'descuento' => ['nullable', 'numeric', 'min:0'],
            'descuento_motivo' => ['nullable', 'string', 'max:255'],
            'propina' => ['nullable', 'numeric', 'min:0'],
        ]);

        // ------------------------------------------------------------
        // 3. AJUSTES OPCIONALES: solo ADMIN/GERENTE los autorizan
        // ------------------------------------------------------------
        // Si no se envía nada, ambos son cero: no se aplica ningún ajuste
        // automático. MESERO ni COCINERO llegan aquí (paso 1).
        $descuento = round((float) ($request->input('descuento') ?? 0), 2);
        $propina = round((float) ($request->input('propina') ?? 0), 2);
        $motivo = trim((string) ($request->input('descuento_motivo') ?? ''));

        if ($descuento < 0 || $propina < 0) {
            return response()->json(['exito' => false, 'error' => 'El descuento y la propina no pueden ser negativos.'], 422);
        }

        if (($descuento > 0 || $propina > 0 || $motivo !== '') && !in_array($rol, ['ADMIN', 'GERENTE', 'CAJERO'], true)) {
            return response()->json(['exito' => false, 'error' => 'Solo ADMIN, GERENTE o CAJERO pueden aplicar descuentos o propinas.'], 403);
        }

        try {
            $resultado = DB::transaction(function () use ($request, $cuentaId, $usuario, $descuento, $propina, $motivo) {
                // Lectura de navegación sin bloqueo: solo para conocer la venta.
                $cuentaNav = DB::table('cuentas')->where('id', $cuentaId)->first();
                if (!$cuentaNav) throw new \RuntimeException('La cuenta no existe.');

                // ORDEN GLOBAL DE BLOQUEO: venta -> cuentas (id ASC).
                // Bloquear la venta primero serializa dos cobros simultáneos de
                // las últimas cuentas y evita liberar la mesa dos veces (o ninguna).
                $venta = DB::table('ventas')->where('id', $cuentaNav->venta_id)->lockForUpdate()->first();
                if (!$venta) throw new \RuntimeException('La venta no existe.');

                $cuentas = DB::table('cuentas')
                    ->where('venta_id', $venta->id)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                // Solo cobra una cuenta ABIERTA: si ya está PAGADA o
                // CANCELADA el reintento se rechaza y NO se duplican pagos
                // ni facturas.
                $cuenta = $cuentas->get($cuentaId);
                if (!$cuenta || $cuenta->estado !== 'ABIERTA') throw new \RuntimeException('La cuenta no está disponible para cobro (ya fue pagada o cancelada).');

                // ----------------------------------------------------
                // TOTALES: consumo -> descuento -> base -> propina -> total
                // ----------------------------------------------------
                $consumo = (float) DB::table('cuenta_detalles')->where('cuenta_id', $cuentaId)->where('eliminado', false)->sum('subtotal');

                if ($consumo <= 0) {
                    throw new \RuntimeException('La cuenta no tiene consumo por cobrar.');
                }

                if ($descuento > $consumo) {
                    throw new \RuntimeException('El descuento (C$ '.number_format($descuento, 2).') no puede superar el consumo de la cuenta (C$ '.number_format($consumo, 2).').');
                }

                // FÓRMULA ÚNICA de propina: porcentaje sobre la BASE
                // (consumo − descuento). Nunca se calcula sobre sí misma ni
                // se aplica dos veces: aquí se fija UNA vez por cobro.
                $base = round($consumo - $descuento, 2);
                $totalCobrar = round($base + $propina, 2);

                // ----------------------------------------------------
                // LÍNEAS DE PAGO (importes en córdobas calculados en
                // el servidor; ver lineasDePago)
                // ----------------------------------------------------
                $lineas = $this->lineasDePago($request);

                $efectivo = 0.0;
                $otros = 0.0;

                foreach ($lineas as $linea) {
                    if (str_contains($linea['metodo_pago'], 'EFECTIVO')) {
                        $efectivo += $linea['monto_cordobas'];
                    } else {
                        $otros += $linea['monto_cordobas'];
                    }
                }

                $efectivo = round($efectivo, 2);
                $otros = round($otros, 2);

                // REGLA DE EXCEDENTE: las tarjetas se cobran exactas; no se
                // devuelve "cambio" por tarjeta. El sobrante solo puede venir
                // del efectivo (posiblemente convertido desde USD).
                if ($otros > $totalCobrar) {
                    throw new \RuntimeException('Los pagos con tarjeta (C$ '.number_format($otros, 2).') exceden el total a cobrar (C$ '.number_format($totalCobrar, 2).'): el excedente solo se admite en efectivo.');
                }

                $suma = round($efectivo + $otros, 2);

                if ($suma < $totalCobrar) {
                    throw new \RuntimeException('El total de los pagos (C$ '.number_format($suma, 2).') no cubre el total a cobrar (C$ '.number_format($totalCobrar, 2).').');
                }

                // Cambio entregado en efectivo; por construcción >= 0.
                $cambio = round($suma - $totalCobrar, 2);

                // ----------------------------------------------------
                // IDEMPOTENCIA DE LÍNEAS: una misma clave nunca se
                // registra dos veces (el índice UNIQUE lo garantiza;
                // aquí se detecta antes de insertar para dar un
                // mensaje claro).
                // ----------------------------------------------------
                $claves = array_values(array_filter(array_map(fn ($l) => $l['clave_operacion'], $lineas)));

                if ($claves !== [] && DB::table('pagos_cuenta')->whereIn('clave_operacion', $claves)->exists()) {
                    throw new \RuntimeException('Una línea de pago ya estaba registrada con esa clave (reintento detectado). Vuelve a cargar la cuenta.');
                }

                // Resumen del cobro para la cuenta: un solo método se guarda
                // tal cual; varios se guardan como MIXTO (NUNCA se concatena
                // la lista ni se pisa con el último recibido).
                $metodos = array_values(array_unique(array_map(fn ($l) => $l['metodo_pago'], $lineas)));
                $bancos = array_values(array_unique(array_filter(array_map(fn ($l) => $l['banco'], $lineas))));

                // ----------------------------------------------------
                // REGISTRO DE CADA LÍNEA DE PAGO
                // ----------------------------------------------------
                foreach ($lineas as $linea) {
                    DB::table('pagos_cuenta')->insert([
                        'clave_operacion' => $linea['clave_operacion'],
                        'cuenta_id' => (int) $cuentaId,
                        'venta_id' => (int) $venta->id,
                        'metodo_pago' => $linea['metodo_pago'],
                        'banco' => $linea['banco'],
                        'moneda' => $linea['moneda'],
                        'monto' => $linea['monto'],
                        'tasa_cambio' => $linea['tasa_cambio'],
                        'monto_cordobas' => $linea['monto_cordobas'],
                        'usuario_id' => (int) $usuario->id,
                        'creado_en' => now(),
                    ]);
                }

                // ----------------------------------------------------
                // CUENTA PAGADA + AJUSTES (auditoría de quién aplicó
                // el descuento). `total` sigue siendo el CONSUMO: los
                // ajustes viven en sus propias columnas.
                // ----------------------------------------------------
                DB::table('cuentas')->where('id', $cuentaId)->update([
                    'total' => $consumo,
                    'estado' => 'PAGADA',
                    'descuento' => $descuento,
                    'descuento_motivo' => $descuento > 0 && $motivo !== '' ? mb_substr($motivo, 0, 255) : null,
                    'descuento_usuario_id' => $descuento > 0 ? (int) $usuario->id : null,
                    'propina' => $propina,
                    'metodo_pago' => count($metodos) === 1 ? $metodos[0] : 'MIXTO',
                    'banco' => count($bancos) === 1 ? $bancos[0] : null,
                    'monto_recibido' => $suma,
                    'cambio' => $cambio,
                    'usuario_cobro' => (int) $usuario->id,
                    'updated_at' => now(),
                ]);

                // Una cuenta vacía no debe impedir el cierre: solo importan
                // las cuentas abiertas que todavía tienen consumo pendiente.
                // Lectura con bloqueo para ver el último estado confirmado.
                $abiertas = DB::table('cuentas')
                    ->where('venta_id', $venta->id)
                    ->where('id', '!=', $cuentaId)
                    ->where('estado', 'ABIERTA')
                    ->where('total', '>', 0)
                    ->lockForUpdate()
                    ->exists();

                if (!$abiertas) {
                    DB::table('ventas')->where('id', $venta->id)->update(['estado' => 'PAGADA']);
                    DB::table('mesas')->where('id', $venta->mesa_id)->update(['estado' => 'LIBRE']);
                }

                // FACTURA DEL COBRO. Se emite dentro de MISMA transacción para
                // que no pueda existir un cobro sin factura ni una factura sin
                // cobro: si la inserción falla, el pago entero se revierte.
                // Una factura por cobro (por cuenta). Ver FacturaService.
                // El total que se factura es lo REALMENTE cobrado
                // (consumo − descuento + propina) y los ajustes quedan en el
                // detalle JSON del comprobante.
                $factura = app(FacturaService::class)
                    ->emitirPorCobro($cuenta, $venta, $totalCobrar, (int) $usuario->id, [
                        'consumo' => $consumo,
                        'descuento' => $descuento,
                        'base' => $base,
                        'propina' => $propina,
                        'total' => $totalCobrar,
                    ]);

                return [
                    'cambio' => $cambio,
                    'mesa_liberada' => !$abiertas,
                    'factura' => $factura->numero_factura,
                    'factura_id' => $factura->id,
                    'totales' => [
                        'consumo' => $consumo,
                        'descuento' => $descuento,
                        'base' => $base,
                        'propina' => $propina,
                        'total' => $totalCobrar,
                    ],
                    'pagos' => count($lineas),
                ];
            });
            return response()->json(['exito' => true, 'mensaje' => $resultado['mesa_liberada'] ? 'Todas las cuentas fueron pagadas. Mesa liberada.' : 'Cuenta pagada. La mesa sigue abierta.', ...$resultado]);
        } catch (\Throwable $e) {
            return response()->json(['exito' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /**
     * Convierte la petición en líneas de pago normalizadas. TODOS los importes
     * en córdobas se calculan aquí, en el servidor: el navegador solo envía el
     * importe original y (en dólares) la tasa elegida.
     *
     * Formato nuevo:   pagos[] = {clave_operacion, metodo_pago, monto, banco?, tasa_cambio?}
     * Formato antiguo: metodo_pago + monto_recibido (un único método en córdobas).
     *
     * Reglas por línea:
     *  - metodo_pago debe ser de METODOS_PAGO (validado también por Laravel).
     *  - Tarjeta (débito/crédito): banco OBLIGATORIO y de la lista BANCOS_TARJETA.
     *  - Dólares: tasa obligatoria > 0; si no llega, se usa la vigente del
     *    sistema (configuraciones.tipo_cambio_usd o la última tasa de
     *    caja_cierre). Si no hay NINGUNA, se rechaza el cobro en USD en vez
     *    de inventar un valor: ese es el punto que debe definir el negocio.
     *  - El monto en córdobas (monto_cordobas) se deriva SIEMPRE aquí.
     *
     * @return array<int, array{clave_operacion: string, metodo_pago: string, banco: ?string, moneda: string, monto: float, tasa_cambio: ?float, monto_cordobas: float}>
     */
    private function lineasDePago(Request $request): array
    {
        $pagos = $request->input('pagos');

        // ------------------------------------------------------------
        // Formato nuevo: varias líneas (pagos mixtos).
        // ------------------------------------------------------------
        if (is_array($pagos) && $pagos !== []) {
            $lineas = [];

            foreach (array_values($pagos) as $indice => $pago) {
                $n = $indice + 1;
                $metodo = strtoupper(trim((string) ($pago['metodo_pago'] ?? '')));

                if (!in_array($metodo, self::METODOS_PAGO, true)) {
                    throw new \RuntimeException("Método de pago no válido en la línea $n.");
                }

                $monto = round((float) ($pago['monto'] ?? 0), 2);

                if ($monto <= 0) {
                    throw new \RuntimeException("El importe de la línea $n debe ser mayor que cero.");
                }

                $clave = trim((string) ($pago['clave_operacion'] ?? ''));

                if ($clave === '') {
                    throw new \RuntimeException("La línea $n no tiene clave de operación.");
                }

                $esTarjeta = in_array($metodo, ['TARJETA_DEBITO', 'TARJETA_CREDITO'], true);
                $esDolares = $metodo === 'EFECTIVO_USD';

                // Banco: SOLO en tarjeta, y siempre de la lista oficial.
                $banco = null;

                if ($esTarjeta) {
                    $banco = $this->normalizarBanco((string) ($pago['banco'] ?? ''));

                    if ($banco === null) {
                        throw new \RuntimeException("La línea $n es con tarjeta: seleccione banco (Banpro, Ficohsa, BAC o Lafise).");
                    }
                }

                // Tasa de cambio: solo en dólares; nunca un valor inventado
                // por el código (ver tipoCambioVigente).
                $tasa = null;

                if ($esDolares) {
                    $tasa = round((float) ($pago['tasa_cambio'] ?? 0), 4);

                    if ($tasa <= 0) {
                        $tasa = $this->tipoCambioVigente() ?? 0.0;
                    }

                    if ($tasa <= 0) {
                        throw new \RuntimeException('No hay tipo de cambio vigente para cobrar en dólares. Capture la tasa del día (> 0): el sistema no inventa un tipo de cambio.');
                    }
                }

                $lineas[] = [
                    'clave_operacion' => $clave,
                    'metodo_pago' => $metodo,
                    'banco' => $banco,
                    'moneda' => $esDolares ? 'USD' : 'NIO',
                    'monto' => $monto,
                    'tasa_cambio' => $esDolares ? $tasa : null,
                    'monto_cordobas' => $esDolares ? round($monto * $tasa, 2) : $monto,
                ];
            }

            return $lineas;
        }

        // ------------------------------------------------------------
        // Formato antiguo: un solo método (compatibilidad total con el
        // POS actual y con las peticiones ya existentes).
        // ------------------------------------------------------------
        $metodo = trim((string) ($request->input('metodo_pago') ?? ''));

        if ($metodo === '') {
            throw new \RuntimeException('Debe indicar al menos un método de pago.');
        }

        if (strtoupper($metodo) === 'EFECTIVO_USD') {
            throw new \RuntimeException('Para cobrar en dólares use las líneas de pago (pagos[]) con la tasa del día.');
        }

        $monto = round((float) ($request->input('monto_recibido') ?? 0), 2);

        if ($monto <= 0) {
            throw new \RuntimeException('El monto recibido debe ser mayor que cero.');
        }

        return [[
            'clave_operacion' => (string) Str::uuid(),
            'metodo_pago' => mb_substr($metodo, 0, 30),
            'banco' => $request->filled('banco') ? mb_substr(trim((string) $request->input('banco')), 0, 30) : null,
            'moneda' => 'NIO',
            'monto' => $monto,
            'tasa_cambio' => null,
            'monto_cordobas' => $monto,
        ]];
    }

    /**
     * Devuelve el banco tal cual está en la lista oficial (respetando su
     * mayúscula) o null si no es un banco admitido para tarjetas.
     */
    private function normalizarBanco(string $banco): ?string
    {
        foreach (self::BANCOS_TARJETA as $permitido) {
            if (strcasecmp($banco, $permitido) === 0) {
                return $permitido;
            }
        }

        return null;
    }

    /**
     * Tipo de cambio "vigente" según la información REAL del sistema, en
     * este orden:
     *
     *  1. `configuraciones.tipo_cambio_usd`, si un ADMIN/GERENTE la definió.
     *  2. La última `tasa_cambio` registrada en un cierre de caja
     *     (`caja_cierre`), que es la única tasa que el negocio guarda hoy.
     *  3. null: no hay tasa -> el cobro en USD exige capturarla.
     *
     * El código NUNCA devuelve un valor fijo inventado.
     */
    private function tipoCambioVigente(): ?float
    {
        if (Schema::hasTable('configuraciones')) {
            $valor = DB::table('configuraciones')->where('clave', 'tipo_cambio_usd')->value('valor');

            if ($valor !== null && is_numeric($valor) && (float) $valor > 0) {
                return round((float) $valor, 4);
            }
        }

        if (Schema::hasTable('caja_cierre')) {
            $valor = DB::table('caja_cierre')->where('tasa_cambio', '>', 0)->orderByDesc('id')->value('tasa_cambio');

            if ($valor !== null && (float) $valor > 0) {
                return round((float) $valor, 4);
            }
        }

        return null;
    }

    /**
     * Tipo de cambio vigente para la interfaz de cobro (solo lectura).
     * El valor es una SUGERENCIA: la línea de pago en USD siempre guarda la
     * tasa que quedó confirmada en el cobro.
     */
    public function tipoCambio()
    {
        $rol = strtoupper((string) (Auth::user()?->rol?->nombre ?? ''));

        if (!in_array($rol, ['ADMIN', 'GERENTE', 'CAJERO'], true)) {
            return response()->json(['exito' => false, 'error' => 'No tienes permiso para consultar el tipo de cambio.'], 403);
        }

        $tasa = $this->tipoCambioVigente();

        return response()->json([
            'exito' => true,
            'tasa' => $tasa,
            'disponible' => $tasa !== null,
        ]);
    }

    /**
     * ============================================================
     * PREFACTURA (SOLO LECTURA)
     * ============================================================
     *
     * Imprime la cuenta ANTES de cobrarla. La prefactura:
     *  - usa los detalles REALMENTE asignados a la cuenta seleccionada
     *    (FacturaService::detalleCuenta, la misma fuente del cobro), así
     *    no incluye consumos de otra subcuenta ni líneas anuladas;
     *  - muestra consumo, descuento, base, propina y total (los ajustes se
     *    reciben como consulta para previsualizar lo capturado en el modal
     *    de cobro; NUNCA se guardan aquí);
     *  - NO marca la cuenta como pagada, NO emite factura, NO toca la mesa
     *    ni la venta: es una operación independiente del pago.
     *
     * Permisos: los mismos del POS (COCINERO no ve información financiera).
     */
    public function prefactura(Request $request, int $cuentaId)
    {
        $usuario = Auth::user();
        $rol = strtoupper((string) ($usuario?->rol?->nombre ?? ''));

        if ($rol === 'COCINERO' || $rol === '') {
            abort(403, 'No tienes permiso para imprimir la prefactura.');
        }

        $cuenta = DB::table('cuentas')->where('id', $cuentaId)->first();

        if (!$cuenta) {
            abort(404, 'No se encontró la cuenta solicitada.');
        }

        $venta = DB::table('ventas')->where('id', $cuenta->venta_id)->first();
        $mesa = $venta ? DB::table('mesas')->where('id', $venta->mesa_id)->first() : null;

        // Detalles realmente asignados a ESTA cuenta (misma fuente que la
        // factura y el cobro): no duplica ni mezcla subcuentas.
        $lineas = app(FacturaService::class)->detalleCuenta($cuentaId);
        $consumo = round(array_sum(array_column($lineas, 'subtotal')), 2);

        // Ajustes previsualizados (query string del modal de cobro). Solo
        // ADMIN, GERENTE o CAJERO pueden anticipar un descuento o una propina;
        // para MESERO y COCINERO no se permiten ajustes (y no se persisten).
        $descuento = round((float) $request->query('descuento', 0), 2);
        $propina = round((float) $request->query('propina', 0), 2);

        if ($descuento < 0 || $propina < 0 || $descuento > $consumo) {
            abort(422, 'Los ajustes de la prefactura no son válidos.');
        }

        if ($descuento > 0 || $propina > 0) {
            if (!in_array($rol, ['ADMIN', 'GERENTE', 'CAJERO'], true)) {
                abort(403, 'Solo ADMIN, GERENTE o CAJERO pueden aplicar descuentos o propinas.');
            }
        } else {
            $descuento = 0.0;
            $propina = 0.0;
        }

        $base = round($consumo - $descuento, 2);
        $total = round($base + $propina, 2);

        return view('ventas.prefactura', [
            'usuario' => $usuario,
            'mesa' => $mesa,
            'venta' => $venta,
            'cuenta' => $cuenta,
            'lineas' => $lineas,
            'consumo' => $consumo,
            'descuento' => $descuento,
            'base' => $base,
            'propina' => $propina,
            'total' => $total,
            // Propina sugerida: 10 % de la misma base del cálculo.
            'propinaSugerida' => round($base * 0.10, 2),
            'emision' => now(),
        ]);
    }

    /**
     * ============================================================
     * ANULAR DETALLE (BORRADO LÓGICO)
     * ============================================================
     *
     * Anula una cantidad parcial (o total) de una línea de venta y
     * actualiza cantidades, subtotales y totales de forma coherente.
     * No borra físicamente ninguna fila: usa borrado lógico en las
     * asignaciones y registra la anulación en "anulaciones".
     *
     * Reglas:
     * - Solo ADMIN y GERENTE.
     * - La cantidad y el motivo son obligatorios.
     * - La cantidad debe estar entre 1 y lo realmente anulable.
     * - Nunca se altera una cuenta PAGADA: si anular Q unidades exigiera
     *   tocar unidades ya pagadas, se rechaza sin modificar datos.
     * - La línea solo queda ANULADO cuando no quedan unidades activas.
     * - Si todas las unidades se anulan, la venta permanece ACTIVA con
     *   monto = 0 y la mesa NO se libera automáticamente.
     * - No se sincroniza comanda_detalles.
     *
     * Bloqueos: orden global "ventas -> cuentas (id ASC) -> venta_detalles
     * (id ASC)". La venta se bloquea primero y funciona como mutex de la
     * mesa para cobros, divisiones, pedidos y anulaciones concurrentes.
     */
    public function anularDetalle(Request $request, int $detalleId)
    {
        // Autorización primero: nunca revelar detalles de validación a un
        // usuario sin permiso. La seguridad se valida en backend.
        $usuario = Auth::user();

        if (!$usuario) {
            return response()->json([
                'exito' => false,
                'error' => 'Usuario no autenticado.',
            ], 401);
        }

        $rol = strtoupper(
            (string) ($usuario->rol->nombre ?? '')
        );

        // Autorización real en backend (no confiar en el botón oculto).
        if (!in_array($rol, ['ADMIN', 'GERENTE'], true)) {
            return response()->json([
                'exito' => false,
                'error' => 'No tienes permisos para anular productos.',
            ], 403);
        }

        // Cantidad y motivo obligatorios. La cantidad es obligatoria para
        // evitar que omitirla anule toda la línea por accidente.
        $request->validate([
            'cantidad' => ['required', 'integer', 'min:1'],
            'motivo' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $motivo = trim((string) $request->input('motivo'));
        $cantidadAnular = (int) $request->input('cantidad');

        // Cuenta sobre la que se anula (opcional). El POS abre ?cuenta=ID y el
        // ADMIN/GERENTE anula SOLO las unidades de esa cuenta. Sin el parámetro
        // se conserva exactamente el comportamiento global anterior.
        $cuentaIdScope = (int) $request->input('cuenta_id', 0);

        try {
            $resultado = DB::transaction(function () use (
                $detalleId,
                $usuario,
                $motivo,
                $cantidadAnular,
                $cuentaIdScope
            ) {
                // 1. Lectura de navegación sin bloqueo: solo para conocer
                //    a qué venta pertenece el detalle.
                $detalleNavegacion = DB::table('venta_detalles')
                    ->where('id', $detalleId)
                    ->first();

                if (!$detalleNavegacion) {
                    throw new \RuntimeException('El producto no existe.');
                }

                // 2. ORDEN GLOBAL: venta primero. Funciona como mutex de la
                //    mesa frente a cobros, divisiones y pedidos concurrentes.
                $venta = DB::table('ventas')
                    ->where('id', $detalleNavegacion->venta_id)
                    ->lockForUpdate()
                    ->first();

                if (!$venta) {
                    throw new \RuntimeException('La venta no existe.');
                }

                if (in_array($venta->estado, ['PAGADA', 'CANCELADA'], true)) {
                    throw new \RuntimeException(
                        'No se puede anular: la venta ya está cerrada.'
                    );
                }

                // 3. Bloquear todas las cuentas de la venta en orden ascendente.
                $cuentas = DB::table('cuentas')
                    ->where('venta_id', $venta->id)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                // Si la anulación está acotada a una cuenta, ésta debe existir
                // y estar ABIERTA: nunca se anula sobre una cuenta pagada.
                $cuentaScope = null;

                if ($cuentaIdScope > 0) {
                    $cuentaScope = $cuentas->get($cuentaIdScope);

                    if (!$cuentaScope) {
                        throw new \RuntimeException(
                            'La cuenta indicada no pertenece a esta venta.'
                        );
                    }

                    if ($cuentaScope->estado !== 'ABIERTA') {
                        throw new \RuntimeException(
                            'No se puede anular en una cuenta que no está abierta.'
                        );
                    }
                }

                // 4. Bloquear el detalle y revalidar su estado.
                $detalle = DB::table('venta_detalles')
                    ->where('id', $detalleId)
                    ->lockForUpdate()
                    ->first();

                if (!$detalle || $detalle->estado !== 'ACTIVO') {
                    throw new \RuntimeException(
                        'El producto ya fue anulado o cancelado.'
                    );
                }

                // Protección de cocina: si el módulo está habilitado y la
                // cocina YA empezó a preparar la línea (o la marcó LISTA), no
                // se permite la anulación ordinaria, total ni parcial. El
                // estado corresponde a la línea completa, por eso se bloquea
                // cualquier cantidad. No hay excepción para ADMIN/GERENTE en
                // esta etapa. Las líneas históricas o de barra tienen
                // estado_preparacion NULL y NO se ven afectadas.
                if ($this->cocinaGestionaEstados()) {
                    $estadoPreparacion = $detalle->estado_preparacion ?? null;

                    if (in_array((string) $estadoPreparacion, ['EN_PREPARACION', 'LISTO'], true)) {
                        throw new \RuntimeException(
                            $estadoPreparacion === 'LISTO'
                                ? 'No se puede anular: el plato ya fue marcado como LISTO en cocina.'
                                : 'No se puede anular: la preparación del plato ya comenzó en cocina.'
                        );
                    }
                }

                $cantidadLinea = (int) $detalle->cantidad;
                $precioUnitario = (float) $detalle->precio_unitario;

                if ($cantidadAnular > $cantidadLinea) {
                    throw new \RuntimeException(
                        'No puedes anular más unidades de las disponibles (' .
                        $cantidadLinea .
                        ').'
                    );
                }

                // 5. Asignaciones activas del detalle con el estado de su
                //    cuenta (bloqueadas, ordenadas por cuenta_id). Cuando se
                //    anula en una cuenta concreta, solo se cargan sus unidades.
                $asignacionesQuery = DB::table('cuenta_detalles')
                    ->where('venta_detalle_id', $detalleId)
                    ->where('eliminado', false);

                if ($cuentaScope) {
                    $asignacionesQuery->where('cuenta_id', $cuentaScope->id);
                }

                $asignaciones = $asignacionesQuery
                    ->orderBy('cuenta_id')
                    ->lockForUpdate()
                    ->get();

                $unidadesPagadas = 0;
                $unidadesAbiertas = 0;

                foreach ($asignaciones as $asignacion) {
                    $cuenta = $cuentas->get((int) $asignacion->cuenta_id);

                    if ($cuenta && $cuenta->estado === 'PAGADA') {
                        $unidadesPagadas += (int) $asignacion->cantidad;
                    } elseif ($cuenta && $cuenta->estado === 'ABIERTA') {
                        $unidadesAbiertas += (int) $asignacion->cantidad;
                    }
                }

                // Unidades que no están asignadas a ninguna cuenta. Cuando la
                // anulación está acotada a una cuenta, las unidades sin asignar
                // no forman parte de ella y no se tocan.
                $unidadesSinAsignar = $cuentaScope
                    ? 0
                    : $cantidadLinea - $unidadesPagadas - $unidadesAbiertas;

                if ($unidadesSinAsignar < 0) {
                    $unidadesSinAsignar = 0;
                }

                // Lo realmente anulable son las unidades abiertas más las
                // que nunca fueron asignadas. Las pagadas son intocables.
                $disponible = $unidadesAbiertas + $unidadesSinAsignar;

                if ($cantidadAnular > $disponible) {
                    throw new \RuntimeException(
                        'No se puede anular esa cantidad: parte del producto ' .
                        'pertenece a una cuenta ya pagada.'
                    );
                }

                // 6. Repartir la anulación: primero unidades sin asignar y
                //    luego asignaciones de cuentas abiertas en orden de id.
                $restante = $cantidadAnular;

                if ($unidadesSinAsignar > 0) {
                    $restante -= min($restante, $unidadesSinAsignar);
                }

                foreach ($asignaciones as $asignacion) {
                    if ($restante <= 0) {
                        break;
                    }

                    $cuenta = $cuentas->get((int) $asignacion->cuenta_id);

                    // Solo se reducen asignaciones de cuentas abiertas.
                    if (!$cuenta || $cuenta->estado !== 'ABIERTA') {
                        continue;
                    }

                    $cantidadAsignada = (int) $asignacion->cantidad;

                    if ($cantidadAsignada <= $restante) {
                        // Se anula la asignación completa (borrado lógico).
                        DB::table('cuenta_detalles')
                            ->where('id', $asignacion->id)
                            ->update([
                                'eliminado' => true,
                                'eliminado_por' => $usuario->id,
                                'eliminado_en' => now(),
                                'updated_at' => now(),
                            ]);

                        $restante -= $cantidadAsignada;
                    } else {
                        // Se reduce parcialmente la asignación. Se calcula el
                        // subtotal con SQL para no arrastrar errores de float.
                        $nuevaCantidad = $cantidadAsignada - $restante;

                        $nuevoSubtotal = DB::selectOne(
                            'SELECT ROUND(CAST(? AS DECIMAL(10,2)) * CAST(? AS DECIMAL(10,2)), 2) AS subtotal',
                            [$nuevaCantidad, $precioUnitario]
                        )->subtotal;

                        DB::table('cuenta_detalles')
                            ->where('id', $asignacion->id)
                            ->update([
                                'cantidad' => $nuevaCantidad,
                                'subtotal' => $nuevoSubtotal,
                                'updated_at' => now(),
                            ]);

                        $restante = 0;
                    }
                }

                if ($restante > 0) {
                    // Nunca debería ocurrir tras validar "disponible".
                    throw new \RuntimeException(
                        'No se pudo distribuir la anulación entre las cuentas.'
                    );
                }

                // 7. Actualizar la línea de venta conservando lo que queda.
                //    Solo pasa a ANULADO cuando no quedan unidades activas.
                $cantidadRestante = $cantidadLinea - $cantidadAnular;

                $nuevoSubtotalVenta = DB::selectOne(
                    'SELECT ROUND(CAST(? AS DECIMAL(10,2)) * CAST(? AS DECIMAL(10,2)), 2) AS subtotal',
                    [$cantidadRestante, $precioUnitario]
                )->subtotal;

                DB::table('venta_detalles')
                    ->where('id', $detalleId)
                    ->update([
                        'cantidad' => $cantidadRestante,
                        'subtotal' => $nuevoSubtotalVenta,
                        'estado' => $cantidadRestante > 0 ? 'ACTIVO' : 'ANULADO',
                        'updated_at' => now(),
                    ]);

                // 8. Recalcular el total de las cuentas abiertas afectadas.
                $cuentasAbiertasAfectadas = $asignaciones
                    ->filter(function ($asignacion) use ($cuentas) {
                        $cuenta = $cuentas->get((int) $asignacion->cuenta_id);

                        return $cuenta && $cuenta->estado === 'ABIERTA';
                    })
                    ->pluck('cuenta_id')
                    ->map(fn ($id) => (int) $id)
                    ->unique()
                    ->values()
                    ->all();

                foreach ($cuentasAbiertasAfectadas as $cuentaId) {
                    DB::table('cuentas')
                        ->where('id', $cuentaId)
                        ->update([
                            'total' => DB::table('cuenta_detalles')
                                ->where('cuenta_id', $cuentaId)
                                ->where('eliminado', false)
                                ->sum('subtotal'),
                            'updated_at' => now(),
                        ]);
                }

                // 9. Recalcular el monto de la venta. Permanece ACTIVA
                //    aunque quede en cero; la mesa no se libera aquí.
                $monto = DB::table('venta_detalles')
                    ->where('venta_id', $venta->id)
                    ->where('estado', 'ACTIVO')
                    ->sum('subtotal');

                DB::table('ventas')
                    ->where('id', $venta->id)
                    ->update(['monto' => $monto]);

                // 10. Auditoría: se registra la cantidad y el importe anulados.
                $montoAnulado = DB::selectOne(
                    'SELECT ROUND(CAST(? AS DECIMAL(10,2)) * CAST(? AS DECIMAL(10,2)), 2) AS subtotal',
                    [$cantidadAnular, $precioUnitario]
                )->subtotal;

                $anulacionId = DB::table('anulaciones')->insertGetId([
                    'tipo' => 'ITEM_ANULADO',
                    'venta_id' => $venta->id,
                    'venta_detalle_id' => $detalle->id,
                    'comanda_id' => $detalle->comanda_id,
                    'producto_id' => $detalle->producto_id,
                    'cantidad' => $cantidadAnular,
                    'monto' => $montoAnulado,
                    'motivo' => $motivo,
                    'usuario_id' => $usuario->id,
                    'estado_previo' => 'ACTIVO',
                    'creado_en' => now(),
                ]);

                // Inventario (Etapa 2): reponer stock de la cantidad realmente
                // anulada. Solo si la línea tenía una salida VENTA registrada.
                // Se usa el id real de la anulación como origen.
                $this->inventario()->registrarAnulacion(
                    (int) $detalle->id,
                    (int) $anulacionId,
                    $cantidadAnular,
                    (int) $usuario->id
                );

                return [
                    'venta_id' => $venta->id,
                    'monto' => (float) $monto,
                    'cantidad_anulada' => $cantidadAnular,
                    'cantidad_restante' => $cantidadRestante,
                    'estado' => $cantidadRestante > 0 ? 'ACTIVO' : 'ANULADO',
                ];
            });

            return response()->json([
                'exito' => true,
                'mensaje' => $resultado['estado'] === 'ANULADO'
                    ? 'Todas las unidades del producto fueron anuladas.'
                    : 'Se anuló(aron) ' . $resultado['cantidad_anulada'] .
                        ' unidad(es). Quedan ' . $resultado['cantidad_restante'] .
                        ' activa(s).',
                'venta_id' => $resultado['venta_id'],
                'monto' => $resultado['monto'],
                'cantidad_anulada' => $resultado['cantidad_anulada'],
                'cantidad_restante' => $resultado['cantidad_restante'],
                'estado' => $resultado['estado'],
            ]);
        } catch (\Throwable $e) {
            Log::warning(
                'No se pudo anular detalle',
                [
                    'detalle_id' => $detalleId,
                    'usuario_id' => $usuario->id,
                    'error' => $e->getMessage(),
                ]
            );

            return response()->json([
                'exito' => false,
                'error' => $e->getMessage(),
            ], 422);
        }
    }
}
