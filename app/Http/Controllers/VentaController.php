<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class VentaController extends Controller
{
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

                    // Producto.
                    'productos.nombre',

                    // Fuente de verdad para saber si va a cocina.
                    'productos.requiere_cocina',

                    // Número de ticket de la comanda.
                    'comandas.numero_ticket'
                )

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
                foreach ($carrito as $item) {
                    $producto = DB::table('productos')->where('id', (int) ($item['id'] ?? 0))->where('activo', 1)->first();
                    $cantidad = (int) ($item['cantidad'] ?? 0);
                    if (!$producto || $cantidad <= 0) throw new \RuntimeException('El pedido contiene un producto inválido.');
                    $subtotal = $cantidad * (float) $producto->precio;
                    $requiereCocina = ((int) $producto->requiere_cocina === 1);
                    if ($requiereCocina) {
                        $hayCocina = true;
                        $totalCocina += $subtotal;
                    }
                    $items[] = ['producto' => $producto, 'cantidad' => $cantidad, 'subtotal' => $subtotal, 'cocina' => $requiereCocina];
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
                    $detalleId = DB::table('venta_detalles')->insertGetId(['venta_id' => $venta->id, 'producto_id' => $producto->id, 'cantidad' => $cantidad, 'precio_unitario' => $producto->precio, 'subtotal' => $subtotal, 'comanda_id' => $detalleComandaId, 'estado' => 'ACTIVO', 'created_at' => now(), 'updated_at' => now()]);
                    DB::table('cuenta_detalles')->insert(['cuenta_id' => $cuenta->id, 'venta_id' => $venta->id, 'venta_detalle_id' => $detalleId, 'cantidad' => $cantidad, 'subtotal' => $subtotal, 'created_at' => now(), 'updated_at' => now()]);
                }

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

                    DB::table('venta_detalles')
                        ->where('venta_id', $ventaId)
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get();


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

            DB::transaction(
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


                    if (!$venta) {

                        throw new \Exception(
                            'La venta no existe.'
                        );
                    }


                    // Verificar si existen productos activos.
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
                            ->count();


                    if ($cantidadDetalles > 0) {

                        throw new \Exception(
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

                        throw new \Exception(
                            'No se puede liberar la mesa porque hay consumos pendientes en sus cuentas.'
                        );
                    }

                    $hayCuentasConSaldo = DB::table('cuentas')
                        ->where('venta_id', $ventaId)
                        ->where('estado', 'ABIERTA')
                        ->where('total', '>', 0)
                        ->count();

                    if ($hayCuentasConSaldo > 0) {

                        throw new \Exception(
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
                }
            );


            return response()->json([
                'exito' => true,
                'mensaje' =>
                    'La mesa fue liberada correctamente.',
            ]);


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

        // Cuenta representativa para el traslado de la MESA COMPLETA. El POS
        // maneja una venta por mesa, así que mover una subcuenta de forma
        // individual no es posible con el esquema actual; el backend mueve
        // TODA la venta (todas las cuentas) a la mesa destino. Solo se
        // necesita una cuenta ABIERTA válida de la venta como referencia.
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
            // Cuenta ABIERTA de referencia para trasladar la venta completa.
            'cuentaTrasladoId' => $cuentaTrasladoId,
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
                //    trasladada, no por el mero hecho de moverla.
                $tieneConsumos = DB::table('venta_detalles')
                    ->where('venta_id', $venta->id)
                    ->where('estado', 'ACTIVO')
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

    /** Cobra una cuenta y libera la mesa únicamente al pagar todas las cuentas. */
    public function pagarCuenta(Request $request, int $cuentaId)
    {
        $request->validate(['metodo_pago' => ['required', 'string', 'max:30'], 'monto_recibido' => ['required', 'numeric', 'min:0'], 'banco' => ['nullable', 'string', 'max:30']]);
        $rol = strtoupper((string) (Auth::user()?->rol?->nombre ?? ''));
        if (!in_array($rol, ['ADMIN', 'GERENTE', 'CAJERO'], true)) return response()->json(['exito' => false, 'error' => 'No tienes permiso para cobrar.'], 403);

        try {
            $resultado = DB::transaction(function () use ($request, $cuentaId) {
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

                $cuenta = $cuentas->get($cuentaId);
                if (!$cuenta || $cuenta->estado !== 'ABIERTA') throw new \RuntimeException('La cuenta no está disponible para cobro.');

                $total = (float) DB::table('cuenta_detalles')->where('cuenta_id', $cuentaId)->where('eliminado', false)->sum('subtotal');
                $recibido = (float) $request->input('monto_recibido');
                if ($total <= 0 || $recibido < $total) throw new \RuntimeException('El monto recibido no cubre el total de la cuenta.');
                $cambio = $recibido - $total;
                DB::table('cuentas')->where('id', $cuentaId)->update(['total' => $total, 'estado' => 'PAGADA', 'metodo_pago' => $request->input('metodo_pago'), 'banco' => $request->input('banco'), 'monto_recibido' => $recibido, 'cambio' => $cambio, 'usuario_cobro' => Auth::id(), 'updated_at' => now()]);
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
                return ['cambio' => $cambio, 'mesa_liberada' => !$abiertas];
            });
            return response()->json(['exito' => true, 'mensaje' => $resultado['mesa_liberada'] ? 'Todas las cuentas fueron pagadas. Mesa liberada.' : 'Cuenta pagada. La mesa sigue abierta.', ...$resultado]);
        } catch (\Throwable $e) {
            return response()->json(['exito' => false, 'error' => $e->getMessage()], 422);
        }
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

                DB::table('anulaciones')->insert([
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
