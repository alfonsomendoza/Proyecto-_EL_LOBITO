<?php

namespace App\Services;

use App\Models\CajaApertura;
use App\Models\CajaCierre;
use App\Models\CajaGasto;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Auth;

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
     *
     * `$finExclusivo` debe ser true cuando `$fin` es el INICIO del turno
     * siguiente: el instante exacto de corte pertenece al turno nuevo, no al
     * anterior. Sin esta bandera una cuenta cobrada justo en ese instante se
     * contaría en los dos turnos.
     */
    public function cuentasDelTurno(CajaApertura $apertura, ?Carbon $fin = null, bool $finExclusivo = false): Collection
    {
        $inicio = $this->inicioTurno($apertura);
        $fin = $fin ?? now();

        $consulta = DB::table('cuentas')
            ->leftJoin('ventas', 'ventas.id', '=', 'cuentas.venta_id');

        // El ticket necesita el número de mesa; esquemas mínimos de prueba
        // pueden no tener `mesas`. La consulta funciona igual sin el join.
        if (Schema::hasTable('mesas')) {
            $consulta->leftJoin('mesas', 'mesas.id', '=', 'ventas.mesa_id');
        }
        return $consulta
            ->where('cuentas.estado', 'PAGADA')
            ->where(function ($query) use ($apertura, $inicio, $fin, $finExclusivo) {
                $query->where('cuentas.apertura_id', $apertura->id);

                if ($inicio !== null) {
                    $query->orWhere(function ($sub) use ($inicio, $fin, $finExclusivo) {
                        $sub->whereNull('cuentas.apertura_id')
                            ->where('cuentas.updated_at', '>=', $inicio);

                        if ($finExclusivo) {
                            $sub->where('cuentas.updated_at', '<', $fin);
                        } else {
                            $sub->where('cuentas.updated_at', '<=', $fin);
                        }
                    });
                }
            })
            ->orderBy('cuentas.id')
            ->get(Schema::hasTable('mesas')
                ? ['cuentas.*', 'ventas.mesa_id as mesa_id', 'mesas.numero as mesa_numero']
                : ['cuentas.*', 'ventas.mesa_id as mesa_id', DB::raw('NULL as mesa_numero')]);
    }

    /**
     * Resumen de ventas del turno separado por forma de cobro y por banco
     * (las tarjetas se agrupan por banco para contrastar con los vouchers).
     *
     * COBROS CON PAGOS MIXTOS: cuando la cuenta tiene líneas en `pagos_cuenta`
     * (tabla creada por 2026_10_18_000002), el resumen se calcula LÍNEA A
     * LÍNEA: cada método aporta su importe en córdobas y el cambio (que
     * siempre se entrega en efectivo) se descuenta una sola vez del efectivo
     * para quedarse con el neto retenido en caja — el mismo criterio que ya
     * aplicaba `cuentas.total` en los cobros de un solo método. Si la tabla
     * todavía no existe (esquemas manuales de pruebas o despliegue sin
     * migrar) se conserva íntegro el cálculo histórico por
     * `cuentas.metodo_pago` + `cuentas.total`.
     *
     * @return array{cuentas: Collection, cantidad: int, efectivo: float, tarjeta: float, tarjeta_debito: float, tarjeta_credito: float, transferencia: float, otro: float, tarjetas_por_banco: array<string, float>, total: float}
     */
    public function resumenVentas(CajaApertura $apertura, ?Carbon $fin = null, bool $finExclusivo = false): array
    {
        $cuentas = $this->cuentasDelTurno($apertura, $fin, $finExclusivo)
            ->map(function ($cuenta) {
                // Subdivide a C$ qué mesa pertenece: la línea de detalle puede
                // mostrar Mesa y si es cuenta principal o subcuenta.
                $cuenta->es_subcuenta = ! empty($cuenta->cuenta_origen_id);

                return $cuenta;
            });

        $totales = ['EFECTIVO' => 0.0, 'TARJETA' => 0.0, 'TRANSFERENCIA' => 0.0, 'OTRO' => 0.0];
        // Desglose de tarjetas por tipo (débito / crédito). Las tarjetas de
        // cobros antiguos sin líneas y con método genérico `TARJETA` no
        // tienen tipo histórico verificable: se exponen en `tarjeta_generico`.
        $tarjetaDebito = 0.0;
        $tarjetaCredito = 0.0;
        $tarjetaGenerico = 0.0;
        $tarjetasPorBanco = [];

        // Líneas de pago reales de las cuentas del turno (una consulta sola).
        $pagosPorCuenta = collect();

        if (Schema::hasTable('pagos_cuenta') && $cuentas->isNotEmpty()) {
            $consultaPagos = DB::table('pagos_cuenta')
                ->whereIn('cuenta_id', $cuentas->pluck('id'));

            // Una cuenta reabierta y recobrada conserva sus líneas antiguas
            // marcadas REVERSADO. Contarlas otra vez duplicaría la venta del
            // turno; solo suman las líneas ACTIVAS (o históricas sin estado).
            if (Schema::hasColumn('pagos_cuenta', 'estado')) {
                $consultaPagos->where(function ($query) {
                    $query->whereNull('estado')->orWhere('estado', '!=', 'REVERSADO');
                });
            }

            $pagosPorCuenta = $consultaPagos
                ->orderBy('id')
                ->get()
                ->groupBy(fn ($pago) => (int) $pago->cuenta_id);
        }

        // Última factura EMITIDA por cuenta: es la fuente AUTORITATIVA del
        // importe cobrado (incluye propina e impuestos u otros ajustes
        // registrados al cobrar). Las ANULADAS quedan fuera.
        $facturasPorCuenta = collect();
        if (Schema::hasTable('facturas') && $cuentas->isNotEmpty()) {
            $facturasPorCuenta = DB::table('facturas')
                ->whereIn('cuenta_id', $cuentas->pluck('id'))
                ->where('estado', 'EMITIDA')
                ->orderBy('id')
                ->get(['id', 'cuenta_id', 'numero_factura', 'total', 'detalle_consumo'])
                ->groupBy('cuenta_id')
                ->map(fn ($filas) => $filas->last())
                ->mapWithKeys(fn ($f) => [(int) $f->cuenta_id => $f]);
        }

        foreach ($cuentas as $cuenta) {
            $lineas = $pagosPorCuenta->get((int) $cuenta->id);

            // Importes del cobro VENDIENDO: se leen del snapshot histórico
            // de la factura EMITIDA (o, a falta de ella, de las columnas de
            // la cuenta). NUNCA de la configuración fiscal vigente.
            $facturaVigente = $facturasPorCuenta->get((int) $cuenta->id);
            $ajustes = null;
            $decodificado = $facturaVigente ? json_decode((string) $facturaVigente->detalle_consumo, true) : null;
            if (is_array($decodificado)) {
                $ajustes = $decodificado['ajustes'] ?? null;
            }

            $cuenta->consumo = $ajustes['consumo']
                ?? (float) $cuenta->total;
            $cuenta->descuento = $ajustes['descuento']
                ?? (isset($cuenta->descuento) ? (float) $cuenta->descuento : 0.0);
            $cuenta->propina = $ajustes['propina']
                ?? (isset($cuenta->propina) ? (float) $cuenta->propina : 0.0);
            $cuenta->iva = $ajustes['iva'] ?? 0.0;
            $cuenta->retencion_dgi = $ajustes['retencion_dgi'] ?? 0.0;
            $cuenta->retencion_alcaldia = $ajustes['retencion_alcaldia'] ?? 0.0;
            $cuenta->base = $ajustes['base'] ?? round($cuenta->consumo - $cuenta->descuento, 2);
            $cuenta->cobrado = $facturaVigente
                ? (float) $facturaVigente->total
                : round($cuenta->base + $cuenta->iva + $cuenta->propina
                    - $cuenta->retencion_dgi - $cuenta->retencion_alcaldia, 2);
            $cuenta->numero_factura = $facturaVigente ? $facturaVigente->numero_factura : null;
            $cuenta->tiene_factura_vigente = $facturaVigente !== null;

            if ($lineas !== null && $lineas->isNotEmpty()) {
                // ---- Cobro con líneas de pago (mixtos o simples) ----
                $ingresos = ['EFECTIVO' => 0.0, 'TARJETA' => 0.0, 'TRANSFERENCIA' => 0.0, 'OTRO' => 0.0];
                $bancosDeLaCuenta = [];

                foreach ($lineas as $linea) {
                    $categoria = $this->clasificarMetodo($linea->metodo_pago);
                    $ingresos[$categoria] += (float) $linea->monto_cordobas;

                    if ($categoria === 'TARJETA') {
                        $tipoTarjeta = $this->tipoTarjetaDe($linea->metodo_pago);
                        if ($tipoTarjeta === 'DEBITO') {
                            $tarjetaDebito += (float) $linea->monto_cordobas;
                        } elseif ($tipoTarjeta === 'CREDITO') {
                            $tarjetaCredito += (float) $linea->monto_cordobas;
                        } else {
                            $tarjetaGenerico += (float) $linea->monto_cordobas;
                        }
                        $banco = trim((string) ($linea->banco ?? '')) ?: 'SIN BANCO';
                        $bancosDeLaCuenta[$banco] = ($bancosDeLaCuenta[$banco] ?? 0.0) + (float) $linea->monto_cordobas;
                    }
                }

                // El cambio (siempre en efectivo) no es ingreso: se entrega
                // al cliente al salir de la gaveta. Se descuenta UNA vez.
                $cambio = max(0.0, (float) ($cuenta->cambio ?? 0));
                $ingresos['EFECTIVO'] = max(0.0, $ingresos['EFECTIVO'] - $cambio);

                foreach ($ingresos as $categoria => $monto) {
                    $totales[$categoria] += $monto;
                }

                foreach ($bancosDeLaCuenta as $banco => $monto) {
                    $tarjetasPorBanco[$banco] = ($tarjetasPorBanco[$banco] ?? 0.0) + $monto;
                }

                // Se exponen las líneas de pago a la vista para que el ticket
                // muestre el desglose de un cobro mixto sin volver a consultar.
                $metodosCuenta = collect($lineas)->map(fn ($l) => strtoupper((string) $l->metodo_pago))->unique()->values();
                $cuenta->pagos = collect($lineas)->map(fn ($l) => [
                    'metodo_pago' => strtoupper((string) $l->metodo_pago),
                    'monto_cordobas' => (float) $l->monto_cordobas,
                    'banco' => $l->banco,
                ])->values()->all();
                $cuenta->es_mixto = $metodosCuenta->count() > 1;

                continue;
            }

            // ---- Cobro histórico: un solo método sin líneas registradas ----
            $categoria = $this->clasificarMetodo($cuenta->metodo_pago ?? null);
            $monto = (float) $cuenta->total;
            $totales[$categoria] += $monto;

            if ($categoria === 'TARJETA') {
                $tipoTarjeta = $this->tipoTarjetaDe($cuenta->metodo_pago);
                if ($tipoTarjeta === 'DEBITO') {
                    $tarjetaDebito += $monto;
                } elseif ($tipoTarjeta === 'CREDITO') {
                    $tarjetaCredito += $monto;
                } else {
                    $tarjetaGenerico += $monto;
                }
                $banco = trim((string) ($cuenta->banco ?? '')) ?: 'SIN BANCO';
                $tarjetasPorBanco[$banco] = ($tarjetasPorBanco[$banco] ?? 0.0) + $monto;
            }

            $cuenta->pagos = [[
                'metodo_pago' => strtoupper((string) ($cuenta->metodo_pago ?? 'EFECTIVO')),
                'monto_cordobas' => $monto,
                'banco' => $cuenta->banco,
            ]];
            $cuenta->es_mixto = false;
        }

        return [
            'cuentas' => $cuentas,
            'cantidad' => $cuentas->count(),
            'efectivo' => $totales['EFECTIVO'],
            'tarjeta' => $totales['TARJETA'],
            'tarjeta_debito' => $tarjetaDebito,
            'tarjeta_credito' => $tarjetaCredito,
            'tarjeta_generico' => $tarjetaGenerico,
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
                if (Schema::hasTable('caja_bloqueo')) {
                    DB::table('caja_bloqueo')->where('id', 1)->lockForUpdate()->get();
                }
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
    public function anularCierre(int $cierreId, ?string $motivo = null, ?int $usuarioId = null): array
    {
        $motivo = trim((string) $motivo);
        if ($motivo === '') {
            return ['exito' => false, 'codigo' => 422, 'error' => 'El motivo de anulación es obligatorio.'];
        }

        $cierre = CajaCierre::find($cierreId);

        if (! $cierre) {
            return ['exito' => false, 'codigo' => 404, 'error' => 'El cierre no existe.'];
        }

        if ($cierre->estado === 'ANULADO') {
            return ['exito' => false, 'codigo' => 409, 'error' => 'El cierre ya estaba anulado.'];
        }

        try {
            DB::transaction(function () use ($cierre, $motivo, $usuarioId) {
                if (Schema::hasTable('caja_bloqueo')) {
                    DB::table('caja_bloqueo')->where('id', 1)->lockForUpdate()->get();
                }

                // Verificar que no hay otra apertura activa distinta
                $otraAbierta = CajaApertura::query()
                    ->where('estado', 'ABIERTA')
                    ->where('id', '!=', $cierre->apertura_id)
                    ->lockForUpdate()
                    ->exists();
                if ($otraAbierta) {
                    throw new \Exception('Ya existe otra caja abierta actualmente.');
                }

                $estadoAnterior = $cierre->estado;
                CajaCierre::query()->where('id', $cierre->id)->update(['estado' => 'ANULADO']);

                $vigentes = CajaCierre::query()
                    ->where('apertura_id', $cierre->apertura_id)
                    ->where('estado', '!=', 'ANULADO')
                    ->count();

                if ($vigentes === 0) {
                    CajaApertura::query()
                        ->where('id', $cierre->apertura_id)
                        ->update(['estado' => 'ABIERTA']);
                }

                if (Schema::hasTable('anulaciones_caja_auditoria')) {
                    $usuario = $usuarioId ?: Auth::id();
                    $rol = Auth::check() ? strtoupper((string) (Auth::user()->rol->nombre ?? '')) : null;
                    DB::table('anulaciones_caja_auditoria')->insert([
                        'tipo' => 'cierre',
                        'registro_original_id' => (int) $cierre->id,
                        'apertura_id' => (int) $cierre->apertura_id,
                        'cierre_id' => (int) $cierre->id,
                        'fecha_anulacion' => now(),
                        'usuario_id' => $usuario,
                        'rol' => $rol,
                        'motivo' => $motivo,
                        'estado_anterior' => $estadoAnterior,
                        'estado_resultante' => 'ANULADO',
                        'referencia_json' => json_encode(['apertura_id' => (int) $cierre->apertura_id]),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            });
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), 'Ya existe otra caja abierta')) {
                return ['exito' => false, 'codigo' => 409, 'error' => $e->getMessage()];
            }
            return ['exito' => false, 'codigo' => 500, 'error' => 'No fue posible anular el cierre.'];
        }

        return ['exito' => true, 'codigo' => 200, 'mensaje' => 'Cierre anulado correctamente.'];
    }

    /**
     * Tipo de tarjeta a partir del método de pago registrado. Históricos
     * con método genérico `TARJETA` no permiten distinguir y caen en GENERICO.
     */
    private function tipoTarjetaDe(?string $metodo): string
    {
        $normalizado = strtoupper(trim((string) $metodo));

        if (str_contains($normalizado, 'DEBITO')) {
            return 'DEBITO';
        }

        if (str_contains($normalizado, 'CREDITO')) {
            return 'CREDITO';
        }

        return 'GENERICO';
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
