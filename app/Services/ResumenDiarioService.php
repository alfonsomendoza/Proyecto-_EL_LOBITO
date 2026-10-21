<?php

namespace App\Services;

use App\Models\CajaApertura;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Resumen del Día (varios turnos de una misma fecha).
 *
 * PORTADO de la funcionalidad "Operaciones" del respaldo PHP puro
 * (`api_operaciones.php::buscar_resumen_dia` y `CajaService::obtenerDatosAdmin`),
 * adaptado al modelo de datos real del POS Laravel.
 *
 * DIFERENCIAS documentadas frente al PHP puro (no son cambios de fórmula, sino
 * de adaptación al esquema actual):
 *
 *  1. El PHP puro agrupaba las ventas por `ventas.apertura_id`. El POS Laravel
 *     crea las ventas con `apertura_id = null` y guarda el cobro en `cuentas`,
 *     así que aquí se reutiliza el MISMO criterio del cierre de caja
 *     (`CajaCierreService`): cuentas PAGADAS por `apertura_id` o por su ventana
 *     temporal.
 *  2. Para que varios turnos NO cuenten dos veces una venta, cada turno se
 *     acota con la ventana [inicio_turno, inicio_del_siguiente_turno). El cierre
 *     de un turno suelto usa [inicio, ahora]; el resumen del día necesita el
 *     corte en el turno siguiente.
 *  3. NO se restan los "cambios entregados": en el POS Laravel `cuentas.total`
 *     ya es el neto retenido en caja. El PHP puro sumaba `ventas.monto` (neto)
 *     y ADEMÁS restaba los cambios, descontándolos dos veces (bug documentado).
 *     Aquí los cambios se muestran solo como dato informativo.
 *  4. Se omiten las categorías exclusivas del PHP puro que no existen en el POS
 *     Laravel (p. ej. "Efectivo US$" como forma de cobro o la separación
 *     tarjeta débito/crédito): el POS solo registra EFECTIVO, TARJETA y
 *     TRANSFERENCIA, y las tarjetas se agrupan por banco.
 *
 * Solo LEE datos: no escribe ni modifica ventas, cuentas, gastos ni cierres.
 */
class ResumenDiarioService
{
    public function __construct(private CajaCierreService $cierre) {}

    /**
     * Turnos (aperturas) cuya fecha de negocio corresponde al día indicado,
     * opcionalmente filtrados por cajero. La fecha se compara contra
     * `caja_apertura.fecha` (DATETIME en hora local del negocio), igual que el
     * PHP puro (`DATE(fecha) = ?`).
     */
    public function aperturasDelDia(string $fecha, ?string $cajero = null): Collection
    {
        $query = CajaApertura::query()
            ->whereDate('fecha', $fecha)
            ->orderBy('creado_en')
            ->orderBy('id');

        if ($this->cajeroValido($cajero)) {
            $query->where('cajero', $cajero);
        }

        return $query->get();
    }

    /**
     * Cajeros distintos que abrieron caja en una fecha (para el selector).
     */
    public function cajerosDisponibles(string $fecha): Collection
    {
        return CajaApertura::query()
            ->whereDate('fecha', $fecha)
            ->whereNotNull('cajero')
            ->where('cajero', '!=', '')
            ->orderBy('cajero')
            ->distinct()
            ->pluck('cajero');
    }

    /**
     * Fin de la ventana de un turno: el inicio del siguiente turno (para que no
     * se solapen) o "ahora" si es el último.
     */
    public function finDeTurno(CajaApertura $apertura, ?Collection $todas = null): Carbon
    {
        $ordenadas = $this->aperturasOrdenadas($todas);
        $indice = $ordenadas->search(fn ($a) => (int) $a->id === (int) $apertura->id);

        if ($indice !== false && isset($ordenadas[$indice + 1])) {
            $inicioSiguiente = $this->cierre->inicioTurno($ordenadas[$indice + 1]);

            if ($inicioSiguiente !== null) {
                return $inicioSiguiente;
            }
        }

        return now();
    }

    /**
     * Construye el resumen completo del día.
     *
     * @return array<string, mixed>
     */
    public function aperturasDelRango(string $fechaInicio, string $fechaFin, ?string $cajero = null): Collection
    {
        $query = CajaApertura::query()
            ->whereBetween(DB::raw('DATE(fecha)'), [$fechaInicio, $fechaFin])
            ->orderBy('fecha')
            ->orderBy('id');

        if ($this->cajeroValido($cajero)) {
            $query->where('cajero', $cajero);
        }

        return $query->get();
    }

    public function cajerosDisponiblesRango(string $fechaInicio, string $fechaFin): Collection
    {
        return CajaApertura::query()
            ->whereBetween(DB::raw('DATE(fecha)'), [$fechaInicio, $fechaFin])
            ->whereNotNull('cajero')
            ->where('cajero', '!=', '')
            ->orderBy('cajero')
            ->distinct()
            ->pluck('cajero');
    }

