<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Emisión de facturas desde el cobro del POS Laravel.
 *
 * ANTECEDENTE
 * -----------
 * En el sistema PHP puro, `CajaService::agregarVenta()` insertaba una fila en
 * `facturas` (numeración `FAC-####`) en la misma transacción del cobro. El POS
 * Laravel no lo hacía: `facturas` solo se consultaba, nunca se escribía.
 *
 * CRITERIO DE EMISIÓN
 * -------------------
 * Se emite UNA FACTURA POR COBRO (es decir, por cuenta pagada):
 *
 *  - Cada cuenta es un cobro independiente con su propio método de pago
 *    (`cuentas.metodo_pago`), su monto recibido y su cambio. Emitir una sola
 *    factura por venta obligaría a mezclar métodos de pago distintos en un
 *    mismo comprobante cuando la mesa se divide.
 *  - La tabla `facturas` ya tenía pensado ese escenario: `cuenta_id` es una
 *    columna real y admite NULL. Las facturas históricas del PHP puro tienen
 *    `cuenta_id = NULL` (una sola cuenta por venta); las nuevas la rellenan.
 *  - Cobrar una cuenta vacía ya está prohibido por `pagarCuenta`, así que no
 *    se emiten facturas por C$ 0.
 *
 * NUMERACIÓN CONCURRENTE-SEGURA
 * -----------------------------
 * `facturas.numero_factura` tiene restricción UNIQUE, pero calcular el número
 * con "SELECT MAX(...) + 1" introduciría una carrera entre dos cobros
 * simultáneos. En su lugar:
 *
 *  1. Se inserta la fila con un número provisional único (marcador "TMP-"
 *     + los 15 primeros caracteres del UUID, 19 caracteres en total: LA
 *     COLUMNA ES varchar(20) EN MySQL REAL y no admite más. Un UUID completo
 *     ("TMP-" + 36) provocaba el error 1406 Data too long en producción y
 *     el cobro entero se revolvía).
 *  2. El número definitivo se deriva del `id` autoincremental de la propia
 *     fila, que el motor asigna de forma atómica: dos transacciones no pueden
 *     obtener el mismo id, luego no pueden obtener el mismo número.
 *  3. Se reemplaza el provisional por el definitivo (mismma transacción).
 *
 *  El marcador TMP- NUNCA persiste: o se reemplaza por FAC-#### dentro de la
 *  misma transacción, o la transacción se revierte y la fila desaparece.
 *
 * Esto reproduce además la continuidad del histórico real (FAC-0001..FAC-0003
 * corresponden a los ids 1..3). Si un número resultara ocupado por un registro
 * previo, se avanza hasta encontrar uno libre.
 *
 * ATOMICIDAD
 * ----------
 * El servicio NO abre su propia transacción: se ejecuta dentro de la del cobro.
 * Si la factura no se puede guardar, el cobro completo se revierte y no queda
 * un pago sin factura ni una factura sin pago.
 */
class FacturaService
{
    /**
     * Emite la factura de un cobro. Debe llamarse dentro de la transacción
     * que registra el pago.
     *
     * @param  object  $cuenta  Fila de `cuentas` (debe estar recién pagada).
     * @param  object  $venta  Fila de `ventas` a la que pertenece la cuenta.
     * @param  float  $total  Total REALMENTE cobrado (consumo − descuento + propina).
     * @param  int  $usuarioId  Cajero que registró el cobro.
     * @param  array  $ajustes  Ajustes opcionales del cobro
     *                          (consumo, descuento, base, propina, total).
     *                          Se guardan en el detalle JSON del comprobante.
     * @return object Factura emitida (id, numero_factura, estado).
     */
    public function emitirPorCobro(object $cuenta, object $venta, float $total, int $usuarioId, array $ajustes = []): object
    {
        $lineas = $this->detalleCuenta((int) $cuenta->id);
        $mesa = $this->descripcionMesa($venta, $cuenta);

        // `detalle_consumo` es JSON. Se conserva la clave "nota" porque el
        // ticket histórico y `FacturaController::ticket` ya la interpretan, y
        // se añade "lineas" con el detalle real para las facturas nuevas.
        // "ajustes" (si el cobro tuvo descuento/propina) deja constancia de
        // cómo se llegó al total facturado: consumo − descuento + propina.
        $detalleConsumo = json_encode(array_merge([
            'nota' => $this->resumenTexto($lineas, $mesa),
            'lineas' => $lineas,
            'cuenta' => (string) $cuenta->nombre,
        ], $ajustes !== [] ? ['ajustes' => $ajustes] : []), JSON_UNESCAPED_UNICODE);

        // 1) Fila con número provisional único (ver docblock). OJO: el
        //    marcador debe caber en varchar(20) — por eso se trunca el UUID
        //    a 15 caracteres; NO se amplía la columna (mantendría el formato
        //    oficial FAC-#### del histórico y su índice UNIQUE).
        $id = DB::table('facturas')->insertGetId([
            'numero_factura' => 'TMP-'.substr(Str::uuid()->toString(), 0, 15),
            'venta_id' => (int) $venta->id,
            'cuenta_id' => (int) $cuenta->id,
            'mesa' => mb_substr($mesa, 0, 50),
            'detalle_consumo' => $detalleConsumo,
            'total' => round($total, 2),
            'fecha_emision' => now(),
            'usuario_id' => $usuarioId,
            'estado' => 'EMITIDA',
        ]);

        // 2) Número definitivo derivado del id (atómico) y 3) reemplazo.
        $numero = $this->numeroDisponible($id);
        DB::table('facturas')->where('id', $id)->update(['numero_factura' => $numero]);

        return (object) [
            'id' => $id,
            'numero_factura' => $numero,
            'estado' => 'EMITIDA',
        ];
    }

