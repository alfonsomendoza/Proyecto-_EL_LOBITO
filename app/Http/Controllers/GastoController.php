<?php

namespace App\Http\Controllers;

use App\Services\GastoService;
use Illuminate\Http\Request;
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

        $apertura = $this->service->aperturaActiva();

        // Filtro del listado: activos (por defecto), anulados o todos.
        $filtro = $this->filtroEstado($request);

        $todos = $apertura
            ? $this->service->gastosDeApertura((int) $apertura->id)
            : collect();

        $gastos = $this->filtrarPorEstado($todos, $filtro);

        // Los totales se calculan SIEMPRE sobre el turno completo: los anulados
        // nunca se suman al total activo; se muestran aparte como informativos.
        $totalActivos = $this->service->totalActivos($todos);
        $totalAnulados = $this->service->totalAnulados($todos);
        $puedeAnular = $this->puedeAnular();

        return view('gastos.index', compact(
            'apertura',
            'gastos',
            'totalActivos',
            'totalAnulados',
            'filtro',
            'puedeAnular'
        ));
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
            'montos.*' => ['required', 'numeric', 'gt:0'],
        ], [
            'conceptos.required' => 'Debes indicar al menos un concepto.',
            'montos.*.gt' => 'Cada monto debe ser mayor que cero.',
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

        $apertura = $this->service->aperturaActiva();

        // El listado impreso usa el MISMO criterio de filtrado que la pantalla.
        $filtro = $this->filtroEstado($request);

        $todos = $apertura
            ? $this->service->gastosDeApertura((int) $apertura->id)
            : collect();

        $gastos = $this->filtrarPorEstado($todos, $filtro);

        // Totales sobre el turno completo, separados por estado.
        $totalActivos = $this->service->totalActivos($todos);
        $totalAnulados = $this->service->totalAnulados($todos);

        return view('gastos.ticket', compact(
            'apertura',
            'gastos',
            'totalActivos',
            'totalAnulados',
            'filtro'
        ));
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
