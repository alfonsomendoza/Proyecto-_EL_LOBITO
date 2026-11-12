<?php

namespace App\Http\Controllers;

use App\Models\CajaApertura;
use App\Services\CajaCierreService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo "Historial Facturas" → listado e impresión de facturas de venta.
 *
 * PORTADO de la funcionalidad "Operaciones" del respaldo PHP puro
 * (`api_operaciones.php::listar_facturas` y `ticket_factura.php`).
 *
 * Permisos (validados SIEMPRE en backend), tomados del PHP puro:
 *  - ADMIN y GERENTE: consultar el historial e imprimir.
 *  - CAJERO         : solo imprimir una factura (reimpresión), no listar.
 *  - MESERO         : sin acceso (403).
 *
 * IMPORTANTE — lectura + anulación controlada:
 *  - El listado y la impresión son de solo lectura y NO modifican datos.
 *  - La EMISIÓN la hace el cobro del POS: VentaController::pagarCuenta llama a
 *    App\Services\FacturaService dentro de la transacción del pago.
 *  - La ANULACIÓN vive en `anular()`: solo ADMIN/GERENTE, solo el mismo día
 *    calendario de emisión, observación obligatoria y transacción. Nunca borra
 *    la factura ni sus pagos: la marca ANULADA, revierte LÓGICAMENTE cada pago
 *    (REVERSADO, sin borrar filas) y reconstruye la cuenta para un nuevo cobro.
 *    Deja la trazabilidad en `anulaciones_caja_auditoria`.
 *  - Las facturas históricas del PHP puro tienen `cuenta_id = NULL` y el cobro
 *    en `ventas`; las nuevas tienen `cuenta_id` y el cobro en `cuentas`. Por
 *    eso las consultas unen ambas tablas con COALESCE.
 */
class FacturaController extends Controller
{
    /** Estados válidos de una factura (enum real de la tabla). */
    private const ESTADOS = ['EMITIDA', 'ANULADA'];

    public function index(Request $request)
    {
        if (! in_array($this->rolActual(), ['ADMIN', 'GERENTE'], true)) {
            abort(403, 'No tienes permiso para ver el historial de facturas.');
        }

        $filtros = $this->filtrosListado($request);

        $consulta = DB::table('facturas as f')
            ->leftJoin('ventas as v', 'v.id', '=', 'f.venta_id')
            // El POS Laravel registra el cobro en `cuentas` (método de pago,
            // banco, recibido y cambio); el PHP puro lo guardaba en `ventas`.
            // Se unen ambas para que el historial sirva para facturas viejas y
            // nuevas sin alterar ningún dato.
            ->leftJoin('cuentas as c', 'c.id', '=', 'f.cuenta_id')
            ->leftJoin('usuarios as u', 'u.id', '=', 'f.usuario_id')
            // El cliente NO está en `facturas`: se identifica a través de la
            // venta de origen (`ventas.cliente_id`), que existe para las
            // facturas del POS Laravel y del PHP puro. Es un LEFT JOIN a
            // propósito: las facturas históricas sin cliente asociado siguen
            // apareciendo cuando no se filtra por cliente. `clientes.id` es
            // clave primaria, así que no genera filas duplicadas.
            ->leftJoin('clientes as cl', 'cl.id', '=', DB::raw(Schema::hasColumn('cuentas', 'cliente_id') ? 'COALESCE(c.cliente_id, v.cliente_id)' : 'v.cliente_id'))
            ->select(
                'f.id',
                'f.numero_factura',
                'f.mesa',
                'f.cuenta_id',
                'f.total',
                'f.fecha_emision',
                'f.estado',
                // Nombre de la cuenta/subcuenta cobrada. Es NULL en las
                // facturas históricas del PHP puro (una sola cuenta por
                // venta), por eso se muestra como "—" en el historial.
                'c.nombre as cuenta_nombre',
                DB::raw('COALESCE(c.metodo_pago, v.metodo_pago) as metodo_pago'),
                DB::raw('COALESCE(c.banco, v.banco) as banco'),
                'u.nombre as cajero',
                'cl.nombre as cliente'
            );

        if ($filtros['numero'] !== '') {
            $consulta->where('f.numero_factura', 'like', '%'.$filtros['numero'].'%');
        }

        if ($filtros['mesa'] !== '') {
            $consulta->where('f.mesa', 'like', '%'.$filtros['mesa'].'%');
        }

        if ($filtros['estado'] !== '') {
            $consulta->where('f.estado', $filtros['estado']);
        }

        // Filtro por cajero. Se usa EXCLUSIVAMENTE `facturas.usuario_id`, que
        // es el usuario autenticado que ejecutó el cobro y emitió la factura
        // (VentaController::pagarCuenta -> FacturaService::emitirPorCobro).
        // No se usa `ventas.usuario_id` ni el mesero: no identifican al cajero.
        if ($filtros['cajero'] !== '') {
            $consulta->where('f.usuario_id', (int) $filtros['cajero']);
        }

        if ($filtros['desde'] !== '') {
            $consulta->whereDate('f.fecha_emision', '>=', $filtros['desde']);
        }

        if ($filtros['hasta'] !== '') {
            $consulta->whereDate('f.fecha_emision', '<=', $filtros['hasta']);
        }

        // Filtro por cliente: se busca por nombre o por identificación
        // (`clientes.identificacion`), que son los campos reales disponibles.
        // Al ser LEFT JOIN, solo se acotan las filas cuando el filtro está
        // presente; con el campo vacío no se descarta ninguna factura.
        if ($filtros['cliente'] !== '') {
            $termino = $filtros['cliente'];
            $consulta->where(function ($sub) use ($termino) {
                $sub->where('cl.nombre', 'like', '%'.$termino.'%')
                    ->orWhere('cl.identificacion', 'like', '%'.$termino.'%');
            });
        }

        // Opciones del filtro por cajero: solo usuarios que realmente tienen
        // facturas emitidas. Se obtienen de `facturas.usuario_id` (atribución
        // fiable del cobro), no de la venta ni de la mesa.
        $cajeros = DB::table('facturas as f')
            ->join('usuarios as u', 'u.id', '=', 'f.usuario_id')
            ->select('u.id', 'u.nombre')
            ->distinct()
            ->orderBy('u.nombre')
            ->get();

        // Historial paginado: se conserva el orden actual (más reciente
        // primero) y TODOS los filtros en los enlaces de página.
        $facturas = $consulta->orderByDesc('f.id')
            ->paginate(50, ['*'], 'pagina')
            ->withQueryString();

        // Datos de anulación SOLO para las filas visibles. Se leen de la
        // auditoría existente (no se agregaron columnas a `facturas`): quién
        // anuló, cuándo y la observación. La tabla puede no existir en
        // esquemas mínimos de prueba; en ese caso el historial sigue igual.
        $anulaciones = collect();

        if (Schema::hasTable('anulaciones_caja_auditoria') && $facturas->isNotEmpty()) {
            $anulaciones = DB::table('anulaciones_caja_auditoria as a')
                ->leftJoin('usuarios as ua', 'ua.id', '=', 'a.usuario_id')
                ->where('a.tipo', 'FACTURA_ANULADA')
                ->whereIn('a.registro_original_id', $facturas->pluck('id')->all())
                ->orderBy('a.id')
                ->get([
                    'a.registro_original_id',
                    'a.motivo',
                    'a.fecha_anulacion',
                    'a.usuario_id',
                    'ua.nombre as usuario_nombre',
                ])
                ->keyBy('registro_original_id');
        }

        return view('facturas.index', [
            'facturas' => $facturas,
            'filtros' => $filtros,
            'cajeros' => $cajeros,
            'anulaciones' => $anulaciones,
        ]);
    }

