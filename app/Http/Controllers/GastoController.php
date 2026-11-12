<?php

namespace App\Http\Controllers;

use App\Rules\NumeroEstricto;
use App\Services\GastoService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Módulo de Gastos (salidas de dinero).
 *
 * Permisos (validados SIEMPRE en backend, no solo en Blade/JS):
 *  - ADMIN y GERENTE: registrar, consultar, imprimir y anular.
 *  - CAJERO         : registrar, consultar e imprimir. NO puede anular (403).
 *  - MESERO         : sin acceso al módulo (403).
 */
class GastoController extends Controller
{
    public function __construct(private GastoService $service)
    {
    }

    /* ------------------------------------------------------------------
     | Panel del módulo: formulario + gastos del turno activo
     | ------------------------------------------------------------------ */

    public function index(Request $request)
    {
        if (!$this->puedeOperar()) {
            abort(403, 'No tienes permiso para acceder al módulo de gastos.');
        }

        $listado = $this->listado($request);
        $puedeAnular = $this->puedeAnular();

        return view('gastos.index', [
            'apertura' => $listado['apertura'],
            // Conjunto COMPLETO filtrado: de aquí salen los totales.
            'gastos' => $listado['gastos'],
            // Solo la página visible del listado (no altera los totales).
            'paginaGastos' => $listado['paginaGastos'],
            'totalActivos' => $listado['totalActivos'],
            'totalAnulados' => $listado['totalAnulados'],
            'filtro' => $listado['filtro'],
            'puedeAnular' => $puedeAnular,
            'rango' => $listado['rango'],
        ]);
    }

    /* ------------------------------------------------------------------
     | Registro de gastos (una o varias filas) en la apertura activa
     | ------------------------------------------------------------------ */

    public function store(Request $request)
    {
        if (!$this->puedeOperar()) {
            return $this->noAutorizado('No tienes permiso para registrar gastos.');
        }

        // La apertura se obtiene del servidor: no se acepta un id del navegador.
        $apertura = $this->service->aperturaActiva();

        if (!$apertura) {
            return response()->json([
                'exito' => false,
                'error' => 'No hay una caja abierta. Debes abrir caja antes de registrar gastos.',
            ], 409);
        }

        $datos = $request->validate([
            'conceptos' => ['required', 'array', 'min:1'],
            'conceptos.*' => ['required', 'string', 'max:255'],
            'montos' => ['required', 'array', 'min:1'],
            'montos.*' => ['required', 'numeric', new NumeroEstricto(2), 'gt:0', 'max:9999999999.99'],
        ], [
            'conceptos.required' => 'Debes indicar al menos un concepto.',
            'montos.*.gt' => 'Cada monto debe ser mayor que cero.',
            'montos.*.max' => 'Cada monto supera el máximo permitido (C$ 9,999,999,999.99).',
        ]);

        if (count($datos['conceptos']) !== count($datos['montos'])) {
            return response()->json([
                'exito' => false,
                'error' => 'Los conceptos y los montos no coinciden en cantidad.',
            ], 422);
        }

        // El usuario sale de la sesión autenticada, nunca del request.
        $resultado = $this->service->registrarGastos(
            (int) $apertura->id,
            (int) Auth::id(),
            $datos['conceptos'],
            $datos['montos']
        );

        return response()->json($resultado, $resultado['exito'] ? 200 : 422);
    }

    /* ------------------------------------------------------------------
     | Anulación lógica (solo ADMIN y GERENTE)
     | ------------------------------------------------------------------ */

    public function anular(int $id)
    {
        // Autorización en backend: CAJERO y MESERO reciben 403 aunque
        // intenten llamar al endpoint directamente.
        if (!$this->puedeAnular()) {
            return $this->noAutorizado('Solo ADMIN y GERENTE pueden anular gastos.');
        }

        $resultado = $this->service->anular($id);

        return response()->json($resultado, $resultado['codigo'] ?? 200);
    }

    /* ------------------------------------------------------------------
     | Ticket imprimible del turno (respeta el filtro seleccionado)
     | ------------------------------------------------------------------ */

