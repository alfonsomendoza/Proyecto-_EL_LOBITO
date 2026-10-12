<?php

namespace App\Http\Controllers;

use App\Models\BackupRegistro;
use App\Services\BackupService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Módulo de copias de seguridad de la base de datos.
 *
 * Permisos (validados SIEMPRE en backend, no solo en Blade/JS):
 *  - ADMIN y GERENTE: crear, consultar historial, descargar y eliminar.
 *  - CAJERO y MESERO: sin acceso al módulo.
 *
 * No existe restauración operativa: se documenta y se prueba solo en un
 * entorno aislado.
 */
class BackupController extends Controller
{
    public function __construct(private BackupService $service)
    {
    }

    /* ------------------------------------------------------------------
     | Panel del módulo
     | ------------------------------------------------------------------ */

    public function index()
    {
        if (!$this->autorizado()) {
            abort(403, 'No tienes permiso para acceder al módulo de respaldos.');
        }

        $historial = $this->service->historial();
        $baseDatos = $this->service->nombreBaseDatos();
        $herramientaDisponible = $this->service->herramientaDisponible();

        return view('backups.index', compact('historial', 'baseDatos', 'herramientaDisponible'));
    }

    /* ------------------------------------------------------------------
     | Crear copia
     | ------------------------------------------------------------------ */

    public function crear(Request $request)
    {
        if (!$this->autorizado()) {
            return $this->noAutorizado();
        }

        $resultado = $this->service->registrarCreacion(
            (int) Auth::id(),
            $request->ip()
        );

        return response()->json($resultado, $resultado['codigo']);
    }

    /* ------------------------------------------------------------------
     | Descarga protegida
     | ------------------------------------------------------------------ */

    public function descargar(int $id)
    {
        if (!$this->autorizado()) {
            abort(403, 'No tienes permiso para descargar respaldos.');
        }

        $registro = BackupRegistro::find($id);

        if (!$registro) {
            abort(404, 'El respaldo no existe.');
        }

        $ruta = $this->service->archivoParaDescarga($registro);

        if ($ruta === null) {
            abort(404, 'El archivo no está disponible.');
        }

        return response()->download($ruta, $registro->nombre_archivo);
    }

    /* ------------------------------------------------------------------
     | Eliminación manual (auditada)
     | ------------------------------------------------------------------ */

    public function eliminar(Request $request, int $id)
    {
        if (!$this->autorizado()) {
            return $this->noAutorizado();
        }

        $resultado = $this->service->eliminar(
            $id,
            (int) Auth::id(),
            $request->ip()
        );

        return response()->json($resultado, $resultado['codigo']);
    }

    /* ------------------------------------------------------------------
     | Autorización del módulo
     | ------------------------------------------------------------------ */

    /**
     * Solo ADMIN y GERENTE operan este módulo. No amplía sus permisos en
     * ninguna otra parte del sistema.
     */
    private function autorizado(): bool
    {
        $usuario = Auth::user();

        if (!$usuario) {
            return false;
        }

        $rol = $usuario->rol->nombre ?? '';

        return in_array($rol, ['ADMIN', 'GERENTE'], true);
    }

    private function noAutorizado()
    {
        return response()->json([
            'exito' => false,
            'error' => 'No tienes permiso para usar el módulo de respaldos.',
        ], 403);
    }
}