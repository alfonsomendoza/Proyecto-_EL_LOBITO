<?php

namespace App\Http\Controllers;

use App\Services\ResumenPropinasService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Módulo "Administración" → Resumen de Propinas del Día.
 *
 * Permite consultar, por fecha, cuánto se cobró en propinas y contrastarlo
 * contra los vouchers de las facturas. Es de SOLO LECTURA: NUNCA modifica
 * facturas, cuentas ni propinas históricas (registrar el pago de propinas a
 * los meseros NO está definido por el negocio y no se implementa aquí).
 *
 * Importante: el esquema real NO registra quién atendió la mesa, solo quién
 * cobró. Por eso el resumen es GENERAL del día (no se atribuye por mesero):
 * ver App\Services\ResumenPropinasService para la justificación.
 *
 * Permisos (validados SIEMPRE en backend, no solo en Blade/JS):
 *  - ADMIN y GERENTE: consultar (es una consulta administrativa de recapitulación).
 *  - CAJERO y MESERO: sin acceso (403).
 */
class ResumenPropinasController extends Controller
{
    public function __construct(private ResumenPropinasService $service) {}

    public function index(Request $request)
    {
        if (! $this->autorizado()) {
            abort(403, 'No tienes permiso para ver el resumen de propinas.');
        }

        $rango = $this->rangoSolicitado($request);

        // Si alguna fecha no es válida NO se genera un resumen: se muestra el
        // aviso y los totales en cero (estado controlado).
        $resumen = $rango['valido']
            ? $this->service->resumenDelRango($rango['desde'], $rango['hasta'])
            : [
                'exito' => false,
                'error' => $rango['error'],
                'desde' => $rango['desde'],
                'hasta' => $rango['hasta'],
                'total' => 0.0,
                'cantidad' => 0,
                'detalle' => collect(),
            ];

        return view('resumen_propinas.index', [
            'desde' => $rango['desde'],
            'hasta' => $rango['hasta'],
            'resumen' => $resumen,
        ]);
    }

    /* ------------------------------------------------------------------
     | Utilidades internas
     | ------------------------------------------------------------------ */

    /**
     * Rango consultado (Desde/Hasta, Y-m-d).
     *
     * Por defecto (sin parámetros) se consulta el DÍA ACTUAL, que es el mismo
     * comportamiento que tenía el módulo de un solo día. Si el usuario envía
     * fechas, se usan tal cual; si alguna es inválida, se devuelve
     * `valido = false` con un mensaje claro y NO se calcula el resumen.
     *
     * @return array{valido: bool, desde: string, hasta: string, error: ?string}
     */
    private function rangoSolicitado(Request $request): array
    {
        $hoy = now()->format('Y-m-d');
        $desde = trim((string) $request->query('desde', ''));
        $hasta = trim((string) $request->query('hasta', ''));

        // Sin filtros: día actual (comportamiento previo).
        if ($desde === '' && $hasta === '') {
            return ['valido' => true, 'desde' => $hoy, 'hasta' => $hoy, 'error' => null];
        }

        // Si solo llega una de las dos, se completa con la otra (o con hoy si
        // tampoco llega) para no romper la consulta.
        if ($desde === '') {
            $desde = $this->esFechaValida($hasta) ? $hasta : $hoy;
        }
        if ($hasta === '') {
            $hasta = $this->esFechaValida($desde) ? $desde : $hoy;
        }

        if (! $this->esFechaValida($desde) || ! $this->esFechaValida($hasta)) {
            return [
                'valido' => false,
                'desde' => $desde,
                'hasta' => $hasta,
                'error' => 'Las fechas indicadas no son válidas.',
            ];
        }

        if ($desde > $hasta) {
            return [
                'valido' => false,
                'desde' => $desde,
                'hasta' => $hasta,
                'error' => 'La fecha "Desde" no puede ser posterior a la fecha "Hasta".',
            ];
        }

        return ['valido' => true, 'desde' => $desde, 'hasta' => $hasta, 'error' => null];
    }

    private function esFechaValida(string $fecha): bool
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            return false;
        }

        [$anio, $mes, $dia] = array_map('intval', explode('-', $fecha));

        return checkdate($mes, $dia, $anio);
    }

    private function autorizado(): bool
    {
        $rol = strtoupper((string) (Auth::user()?->rol?->nombre ?? ''));

        return in_array($rol, ['ADMIN', 'GERENTE'], true);
    }
}
