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
                ->where(
                    'ventas.estado',
                    'ACTIVA'
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
                // MARCAR LA MESA COMO OCUPADA
                // ------------------------------------------------

                DB::table('mesas')
                    ->where('id', $mesaId)
                    ->update([
                        'estado' => 'OCUPADA',
                    ]);

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

                // Total.
                'total' => $total,
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
                $venta = DB::table('ventas')->where('id', (int) $request->input('venta_id'))->where('estado', 'ACTIVA')->lockForUpdate()->first();
                if (!$venta) throw new \RuntimeException('La venta ya no está activa.');
                $cuenta = DB::table('cuentas')->where('venta_id', $venta->id)->where('estado', 'ABIERTA')->orderBy('id')->first();
                if (!$cuenta) throw new \RuntimeException('No existe una cuenta abierta para agregar el pedido.');
                $ultimoTicket = (int) (DB::table('comandas')->where('venta_id', $venta->id)->max('numero_ticket') ?? 0);
                $comandaId = DB::table('comandas')->insertGetId(['mesa_id' => $venta->mesa_id, 'venta_id' => $venta->id, 'numero_ticket' => $ultimoTicket + 1, 'usuario_id' => Auth::id(), 'cliente_id' => $venta->cliente_id, 'descripcion' => null, 'estado' => 'ABIERTA', 'total' => 0, 'creado_en' => now(), 'conteo_impresiones' => 0]);
                $totalComanda = 0;
                foreach ($carrito as $item) {
                    $producto = DB::table('productos')->where('id', (int) ($item['id'] ?? 0))->where('activo', 1)->first();
                    $cantidad = (int) ($item['cantidad'] ?? 0);
                    if (!$producto || $cantidad <= 0) throw new \RuntimeException('El pedido contiene un producto inválido.');
                    $subtotal = $cantidad * (float) $producto->precio;
                    DB::table('comanda_detalles')->insert(['comanda_id' => $comandaId, 'producto_id' => $producto->id, 'cantidad' => $cantidad, 'precio_unitario' => $producto->precio, 'subtotal' => $subtotal, 'estado' => 'ACTIVO', 'impreso_cocina' => 0, 'estado_pago' => 'PENDIENTE']);
                    $detalleId = DB::table('venta_detalles')->insertGetId(['venta_id' => $venta->id, 'producto_id' => $producto->id, 'cantidad' => $cantidad, 'precio_unitario' => $producto->precio, 'subtotal' => $subtotal, 'comanda_id' => $comandaId, 'estado' => 'ACTIVO', 'created_at' => now(), 'updated_at' => now()]);
                    DB::table('cuenta_detalles')->insert(['cuenta_id' => $cuenta->id, 'venta_id' => $venta->id, 'venta_detalle_id' => $detalleId, 'cantidad' => $cantidad, 'subtotal' => $subtotal, 'created_at' => now(), 'updated_at' => now()]);
                    $totalComanda += $subtotal;
                }
                DB::table('comandas')->where('id', $comandaId)->update(['total' => $totalComanda]);
                DB::table('cuentas')->where('id', $cuenta->id)->update(['total' => DB::table('cuenta_detalles')->where('cuenta_id', $cuenta->id)->sum('subtotal'), 'updated_at' => now()]);
                DB::table('ventas')->where('id', $venta->id)->update(['monto' => DB::table('venta_detalles')->where('venta_id', $venta->id)->where('estado', 'ACTIVO')->sum('subtotal')]);
                return $comandaId;
            });
            return response()->json(['exito' => true, 'mensaje' => 'Pedido guardado correctamente.', 'comanda_id' => $comandaId]);
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
                ->where('estado', 'ACTIVA')
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

                    // Buscar mesa.
                    $venta = DB::table('ventas')
                        ->where(
                            'id',
                            $ventaId
                        )
                        ->first();


                    if (!$venta) {

                        throw new \Exception(
                            'La venta no existe.'
                        );
                    }


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

                    // Buscar venta.
                    $venta = DB::table('ventas')
                        ->where(
                            'id',
                            $ventaId
                        )
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

    /** Datos seguros para la pantalla de división y cobro por cuenta. */
    public function cuentas(int $mesaId)
    {
        $venta = DB::table('ventas')->where('mesa_id', $mesaId)->where('estado', 'ACTIVA')->first();

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
        $asignaciones = DB::table('cuenta_detalles')
            ->join('cuentas', 'cuentas.id', '=', 'cuenta_detalles.cuenta_id')
            ->where('cuentas.venta_id', $venta->id)
            ->select('cuenta_detalles.cuenta_id', 'cuenta_detalles.venta_detalle_id', 'cuenta_detalles.cantidad')
            ->get();

        return response()->json(compact('venta', 'detalles', 'cuentas', 'asignaciones') + ['exito' => true]);
    }

    /** Guarda una división completa. Las cuentas ya pagadas no se pueden modificar. */
    public function guardarDivision(Request $request, int $mesaId)
    {
        $request->validate([
            'cuentas_nuevas' => ['array'],
            'cuentas_nuevas.*' => ['string', 'max:100'],
            'asignaciones' => ['required', 'array', 'min:1'],
            'asignaciones.*.cuenta_id' => ['required', 'integer'],
            'asignaciones.*.venta_detalle_id' => ['required', 'integer'],
            'asignaciones.*.cantidad' => ['required', 'integer', 'min:1'],
        ]);

        try {
            DB::transaction(function () use ($request, $mesaId) {
                $venta = DB::table('ventas')->where('mesa_id', $mesaId)->where('estado', 'ACTIVA')->lockForUpdate()->first();
                if (!$venta) {
                    throw new \RuntimeException('La mesa no tiene una venta activa.');
                }

                $nombresNuevos = [];
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

                        DB::table('cuentas')->insert(['venta_id' => $venta->id, 'nombre' => $nombre, 'total' => 0, 'estado' => 'ABIERTA', 'created_at' => now(), 'updated_at' => now()]);
                        $nombresNuevos[] = $claveNombre;
                    }
                }

                $cuentasAbiertas = DB::table('cuentas')
                    ->where('venta_id', $venta->id)
                    ->where('estado', 'ABIERTA')
                    ->lockForUpdate()
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->all();
                $detalles = DB::table('venta_detalles')->where('venta_id', $venta->id)->where('estado', 'ACTIVO')->lockForUpdate()->get()->keyBy('id');

                // Se reemplazan solo asignaciones de cuentas abiertas; lo pagado queda congelado.
                DB::table('cuenta_detalles')->whereIn('cuenta_id', $cuentasAbiertas)->delete();
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
                    $cantidades[$clave] = $cantidad;
                    $subtotal = DB::selectOne(
                        'SELECT ROUND(CAST(? AS DECIMAL(10,2)) * CAST(? AS DECIMAL(10,2)), 2) AS subtotal',
                        [$cantidad, $detalles[$detalleId]->precio_unitario]
                    )->subtotal;

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

                foreach ($detalles as $detalle) {
                    $asignado = DB::table('cuenta_detalles')->where('venta_detalle_id', $detalle->id)->sum('cantidad');
                    if ((int) $asignado !== (int) $detalle->cantidad) throw new \RuntimeException('Debes asignar exactamente todas las unidades de ' . $detalle->id . '.');
                }
                foreach ($cuentasAbiertas as $cuentaId) {
                    DB::table('cuentas')->where('id', $cuentaId)->update(['total' => DB::table('cuenta_detalles')->where('cuenta_id', $cuentaId)->sum('subtotal'), 'updated_at' => now()]);
                }
            });
            return response()->json(['exito' => true, 'mensaje' => 'División guardada. Ya puedes cobrar cuentas por separado.']);
        } catch (\Throwable $e) {
            Log::warning('No se pudo dividir la cuenta', ['mesa_id' => $mesaId, 'error' => $e->getMessage()]);
            return response()->json(['exito' => false, 'error' => $e->getMessage()], 422);
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
                $cuenta = DB::table('cuentas')->where('id', $cuentaId)->lockForUpdate()->first();
                if (!$cuenta || $cuenta->estado !== 'ABIERTA') throw new \RuntimeException('La cuenta no está disponible para cobro.');
                $total = (float) DB::table('cuenta_detalles')->where('cuenta_id', $cuentaId)->sum('subtotal');
                $recibido = (float) $request->input('monto_recibido');
                if ($total <= 0 || $recibido < $total) throw new \RuntimeException('El monto recibido no cubre el total de la cuenta.');
                $cambio = $recibido - $total;
                DB::table('cuentas')->where('id', $cuentaId)->update(['total' => $total, 'estado' => 'PAGADA', 'metodo_pago' => $request->input('metodo_pago'), 'banco' => $request->input('banco'), 'monto_recibido' => $recibido, 'cambio' => $cambio, 'usuario_cobro' => Auth::id(), 'updated_at' => now()]);
                // Una cuenta vacía no debe impedir el cierre: solo importan
                // las cuentas abiertas que todavía tienen consumo pendiente.
                $abiertas = DB::table('cuentas')
                    ->where('venta_id', $cuenta->venta_id)
                    ->where('estado', 'ABIERTA')
                    ->where('total', '>', 0)
                    ->exists();
                if (!$abiertas) {
                    $venta = DB::table('ventas')->where('id', $cuenta->venta_id)->lockForUpdate()->first();
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
}
