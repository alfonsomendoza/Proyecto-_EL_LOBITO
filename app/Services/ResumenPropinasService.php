<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Resumen diario de PROPINAS (solo lectura).
 *
 * QUÉ CALCULA
 * -----------
 * Suma las propinas realmente guardadas en `cuentas.propina` para las cuentas
 * COBRADAS (estado PAGADA) dentro de un RANGO de fechas (Desde/Hasta, ambos
 * extremos incluidos), y devuelve además el desglose por factura/cuenta para
 * contrastar contra los vouchers.
 *
 * POR QUÉ NO SE AGRUPA POR MESERO
 * -------------------------------
 * El esquema real NO registra quién atendió la mesa. Las únicas columnas con
 * usuario son:
 *   - `cuentas.usuario_cobro`   = quién COBRÓ la cuenta.
 *   - `facturas.usuario_id`      = quién EMITIÓ/COBRÓ la factura.
 *   - `ventas.usuario_id`        = quién ABRIÓ la pantalla de la mesa en el POS.
 * Ninguna es el mesero que sirvió la mesa: en los datos reales coinciden con el
 * ADMIN que cobró. Atribuir propinas a un mesero sería una conjetura, así que
 * este resumen es GENERAL del día (agrupa por factura/cuenta, no por persona).
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
 * Solo LEE: nunca modifica facturas, cuentas ni propinas históricas. Tampoco
 * marca propinas como pagadas (eso no está definido por el negocio).
 */
class ResumenPropinasService
{
    /**
     * Resumen de propinas de un solo día (Y-m-d).
     *
     * Se conserva como atajo de `resumenDelRango` para no romper a los
     * consumidores que ya lo usaban con una única fecha.
     *
     * @return array<string, mixed>
     */
    public function resumenDelDia(string $fecha): array
    {
        $resumen = $this->resumenDelRango($fecha, $fecha);

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
     * @return array<string, mixed>
     */
    public function resumenDelRango(string $desde, string $hasta): array
    {
        if (! $this->esFechaValida($desde) || ! $this->esFechaValida($hasta)) {
            return [
                'exito' => false,
                'error' => 'Las fechas indicadas no son válidas.',
                'desde' => $desde,
                'hasta' => $hasta,
                'total' => 0.0,
                'cantidad' => 0,
                'detalle' => collect(),
            ];
        }

        // Un rango invertido generaría un resumen vacío engañoso: se rechaza
        // con un mensaje claro en vez de devolver cifras incorrectas.
        if ($desde > $hasta) {
            return [
                'exito' => false,
                'error' => 'La fecha "Desde" no puede ser posterior a la fecha "Hasta".',
                'desde' => $desde,
                'hasta' => $hasta,
                'total' => 0.0,
                'cantidad' => 0,
                'detalle' => collect(),
            ];
        }

        // Límites semiabiertos: [inicio del día Desde, inicio del día siguiente
        // a Hasta). Incluye todo el día Hasta aunque tenga hora. Se usan
        // comparaciones de rango (no funciones sobre la columna) para no
        // impedir el uso de índices.
        $inicio = Carbon::parse($desde)->startOfDay();
        $finExclusivo = Carbon::parse($hasta)->startOfDay()->addDay();

        // Unión 1:1 cuentas → facturas: cada cuenta cobrada tiene UNA factura
        // (emitida en el mismo cobro). La propina vive SOLO en `cuentas.propina`
        // y se lee una vez por cuenta; no se une a `cuenta_detalles` ni a
        // `pagos_cuenta` (esas uniones duplicarían el importe).
        $cuentas = DB::table('cuentas as c')
            ->join('facturas as f', 'f.cuenta_id', '=', 'c.id')
            ->where('c.estado', 'PAGADA')
            ->where('c.propina', '>', 0)
            ->where('f.fecha_emision', '>=', $inicio)
            ->where('f.fecha_emision', '<', $finExclusivo)
            ->orderBy('c.id')
            ->get([
                'c.id as cuenta_id',
                'c.nombre as cuenta_nombre',
                'c.propina',
                'c.venta_id',
                'c.usuario_cobro',
                'c.updated_at as cuenta_actualizada',
                'f.numero_factura',
                'f.fecha_emision',
            ]);

        $detalle = $cuentas->map(function ($cuenta) {
            return [
                'cuenta_id' => (int) $cuenta->cuenta_id,
                'cuenta_nombre' => (string) $cuenta->cuenta_nombre,
                'venta_id' => (int) $cuenta->venta_id,
                'numero_factura' => $cuenta->numero_factura !== null ? (string) $cuenta->numero_factura : null,
                'propina' => round((float) $cuenta->propina, 2),
                // Fecha de cobro real = emisión de la factura (inmutable).
                'fecha' => $cuenta->fecha_emision,
                // Se conserva la última modificación de la cuenta solo como
                // dato informativo para detectar ediciones posteriores.
                'cuenta_actualizada' => $cuenta->cuenta_actualizada,
            ];
        });

        $total = round((float) $detalle->sum('propina'), 2);

        return [
            'exito' => true,
            'desde' => $desde,
            'hasta' => $hasta,
            'total' => $total,
            'cantidad' => $detalle->count(),
            'detalle' => $detalle,
        ];
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