    public function show(Request $request, $factura)
    {
        if (! in_array($this->rolActual(), ['ADMIN', 'GERENTE', 'CAJERO'], true)) {
            abort(403, 'No tienes permiso para ver esta factura.');
        }

        $f = $this->buscarFacturaPorParam($factura);

        if (! $f) {
            abort(404, 'No se encontró la factura solicitada.');
        }

        // El detalle del consumo se guarda como JSON {"nota": "...", "lineas":
        // [...]} en las facturas emitidas por el POS Laravel, o como texto muy
        // antiguo en las del PHP puro. Se replica el criterio del ticket
        // original (ticket_factura.php) y, si hay líneas, se muestran en tabla.
        $detalle = $f->detalle_consumo ?? '';

        // Mismos desgloses que ticket().
        $decodificado = is_string($detalle) ? json_decode($detalle, true) : null;
        $textoConsumo = is_array($decodificado)
            ? ($decodificado['nota'] ?? ($detalle !== '' ? $detalle : $f->mesa))
            : ($detalle !== '' ? $detalle : $f->mesa);

        $lineas = is_array($decodificado) && isset($decodificado['lineas']) && is_array($decodificado['lineas'])
            ? $decodificado['lineas']
            : [];

        $ajustes = is_array($decodificado) && isset($decodificado['ajustes']) && is_array($decodificado['ajustes'])
            ? $decodificado['ajustes']
            : [];

        $cuentaNombre = $f->cuenta_nombre ?? null;
        if ($cuentaNombre === null && is_array($decodificado)) {
            $cuentaNombre = $decodificado['cuenta'] ?? null;
        }

        $clienteSnapshot = null;
        if (is_array($decodificado) && isset($decodificado['cliente_snapshot']) && is_array($decodificado['cliente_snapshot'])) {
            $clienteSnapshot = (object) $decodificado['cliente_snapshot'];
        }

        $clienteNombre = $f->cliente ?? null;
        $clienteIdentificacion = $f->cliente_identificacion ?? null;

        $pagos = $this->pagosDeFactura($f);

        return view('facturas.factura', [
            'factura' => $f,
            'detalle' => $detalle,
            'textoConsumo' => $textoConsumo,
            'lineas' => $lineas,
            'ajustes' => $ajustes,
            'cuentaNombre' => $cuentaNombre,
            'pagos' => $pagos,
            'clienteSnapshot' => $clienteSnapshot,
            'clienteNombre' => $clienteNombre,
            'clienteIdentificacion' => $clienteIdentificacion,
        ]);

    }