    public function resumenPorRango(string $fechaInicio, string $fechaFin, ?string $cajero = null): array
    {
        $etiquetaCajero = $this->cajeroValido($cajero) ? (string) $cajero : 'TODOS';

        if (! $this->esFechaValida($fechaInicio) || ! $this->esFechaValida($fechaFin)) {
            return [
                'exito' => false,
                'error' => 'Las fechas indicadas no son válidas.',
                'fecha' => $fechaInicio,
                'fecha_inicio' => $fechaInicio,
                'fecha_fin' => $fechaFin,
                'cajero' => $etiquetaCajero,
            ];
        }

        if ($fechaInicio > $fechaFin) {
            [$fechaInicio, $fechaFin] = [$fechaFin, $fechaInicio];
        }

        $aperturas = $this->aperturasDelRango($fechaInicio, $fechaFin, $cajero);

        if ($aperturas->isEmpty()) {
            return [
                'exito' => false,
                'error' => 'No se encontraron turnos de caja para el rango de fechas seleccionado.',
                'fecha' => $fechaInicio,
                'fecha_inicio' => $fechaInicio,
                'fecha_fin' => $fechaFin,
                'cajero' => $etiquetaCajero,
            ];
        }

        $todas = $this->aperturasOrdenadas();
        $ids = $aperturas->pluck('id')->map(fn ($id) => (int) $id)->all();

        $gastos = DB::table('caja_gastos')->whereIn('apertura_id', $ids)->get();

        $totales = [
            'monto_apertura' => 0.0,
            'efectivo' => 0.0,
            'tarjeta' => 0.0,
            'transferencia' => 0.0,
            'otro' => 0.0,
            'cambios' => 0.0,
            'otros_ingresos' => 0.0,
            'retiros' => 0.0,
            'gastos_activos' => 0.0,
            'gastos_anulados' => 0.0,
            'cantidad_gastos_anulados' => 0,
            'ventas_anuladas' => 0.0,
            'cantidad_ventas_anuladas' => 0,
            'cantidad_cuentas' => 0,
        ];

        $tarjetasPorBanco = [];
        $turnos = [];

        foreach ($aperturas as $apertura) {
            $fin = $this->finDeTurno($apertura, $todas);
            $ventas = $this->cierre->resumenVentas($apertura, $fin);
            $cierreVigente = $this->cierre->cierreVigente((int) $apertura->id);

            $egresosTurno = 0.0;
            $otrosIngresosTurno = (float) ($cierreVigente->otros_ingresos ?? 0);
            $retirosTurno = (float) ($cierreVigente->retiros ?? 0);

            $cambiosTurno = 0.0;
            foreach ($ventas['cuentas'] as $cuenta) {
                $cambiosTurno += (float) ($cuenta->cambio ?? 0);
            }

            $anuladas = $this->ventasAnuladasEnVentana($apertura, $fin);

            foreach ($gastos as $gasto) {
                if ((int) $gasto->apertura_id !== (int) $apertura->id) {
                    continue;
                }

                if (strtoupper((string) $gasto->estado) === 'ANULADO') {
                    $totales['gastos_anulados'] += (float) $gasto->monto;
                    $totales['cantidad_gastos_anulados']++;
                } else {
                    $totales['gastos_activos'] += (float) $gasto->monto;
                    $egresosTurno += (float) $gasto->monto;
                }
            }

            foreach ($ventas['tarjetas_por_banco'] as $banco => $monto) {
                $tarjetasPorBanco[$banco] = ($tarjetasPorBanco[$banco] ?? 0.0) + $monto;
            }

            $totales['monto_apertura'] += (float) $apertura->monto_inicial;
            $totales['efectivo'] += $ventas['efectivo'];
            $totales['tarjeta'] += $ventas['tarjeta'];
            $totales['transferencia'] += $ventas['transferencia'];
            $totales['otro'] += $ventas['otro'];
            $totales['cambios'] += $cambiosTurno;
            $totales['otros_ingresos'] += $otrosIngresosTurno;
            $totales['retiros'] += $retirosTurno;
            $totales['cantidad_cuentas'] += $ventas['cantidad'];
            $totales['ventas_anuladas'] += $anuladas['monto'];
            $totales['cantidad_ventas_anuladas'] += $anuladas['cantidad'];

            $turnos[] = [
                'apertura' => $apertura,
                'inicio' => $this->cierre->inicioTurno($apertura),
                'fin' => $fin,
                'estado' => strtoupper((string) $apertura->estado),
                'cierre' => $cierreVigente,
                'ventas' => $ventas,
                'egresos' => $egresosTurno,
                'otros_ingresos' => $otrosIngresosTurno,
                'retiros' => $retirosTurno,
                'cambios' => $cambiosTurno,
                'ventas_anuladas' => $anuladas,
            ];
        }

        $efectivoEsperado = $totales['monto_apertura']
            + $totales['efectivo']
            + $totales['otros_ingresos']
            - $totales['gastos_activos']
            - $totales['retiros'];

        $totalVentas = $totales['efectivo'] + $totales['tarjeta'] + $totales['transferencia'] + $totales['otro'];

        return [
            'exito' => true,
            'fecha' => $fechaInicio,
            'fecha_inicio' => $fechaInicio,
            'fecha_fin' => $fechaFin,
            'cajero' => $etiquetaCajero,
            'aperturas' => $aperturas,
            'turnos' => $turnos,
            'cantidad_turnos' => count($turnos),
            'cajeros' => $aperturas->pluck('cajero')->filter()->unique()->values()->all(),
            'total_monto_apertura' => round($totales['monto_apertura'], 2),
            'efectivo' => round($totales['efectivo'], 2),
            'tarjeta' => round($totales['tarjeta'], 2),
            'transferencia' => round($totales['transferencia'], 2),
            'otro' => round($totales['otro'], 2),
            'tarjetas_por_banco' => $tarjetasPorBanco,
            'total_ventas' => round($totalVentas, 2),
            'gastos_activos' => round($totales['gastos_activos'], 2),
            'gastos_anulados' => round($totales['gastos_anulados'], 2),
            'cantidad_gastos_anulados' => $totales['cantidad_gastos_anulados'],
            'otros_ingresos' => round($totales['otros_ingresos'], 2),
            'retiros' => round($totales['retiros'], 2),
            'cambios_entregados' => round($totales['cambios'], 2),
            'ventas_anuladas' => round($totales['ventas_anuladas'], 2),
            'cantidad_ventas_anuladas' => $totales['cantidad_ventas_anuladas'],
            'cantidad_cuentas' => $totales['cantidad_cuentas'],
            'efectivo_esperado' => round($efectivoEsperado, 2),
        ];
    }

