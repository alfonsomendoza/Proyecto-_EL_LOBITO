<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
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
 * IMPORTANTE — solo lectura:
 *  - Este módulo NO genera ni anula facturas, y NO toca ventas, cuentas ni
 *    caja. La acción destructiva "eliminar_venta" del PHP puro NO se migró.
 *  - La EMISIÓN la hace el cobro del POS: VentaController::pagarCuenta llama a
 *    App\Services\FacturaService dentro de la transacción del pago. Aquí solo
 *    se consulta e imprime; consultar o imprimir nunca modifica datos.
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
            ->leftJoin('clientes as cl', 'cl.id', '=', 'v.cliente_id')
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

        // Historial extenso: se acota para no cargar miles de filas de golpe.
        $facturas = $consulta->orderByDesc('f.id')->limit(500)->get();

        return view('facturas.index', [
            'facturas' => $facturas,
            'filtros' => $filtros,
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

        // Pagos reales del cobro (pagos mixtos incluidos). Solo se consultan
        // cuando la factura tiene cuenta; las históricas con cuenta_id = NULL
        // se imprimen con el resumen de `ventas`. La tabla existe en la base
        // real (migración 2026_10_18_000002); se protege por si un esquema
        // antiguo de pruebas no la tuviera.
        $pagos = collect();

        if (! empty($factura->cuenta_id) && Schema::hasTable('pagos_cuenta')) {
            $columnas = [
                'metodo_pago',
                'banco',
                'moneda',
                'monto',
                'tasa_cambio',
                'monto_cordobas',
            ];

            // `numero_transferencia` solo existe si se aplicó la migración
            // extendida de pagos_cuenta; se lee solo cuando esté disponible.
            if (Schema::hasColumn('pagos_cuenta', 'numero_transferencia')) {
                $columnas[] = 'numero_transferencia';
            }

            $pagos = DB::table('pagos_cuenta')
                ->where('cuenta_id', (int) $factura->cuenta_id)
                ->orderBy('id')
                ->get($columnas);
        }

        return view('facturas.ticket', [
            'factura' => $factura,
            'textoConsumo' => $textoConsumo,
            'lineas' => $lineas,
            'ajustes' => $ajustes,
            'cuentaNombre' => $cuentaNombre,
            'pagos' => $pagos,
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
            ->select(
                'f.*',
                'c.nombre as cuenta_nombre',
                DB::raw('COALESCE(c.metodo_pago, v.metodo_pago) as metodo_pago'),
                DB::raw('COALESCE(c.banco, v.banco) as banco'),
                DB::raw('COALESCE(c.monto_recibido, v.monto_recibido) as monto_recibido'),
                DB::raw('COALESCE(c.cambio, v.cambio) as cambio'),
                'u.nombre as cajero'
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
     * Normaliza los filtros del listado. Solo se aceptan valores válidos para
     * no confiar en el navegador.
     *
     * @return array{numero: string, mesa: string, estado: string, desde: string, hasta: string, cliente: string}
     */
    private function filtrosListado(Request $request): array
    {
        $estado = strtoupper(trim((string) $request->query('estado', '')));

        if (! in_array($estado, self::ESTADOS, true)) {
            $estado = '';
        }

        return [
            'numero' => mb_substr(trim((string) $request->query('numero', '')), 0, 20),
            'mesa' => mb_substr(trim((string) $request->query('mesa', '')), 0, 50),
            'estado' => $estado,
            'desde' => $this->fechaValida((string) $request->query('desde', '')),
            'hasta' => $this->fechaValida((string) $request->query('hasta', '')),
            // `clientes.nombre` es varchar(150); se acota el término a ese
            // tamaño para no aceptar entradas desproporcionadas del navegador.
            'cliente' => mb_substr(trim((string) $request->query('cliente', '')), 0, 150),
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
     * - Marca la factura como ANULADA sin borrarla ni tocar la venta.
     * - Reabre SOLO la cuenta/subcuenta de la factura para permitir corregir
     *   el cobro y volver a facturar (venta, productos, comandas y cocina se
     *   conservan íntegros).
     * - Si era la única cuenta pagada de la venta, la venta vuelve a ACTIVA y
     *   la mesa a OCUPADA.
     * - Conserva pagos_cuenta como historial y registra auditoría en
     *   anulaciones_caja_auditoria (usuario, fecha, motivo).
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

        $request->validate([
            'motivo' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $motivo = trim((string) $request->input('motivo'));

        try {
            return DB::transaction(function () use ($factura, $motivo) {
                $usuario = Auth::user();
                $rol = $this->rolActual();

                $consulta = DB::table('facturas as f')
                    ->leftJoin('ventas as v', 'v.id', '=', 'f.venta_id')
                    ->leftJoin('cuentas as c', 'c.id', '=', 'f.cuenta_id')
                    ->leftJoin('mesas as m', 'm.id', '=', 'v.mesa_id')
                    ->select(
                        'f.id',
                        'f.numero_factura',
                        'f.estado',
                        'f.total',
                        'f.cuenta_id',
                        'f.venta_id',
                        'v.estado as venta_estado',
                        'v.mesa_id',
                        'm.id as mesa_id',
                        'm.estado as mesa_estado',
                        'm.numero as mesa_numero'
                    );

                $id = trim((string) $factura);

                if (ctype_digit($id)) {
                    $consulta->where('f.id', (int) $id);
                } else {
                    $consulta->where('f.numero_factura', $id);
                }

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

                if ($facturaRow->venta_estado === 'CANCELADA') {
                    throw new \RuntimeException('No se puede anular: la venta de origen ya fue cancelada.');
                }

                // ---------------------------------------------------------
                // Auditoría: usuario, fecha y motivo (mecanismo existente).
                // ---------------------------------------------------------
                if (Schema::hasTable('anulaciones_caja_auditoria')) {
                    DB::table('anulaciones_caja_auditoria')->insert([
                        'tipo' => 'FACTURA_ANULADA',
                        'registro_original_id' => (int) $facturaRow->id,
                        'apertura_id' => null,
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
                            'cuenta_id' => $facturaRow->cuenta_id ? (int) $facturaRow->cuenta_id : null,
                            'mesa_id' => $facturaRow->mesa_id ? (int) $facturaRow->mesa_id : null,
                            'mesa' => $facturaRow->mesa_numero ?? null,
                            'total' => (float) $facturaRow->total,
                        ], JSON_UNESCAPED_UNICODE),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                // ---------------------------------------------------------
                // 7. Marcar factura como ANULADA (sin borrar, sin destruir la
                //    venta ni sus pagos: pagos_cuenta queda como historial).
                // ---------------------------------------------------------
                DB::table('facturas')->where('id', $facturaRow->id)->update([
                    'estado' => 'ANULADA',
                ]);

                // ---------------------------------------------------------
                // Reabrir solo la cuenta/subcuenta de esta factura para
                // corregir el cobro. Nunca se tocan otras cuentas ni se
                // reverten pagos ajenos.
                // ---------------------------------------------------------
                if ($facturaRow->cuenta_id) {
                    DB::table('cuentas')->where('id', (int) $facturaRow->cuenta_id)->update([
                        'estado' => 'ABIERTA',
                        'updated_at' => now(),
                    ]);

                    // Si esta era la única cuenta pagada de la venta, la venta
                    // vuelve a ACTIVA y la mesa a OCUPADA.
                    $otrasPagadas = DB::table('cuentas')
                        ->where('venta_id', $facturaRow->venta_id)
                        ->where('id', '!=', (int) $facturaRow->cuenta_id)
                        ->where('estado', 'PAGADA')
                        ->exists();

                    if (!$otrasPagadas) {
                        DB::table('ventas')->where('id', $facturaRow->venta_id)->update(['estado' => 'ACTIVA']);

                        if ($facturaRow->mesa_id) {
                            DB::table('mesas')->where('id', (int) $facturaRow->mesa_id)->update([
                                'estado' => 'OCUPADA',
                            ]);
                        }
                    }
                }

                return response()->json([
                    'exito' => true,
                    'mensaje' => 'La factura fue anulada correctamente. La venta y la mesa quedan disponibles para corregir el cobro y volver a facturar.',
                    'factura_id' => (int) $facturaRow->id,
                    'numero_factura' => $facturaRow->numero_factura,
                    'venta_id' => (int) $facturaRow->venta_id,
                    'cuenta_id' => $facturaRow->cuenta_id ? (int) $facturaRow->cuenta_id : null,
                ]);
            });
        } catch (\Throwable $e) {
            return response()->json([
                'exito' => false,
                'error' => $e->getMessage(),
            ], 422);
        }
    }
}
