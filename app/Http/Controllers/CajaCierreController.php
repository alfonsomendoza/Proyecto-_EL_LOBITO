<?php

namespace App\Http\Controllers;

use App\Models\CajaCierre;
use App\Services\CajaCierreService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Módulo de Cierre de Caja (arqueo del turno).
 *
 * Permisos (validados SIEMPRE en backend, no solo en Blade/JS):
 *  - ADMIN, GERENTE y CAJERO: ver el panel, cerrar el turno e imprimir.
 *  - ADMIN y GERENTE        : anular un cierre. CAJERO/MESERO reciben 403.
 *  - MESERO                 : sin acceso al módulo (403).
 *
 * La apertura y el usuario salen de la sesión/servidor; nunca de un id
 * enviado por el navegador.
 */
class CajaCierreController extends Controller
{
    public function __construct(private CajaCierreService $service) {}

    /* ------------------------------------------------------------------
     | Panel de arqueo y cierre
     | ------------------------------------------------------------------ */

    public function index()
    {
        if (! $this->puedeCerrar()) {
            abort(403, 'No tienes permiso para acceder al cierre de caja.');
        }

        $apertura = $this->service->aperturaActiva();

        $resumen = $apertura ? $this->service->resumenVentas($apertura) : null;
        $gastos = $apertura ? $this->service->gastosActivos((int) $apertura->id) : collect();
        $totalEgresos = $apertura ? $this->service->totalEgresos((int) $apertura->id) : 0.0;

        // Aviso + bloqueo: mesas con consumos pendientes de cobro. El número
        // sale del mismo cálculo que usa el backend al cerrar (sin doble conteo
        // de subcuentas), para que el aviso y el rechazo siempre coincidan.
        $mesasPendientes = $this->service->mesasConConsumosPendientes()->count();

        $cierres = $this->service->ultimosCierres();
        $puedeAnular = $this->puedeAnular();

        return view('caja.cierre', compact(
            'apertura',
            'resumen',
            'gastos',
            'totalEgresos',
            'mesasPendientes',
            'cierres',
            'puedeAnular'
        ));
    }

    /* ------------------------------------------------------------------
     | Registro del cierre
     | ------------------------------------------------------------------ */

    public function registrar(Request $request)
    {
        if (! $this->puedeCerrar()) {
            return $this->noAutorizado('No tienes permiso para cerrar la caja.');
        }

        // La apertura se toma del servidor, nunca del navegador.
        $apertura = $this->service->aperturaActiva();

        if (! $apertura) {
            return response()->json([
                'exito' => false,
                'error' => 'No hay una caja abierta para cerrar.',
            ], 409);
        }

        $datos = $request->validate([
            'otros_ingresos' => ['nullable', 'numeric', 'min:0'],
            'retiros' => ['nullable', 'numeric', 'min:0'],
            'tasa_cambio' => ['required', 'numeric', 'gt:0'],
            'observaciones' => ['nullable', 'string', 'max:1000'],
            'conteo_local' => ['nullable', 'array'],
            'conteo_local.*' => ['nullable', 'integer', 'min:0'],
            'conteo_dolar' => ['nullable', 'array'],
            'conteo_dolar.*' => ['nullable', 'integer', 'min:0'],
        ], [
            'tasa_cambio.gt' => 'La tasa de cambio debe ser mayor que cero.',
            'retiros.min' => 'Los retiros no pueden ser negativos.',
            'otros_ingresos.min' => 'Los otros ingresos no pueden ser negativos.',
        ]);

        $conteoLocal = $this->normalizarConteo($request->input('conteo_local', []), CajaCierreService::DENOMINACIONES_LOCAL);
        $conteoDolar = $this->normalizarConteo($request->input('conteo_dolar', []), CajaCierreService::DENOMINACIONES_DOLAR);

        $resultado = $this->service->registrarCierre(
            (int) $apertura->id,
            (float) ($datos['otros_ingresos'] ?? 0),
            $conteoLocal,
            $conteoDolar,
            (float) $datos['tasa_cambio'],
            (float) ($datos['retiros'] ?? 0),
            $datos['observaciones'] ?? null,
            Auth::id() !== null ? (int) Auth::id() : null
        );

        return response()->json($resultado, (int) ($resultado['codigo'] ?? ($resultado['exito'] ? 200 : 422)));
    }

    /* ------------------------------------------------------------------
     | Ticket imprimible
     | ------------------------------------------------------------------ */

    public function ticket(Request $request)
    {
        if (! $this->puedeCerrar()) {
            abort(403, 'No tienes permiso para imprimir el cierre de caja.');
        }

        $cierreId = (int) $request->query('cierre_id', 0);

        if ($cierreId > 0) {
            // Reimpresión de un cierre puntual del historial.
            $cierre = CajaCierre::with('apertura')->find($cierreId);
        } else {
            // Sin id: el cierre del turno activo, o el último registrado.
            $apertura = $this->service->aperturaActiva();
            $cierre = $apertura
                ? $this->service->cierreVigente((int) $apertura->id)
                : CajaCierre::orderByDesc('id')->first();
        }

        if (! $cierre) {
            abort(404, 'No se encontró información de cierre para imprimir.');
        }

        // El detalle se recalcula con el mismo criterio del panel; los totales
        // del cuadre salen del cierre guardado (no cambian aunque pasen días).
        $resumen = $cierre->apertura ? $this->service->resumenVentas($cierre->apertura) : null;
        $gastos = $cierre->apertura_id
            ? $this->service->gastosActivos((int) $cierre->apertura_id)
            : collect();

        return view('caja.cierre_ticket', compact('cierre', 'resumen', 'gastos'));
    }

    /* ------------------------------------------------------------------
     | Anulación de cierre (solo ADMIN y GERENTE)
     | ------------------------------------------------------------------ */

    public function anular(int $id)
    {
        if (! $this->puedeAnular()) {
            return $this->noAutorizado('Solo ADMIN y GERENTE pueden anular un cierre.');
        }

        $motivo = trim((string) request()->input('motivo', ''));
        if ($motivo === '') {
            return response()->json(['exito' => false, 'codigo' => 422, 'error' => 'El motivo de anulación es obligatorio.'], 422);
        }

        $resultado = $this->service->anularCierre($id, $motivo, Auth::id());

        return response()->json($resultado, $resultado['codigo'] ?? 200);
    }

    /* ------------------------------------------------------------------
     | Utilidades internas
     | ------------------------------------------------------------------ */

    /**
     * Normaliza el conteo del navegador a un mapa { denominación: cantidad }
     * con las denominaciones oficiales y claves string (para que "0.5" no se
     * convierta en 0 al codificar a JSON).
     *
     * @param  array<int|string, mixed>  $entrada
     * @param  array<int, int|float>  $denominaciones
     * @return array<string, int>
     */
    private function normalizarConteo(array $entrada, array $denominaciones): array
    {
        $conteo = [];

        foreach ($denominaciones as $denominacion) {
            $clave = (string) $denominacion;
            $conteo[$clave] = max(0, (int) ($entrada[$clave] ?? $entrada[$denominacion] ?? 0));
        }

        return $conteo;
    }

    private function puedeCerrar(): bool
    {
        return in_array($this->rolActual(), ['ADMIN', 'GERENTE', 'CAJERO'], true);
    }

    private function puedeAnular(): bool
    {
        return in_array($this->rolActual(), ['ADMIN', 'GERENTE'], true);
    }

    private function rolActual(): string
    {
        $usuario = Auth::user();

        if (! $usuario) {
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