    public function ticket(Request $request)
    {
        if (! in_array($this->rolActual(), ['ADMIN', 'GERENTE', 'CAJERO'], true)) {
            abort(403, 'No tienes permiso para imprimir facturas.');
        }

        $factura = $this->buscarFactura($request);

        if (! $factura) {
            abort(404, 'No se encontró la factura solicitada.');
        }

        // El detalle del consumo se guarda como JSON {"nota": "...", "lineas":
        // [...]} en las facturas emitidas por el POS Laravel, o como texto muy
        // antiguo en las del PHP puro. Se replica el criterio del ticket
        // original (ticket_factura.php) y, si hay líneas, se muestran en tabla.
        $detalle = $factura->detalle_consumo ?? '';
        $decodificado = is_string($detalle) ? json_decode($detalle, true) : null;
        $textoConsumo = is_array($decodificado)
            ? ($decodificado['nota'] ?? ($detalle !== '' ? $detalle : $factura->mesa))
            : ($detalle !== '' ? $detalle : $factura->mesa);

        $lineas = is_array($decodificado) && isset($decodificado['lineas']) && is_array($decodificado['lineas'])
            ? $decodificado['lineas']
            : [];

        // Desglose de ajustes que FacturaService persistió al cobrar:
        // consumo, descuento, base, propina y total. Es la MISMA fuente que
        // usa la prefactura, así el ticket no recalcula con otra fórmula.
        $ajustes = is_array($decodificado) && isset($decodificado['ajustes']) && is_array($decodificado['ajustes'])
            ? $decodificado['ajustes']
            : [];

        // Nombre de la cuenta/subcuenta cobrada. Las facturas nuevas lo traen
        // del JOIN con `cuentas`; las históricas pueden traerlo en el JSON.
        $cuentaNombre = $factura->cuenta_nombre ?? null;
        if ($cuentaNombre === null && is_array($decodificado)) {
            $cuentaNombre = $decodificado['cuenta'] ?? null;
        }

        // Snapshot de cliente guardado al emitir por el POS Laravel. Es una
        // copia fija (id, nombre, identificación, teléfono) que no se
        // modifica si el cliente se edita después. Solo disponible en las
        // facturas nuevas; las históricas sin él conservan el comportamiento
        // actual (se muestra desde v.cliente / null).
        $clienteSnapshot = null;
        if (is_array($decodificado) && isset($decodificado['cliente_snapshot']) && is_array($decodificado['cliente_snapshot'])) {
            $clienteSnapshot = (object) $decodificado['cliente_snapshot'];
        }

        // Cliente de la venta (fuente actual para facturas históricas y
        // respaldo). Se conserva para compatibilidad con el listado actual.
        $clienteNombre = $factura->cliente ?? null;
        $clienteIdentificacion = $factura->cliente_identificacion ?? null;

        // Pagos reales del cobro (pagos mixtos incluidos). Solo se consultan
        // cuando la factura tiene cuenta; las históricas con cuenta_id = NULL
        // se imprimen con el resumen de `ventas`. La tabla existe en la base
        // real (migración 2026_10_18_000002); se protege por si un esquema
        // antiguo de pruebas no la tuviera.
        $pagos = $this->pagosDeFactura($factura);

        return view('facturas.ticket', [
            'factura' => $factura,
            'textoConsumo' => $textoConsumo,
            'lineas' => $lineas,
            'ajustes' => $ajustes,
            'cuentaNombre' => $cuentaNombre,
            'pagos' => $pagos,
            'clienteSnapshot' => $clienteSnapshot,
            'clienteNombre' => $clienteNombre,
            'clienteIdentificacion' => $clienteIdentificacion,
        ]);

    }


    /* ------------------------------------------------------------------
     | Utilidades internas
     | ------------------------------------------------------------------ */

    /**
     * Busca una factura por id numérico (parámetro `id`) o por su número
     * (parámetro `numero`, p. ej. FAC-0001). Misma lógica flexible del PHP puro.
     */
    private function buscarFactura(Request $request): ?object
    {
        $id = trim((string) $request->query('id', ''));
        $numero = trim((string) $request->query('numero', ''));

        $consulta = DB::table('facturas as f')
            ->leftJoin('ventas as v', 'v.id', '=', 'f.venta_id')
            ->leftJoin('cuentas as c', 'c.id', '=', 'f.cuenta_id')
            ->leftJoin('usuarios as u', 'u.id', '=', 'f.usuario_id')
            ->leftJoin('clientes as cl', 'cl.id', '=', DB::raw(Schema::hasColumn('cuentas', 'cliente_id') ? 'COALESCE(c.cliente_id, v.cliente_id)' : 'v.cliente_id'))
            ->select(
                'f.*',
                'c.nombre as cuenta_nombre',
                DB::raw('COALESCE(c.metodo_pago, v.metodo_pago) as metodo_pago'),
                DB::raw('COALESCE(c.banco, v.banco) as banco'),
                DB::raw('COALESCE(c.monto_recibido, v.monto_recibido) as monto_recibido'),
                DB::raw('COALESCE(c.cambio, v.cambio) as cambio'),
                'u.nombre as cajero',
                'cl.nombre as cliente',
                'cl.identificacion as cliente_identificacion',
            );

        if ($id !== '' && ctype_digit($id)) {
            return $consulta->where('f.id', (int) $id)->first();
        }

        if ($numero !== '') {
            return $consulta->where('f.numero_factura', $numero)->first();
        }

        return null;
    }