    public function resumenDelDia(string $fecha, ?string $cajero = null): array
    {
        return $this->resumenPorRango($fecha, $fecha, $cajero);
    }

        $todas = $this->aperturasOrdenadas();
        $ids = $aperturas->pluck('id')->map(fn ($id) => (int) $id)->all();

        // Gastos de todos los turnos del día (activos y anulados por separado).
        $gastos = DB::table('caja_gastos')->whereIn('apertura_id', $ids)->get();

        $totales = [
            'monto_apertura' => 0.0,
            'efectivo' => 0.0,
            'tarjeta' => 0.0,
            'transferencia' => 0.0,
            'otro' => 0.0,
            'cambios' => 0.0,
            'otros_ingresos' => 0.0,
            'retiros' => 0.0,
            'gastos_activos' => 0.0,
            'gastos_anulados' => 0.0,
            'cantidad_gastos_anulados' => 0,
            'ventas_anuladas' => 0.0,
            'cantidad_ventas_anuladas' => 0,
            'cantidad_cuentas' => 0,
        ];

        $tarjetasPorBanco = [];
        $turnos = [];

        foreach ($aperturas as $apertura) {
            $fin = $this->finDeTurno($apertura, $todas);
            $ventas = $this->cierre->resumenVentas($apertura, $fin);
            $cierreVigente = $this->cierre->cierreVigente((int) $apertura->id);

            // Egresos del turno: gastos ACTIVOS de esa apertura.
            $egresosTurno = 0.0;
            $otrosIngresosTurno = (float) ($cierreVigente->otros_ingresos ?? 0);
            $retirosTurno = (float) ($cierreVigente->retiros ?? 0);

            $cambiosTurno = 0.0;
            foreach ($ventas['cuentas'] as $cuenta) {
                $cambiosTurno += (float) ($cuenta->cambio ?? 0);
            }

            // Ventas anuladas del turno (ventas CANCELADAS creadas en su ventana).
            $anuladas = $this->ventasAnuladasEnVentana($apertura, $fin);

            foreach ($gastos as $gasto) {
                if ((int) $gasto->apertura_id !== (int) $apertura->id) {
                    continue;
                }

                if (strtoupper((string) $gasto->estado) === 'ANULADO') {
                    $totales['gastos_anulados'] += (float) $gasto->monto;
                    $totales['cantidad_gastos_anulados']++;
                } else {
                    $totales['gastos_activos'] += (float) $gasto->monto;
                    $egresosTurno += (float) $gasto->monto;
                }
            }

            foreach ($ventas['tarjetas_por_banco'] as $banco => $monto) {
                $tarjetasPorBanco[$banco] = ($tarjetasPorBanco[$banco] ?? 0.0) + $monto;
            }

            $totales['monto_apertura'] += (float) $apertura->monto_inicial;
            $totales['efectivo'] += $ventas['efectivo'];
            $totales['tarjeta'] += $ventas['tarjeta'];
            $totales['transferencia'] += $ventas['transferencia'];
            $totales['otro'] += $ventas['otro'];
            $totales['cambios'] += $cambiosTurno;
            $totales['otros_ingresos'] += $otrosIngresosTurno;
            $totales['retiros'] += $retirosTurno;
            $totales['cantidad_cuentas'] += $ventas['cantidad'];
            $totales['ventas_anuladas'] += $anuladas['monto'];
            $totales['cantidad_ventas_anuladas'] += $anuladas['cantidad'];

            $turnos[] = [
                'apertura' => $apertura,
                'inicio' => $this->cierre->inicioTurno($apertura),
                'fin' => $fin,
                'estado' => strtoupper((string) $apertura->estado),
                'cierre' => $cierreVigente,
                'ventas' => $ventas,
                'egresos' => $egresosTurno,
                'otros_ingresos' => $otrosIngresosTurno,
                'retiros' => $retirosTurno,
                'cambios' => $cambiosTurno,
                'ventas_anuladas' => $anuladas,
            ];
        }

