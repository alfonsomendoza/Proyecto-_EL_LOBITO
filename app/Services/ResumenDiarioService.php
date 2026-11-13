<?php

namespace App\Services;

use App\Models\CajaApertura;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
 * DATOS DE CAJAS Y PROPINAS
 * -------------------------
 * Cada turno devuelve, además de los importes, quién ABRIÓ la caja
 * (`caja_apertura.usuario_id`, con respaldo del texto `cajero`), quién la
 * CERRÓ (`caja_cierre.usuario_id` del cierre vigente), las fechas de apertura y
 * cierre, el fondo inicial, el total de ventas, el total de gastos y la
 * diferencia del arqueo. Las propinas se calculan con
 * `ResumenPropinasService` (una sola fórmula): se informa lo COBRADO al cliente
 * y se deja `pagadas = null` porque el esquema no registra el pago al personal.
 *
 * Solo LEE datos: no escribe ni modifica ventas, cuentas, gastos ni cierres.
 */
class ResumenDiarioService
{
    public function __construct(
        private CajaCierreService $cierre,
        // Reutiliza el MISMO cálculo de propinas que el módulo de Propinas:
        // así no hay dos fórmulas distintas para el mismo dato.
        private ResumenPropinasService $propinas
    ) {
    }

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
        // -----------------------------------------------------------------
        // El resumen se genera POR APERTURA DE CAJA. Cada apertura del
        // período (abierta o cerrada) es una sección independiente; el cierre
        // (si existe) se relaciona con esa misma apertura. Las aperturas
        // todavía abiertas aparecen como resumen PROVISIONAL.
        // -----------------------------------------------------------------
        $aperturasQuery = \App\Models\CajaApertura::query()
            ->whereBetween(DB::raw('DATE(fecha)'), [$fechaInicio, $fechaFin])
            ->orderBy('fecha')
            ->orderBy('id');

        if ($this->cajeroValido($cajero)) {
            $aperturasQuery->where('cajero', $cajero);
        }

        $aperturas = $aperturasQuery->get();