    /**
     * Versión de búsqueda de factura por parámetro de ruta (id numérico o
     * número FAC-####). Comparte lógica con buscarFactura() pero acepta el
     * parámetro directamente y reutiliza el mismo SELECT con cliente.
     */
    private function buscarFacturaPorParam($param): ?object
    {
        $id = trim((string) $param);
        $consulta = DB::table('facturas as f')
            ->leftJoin('ventas as v', 'v.id', '=', 'f.venta_id')
            ->leftJoin('cuentas as c', 'c.id', '=', 'f.cuenta_id')
            ->leftJoin('usuarios as u', 'u.id', '=', 'f.usuario_id')
            ->leftJoin('clientes as cl', 'cl.id', '=', DB::raw(Schema::hasColumn('cuentas', 'cliente_id') ? 'COALESCE(c.cliente_id, v.cliente_id)' : 'v.cliente_id'))
            ->select(
                'f.*',
                'c.nombre as cuenta_nombre',
                DB::raw('COALESCE(c.metodo_pago, v.metodo_pago) as metodo_pago'),
                DB::raw('COALESCE(c.banco, v.banco) as banco'),
                DB::raw('COALESCE(c.monto_recibido, v.monto_recibido) as monto_recibido'),
                DB::raw('COALESCE(c.cambio, v.cambio) as cambio'),
                'u.nombre as cajero',
                'cl.nombre as cliente',
                'cl.identificacion as cliente_identificacion',
            );

        if (ctype_digit($id)) {
            return $consulta->where('f.id', (int) $id)->first();
        }

        return $consulta->where('f.numero_factura', $id)->first();
    }

    /**
     * Normaliza los filtros del listado. Solo se aceptan valores válidos para
     * no confiar en el navegador.
     *
     * @return array{numero: string, mesa: string, estado: string, desde: string, hasta: string, cliente: string, cajero: string}
     */
    private function filtrosListado(Request $request): array
    {
        $estado = strtoupper(trim((string) $request->query('estado', '')));

        if (! in_array($estado, self::ESTADOS, true)) {
            $estado = '';
        }

        // ID de cajero: solo dígitos positivos. Cualquier otro valor se ignora
        // (equivale a «Todos los cajeros»), igual que el resto de filtros.
        $cajero = trim((string) $request->query('cajero', ''));
        $cajero = ctype_digit($cajero) && (int) $cajero > 0 ? $cajero : '';

        return [
            'numero' => mb_substr(trim((string) $request->query('numero', '')), 0, 20),
            'mesa' => mb_substr(trim((string) $request->query('mesa', '')), 0, 50),
            'estado' => $estado,
            'desde' => $this->fechaValida((string) $request->query('desde', '')),
            'hasta' => $this->fechaValida((string) $request->query('hasta', '')),
            // `clientes.nombre` es varchar(150); se acota el término a ese
            // tamaño para no aceptar entradas desproporcionadas del navegador.
            'cliente' => mb_substr(trim((string) $request->query('cliente', '')), 0, 150),
            'cajero' => $cajero,
        ];
    }

    private function fechaValida(string $fecha): string
    {
        $fecha = trim($fecha);

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            return '';
        }

        [$anio, $mes, $dia] = array_map('intval', explode('-', $fecha));

