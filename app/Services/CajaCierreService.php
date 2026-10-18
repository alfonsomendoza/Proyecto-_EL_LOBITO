<?php

namespace App\Services;

use App\Models\CajaApertura;
use App\Models\CajaCierre;
use App\Models\CajaGasto;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lógica de negocio del Cierre de Caja (arqueo de turno).
 *
 * FÓRMULA (validada contra el respaldo PHP `CajaService::cerrarTurno` y el
 * diseño "correcto" de `CajaRepository`, descartando sus errores):
 *
 *   ventas_efectivo = Σ total de cuentas PAGADAS en EFECTIVO del turno
 *   egresos         = Σ gastos ACTIVOS del turno (los anulados NO cuentan)
 *   total_esperado  = monto_inicial + ventas_efectivo + otros_ingresos
 *                     − egresos − retiros
 *   monto_extranjero= Σ (denominación USD × cantidad contada)
 *   total_contado   = Σ (denominación C$ × cantidad) + monto_extranjero × tasa
 *   diferencia      = total_contado − total_esperado
 *   estado          = diferencia >= 0 ? 'CUADRA' : 'DESCUADRE'
 *
 * DECISIONES documentadas:
 *  - Las TARJETAS y TRANSFERENCIAS se reportan aparte por banco: nunca suman
 *    al efectivo esperado porque ese dinero no entra a la gaveta.
 *  - NO se resta el "cambio": en el POS Laravel `cuentas.total` ya es el neto
 *    que se retiene en caja (el vuelto se descuenta al entregarlo). Restarlo
 *    de nuevo inflaría el descuadre (era un bug del PHP puro).
 *  - La atribución de ventas al turno usa la ventana [creado_en, ahora] del
 *    turno; si en el futuro se rellena `cuentas.apertura_id`, ese valor manda.
 */
class CajaCierreService
{
    /** Denominaciones de córdobas que cuenta el arqueo. */
    public const DENOMINACIONES_LOCAL = [1000, 500, 200, 100, 50, 20, 10, 5, 1, 0.5];

    /** Denominaciones de dólares que cuenta el arqueo. */
    public const DENOMINACIONES_DOLAR = [100, 50, 20, 10, 5, 1];

