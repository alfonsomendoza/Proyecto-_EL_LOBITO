<?php

namespace App\Http\Controllers;

use App\Services\ConfiguracionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Configuración de módulos (EXCLUSIVA de ADMIN).
 *
 * Permite habilitar o deshabilitar de forma independiente:
 *  - Cocina digital (monitor de cocina).
 *  - Inventario.
 *
 * La autorización se valida SIEMPRE aquí en el backend: ocultar el enlace no
 * basta. Deshabilitar un módulo NO borra datos; solo bloquea su uso.
 */
class ConfiguracionController extends Controller
{
    public function __construct(private ConfiguracionService $configuracion) {}

    /* ------------------------------------------------------------------
     | Pantalla de configuración
     | ------------------------------------------------------------------ */

    public function index()
    {
        if (! $this->esAdmin()) {
            abort(403, 'Solo ADMIN puede ver la configuración de módulos.');
        }

        return view('configuracion.index', [
            'cocinaDigital' => $this->configuracion->cocinaDigitalHabilitada(),
            'inventario' => $this->configuracion->inventarioHabilitado(),
            'tablaDisponible' => $this->configuracion->tablaDisponible(),
        ]);
    }

    /* ------------------------------------------------------------------
     | Cambiar una preferencia (JSON)
     | ------------------------------------------------------------------ */

    public function actualizar(Request $request)
    {
        if (! $this->esAdmin()) {
            return response()->json([
                'exito' => false,
                'error' => 'Solo ADMIN puede cambiar la configuración.',
            ], 403);
        }

        // Sin la tabla de configuración no se puede persistir nada: se avisa en
        // lugar de fallar o de fingir el cambio.
        if (! $this->configuracion->tablaDisponible()) {
            return response()->json([
                'exito' => false,
                'error' => 'Falta aplicar la migración de configuración.',
            ], 503);
        }

        $datos = $request->validate([
            'modulo' => ['required', 'string', 'in:cocina,inventario'],
            'habilitado' => ['required', 'boolean'],
        ]);

        $clave = $datos['modulo'] === 'cocina'
            ? ConfiguracionService::CLAVE_COCINA_DIGITAL
            : ConfiguracionService::CLAVE_INVENTARIO;

        $habilitado = $request->boolean('habilitado');

        if ($habilitado) {
            $this->configuracion->habilitar($clave);
        } else {
            $this->configuracion->deshabilitar($clave);
        }

        return response()->json([
            'exito' => true,
            'modulo' => $datos['modulo'],
            'habilitado' => $habilitado,
        ]);
    }

    private function esAdmin(): bool
    {
        return strtoupper((string) (Auth::user()?->rol->nombre ?? '')) === 'ADMIN';
    }
}