        if ($aperturas->isEmpty()) {
            return [
                'exito' => false,
                'error' => 'No se encontraron aperturas de caja para el período seleccionado.',
                'fecha' => $fechaInicio,
                'fecha_inicio' => $fechaInicio,
                'fecha_fin' => $fechaFin,
                'cajero' => $etiquetaCajero,
            ];
        }
        $todasAperturas = CajaApertura::query()->get();
        $turnos = [];
        $turnosAbiertos = 0;

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
            'diferencia_caja' => 0.0,
            'cantidad_cierres' => 0,
        ];
        $tarjetasPorBanco = [];
        // Resumen por apertura: las aperturas todavía abiertas aparecen como
        // «CAJA ABIERTA — resumen provisional» y las cerradas como definitivas.
        foreach ($aperturas as $apertura) {
            $cierre = $this->cierre->cierreVigente((int)$apertura->id);
            $fin = $this->finDeTurno($apertura, $todasAperturas);

            $ventas = $this->cierre->resumenVentas($apertura, $fin, true);
            if ($cierre === null) {
                $turnosAbiertos++;
            }
            $egresosTurno = 0.0;
            $otrosIngresosTurno = (float) ($cierre?->otros_ingresos ?? 0);
            $retirosTurno = (float) ($cierre?->retiros ?? 0);
            $cambiosTurno = 0.0;
            foreach ($ventas['cuentas'] as $cuenta) {
                $cambiosTurno += (float) ($cuenta->cambio ?? 0);
            }
            $anuladas = $this->ventasAnuladasEnVentana($apertura, $fin);
            $gastos = DB::table('caja_gastos')->where('apertura_id', (int) $apertura->id)->get();
            $gastosActivosTurno = 0.0;
            $gastosAnuladosTurno = 0.0;
            $cantidadGastosAnuladosTurno = 0;
            foreach ($gastos as $gasto) {
                if (strtoupper((string) $gasto->estado) === 'ANULADO') {
                    $gastosAnuladosTurno += (float) $gasto->monto;
                    $cantidadGastosAnuladosTurno++;
                } else {
                    $gastosActivosTurno += (float) $gasto->monto;
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
            $totales['gastos_activos'] += $gastosActivosTurno;
            $totales['gastos_anulados'] += $gastosAnuladosTurno;
            $totales['cantidad_gastos_anulados'] += $cantidadGastosAnuladosTurno;
            $totales['ventas_anuladas'] += $anuladas['monto'];
            $totales['cantidad_ventas_anuladas'] += $anuladas['cantidad'];
            $totales['cantidad_cuentas'] += $ventas['cantidad'];
            $totales['diferencia_caja'] += round((float) ($cierre?->diferencia ?? 0), 2);
            if ($cierre !== null) {
                $totales['cantidad_cierres']++;
            }

            $aperturaId = (int) $apertura->id;
            $perTurnoPropinas = $this->propinasDelTurno($aperturaId, $fechaInicio, $fechaFin);

            $idsUsuarios = array_filter([(int) $apertura->usuario_id, (int) ($cierre?->usuario_id ?? 0)]);
            $nombresUsuarios = [];
            if ($idsUsuarios !== [] && Schema::hasTable('usuarios')) {
                $nombresUsuarios = DB::table('usuarios')
                    ->whereIn('id', array_values(array_unique($idsUsuarios)))
                    ->pluck('nombre', 'id')
                    ->toArray();
            }
            $turnos[] = [
                'apertura' => $apertura,
                'cierre' => $cierre,
                'inicio' => $this->cierre->inicioTurno($apertura),
                'fin' => $fin,
                'estado' => strtoupper((string) $apertura->estado),
                'ventas' => $ventas,
                'egresos' => $gastosActivosTurno,
                'otros_ingresos' => $otrosIngresosTurno,
                'retiros' => $retirosTurno,
                'cambios' => $cambiosTurno,
                'ventas_anuladas' => $anuladas,
                'gastos_activos' => $gastosActivosTurno,
                'gastos_anulados' => $gastosAnuladosTurno,
                'cantidad_gastos_anulados' => $cantidadGastosAnuladosTurno,
                'abrio' => $this->nombreUsuario($apertura->usuario_id, $nombresUsuarios)
                    ?? ($apertura->cajero !== null && $apertura->cajero !== '' ? (string) $apertura->cajero : null),
                'cerro' => $this->nombreUsuario($cierre?->usuario_id ?? null, $nombresUsuarios)
                    ?? (($cierre?->cajero ?? null) !== null && ($cierre?->cajero ?? '') !== '' ? (string) $cierre->cajero : null),
                'fecha_apertura' => $apertura->fecha,
                'fecha_cierre' => $cierre?->fecha,
                'estado_caja' => $cierre !== null ? 'CERRADA' : 'ABIERTA',
                'total_ventas' => round((float) $ventas['total'], 2),
                'diferencia' => $cierre !== null ? round((float) $cierre->diferencia, 2) : null,
                'propinas' => $perTurnoPropinas,
            ];
        }
        $cajeros = $aperturas->pluck('cajero')->filter()->unique()->values()->all();
        $propinas = $this->propinasDelPeriodo($fechaInicio, $fechaFin);
        return [
            'exito' => true,
            'fecha' => $fechaInicio,
            'fecha_inicio' => $fechaInicio,
            'fecha_fin' => $fechaFin,
            'cajero' => $etiquetaCajero,
            'aperturas' => $aperturas,
            'turnos' => $turnos,
            'cantidad_turnos' => count($turnos),
            'cajeros' => $cajeros,
            // Totales globales (suma de los cierres del período) para compatibilidad
            // con pruebas y consumidores existentes; la presentación por cierre
            // no los combina.
            'total_monto_apertura' => round($totales['monto_apertura'], 2),
            'efectivo' => round($totales['efectivo'], 2),
            'tarjeta' => round($totales['tarjeta'], 2),
            'transferencia' => round($totales['transferencia'], 2),
            'otro' => round($totales['otro'], 2),
            'tarjetas_por_banco' => $tarjetasPorBanco,
            'total_ventas' => round($totales['efectivo'] + $totales['tarjeta'] + $totales['transferencia'] + $totales['otro'], 2),
            'gastos_activos' => round($totales['gastos_activos'], 2),
            'gastos_anulados' => round($totales['gastos_anulados'], 2),
            'cantidad_gastos_anulados' => $totales['cantidad_gastos_anulados'],
            'otros_ingresos' => round($totales['otros_ingresos'], 2),
            'retiros' => round($totales['retiros'], 2),
            'cambios_entregados' => round($totales['cambios'], 2),
            'ventas_anuladas' => round($totales['ventas_anuladas'], 2),
            'cantidad_ventas_anuladas' => $totales['cantidad_ventas_anuladas'],
            'cantidad_cuentas' => $totales['cantidad_cuentas'],
            'efectivo_esperado' => round($totales['monto_apertura'] + $totales['efectivo'] + $totales['otros_ingresos'] - $totales['gastos_activos'] - $totales['retiros'], 2),
            'diferencia_caja' => round($totales['diferencia_caja'], 2),
            'cantidad_cierres' => $totales['cantidad_cierres'],
            'turnos_abiertos' => $turnosAbiertos,
            'propinas' => $propinas,
        ];
    }
    /**
     * Propinas atribuidas a un cierre/turno específico (reutilizando
     * ResumenPropinasService con filtro por apertura). Devuelve las claves
     * que la vista necesita.
     */
    private function propinasDelTurno(int $aperturaId, string $fechaInicio, string $fechaFin): array
    {
        if (! Schema::hasTable('cuentas') || ! Schema::hasTable('facturas')) {
            return ['disponible' => false];
        }

        $rango = $this->propinas->resumenDelRango($fechaInicio, $fechaFin, ['apertura' => $aperturaId]);

        if (! ($rango['exito'] ?? false)) {
            return ['disponible' => false];
        }

        return [
            'disponible' => true,
            'total' => round((float) ($rango['total'] ?? 0), 2),
            'cantidad' => (int) ($rango['cantidad'] ?? 0),
            'atribuidas' => round((float) ($rango['atribuido'] ?? 0), 2),
            'pendientes' => round((float) ($rango['pendiente'] ?? 0), 2),
            'meseros' => $rango['resumen_meseros'] ?? collect(),
        ];
    }

    private function propinasDelPeriodo(string $desde, string $hasta): array
    {
        $noDisponible = [
            'disponible' => false,
            'cobradas' => 0.0,
            'cantidad' => 0,
            'pagadas' => null,
        ];

        if (! Schema::hasTable('facturas')
            || ! Schema::hasTable('cuenta_detalles')
            || ! Schema::hasColumn('cuentas', 'propina')) {
            return $noDisponible;
        }

        $resumen = $this->propinas->resumenDelRango($desde, $hasta);

        if (! ($resumen['exito'] ?? false)) {
            return $noDisponible;
        }

        return [
            'disponible' => true,
            'cobradas' => round((float) $resumen['total'], 2),
            'cantidad' => (int) $resumen['cantidad'],
            // No hay registro de pago de propinas al personal en el esquema.
            'pagadas' => null,
        ];
    }

    /**
     * Nombre del usuario a partir de su id; null si no hay id o no existe.
     *
     * @param  array<int|string, string>  $nombres
     */
    private function nombreUsuario($usuarioId, array $nombres): ?string
    {
        if (! $usuarioId) {
            return null;
        }

        return $nombres[(int) $usuarioId] ?? null;
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
            ->where('fecha', '>=', $inicio)
            ->where('fecha', '<', $fin)
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