    /**
     * Apertura de caja activa (ABIERTA). Se consulta en el servidor; nunca se
     * confía en un id enviado por el navegador.
     */
    public function aperturaActiva(): ?CajaApertura
    {
        return CajaApertura::query()
            ->where('estado', 'ABIERTA')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Cierre vigente (no anulado) de una apertura, o null si no se ha cerrado.
     */
    public function cierreVigente(int $aperturaId): ?CajaCierre
    {
        return CajaCierre::query()
            ->where('apertura_id', $aperturaId)
            ->where('estado', '!=', 'ANULADO')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Últimos cierres para el historial del panel (más recientes primero).
     */
    public function ultimosCierres(int $limite = 15): Collection
    {
        return CajaCierre::query()
            ->with('usuario')
            ->orderByDesc('id')
            ->limit($limite)
            ->get();
    }

    /**
     * Suma de egresos ACTIVOS del turno. Los anulados quedan fuera.
     */
    public function totalEgresos(int $aperturaId): float
    {
        return (float) CajaGasto::query()
            ->where('apertura_id', $aperturaId)
            ->where('estado', 'ACTIVO')
            ->sum('monto');
    }

    /**
     * Gastos ACTIVOS del turno, con el usuario que los registró.
     */
    public function gastosActivos(int $aperturaId): Collection
    {
        return CajaGasto::query()
            ->with('usuario')
            ->where('apertura_id', $aperturaId)
            ->where('estado', 'ACTIVO')
            ->orderBy('id')
            ->get();
    }

    /**
     * Cuentas PAGADAS que pertenecen al turno.
     *
     * En el POS Laravel la venta y sus cuentas nacen con `apertura_id = null`,
     * así que el turno se delimita por tiempo: [creado_en, ahora]. Si en el
     * futuro `cuentas.apertura_id` se rellena, ese vínculo directo tiene
     * prioridad y no depende de la ventana temporal.
     *
     * `$fin` permite ACOTAR el final de la ventana. El cierre de un turno pasa
     * `null` (hasta ahora, porque no existe un turno posterior abierto); el
     * Resumen del Día pasa el inicio del siguiente turno para que las ventanas
     * de varios turnos NO se solapen (evita contar dos veces una venta).
     */
    public function cuentasDelTurno(CajaApertura $apertura, ?Carbon $fin = null): Collection
    {
        $inicio = $this->inicioTurno($apertura);
        $fin = $fin ?? now();

        return DB::table('cuentas')
            ->where('estado', 'PAGADA')
            ->where(function ($query) use ($apertura, $inicio, $fin) {
                $query->where('apertura_id', $apertura->id);

                if ($inicio !== null) {
                    $query->orWhere(function ($sub) use ($inicio, $fin) {
                        $sub->whereNull('apertura_id')
                            ->whereBetween('updated_at', [$inicio, $fin]);
                    });
                }
            })
            ->orderBy('id')
            ->get();
    }

    /**
     * Resumen de ventas del turno separado por forma de cobro y por banco
     * (las tarjetas se agrupan por banco para contrastar con los vouchers).
     *
     * @return array{cuentas: Collection, cantidad: int, efectivo: float, tarjeta: float, transferencia: float, otro: float, tarjetas_por_banco: array<string, float>, total: float}
     */
    public function resumenVentas(CajaApertura $apertura, ?Carbon $fin = null): array
    {
        $cuentas = $this->cuentasDelTurno($apertura, $fin);

        $totales = ['EFECTIVO' => 0.0, 'TARJETA' => 0.0, 'TRANSFERENCIA' => 0.0, 'OTRO' => 0.0];
        $tarjetasPorBanco = [];

        foreach ($cuentas as $cuenta) {
            $categoria = $this->clasificarMetodo($cuenta->metodo_pago ?? null);
            $monto = (float) $cuenta->total;
            $totales[$categoria] += $monto;

            if ($categoria === 'TARJETA') {
                $banco = trim((string) ($cuenta->banco ?? '')) ?: 'SIN BANCO';
                $tarjetasPorBanco[$banco] = ($tarjetasPorBanco[$banco] ?? 0.0) + $monto;
            }
        }

        return [
            'cuentas' => $cuentas,
            'cantidad' => $cuentas->count(),
            'efectivo' => $totales['EFECTIVO'],
            'tarjeta' => $totales['TARJETA'],
            'transferencia' => $totales['TRANSFERENCIA'],
            'otro' => $totales['OTRO'],
            'tarjetas_por_banco' => $tarjetasPorBanco,
            'total' => $totales['EFECTIVO'] + $totales['TARJETA'] + $totales['TRANSFERENCIA'] + $totales['OTRO'],
        ];
    }

    /**
     * Inicio de la ventana temporal del turno. Preferimos `creado_en` (hora del
     * servidor de base de datos, coherente con los TIMESTAMP de `cuentas`);
     * `fecha` es la hora local escrita por el formulario y sirve de respaldo.
     */
    public function inicioTurno(CajaApertura $apertura): ?Carbon
    {
        $referencia = $apertura->creado_en ?? $apertura->fecha;

        return $referencia ? Carbon::parse($referencia) : null;
    }

    /**
     * Total físico contado: córdobas + (dólares × tasa de cambio).
     */
    public function totalContado(array $conteoLocal, array $conteoDolares, float $tasaCambio): float
    {
        $local = 0.0;
        foreach ($conteoLocal as $denominacion => $cantidad) {
            $local += ((float) $denominacion) * max(0, (int) $cantidad);
        }

        $extranjero = 0.0;
        foreach ($conteoDolares as $denominacion => $cantidad) {
            $extranjero += ((float) $denominacion) * max(0, (int) $cantidad);
        }

        return $local + ($extranjero * $tasaCambio);
    }

    /**
     * Monto crudo en dólares contado (sin convertir).
     */
    public function montoExtranjero(array $conteoDolares): float
    {
        $total = 0.0;
        foreach ($conteoDolares as $denominacion => $cantidad) {
            $total += ((float) $denominacion) * max(0, (int) $cantidad);
        }

        return $total;
    }

    /**
     * Mesas cuya venta sigue ABIERTA (ACTIVA/ABIERTA) y conserva consumos
     * pendientes de cobro. Cada mesa se cuenta UNA sola vez aunque tenga varias
     * subcuentas, porque se agrupa por mesa con SELECT DISTINCT.
     *
     * Definición de "consumo pendiente" usando el esquema real (sin inventar
     * columnas ni tablas):
     *   P1. Existe una cuenta ABIERTA con total > 0 en esa venta.
     *   P2. Existe una asignación vigente (cuenta_detalles.eliminado = 0) a una
     *       cuenta ABIERTA; cubre totales almacenados en cero pero con detalle.
     *   P3. Existe un detalle ACTIVO cuyas unidades NO quedan cubiertas por las
     *       asignaciones vigentes de cuentas ABIERTA/PAGADA; es el consumo que
     *       se quedó sin cuenta válida (p. ej. al cancelar una subcuenta).
     *
     * A propósito NO se mira `mesas.estado` (la mesa pudo quedar mal marcada y
     * aún así tener consumo) ni las cuentas PAGADA/CANCELADA por sí solas: una
     * cuenta pagada ya no se cobra y una cancelada borra sus asignaciones.
     *
     * @return Collection<int, object> mesas {id, numero}
     */
    public function mesasConConsumosPendientes(): Collection
    {
        return DB::table('ventas as v')
            ->join('mesas as m', 'm.id', '=', 'v.mesa_id')
            ->whereIn('v.estado', ['ACTIVA', 'ABIERTA'])
            ->whereNotNull('v.mesa_id')
            ->where(function ($consulta) {
                // P1: cuenta abierta con importe por cobrar.
                $consulta->whereExists(function ($sub) {
                    $sub->select(DB::raw(1))
                        ->from('cuentas as c')
                        ->whereColumn('c.venta_id', 'v.id')
                        ->where('c.estado', 'ABIERTA')
                        ->where('c.total', '>', 0);
                });

                // P2: unidades asignadas a una cuenta ABIERTA.
                $consulta->orWhereExists(function ($sub) {
                    $sub->select(DB::raw(1))
                        ->from('cuenta_detalles as cd')
                        ->join('cuentas as c2', 'c2.id', '=', 'cd.cuenta_id')
                        ->whereColumn('cd.venta_id', 'v.id')
                        ->where('cd.eliminado', false)
                        ->where('c2.estado', 'ABIERTA');
                });

                // P3: detalle activo sin cobertura por cuentas vigentes.
                $consulta->orWhereExists(function ($sub) {
                    $sub->select(DB::raw(1))
                        ->from('venta_detalles as vd')
                        ->whereColumn('vd.venta_id', 'v.id')
                        ->where('vd.estado', 'ACTIVO')
                        ->whereRaw(
                            'vd.cantidad > ('
                            .'select coalesce(sum(cd.cantidad), 0) '
                            .'from cuenta_detalles cd '
                            .'inner join cuentas cv on cv.id = cd.cuenta_id '
                            .'where cd.venta_detalle_id = vd.id '
                            .'and cd.eliminado = 0 '
                            ."and cv.estado in ('ABIERTA', 'PAGADA')"
                            .')'
                        );
                });
            })
            ->distinct()
            ->orderBy('m.id')
            ->get(['m.id', 'm.numero']);
    }

    /**
     * Mensaje único que se muestra cuando el cierre se bloquea por mesas con
     * consumos pendientes. El conteo ya viene sin doble conteo de subcuentas.
     */
    public function mensajeCierreBloqueado(int $mesasPendientes): string
    {
        $sustantivo = $mesasPendientes === 1 ? 'mesa ocupada' : 'mesas ocupadas';

        return sprintf(
            'No se puede cerrar la caja. Hay %d %s con consumos pendientes de cobro. '
            .'Cobra o resuelve las cuentas pendientes antes de intentar el cierre.',
            $mesasPendientes,
            $sustantivo
        );
    }

    /**
     * Registra el cierre del turno y marca la apertura como CERRADA, todo en
     * una transacción. Nunca se confía en totales enviados por el navegador:
     * las ventas y los egresos se recalculan en el servidor.
     *
     * @return array{exito: bool, cierre_id?: int, diferencia?: float, estado?: string, codigo?: int, mesas_pendientes?: int, error?: string}
     */
    public function registrarCierre(
        int $aperturaId,
        float $otrosIngresos,
        array $conteoLocal,
        array $conteoDolares,
        float $tasaCambio,
        float $retiros,
        ?string $observaciones,
        ?int $usuarioId
    ): array {
        if ($otrosIngresos < 0 || $retiros < 0 || $tasaCambio <= 0) {
            return ['exito' => false, 'error' => 'Los importes del cierre no son válidos.'];
        }

        try {
            return DB::transaction(function () use ($aperturaId, $otrosIngresos, $conteoLocal, $conteoDolares, $tasaCambio, $retiros, $observaciones, $usuarioId) {
                $apertura = CajaApertura::query()
                    ->where('id', $aperturaId)
                    ->lockForUpdate()
                    ->first();

                if (! $apertura) {
                    throw new \RuntimeException('La apertura de caja no existe.');
                }

                if ($apertura->estado !== 'ABIERTA') {
                    throw new \RuntimeException('La caja ya no está abierta; no se puede cerrar de nuevo.');
                }

                // BLOQUEO ESTRICTO: no se cierra la caja si queda alguna mesa
                // con consumos pendientes de cobro. La revisión ocurre ANTES de
                // cualquier escritura, así que un rechazo no deja cambios
                // parciales (ni cierre, ni apertura, ni mesas/ventas/cuentas).
                // El conteo es por mesa distinta, sin duplicar subcuentas.
                //
                // CONCURRENCIA: esto corre dentro de la transacción que ya
                // bloquea la apertura (lockForUpdate), así que dos cierres a la
                // vez no se pisan. Límite conocido del esquema: si un pedido o
                // cobro se registra justo en los milisegundos posteriores a esta
                // comprobación, no se alcanza a contar (el POS no bloquea la
                // apertura al vender). En el peor caso el cierre se bloquea de
                // más y el cajero reintenta; nunca se ignora un pendiente que ya
                // existía al momento de comprobar.
                $mesasPendientes = $this->mesasConConsumosPendientes()->count();

                if ($mesasPendientes > 0) {
                    return [
                        'exito' => false,
                        'codigo' => 409,
                        'mesas_pendientes' => $mesasPendientes,
                        'error' => $this->mensajeCierreBloqueado($mesasPendientes),
                    ];
                }

                $ventas = $this->resumenVentas($apertura);
                $egresos = $this->totalEgresos($aperturaId);
                $montoApertura = (float) $apertura->monto_inicial;
                $montoExtranjero = $this->montoExtranjero($conteoDolares);

                $totalEsperado = $montoApertura + $ventas['efectivo'] + $otrosIngresos - $egresos - $retiros;
                $totalContado = $this->totalContado($conteoLocal, $conteoDolares, $tasaCambio);
                $diferencia = $totalContado - $totalEsperado;
                $estado = $diferencia >= 0 ? 'CUADRA' : 'DESCUADRE';

                $cierre = CajaCierre::create([
                    'apertura_id' => $apertura->id,
                    'fecha' => now(),
                    'cajero' => (string) ($apertura->cajero ?? ''),
                    'turno' => $apertura->turno,
                    'caja_id' => (int) ($apertura->caja_id ?? 1),
                    'monto_apertura' => $montoApertura,
                    'ventas_efectivo' => $ventas['efectivo'],
                    'otros_ingresos' => $otrosIngresos,
                    'egresos' => $egresos,
                    'retiros' => $retiros,
                    'total_esperado' => $totalEsperado,
                    'tasa_cambio' => $tasaCambio,
                    'monto_extranjero' => $montoExtranjero,
                    'total_contado' => $totalContado,
                    'diferencia' => $diferencia,
                    'estado' => $estado,
                    'conteo_json' => [
                        'local' => $conteoLocal,
                        'dolares' => $conteoDolares,
                    ],
                    'observaciones' => $observaciones,
                    'usuario_id' => $usuarioId,
                ]);

                CajaApertura::query()
                    ->where('id', $apertura->id)
                    ->update(['estado' => 'CERRADA']);

                return [
                    'exito' => true,
                    'cierre_id' => (int) $cierre->id,
                    'diferencia' => $diferencia,
                    'estado' => $estado,
                ];
            });
        } catch (\Throwable $e) {
            return ['exito' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Anula un cierre de forma lógica. Si era el último cierre vigente de la
     * apertura, la apertura vuelve a ABIERTA para poder cerrarla de nuevo.
     *
     * @return array{exito: bool, codigo: int, mensaje?: string, error?: string}
     */
    public function anularCierre(int $cierreId): array
    {
        $cierre = CajaCierre::find($cierreId);

        if (! $cierre) {
            return ['exito' => false, 'codigo' => 404, 'error' => 'El cierre no existe.'];
        }

        if ($cierre->estado === 'ANULADO') {
            return ['exito' => false, 'codigo' => 409, 'error' => 'El cierre ya estaba anulado.'];
        }

        try {
            DB::transaction(function () use ($cierre) {
                CajaCierre::query()->where('id', $cierre->id)->update(['estado' => 'ANULADO']);

                $vigentes = CajaCierre::query()
                    ->where('apertura_id', $cierre->apertura_id)
                    ->where('estado', '!=', 'ANULADO')
                    ->count();

                if ($vigentes === 0) {
                    // Solo reabrimos si no hay ya otra caja abierta.
                    $otraAbierta = CajaApertura::query()
                        ->where('estado', 'ABIERTA')
                        ->where('id', '!=', $cierre->apertura_id)
                        ->exists();

                    if (! $otraAbierta) {
                        CajaApertura::query()
                            ->where('id', $cierre->apertura_id)
                            ->update(['estado' => 'ABIERTA']);
                    }
                }
            });
        } catch (\Throwable $e) {
            return ['exito' => false, 'codigo' => 500, 'error' => 'No fue posible anular el cierre.'];
        }

        return ['exito' => true, 'codigo' => 200, 'mensaje' => 'Cierre anulado correctamente.'];
    }

    /**
     * Clasifica el método de pago real del POS (EFECTIVO/TARJETA/TRANSFERENCIA)
     * y también los valores históricos (EFECTIVO_CORDOBA, TARJETA_DEBITO...).
     *
     * Es pública para que el Resumen del Día reutilice EXACTAMENTE el mismo
     * criterio que el cierre y no se dupliquen reglas de negocio.
     */
    public function clasificarMetodo(?string $metodo): string
    {
        $normalizado = strtoupper(trim((string) $metodo));

        if ($normalizado === '' || str_contains($normalizado, 'EFECTIVO')) {
            return 'EFECTIVO';
        }

        if (str_contains($normalizado, 'TARJETA') || str_contains($normalizado, 'DEBITO') || str_contains($normalizado, 'CREDITO')) {
            return 'TARJETA';
        }

        if (str_contains($normalizado, 'TRANSFERENCIA')) {
            return 'TRANSFERENCIA';
        }

        return 'OTRO';
    }
}