        // Efectivo esperado del día = fondo + efectivo del día + otros ingresos
        // − gastos activos − retiros. (Los cambios y las tarjetas NO se restan:
        // ver notas de la clase.)
        $efectivoEsperado = $totales['monto_apertura']
            + $totales['efectivo']
            + $totales['otros_ingresos']
            - $totales['gastos_activos']
            - $totales['retiros'];

        $totalVentas = $totales['efectivo'] + $totales['tarjeta'] + $totales['transferencia'] + $totales['otro'];

        return [
            'exito' => true,
            'fecha' => $fecha,
            'cajero' => $etiquetaCajero,
            'cantidad_turnos' => count($turnos),
            'total_monto_apertura' => $totales['monto_apertura'],
            'efectivo' => $totales['efectivo'],
            'tarjeta' => $totales['tarjeta'],
            'transferencia' => $totales['transferencia'],
            'otro' => $totales['otro'],
            'tarjetas_por_banco' => $tarjetasPorBanco,
            'cantidad_cuentas' => $totales['cantidad_cuentas'],
            'total_ventas' => $totalVentas,
            'ventas_anuladas' => $totales['ventas_anuladas'],
            'cantidad_ventas_anuladas' => $totales['cantidad_ventas_anuladas'],
            'gastos_activos' => $totales['gastos_activos'],
            'gastos_anulados' => $totales['gastos_anulados'],
            'cantidad_gastos_anulados' => $totales['cantidad_gastos_anulados'],
            'otros_ingresos' => $totales['otros_ingresos'],
            'retiros' => $totales['retiros'],
            'cambios_entregados' => $totales['cambios'],
            'efectivo_esperado' => $efectivoEsperado,
            'turnos' => $turnos,
        ];
    }

    public function resumenDelDia(string $fecha, ?string $cajero = null): array
    {
        return $this->resumenPorRango($fecha, $fecha, $cajero);
    }

    /* ------------------------------------------------------------------
     | Utilidades internas
     | ------------------------------------------------------------------ */

    /**
     * Aperturas ordenadas por inicio de turno y, como desempate, por id.
     */
    private function aperturasOrdenadas(?Collection $todas = null): Collection
    {
        $todas = $todas ?? CajaApertura::query()->get();

        return $todas
            ->sortBy(function (CajaApertura $a) {
                $inicio = $this->cierre->inicioTurno($a);
                $clave = $inicio ? $inicio->format('Y-m-d H:i:s') : '';

                return $clave.'|'.str_pad((string) (int) $a->id, 12, '0', STR_PAD_LEFT);
            })
            ->values();
    }

    /**
     * Suma y cantidad de ventas CANCELADAS creadas dentro de la ventana del
     * turno. En el POS Laravel la anulación de una venta completa se registra
     * con `ventas.estado = 'CANCELADA'` (ocurre antes del cobro).
     *
     * @return array{monto: float, cantidad: int}
     */
    private function ventasAnuladasEnVentana(CajaApertura $apertura, Carbon $fin): array
    {
        $inicio = $this->cierre->inicioTurno($apertura);

        if ($inicio === null) {
            return ['monto' => 0.0, 'cantidad' => 0];
        }

        $ventas = DB::table('ventas')
            ->where('estado', 'CANCELADA')
            ->whereBetween('fecha', [$inicio, $fin])
            ->get();

        return [
            'monto' => (float) $ventas->sum('monto'),
            'cantidad' => $ventas->count(),
        ];
    }

    private function cajeroValido(?string $cajero): bool
    {
        return $cajero !== null && $cajero !== '' && strtoupper($cajero) !== 'TODOS';
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
