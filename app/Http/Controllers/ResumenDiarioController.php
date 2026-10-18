<?php

namespace App\Http\Controllers;

use App\Services\ResumenDiarioService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Módulo "Operaciones" → Resumen del Día (varios turnos de una fecha).
 *
 * Permisos (validados SIEMPRE en backend, no solo en Blade/JS), tomados del
 * respaldo PHP puro (`api_operaciones.php` y `ticket_resumen_dia.php`):
 *  - ADMIN y GERENTE: consultar e imprimir el resumen del día.
 *  - CAJERO y MESERO: sin acceso (403).
 *
 * Es un módulo de SOLO LECTURA: consultar o imprimir NUNCA modifica importes,
 * ventas, cuentas, gastos ni cierres.
 */
class ResumenDiarioController extends Controller
{
    public function __construct(private ResumenDiarioService $service) {}

    public function index(Request $request)
    {
        if (! $this->autorizado()) {
            abort(403, 'No tienes permiso para ver el resumen del día.');
        }

        $fecha = $this->fechaSolicitada($request);
        $cajero = $this->cajeroSolicitado($request);

        $resumen = $this->service->resumenDelDia($fecha, $cajero);
        $cajeros = $this->service->cajerosDisponibles($fecha);

        return view('resumen_dia.index', [
            'fecha' => $fecha,
            'cajero' => $cajero,
            'cajeros' => $cajeros,
            'resumen' => $resumen,
        ]);
    }

    public function ticket(Request $request)
    {
        if (! $this->autorizado()) {
            abort(403, 'No tienes permiso para imprimir el resumen del día.');
        }

        $fecha = $this->fechaSolicitada($request);
        $cajero = $this->cajeroSolicitado($request);

        $resumen = $this->service->resumenDelDia($fecha, $cajero);

        if (! ($resumen['exito'] ?? false)) {
            abort(404, (string) ($resumen['error'] ?? 'No hay resumen para imprimir.'));
        }

        return view('resumen_dia.ticket', [
            'fecha' => $fecha,
            'cajero' => $resumen['cajero'],
            'resumen' => $resumen,
        ]);
    }

    /* ------------------------------------------------------------------
     | Utilidades internas
     | ------------------------------------------------------------------ */

    /**
     * Fecha consultada (Y-m-d). Si el parámetro no es válido se usa la fecha
     * actual del servidor.
     */
    private function fechaSolicitada(Request $request): string
    {
        $fecha = trim((string) $request->query('fecha', ''));

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            [$anio, $mes, $dia] = array_map('intval', explode('-', $fecha));

            if (checkdate($mes, $dia, $anio)) {
                return $fecha;
            }
        }

        return now()->format('Y-m-d');
    }

    /**
     * Cajero consultado. Cadena vacía o "TODOS" significa todos los turnos.
     */
    private function cajeroSolicitado(Request $request): ?string
    {
        $cajero = trim((string) $request->query('cajero', ''));

        if ($cajero === '' || strtoupper($cajero) === 'TODOS') {
            return null;
        }

        return mb_substr($cajero, 0, 100);
    }

    private function autorizado(): bool
    {
        $rol = strtoupper((string) (Auth::user()?->rol?->nombre ?? ''));

        return in_array($rol, ['ADMIN', 'GERENTE'], true);
    }
}