        return checkdate($mes, $dia, $anio) ? $fecha : '';
    }

    private function rolActual(): string
    {
        return strtoupper((string) (Auth::user()?->rol?->nombre ?? ''));
    }

    /**
     * Anula una factura emitida (solo ADMIN y GERENTE).
     *
     * Reglas (todas validadas en backend):
     * - Solo el MISMO DÍA calendario de emisión (America/Managua).
     * - Si la factura pertenece a un cierre de caja FINALIZADO, se bloquea:
     *   el cierre no se modifica ni se borra.
     * - Marca la factura como ANULADA sin borrarla. Se conservan número,
     *   importe, fecha, cajero, detalle y pagos originales.
     * - Revierte LÓGICAMENTE todos los pagos (REVERSADO, sin borrar filas):
     *   efectivo, tarjeta, transferencia y cualquier otro método. La
     *   devolución física del dinero queda PENDIENTE y no se inventa ningún
     *   movimiento de caja ni gasto ficticio.
     * - Reconstruye la cuenta original (ABIERTA), recupera productos y
     *   comandas (sin duplicarlos), devuelve la venta a ACTIVA y la mesa a
     *   OCUPADA. Se permite recobrar y emitir una nueva factura.
     * - Bloquea la reconstrucción con error 422 si otra venta activa ocupa
     *   la mesa, o si la cuenta ya no está PAGADA.
     * - Registra auditoría en anulaciones_caja_auditoria con usuario, rol,
     *   fecha, observación, apertura y snapshot de los pagos.
     * - Todo dentro de una transacción: si falla, se revierte todo.
     */
    public function anular(Request $request, $factura)
    {
        // Autorización obligatoria en backend (ocultar el botón no basta).
        if (! in_array($this->rolActual(), ['ADMIN', 'GERENTE'], true)) {
            return response()->json([
                'exito' => false,
                'error' => 'No tienes permiso para anular facturas.',
            ], 403);
        }

        // La observación es obligatoria. Se acepta como `motivo` (contrato
        // histórico del endpoint) o como `observacion` (nombre funcional).
        $request->validate([
            'motivo' => ['required_without:observacion', 'nullable', 'string', 'min:5', 'max:1000'],
            'observacion' => ['required_without:motivo', 'nullable', 'string', 'min:5', 'max:1000'],
        ]);

        $motivo = trim((string) ($request->input('motivo') ?? $request->input('observacion') ?? ''));

        try {
            return DB::transaction(function () use ($factura, $motivo) {
                $usuario = Auth::user();
                $rol = $this->rolActual();

                // Mutex de caja: serializa anulaciones y cierres concurrentes.
                if (Schema::hasTable('caja_bloqueo')) {
                    DB::table('caja_bloqueo')->where('id', 1)->lockForUpdate()->get();
                }

                $consulta = DB::table('facturas as f')
                    ->leftJoin('ventas as v', 'v.id', '=', 'f.venta_id')
                    ->leftJoin('cuentas as c', 'c.id', '=', 'f.cuenta_id')
                    ->leftJoin('mesas as m', 'm.id', '=', 'v.mesa_id')
                    ->select(
                        'f.id',
                        'f.numero_factura',
                        'f.estado',
                        'f.total',
                        'f.fecha_emision',
                        'f.cuenta_id',
                        'f.venta_id',
                        'f.usuario_id',
                        'v.estado as venta_estado',
                        'v.mesa_id as mesa_id',
                        'v.apertura_id as venta_apertura_id',
                        'c.apertura_id as cuenta_apertura_id',
                        'm.estado as mesa_estado',
                        'm.numero as mesa_numero'
                    );

                $id = trim((string) $factura);

                if (ctype_digit($id)) {
                    $consulta->where('f.id', (int) $id);
                } else {
                    $consulta->where('f.numero_factura', $id);
                }

                // Bloqueo de la factura: dos solicitudes simultáneas se
                // serializan; la segunda ve el estado ANULADA y se rechaza.
                $facturaRow = $consulta->lockForUpdate()->first();

                if (!$facturaRow) {
                    throw new \RuntimeException('La factura no existe.');
                }

                if ($facturaRow->estado === 'ANULADA') {
                    throw new \RuntimeException('La factura ya está anulada previamente.');
                }

                if ($facturaRow->estado !== 'EMITIDA') {
                    throw new \RuntimeException('La factura no se encuentra en un estado que permita anulación.');
                }

                // ---------------------------------------------------------
                // 1. SOLO EL MISMO DÍA CALENDARIO DE EMISIÓN.
                //    Se compara la fecha de emisión con la fecha del servidor.
                // ---------------------------------------------------------
                $fechaEmision = $facturaRow->fecha_emision
                    ? Carbon::parse($facturaRow->fecha_emision)
                    : null;

                if (!$fechaEmision) {
                    throw new \RuntimeException('La factura no tiene fecha de emisión registrada; no se puede validar la anulación del mismo día.');
                }

                if (!$fechaEmision->isSameDay(now())) {
                    throw new \RuntimeException('Solo se puede anular una factura el mismo día calendario en que fue emitida (emitida el '.$fechaEmision->format('d/m/Y').').');
                }

                // ---------------------------------------------------------
                // 2. ESTADOS COMPATIBLES CON LA RECONSTRUCCIÓN.
                //    La anulación reabre la cuenta y devuelve la venta a
                //    ACTIVA, así que se admite que la venta siga ACTIVA o
                //    ABIERTA (por ejemplo, si ya se había reabierto) o que
                //    esté PAGADA. Una venta CANCELADA no se reconstruye.
                // ---------------------------------------------------------
                $ventaEstado = strtoupper((string) ($facturaRow->venta_estado ?? ''));

                if (!in_array($ventaEstado, ['ACTIVA', 'ABIERTA', 'PAGADA'], true)) {
                    throw new \RuntimeException('La venta de origen no permite reconstruir la operación (estado actual: '.($ventaEstado ?: 'desconocido').').');
                }

                if (empty($facturaRow->cuenta_id)) {
                    throw new \RuntimeException('La factura no tiene una cuenta asociada; no se puede identificar el cobro a revertir.');
                }

                // Bloqueo de las filas que se van a modificar, en orden
                // venta -> cuenta -> mesa, después de bloquear la factura.
                $ventaBloqueada = DB::table('ventas')
                    ->where('id', (int) $facturaRow->venta_id)
                    ->lockForUpdate()
                    ->first();

                if (!$ventaBloqueada) {
                    throw new \RuntimeException('La venta de la factura ya no existe.');
                }

                $cuentaBloqueada = DB::table('cuentas')
                    ->where('id', (int) $facturaRow->cuenta_id)
                    ->lockForUpdate()
                    ->first();

                if (!$cuentaBloqueada) {
                    throw new \RuntimeException('La cuenta de la factura ya no existe.');
                }

                if ($cuentaBloqueada->estado !== 'PAGADA') {
                    throw new \RuntimeException('La cuenta de la factura ya no está PAGADA (estado actual: '.($cuentaBloqueada->estado ?? 'desconocido').'). Solo se revierte un cobro vigente.');
                }

                // ---------------------------------------------------------
                // 3. LA MESA NO PUEDE PERTENECER A OTRA VENTA ACTIVA.
                //    Reabrir esta cuenta no debe pisar una operación nueva.
                // ---------------------------------------------------------
                $otraVentaActiva = false;

                if ($facturaRow->mesa_id) {
                    DB::table('mesas')
                        ->where('id', (int) $facturaRow->mesa_id)
                        ->lockForUpdate()
                        ->get();

                    $otraVentaActiva = DB::table('ventas')
                        ->where('mesa_id', (int) $facturaRow->mesa_id)
                        ->where('id', '!=', (int) $facturaRow->venta_id)
                        ->whereIn('estado', ['ACTIVA', 'ABIERTA'])
                        ->exists();

                    if ($otraVentaActiva) {
                        throw new \RuntimeException('La mesa '.($facturaRow->mesa_numero ?? '').' está ocupada por otra venta activa. No se puede reconstruir la cuenta sin sobrescribir la operación actual.');
                    }
                }

                // ---------------------------------------------------------
                // 4. CIERRES DE CAJA: si el cobro ya pertenece a un cierre
                //    finalizado (distinto de ANULADO), la anulación se BLOQUEA.
                //    No se modifica el cierre, no se inventan movimientos
                //    compensatorios y se explica el motivo al usuario.
                // ---------------------------------------------------------
                [$aperturaId, $cierreFinalizado] = $this->aperturaYCierreDeFactura($facturaRow);

                if ($cierreFinalizado) {
                    throw new \RuntimeException('La factura pertenece al cierre de caja #'.(int) $cierreFinalizado->id.' (estado '.$cierreFinalizado->estado.'), ya finalizado. No se puede anular sin un ajuste de cierre autorizado: el cierre no se modifica ni se borra.');
                }

                // ---------------------------------------------------------
                // 5. PAGOS A REVERTIR (solo los vigentes de ESTA factura).
                //    No se borra ninguna fila: se marca REVERSADO y se
                //    conserva método, banco, moneda, monto, tasa y usuario.
                // ---------------------------------------------------------
                $pagos = collect();

                if (Schema::hasTable('pagos_cuenta')) {
                    $consultaPagos = DB::table('pagos_cuenta')
                        ->where('cuenta_id', (int) $facturaRow->cuenta_id);

                    // Las facturas nuevas vinculan sus líneas con factura_id.
                    // Las históricas (factura_id NULL) se resuelven por cuenta
                    // para no dejarlas sin reversión.
                    if (Schema::hasColumn('pagos_cuenta', 'factura_id')) {
                        $consultaPagos->where(function ($query) use ($facturaRow) {
                            $query->where('factura_id', (int) $facturaRow->id)
                                ->orWhereNull('factura_id');
                        });
                    }

                    // Nunca se revierte dos veces la misma línea.
                    if (Schema::hasColumn('pagos_cuenta', 'estado')) {
                        $consultaPagos->where(function ($query) {
                            $query->whereNull('estado')->orWhere('estado', '!=', 'REVERSADO');
                        });
                    }

                    $pagos = $consultaPagos->orderBy('id')->lockForUpdate()->get();
                }

                // ---------------------------------------------------------
                // 6. Auditoría PRIMERO: su id se graba en cada pago como
                //    `anulacion_id`, de modo que la reversión queda ligada a
                //    la factura, al pago original y al usuario responsable.
                // ---------------------------------------------------------
                $anulacionId = null;

                if (Schema::hasTable('anulaciones_caja_auditoria')) {
                    $anulacionId = DB::table('anulaciones_caja_auditoria')->insertGetId([
                        'tipo' => 'FACTURA_ANULADA',
                        'registro_original_id' => (int) $facturaRow->id,
                        'apertura_id' => $aperturaId ?: null,
                        'cierre_id' => null,
                        'fecha_anulacion' => now(),
                        'usuario_id' => $usuario->id,
                        'rol' => $rol,
                        'motivo' => $motivo,
                        'estado_anterior' => 'EMITIDA',
                        'estado_resultante' => 'ANULADA',
                        'referencia_json' => json_encode([
                            'factura_id' => (int) $facturaRow->id,
                            'numero_factura' => $facturaRow->numero_factura,
                            'venta_id' => (int) $facturaRow->venta_id,
                            'cuenta_id' => (int) $facturaRow->cuenta_id,
                            'mesa_id' => $facturaRow->mesa_id ? (int) $facturaRow->mesa_id : null,
                            'mesa' => $facturaRow->mesa_numero ?? null,
                            'total' => (float) $facturaRow->total,
                            'fecha_emision' => $fechaEmision->toDateTimeString(),
                            'usuario_emision_id' => $facturaRow->usuario_id ? (int) $facturaRow->usuario_id : null,
                            'apertura_id' => $aperturaId ?: null,
                            'cuenta_estado_anterior' => $cuentaBloqueada->estado,
                            'venta_estado_anterior' => $ventaEstado,
                            // Trazabilidad de los pagos originales (no se borran).
                            'pagos' => $pagos->map(fn ($pago) => [
                                'id' => (int) $pago->id,
                                'metodo_pago' => $pago->metodo_pago,
                                'monto' => (float) $pago->monto,
                                'monto_cordobas' => (float) $pago->monto_cordobas,
                            ])->values()->all(),
                            'pagos_reversados' => $pagos->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
                            // La reversión es LÓGICA: el reembolso físico del
                            // dinero queda pendiente y NO se inventa un gasto
                            // ni un movimiento de caja.
                            'reversion_fisica' => 'PENDIENTE',
                        ], JSON_UNESCAPED_UNICODE),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                // ---------------------------------------------------------
                // 7. Reversión lógica de TODOS los métodos de pago.
                // ---------------------------------------------------------
                $reversados = 0;

                if ($pagos->isNotEmpty()) {
                    $cambiosPago = [
                        'estado' => 'REVERSADO',
                        'reversado_en' => now(),
                        'reversado_por' => (int) $usuario->id,
                        'motivo_reversion' => mb_substr($motivo, 0, 255),
                        'devolucion_estado' => 'PENDIENTE',
                    ];

                    if ($anulacionId !== null) {
                        $cambiosPago['anulacion_id'] = (int) $anulacionId;
                    }

                    // Solo se escriben columnas existentes (compatibilidad
                    // con esquemas previos a la migración).
                    foreach (array_keys($cambiosPago) as $columna) {
                        if (!Schema::hasColumn('pagos_cuenta', $columna)) {
                            unset($cambiosPago[$columna]);
                        }
                    }

                    $consultaReversion = DB::table('pagos_cuenta')
                        ->whereIn('id', $pagos->pluck('id'));

                    if (Schema::hasColumn('pagos_cuenta', 'estado')) {
                        $consultaReversion->where(function ($query) {
                            $query->whereNull('estado')->orWhere('estado', '!=', 'REVERSADO');
                        });
                    }

                    if (empty($cambiosPago)) {
                        throw new \RuntimeException('No se pudo preparar la reversión de los pagos: el esquema de pagos_cuenta está incompleto. Aplique las migraciones pendientes.');
                    }

                    $reversados = $consultaReversion->update($cambiosPago);

                    if ($reversados !== $pagos->count()) {
                        throw new \RuntimeException('Los pagos cambiaron durante la anulación; vuelva a consultar la factura antes de reintentar.');
                    }
                }

                // ---------------------------------------------------------
                // 8. Marcar la factura ANULADA sin borrar nada. El número,
                //    importe, fecha, cajero y detalle original se conservan.
                // ---------------------------------------------------------
                $afectadas = DB::table('facturas')
                    ->where('id', (int) $facturaRow->id)
                    ->where('estado', 'EMITIDA')
                    ->update(['estado' => 'ANULADA']);

                if ($afectadas !== 1) {
                    throw new \RuntimeException('La factura cambió de estado durante la anulación; vuelva a consultar el historial.');
                }

                // ---------------------------------------------------------
                // 9. Reconstruir la CUENTA para un nuevo cobro: vuelve a
                //    ABIERTA y se limpia el resumen del cobro anterior. El
                //    consumo (`cuenta_detalles`) y el total NO se tocan.
                // ---------------------------------------------------------
                $cambiosCuenta = [
                    'estado' => 'ABIERTA',
                    'updated_at' => now(),
                ];

                foreach ([
                    'metodo_pago' => null,
                    'banco' => null,
                    'monto_recibido' => null,
                    'cambio' => null,
                    'usuario_cobro' => null,
                    'descuento' => 0,
                    'descuento_motivo' => null,
                    'descuento_usuario_id' => null,
                    'propina' => 0,
                ] as $columna => $valor) {
                    if (Schema::hasColumn('cuentas', $columna)) {
                        $cambiosCuenta[$columna] = $valor;
                    }
                }

                $cuentaReabierta = DB::table('cuentas')
                    ->where('id', (int) $facturaRow->cuenta_id)
                    ->where('estado', 'PAGADA')
                    ->update($cambiosCuenta);

                if ($cuentaReabierta !== 1) {
                    throw new \RuntimeException('La cuenta cambió de estado durante la anulación; vuelva a consultar la factura.');
                }

                // ---------------------------------------------------------
                // 10. La VENTA vuelve a estar operativa y la MESA queda
                //     OCUPADA: solo se libera cuando el nuevo cobro termine.
                // ---------------------------------------------------------
                DB::table('ventas')
                    ->where('id', (int) $facturaRow->venta_id)
                    ->update(['estado' => 'ACTIVA']);

                if ($facturaRow->mesa_id) {
                    DB::table('mesas')
                        ->where('id', (int) $facturaRow->mesa_id)
                        ->update(['estado' => 'OCUPADA']);
                }

                return response()->json([
                    'exito' => true,
                    'mensaje' => 'Factura anulada. Se conservan el comprobante y los pagos originales; los pagos quedaron marcados como REVERSADOS (la devolución física del dinero queda pendiente). La cuenta volvió a estar pendiente de cobro, la venta quedó ACTIVA y la mesa OCUPADA. Puede recobrar y emitir una factura nueva.',
                    'factura_id' => (int) $facturaRow->id,
                    'numero_factura' => $facturaRow->numero_factura,
                    'venta_id' => (int) $facturaRow->venta_id,
                    'cuenta_id' => (int) $facturaRow->cuenta_id,
                    'pagos_reversados' => $reversados,
                    'cuenta_reabierta' => true,
                    'mesa_ocupada' => (bool) $facturaRow->mesa_id,
                ]);
            });
        } catch (\Throwable $e) {
            return response()->json([
                'exito' => false,
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Pagos que pertenecen a UNA factura concreta.
     *
     * Las facturas nuevas vinculan sus líneas con `pagos_cuenta.factura_id`,
     * así el ticket de una factura anulada muestra solo sus pagos originales
     * y el ticket de la factura reemitida muestra solo los nuevos. Las
     * facturas/líneas históricas sin `factura_id` se resuelven por cuenta para
     * no perder la compatibilidad.
     */
    private function pagosDeFactura(object $factura): \Illuminate\Support\Collection
    {
        if (empty($factura->cuenta_id) || !Schema::hasTable('pagos_cuenta')) {
            return collect();
        }

        $columnas = [
            'metodo_pago',
            'banco',
            'moneda',
            'monto',
            'tasa_cambio',
            'monto_cordobas',
        ];

        foreach (['numero_transferencia', 'estado', 'reversado_en', 'devolucion_estado'] as $columna) {
            if (Schema::hasColumn('pagos_cuenta', $columna)) {
                $columnas[] = $columna;
            }
        }

        $consulta = DB::table('pagos_cuenta')
            ->where('cuenta_id', (int) $factura->cuenta_id);

        if (Schema::hasColumn('pagos_cuenta', 'factura_id')) {
            $vinculados = (clone $consulta)
                ->where('factura_id', (int) $factura->id)
                ->exists();

            if ($vinculados) {
                $consulta->where('factura_id', (int) $factura->id);
            }
        }

        return $consulta->orderBy('id')->get($columnas);
    }

    /**
     * Resuelve a qué apertura de caja pertenece una factura y si esa apertura
     * ya tiene un cierre finalizado (vigente).
     *
     * ORDEN:
     *  1. `cuentas.apertura_id` (vínculo directo del cobro).
     *  2. `ventas.apertura_id` (vínculo directo de la venta).
     *  3. Intervalo temporal ACOTADO de cada turno:
     *       [inicio_i, inicio_{i+1}) para los turnos con sucesor;
     *       [inicio_n, fecha de cierre real o ahora] para el último.
     *     Un cobro fuera de todo intervalo NO se atribuye a ciegas: sin
     *     atribución fiable no se bloquea por un cierre antiguo.
     *
     * El error original era usar `cuentasDelTurno()` SIN límite superior:
     * toda cuenta posterior a la apertura #1 caía en su ventana y quedaba
     * bloqueada por el cierre #1. Aquí la ventana SIEMPRE termina.
     *
     * @return array{0: ?int, 1: ?object} [apertura_id, cierre finalizado o null]
     */
    private function aperturaYCierreDeFactura(object $facturaRow): array
    {
        if (! Schema::hasTable('caja_cierre')) {
            return [null, null];
        }

        $aperturaId = (int) ($facturaRow->cuenta_apertura_id ?? 0);

        if ($aperturaId <= 0) {
            $aperturaId = (int) ($facturaRow->venta_apertura_id ?? 0);
        }

        if ($aperturaId > 0) {
            return [$aperturaId, $this->cierreVigenteDeApertura($aperturaId)];
        }

        if (! $facturaRow->cuenta_id || ! Schema::hasTable('caja_apertura')) {
            return [null, null];
        }

        $momento = DB::table('cuentas')->where('id', (int) $facturaRow->cuenta_id)->value('updated_at');

        if (! $momento) {
            return [null, null];
        }

        $momento = Carbon::parse($momento);
        $servicio = app(CajaCierreService::class);

        // Se ordenan por INICIO real, no por id: una apertura pudo registrarse
        // después con una fecha anterior.
        $aperturas = [];

        foreach (DB::table('caja_apertura')->orderBy('id')->get() as $apertura) {
            $modelo = CajaApertura::find($apertura->id);

            if (! $modelo) {
                continue;
            }

            $inicio = $servicio->inicioTurno($modelo);

            if (! $inicio) {
                continue;
            }

            $aperturas[] = [
                'id' => (int) $apertura->id,
                'inicio' => $inicio,
            ];
        }

        usort($aperturas, fn ($a, $b) => $a['inicio']->getTimestamp() <=> $b['inicio']->getTimestamp());

        foreach ($aperturas as $indice => $apertura) {
            $siguiente = $aperturas[$indice + 1] ?? null;

            if ($siguiente !== null) {
                // Turno con sucesor: el instante de corte pertenece al nuevo.
                $fin = $siguiente['inicio'];
                $pertenece = $momento->gte($apertura['inicio']) && $momento->lt($fin);
            } else {
                // Último turno: su ventana termina en el cierre real o, si
                // sigue abierto, en este instante. Nunca es infinita.
                $cierre = $this->cierreVigenteDeApertura($apertura['id']);
                $fin = null;

                if ($cierre) {
                    $referenciaCierre = $cierre->fecha ?? ($cierre->creado_en ?? null);

                    if ($referenciaCierre) {
                        $fin = Carbon::parse($referenciaCierre);
                    }
                }

                $fin = $fin ?? now();
                $pertenece = $momento->gte($apertura['inicio']) && $momento->lte($fin);
            }

            if (! $pertenece) {
                continue;
            }

            return [$apertura['id'], $this->cierreVigenteDeApertura($apertura['id'])];
        }

        return [null, null];
    }

    /**
     * Cierre vigente (no anulado) de una apertura, o null si no se cerró.
     */
    private function cierreVigenteDeApertura(int $aperturaId): ?object
    {
        return DB::table('caja_cierre')
            ->where('apertura_id', $aperturaId)
            ->where('estado', '!=', 'ANULADO')
            ->orderByDesc('id')
            ->first();
    }
}
