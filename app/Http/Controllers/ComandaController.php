<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ComandaController extends Controller
{
    /**
     * ============================================================
     * ENVIAR PEDIDO A COCINA
     * ============================================================
     *
     * Recibe los productos enviados desde la mesa.
     *
     * IMPORTANTE:
     * - Los productos que no requieren cocina NO se imprimen.
     * - Se crea una comanda nueva cada vez que se envía un pedido.
     * - La comanda anterior permanece registrada.
     */
    public function enviarPedido(Request $request)
    {
        $request->validate([
            'mesa_id' => 'required|integer|exists:mesas,id',
            'venta_id' => 'nullable|integer|exists:ventas,id',
            'cliente_id' => 'nullable|integer|exists:clientes,id',
            'productos' => 'required|array|min:1',
            'productos.*.producto_id' => 'required|integer|exists:productos,id',
            'productos.*.cantidad' => 'required|numeric|min:0.01',
            'productos.*.notas' => 'nullable|string|max:500',
        ]);

        $usuario = Auth::user();

        if (!$usuario) {
            return response()->json([
                'exito' => false,
                'mensaje' => 'La sesión del usuario ha expirado.'
            ], 401);
        }

        DB::beginTransaction();

        try {

            /*
             * ======================================================
             * 1. BUSCAR LA VENTA
             * ======================================================
             */

            $venta = null;

            if ($request->filled('venta_id')) {

                $venta = DB::table('ventas')
                    ->where('id', $request->venta_id)
                    ->lockForUpdate()
                    ->first();

                if (!$venta) {
                    throw new \Exception('La venta no existe.');
                }

            } else {

                /*
                 * Si todavía no existe una venta para la mesa,
                 * creamos una.
                 */

                $ventaId = DB::table('ventas')->insertGetId([
                    'mesa_id' => $request->mesa_id,
                    'usuario_id' => Auth::id(),
                    'cliente_id' => $request->cliente_id,
                    'estado' => 'ABIERTA',
                    'total' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $venta = DB::table('ventas')
                    ->where('id', $ventaId)
                    ->first();
            }

            /*
             * ======================================================
             * 2. CREAR NÚMERO DE COMANDA
             * ======================================================
             */

            $ultimaComanda = DB::table('comandas')
                ->where('venta_id', $venta->id)
                ->orderByDesc('id')
                ->first();

            $numeroComanda = $ultimaComanda
                ? ((int) $ultimaComanda->numero_ticket + 1)
                : 1;

            /*
             * ======================================================
             * 3. CREAR COMANDA
             * ======================================================
             */

            $comandaId = DB::table('comandas')->insertGetId([
                'mesa_id' => $request->mesa_id,
                'venta_id' => $venta->id,
                'usuario_id' => Auth::id(),
                'cliente_id' => $request->cliente_id,
                'numero_ticket' => $numeroComanda,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            /*
             * ======================================================
             * 4. INSERTAR DETALLES
             * ======================================================
             */

            $productosCocina = [];

            foreach ($request->productos as $item) {

                $producto = DB::table('productos')
                    ->where('id', $item['producto_id'])
                    ->first();

                if (!$producto) {
                    throw new \Exception(
                        'El producto #' . $item['producto_id'] . ' no existe.'
                    );
                }

                $cantidad = (float) $item['cantidad'];
                $precio = (float) ($producto->precio ?? 0);
                $subtotal = $cantidad * $precio;

                DB::table('comanda_detalles')->insert([
                    'comanda_id' => $comandaId,
                    'producto_id' => $producto->id,
                    'cantidad' => $cantidad,
                    'precio_unitario' => $precio,
                    'subtotal' => $subtotal,
                    'estado' => 'PENDIENTE',
                    'eliminado_por' => null,
                    'eliminado_en' => null,
                    'notas' => $item['notas'] ?? null,
                    'impreso_cocina' => 0,
                    'estado_pago' => 'PENDIENTE',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                /*
                 * Solo mandamos a cocina los productos
                 * que requieren cocina.
                 */
                if ((int) ($producto->requiere_cocina ?? 0) === 1) {

                    $productosCocina[] = [
                        'producto_id' => $producto->id,
                        'nombre' => $producto->nombre,
                        'cantidad' => $cantidad,
                        'notas' => $item['notas'] ?? null,
                    ];
                }

                /*
                 * ==================================================
                 * ACTUALIZAR DETALLE DE LA VENTA
                 * ==================================================
                 */

                DB::table('venta_detalles')->insert([
                    'venta_id' => $venta->id,
                    'producto_id' => $producto->id,
                    'cantidad' => $cantidad,
                    'precio_unitario' => $precio,
                    'subtotal' => $subtotal,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            /*
             * ======================================================
             * 5. RECALCULAR TOTAL
             * ======================================================
             */

            $totalVenta = DB::table('venta_detalles')
                ->where('venta_id', $venta->id)
                ->sum('subtotal');

            DB::table('ventas')
                ->where('id', $venta->id)
                ->update([
                    'total' => $totalVenta,
                    'updated_at' => now(),
                ]);

            /*
             * ======================================================
             * 6. ACTUALIZAR MESA
             * ======================================================
             */

            DB::table('mesas')
                ->where('id', $request->mesa_id)
                ->update([
                    'estado' => 'OCUPADA',
                    'updated_at' => now(),
                ]);

            DB::commit();

            return response()->json([
                'exito' => true,
                'mensaje' => 'Pedido enviado correctamente.',
                'comanda_id' => $comandaId,
                'numero_comanda' => $numeroComanda,
                'venta_id' => $venta->id,
                'productos_cocina' => $productosCocina,
                'requiere_impresion_cocina' => count($productosCocina) > 0,
            ]);

        } catch (\Throwable $e) {

            DB::rollBack();

            Log::error('Error al enviar pedido', [
                'usuario_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'exito' => false,
                'mensaje' => 'No se pudo enviar el pedido.',
                'error' => config('app.debug')
                    ? $e->getMessage()
                    : null,
            ], 500);
        }
    }


    /**
     * ============================================================
     * IMPRIMIR UNA COMANDA
     * ============================================================
     */
    public function imprimir(int $id)
    {
        $comanda = DB::table('comandas')
            ->join('mesas', 'mesas.id', '=', 'comandas.mesa_id')
            ->where('comandas.id', $id)
            ->select(
                'comandas.*',
                'mesas.numero as mesa_numero'
            )
            ->first();

        if (!$comanda) {
            abort(404, 'Comanda no encontrada.');
        }

        /*
         * Solo mostramos productos que requieren cocina.
         */
        $detalles = DB::table('comanda_detalles')
            ->join(
                'productos',
                'productos.id',
                '=',
                'comanda_detalles.producto_id'
            )
            ->where('comanda_detalles.comanda_id', $id)
            ->whereNull('comanda_detalles.eliminado_en')
            ->where('productos.requiere_cocina', 1)
            ->select(
                'comanda_detalles.*',
                'productos.nombre'
            )
            ->get();

        return view('comandas.imprimir', compact(
            'comanda',
            'detalles'
        ));
    }


    /**
     * ============================================================
     * IMPRIMIR TODAS LAS COMANDAS DE UNA VENTA
     * ============================================================
     */
    public function imprimirTodas(int $ventaId)
    {
        $venta = DB::table('ventas')
            ->join('mesas', 'mesas.id', '=', 'ventas.mesa_id')
            ->where('ventas.id', $ventaId)
            ->select(
                'ventas.*',
                'mesas.numero as mesa_numero'
            )
            ->first();

        if (!$venta) {
            abort(404, 'Venta no encontrada.');
        }

        $comandas = DB::table('comandas')
            ->where('venta_id', $ventaId)
            ->orderBy('id')
            ->get();

        foreach ($comandas as $comanda) {

            $comanda->detalles = DB::table('comanda_detalles')
                ->join(
                    'productos',
                    'productos.id',
                    '=',
                    'comanda_detalles.producto_id'
                )
                ->where(
                    'comanda_detalles.comanda_id',
                    $comanda->id
                )
                ->whereNull('comanda_detalles.eliminado_en')
                ->where('productos.requiere_cocina', 1)
                ->select(
                    'comanda_detalles.*',
                    'productos.nombre'
                )
                ->get();
        }

        return view(
            'comandas.imprimir-todas',
            compact('venta', 'comandas')
        );
    }
}