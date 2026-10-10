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
         * Subcuenta opcional (?cuenta=):
         * - Sin el parámetro: reimpresión HISTÓRICA. Muestra la cantidad
         *   completa que tenía la línea cuando se envió la comanda.
         * - Con una cuenta válida de la misma venta: PEDIDO ACTUAL de esa
         *   subcuenta. Muestra solo las unidades asignadas a ella (según
         *   cuenta_detalles), no la línea completa.
         */
        $cuentaId = (int) request()->query('cuenta', 0);
        $cuentaValida = $cuentaId > 0
            && DB::table('cuentas')
                ->where('id', $cuentaId)
                ->where('venta_id', $comanda->venta_id)
                ->where('estado', '!=', 'CANCELADA')
                ->exists();
        $cuentaId = $cuentaValida ? $cuentaId : 0;

        /*
         * Los productos de la comanda viven en venta_detalles, enlazados
         * mediante comanda_id. Se incluyen únicamente los detalles activos.
         * Solo se muestran los productos que requieren cocina.
         */
        $detallesQuery = DB::table('venta_detalles')
            ->join(
                'productos',
                'productos.id',
                '=',
                'venta_detalles.producto_id'
            )
            ->where('venta_detalles.comanda_id', $id)
            ->where('venta_detalles.estado', 'ACTIVO')
            ->where('productos.requiere_cocina', 1);

        if ($cuentaId > 0) {
            $detallesQuery
                ->join('cuenta_detalles', function ($join) use ($cuentaId) {
                    $join->on(
                        'cuenta_detalles.venta_detalle_id',
                        '=',
                        'venta_detalles.id'
                    )
                        ->where('cuenta_detalles.cuenta_id', '=', $cuentaId)
                        ->where('cuenta_detalles.eliminado', '=', false);
                })
                ->select(
                    'cuenta_detalles.cantidad',
                    'productos.nombre'
                );
        } else {
            $detallesQuery->select(
                'venta_detalles.cantidad',
                'productos.nombre'
            );
        }

        $detalles = $detallesQuery
            ->orderBy('venta_detalles.id')
            ->get();

        return view('ventas.comanda_print', compact(
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

        // Subcuenta opcional (?cuenta=): cuando el POS está acotado a una
        // cuenta, la impresión masiva solo incluye platos asignados a ella y
        // con sus unidades (no la cantidad completa de la línea).
        $cuentaId = (int) request()->query('cuenta', 0);
        $cuentaValida = $cuentaId > 0
            && DB::table('cuentas')
                ->where('id', $cuentaId)
                ->where('venta_id', $ventaId)
                ->where('estado', '!=', 'CANCELADA')
                ->exists();
        $cuentaId = $cuentaValida ? $cuentaId : 0;

        // Solo comandas vigentes: se excluyen las anuladas/canceladas de la
        // impresión global de pendientes. El historial sigue disponible para
        // la reimpresión individual.
        $comandas = DB::table('comandas')
            ->where('venta_id', $ventaId)
            ->whereNotIn('estado', ['ANULADA', 'CANCELADA'])
            ->orderBy('id')
            ->get();

        foreach ($comandas as $comanda) {

            // Los productos viven en venta_detalles, enlazados por comanda_id.
            $detallesQuery = DB::table('venta_detalles')
                ->join(
                    'productos',
                    'productos.id',
                    '=',
                    'venta_detalles.producto_id'
                )
                ->where(
                    'venta_detalles.comanda_id',
                    $comanda->id
                )
                ->where('venta_detalles.estado', 'ACTIVO')
                ->where('productos.requiere_cocina', 1);

            if ($cuentaId > 0) {
                $detallesQuery
                    ->join('cuenta_detalles', function ($join) use ($cuentaId) {
                        $join->on(
                            'cuenta_detalles.venta_detalle_id',
                            '=',
                            'venta_detalles.id'
                        )
                            ->where('cuenta_detalles.cuenta_id', '=', $cuentaId)
                            ->where('cuenta_detalles.eliminado', '=', false);
                    })
                    ->select(
                        'cuenta_detalles.cantidad',
                        'productos.nombre'
                    );
            } else {
                $detallesQuery->select(
                    'venta_detalles.cantidad',
                    'productos.nombre'
                );
            }

            $comanda->detalles = $detallesQuery
                ->orderBy('venta_detalles.id')
                ->get();
        }

        // Se omiten comandas sin productos válidos para cocina.
        $comandas = $comandas
            ->filter(fn ($comanda) => $comanda->detalles->isNotEmpty())
            ->values();

        return view(
            'ventas.comandas_masivas_print',
            compact('venta', 'comandas')
        );
    }


    /**
     * ============================================================
     * COMANDA COMPLETA DE COCINA
     * ============================================================
     *
     * Reúne en una sola hoja todos los productos que requieren
     * cocina de la venta actual, sumando las cantidades de las
     * distintas órdenes (comandas) que se hayan enviado.
     *
     * No incluye precios ni totales monetarios. No modifica
     * ninguna venta, comanda ni detalle: es solo de lectura.
     */
    public function imprimirCompleta(int $ventaId)
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

        // Subcuenta opcional (?cuenta=): la comanda completa consolidada se
        // limita a los platos asignados a esa cuenta, con sus unidades.
        $cuentaId = (int) request()->query('cuenta', 0);
        $cuentaValida = $cuentaId > 0
            && DB::table('cuentas')
                ->where('id', $cuentaId)
                ->where('venta_id', $ventaId)
                ->where('estado', '!=', 'CANCELADA')
                ->exists();
        $cuentaId = $cuentaValida ? $cuentaId : 0;

        /*
         * Se consolidan todos los detalles ACTIVOS (los cancelados quedan
         * fuera porque su estado pasa a CANCELADO) cuyo producto requiere
         * cocina. Se agrupa por producto para sumar las cantidades de las
         * distintas órdenes sin duplicar.
         */
        $detallesQuery = DB::table('venta_detalles')
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
            ->where('venta_detalles.estado', 'ACTIVO')
            ->where('productos.requiere_cocina', 1);

        if ($cuentaId > 0) {
            $detallesQuery
                ->join('cuenta_detalles', function ($join) use ($cuentaId) {
                    $join->on(
                        'cuenta_detalles.venta_detalle_id',
                        '=',
                        'venta_detalles.id'
                    )
                        ->where('cuenta_detalles.cuenta_id', '=', $cuentaId)
                        ->where('cuenta_detalles.eliminado', '=', false);
                })
                ->groupBy(
                    'venta_detalles.producto_id',
                    'productos.nombre'
                )
                ->select(
                    'venta_detalles.producto_id',
                    'productos.nombre',
                    DB::raw('SUM(cuenta_detalles.cantidad) as cantidad'),
                    DB::raw('MIN(venta_detalles.id) as primer_detalle')
                );
        } else {
            $detallesQuery
                ->groupBy(
                    'venta_detalles.producto_id',
                    'productos.nombre'
                )
                ->select(
                    'venta_detalles.producto_id',
                    'productos.nombre',
                    DB::raw('SUM(venta_detalles.cantidad) as cantidad'),
                    DB::raw('MIN(venta_detalles.id) as primer_detalle')
                );
        }

        $detalles = $detallesQuery
            ->orderBy('primer_detalle')
            ->get();

        return view(
            'ventas.comanda_completa_print',
            compact('venta', 'detalles')
        );
    }


    /**
     * ============================================================
     * REIMPRIMIR UN PLATO INDIVIDUAL
     * ============================================================
     *
     * Reimprime únicamente el plato seleccionado desde el historial
     * de la comanda, sin imprimir los demás platos de la misma orden.
     *
     * Es de solo lectura: no crea comandas, no duplica detalles ni
     * modifica estados, cantidades o contadores.
     *
     * Solo permite platos de cocina activos (requiere_cocina = 1).
     */
    public function imprimirPlato(int $detalleId)
    {
        /*
         * Se enlaza el detalle con su producto, su comanda y la mesa
         * de esa comanda para obtener los datos de identificación.
         * Relaciones verificadas:
         *   venta_detalles.producto_id -> productos.id
         *   venta_detalles.comanda_id  -> comandas.id
         *   comandas.mesa_id           -> mesas.id
         */
        $detalle = DB::table('venta_detalles')
            ->join(
                'productos',
                'productos.id',
                '=',
                'venta_detalles.producto_id'
            )
            ->join(
                'comandas',
                'comandas.id',
                '=',
                'venta_detalles.comanda_id'
            )
            ->join(
                'mesas',
                'mesas.id',
                '=',
                'comandas.mesa_id'
            )
            ->where('venta_detalles.id', $detalleId)
            ->select(
                'venta_detalles.id',
                'venta_detalles.venta_id',
                'venta_detalles.cantidad',
                'venta_detalles.estado',
                'productos.nombre',
                'productos.requiere_cocina',
                'comandas.numero_ticket',
                'comandas.creado_en',
                'mesas.numero as mesa_numero'
            )
            ->first();

        if (!$detalle) {
            abort(404, 'Plato no encontrado.');
        }

        // Solo se reimprimen platos activos que pertenecen a cocina.
        // Los productos de barra o cancelados no se imprimen en cocina.
        if ($detalle->estado !== 'ACTIVO') {
            abort(404, 'El plato ya no está activo.');
        }

        if ((int) $detalle->requiere_cocina !== 1) {
            abort(404, 'El plato no corresponde a cocina.');
        }

        /*
         * Subcuenta opcional (?cuenta=):
         * - Sin el parámetro: reimpresión HISTÓRICA (cantidad original).
         * - Con una cuenta válida de la misma venta: muestra las unidades
         *   ACTUALES asignadas a esa cuenta. Si el plato no pertenece a la
         *   cuenta, se rechaza para no imprimir información incorrecta.
         */
        $cantidad = (int) $detalle->cantidad;
        $cuentaId = (int) request()->query('cuenta', 0);
        if ($cuentaId > 0) {
            $cuentaValida = DB::table('cuentas')
                ->where('id', $cuentaId)
                ->where('venta_id', $detalle->venta_id)
                ->where('estado', '!=', 'CANCELADA')
                ->exists();

            if ($cuentaValida) {
                $asignacion = DB::table('cuenta_detalles')
                    ->where('cuenta_id', $cuentaId)
                    ->where('venta_detalle_id', $detalleId)
                    ->where('eliminado', false)
                    ->first();

                if (!$asignacion) {
                    abort(404, 'El plato no pertenece a la cuenta indicada.');
                }

                $cantidad = (int) $asignacion->cantidad;
            }
        }

        // Se arma un objeto de comanda y una colección con un único
        // detalle para mantener el mismo estilo de impresión.
        $comanda = (object) [
            'numero_ticket' => $detalle->numero_ticket,
            'mesa_numero' => $detalle->mesa_numero,
            'creado_en' => $detalle->creado_en,
        ];

        $detalles = collect([
            (object) [
                'cantidad' => $cantidad,
                'nombre' => $detalle->nombre,
            ],
        ]);

        return view('ventas.comanda_plato_print', compact(
            'comanda',
            'detalles'
        ));
    }
}