    /**
     * Genera el número de factura a partir del id de la propia fila.
     *
     * Si ese número ya estuviera ocupado por un registro anterior (por ejemplo,
     * un registro manual que no siguió la secuencia), se avanza hasta
     * encontrar uno libre en lugar de fallar por la restricción UNIQUE.
     */
    private function numeroDisponible(int $id): string
    {
        $numero = $this->formatearNumero($id);

        while (DB::table('facturas')->where('numero_factura', $numero)->exists()) {
            $id++;
            $numero = $this->formatearNumero($id);
        }

        return $numero;
    }

    private function formatearNumero(int $id): string
    {
        return 'FAC-'.str_pad((string) $id, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Detalle real de la cuenta, calculado con la MISMA fuente que el cobro
     * y la prefactura: `cuenta_detalles` (sin anulados) unidas a
     * `venta_detalles` y `productos`. Así la prefactura, el cobro y la
     * factura coinciden siempre (y ninguna incluye el consumo de otra
     * subcuenta).
     *
     * PÚBLICO a propósito: `VentaController::prefactura()` reutiliza esta
     * misma consulta para imprimir la cuenta antes de cobrarla.
     *
     * @return array<int, array{producto: string, cantidad: int, precio: float, subtotal: float}>
     */
    public function detalleCuenta(int $cuentaId): array
    {
        return DB::table('cuenta_detalles as cd')
            ->join('venta_detalles as vd', 'vd.id', '=', 'cd.venta_detalle_id')
            ->leftJoin('productos as p', 'p.id', '=', 'vd.producto_id')
            ->where('cd.cuenta_id', $cuentaId)
            ->where('cd.eliminado', false)
            ->orderBy('cd.id')
            ->get(['cd.cantidad', 'vd.precio_unitario', 'cd.subtotal', 'p.nombre as producto'])
            ->map(fn ($fila) => [
                'producto' => (string) ($fila->producto ?? 'Producto'),
                'cantidad' => (int) $fila->cantidad,
                'precio' => (float) $fila->precio_unitario,
                'subtotal' => (float) $fila->subtotal,
            ])
            ->all();
    }

    /**
     * Etiqueta de la mesa. `mesas.numero` ya incluye el prefijo ("Mesa 1").
     * A la cuenta derivada de una división se le añade "(Div)", replicando el
     * criterio del PHP puro ("Consumo en Mesa 1 (Div)").
     */
    private function descripcionMesa(object $venta, object $cuenta): string
    {
        $numero = DB::table('mesas')->where('id', $venta->mesa_id)->value('numero');
        $etiqueta = trim((string) $numero);

        if ($etiqueta === '') {
            $etiqueta = 'Mesa';
        }

        if (! empty($cuenta->cuenta_origen_id)) {
            $etiqueta .= ' (Div)';
        }

        return $etiqueta;
    }

    /**
     * Texto resumen del consumo, en el mismo formato que emitía el PHP puro
     * ("1x TOMA HAWK + 1x MILLER LITE"). Se acota para no desbordar el ticket.
     */
    private function resumenTexto(array $lineas, string $mesa): string
    {
        if ($lineas === []) {
            return $mesa;
        }

        $partes = array_map(
            fn (array $linea) => $linea['cantidad'].'x '.mb_strtoupper($linea['producto']),
            array_slice($lineas, 0, 20)
        );

        $texto = implode(' + ', $partes);

        if (count($lineas) > 20) {
            $texto .= ' + ...';
        }

        return mb_substr($texto, 0, 500);
    }
}