    public function ticket(Request $request)
    {
        if (!$this->puedeOperar()) {
            abort(403, 'No tienes permiso para imprimir gastos.');
        }

        // El listado impreso usa el MISMO criterio que la pantalla:
        // mismo rango de fechas, mismo filtro de estado, mismos totales.
        $listado = $this->listado($request);

        return view('gastos.ticket', [
            'apertura' => $listado['apertura'],
            'gastos' => $listado['gastos'],
            'totalActivos' => $listado['totalActivos'],
            'totalAnulados' => $listado['totalAnulados'],
            'filtro' => $listado['filtro'],
            'rango' => $listado['rango'],
        ]);
    }

    /* ------------------------------------------------------------------
     | Ticket imprimible de un gasto individual (activo o anulado)
     | ------------------------------------------------------------------ */

    public function ticketGasto(int $id)
    {
        if (!$this->puedeOperar()) {
            abort(403, 'No tienes permiso para imprimir gastos.');
        }

        $gasto = $this->service->gasto($id);

        if (!$gasto) {
            abort(404, 'El gasto no existe.');
        }

        $apertura = $this->service->aperturaActiva();

        return view('gastos.ticket_gasto', compact('gasto', 'apertura'));
    }

    /* ------------------------------------------------------------------
     | Filtrado por estado
     | ------------------------------------------------------------------ */

    /**
     * Normaliza el parámetro ?estado= a activos|anulados|todos.
     * Cualquier valor inválido o ausente cae en "activos".
     */
    private function filtroEstado(Request $request): string
    {
        $filtro = (string) $request->query('estado', 'activos');

        return in_array($filtro, ['activos', 'anulados', 'todos'], true)
            ? $filtro
            : 'activos';
    }

    /**
     * Devuelve la colección filtrada por estado, conservando el orden original.
     */
    private function filtrarPorEstado(Collection $gastos, string $filtro): Collection
    {
        return match ($filtro) {
            'anulados' => $gastos->where('estado', 'ANULADO')->values(),
            'todos' => $gastos->values(),
            default => $gastos->where('estado', 'ACTIVO')->values(),
        };
    }

    /* ------------------------------------------------------------------
     | Listado compartido por la pantalla y el ticket
     | ------------------------------------------------------------------ */

    /**
     * Resuelve apertura + filtro de estado + rango de fechas y devuelve el
     * conjunto completo filtrado (de ahí salen los totales) y su página
     * visible. La pantalla y el ticket usan ESTE método, así nunca divergen.
     *
     * Sin fechas el módulo se comporta como siempre: gastos del turno activo.
     * Con fechas consulta TODO el histórico dentro del rango (no requiere caja
     * abierta).
     *
     * @return array{apertura: mixed, filtro: string, rango: array<string, mixed>, gastos: Collection, paginaGastos: LengthAwarePaginator, totalActivos: float, totalAnulados: float}
     */
    private function listado(Request $request): array
    {
        $apertura = $this->service->aperturaActiva();
        $filtro = $this->filtroEstado($request);
        $rango = $this->rangoSolicitado($request);

        if (!$rango['valido']) {
            // Fechas inválidas o rango invertido: NO se calcula nada (estado
            // controlado, con el motivo en pantalla).
            $todos = collect();
        } elseif ($rango['filtrado']) {
            $todos = $this->service->gastosEnRango($rango['desde'], $rango['hasta']);
        } else {
            $todos = $apertura
                ? $this->service->gastosDeApertura((int) $apertura->id)
                : collect();
        }

        $gastos = $this->filtrarPorEstado($todos, $filtro);

        // Totales SIEMPRE sobre el conjunto completo filtrado por estado (no
        // sobre la página visible). Los anulados nunca suman al total activo.
        $totalActivos = $this->service->totalActivos($gastos);
        $totalAnulados = $this->service->totalAnulados($gastos);

        return [
            'apertura' => $apertura,
            'filtro' => $filtro,
            'rango' => $rango,
            'gastos' => $gastos,
            'paginaGastos' => $this->paginar($gastos, $request),
            'totalActivos' => $totalActivos,
            'totalAnulados' => $totalAnulados,
        ];
    }

