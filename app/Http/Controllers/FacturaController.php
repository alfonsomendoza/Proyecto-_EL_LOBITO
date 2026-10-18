<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

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
                'f.total',
                'f.fecha_emision',
                'f.estado',
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

        return view('facturas.ticket', [
            'factura' => $factura,
            'textoConsumo' => $textoConsumo,
            'lineas' => $lineas,
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
}