    /**
     * Pagina SOLO el listado visible (50 filas). Los totales no dependen de
     * esta página: salen del conjunto completo. Conserva los parámetros
     * (desde, hasta, estado) en los enlaces anterior/siguiente.
     */
    private function paginar(Collection $gastos, Request $request): LengthAwarePaginator
    {
        $porPagina = 50;
        $total = $gastos->count();
        $paginas = max(1, (int) ceil($total / $porPagina));
        $pagina = min(max(1, $this->enteroPositivo($request->query('pagina')) ?? 1), $paginas);

        return new LengthAwarePaginator(
            $gastos->forPage($pagina, $porPagina)->values(),
            $total,
            $porPagina,
            $pagina,
            ['path' => route('gastos.index'), 'query' => $request->query()]
        );
    }

    /**
     * Rango consultado (Desde/Hasta, Y-m-d).
     *
     * Sin fechas se mantiene el comportamiento previo del módulo (turno activo).
     * Si llega una sola fecha se completa con la otra (un solo día). Cualquier
     * fecha inválida o rango invertido devuelve `valido = false` con el motivo,
     * y NO se calcula el listado.
     *
     * @return array{valido: bool, filtrado: bool, desde: string, hasta: string, error: ?string}
     */
    private function rangoSolicitado(Request $request): array
    {
        $desde = trim((string) $request->query('desde', ''));
        $hasta = trim((string) $request->query('hasta', ''));

        if ($desde === '' && $hasta === '') {
            return ['valido' => true, 'filtrado' => false, 'desde' => '', 'hasta' => '', 'error' => null];
        }

        if ($desde === '') {
            $desde = $hasta;
        }
        if ($hasta === '') {
            $hasta = $desde;
        }

        if (!$this->esFechaValida($desde) || !$this->esFechaValida($hasta)) {
            return [
                'valido' => false,
                'filtrado' => true,
                'desde' => $desde,
                'hasta' => $hasta,
                'error' => 'Las fechas indicadas no son válidas.',
            ];
        }

        if ($desde > $hasta) {
            return [
                'valido' => false,
                'filtrado' => true,
                'desde' => $desde,
                'hasta' => $hasta,
                'error' => 'La fecha "Desde" no puede ser posterior a la fecha "Hasta".',
            ];
        }

        return ['valido' => true, 'filtrado' => true, 'desde' => $desde, 'hasta' => $hasta, 'error' => null];
    }

    private function enteroPositivo(mixed $valor): ?int
    {
        if (!is_scalar($valor)) {
            return null;
        }

        $valor = trim((string) $valor);

        return (ctype_digit($valor) && (int) $valor > 0) ? (int) $valor : null;
    }

    private function esFechaValida(string $fecha): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            return false;
        }

        [$anio, $mes, $dia] = array_map('intval', explode('-', $fecha));

        return checkdate($mes, $dia, $anio);
    }

    /* ------------------------------------------------------------------
     | Autorización
     | ------------------------------------------------------------------ */

    /**
     * Registrar, consultar e imprimir: ADMIN, GERENTE y CAJERO.
     */
    private function puedeOperar(): bool
    {
        return in_array($this->rolActual(), ['ADMIN', 'GERENTE', 'CAJERO'], true);
    }

    /**
     * Anular: solo ADMIN y GERENTE. CAJERO queda fuera.
     */
    private function puedeAnular(): bool
    {
        return in_array($this->rolActual(), ['ADMIN', 'GERENTE'], true);
    }

    private function rolActual(): string
    {
        $usuario = Auth::user();

        if (!$usuario) {
            return '';
        }

        return strtoupper((string) ($usuario->rol->nombre ?? ''));
    }

    private function noAutorizado(string $mensaje)
    {
        return response()->json([
            'exito' => false,
            'error' => $mensaje,
        ], 403);
    }
}
