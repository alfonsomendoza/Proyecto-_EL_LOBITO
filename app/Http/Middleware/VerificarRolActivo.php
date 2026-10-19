<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

/**
 * ==========================================================================
 * ROL DESACTIVADO: BLOQUEO CENTRALIZADO
 * ==========================================================================
 *
 * Un rol DESACTIVADO (`roles.activo = 0`) no debe conceder permisos. En vez de
 * repetir la comprobación en cada controlador (que resuelve el rol con
 * `Auth::user()->rol->nombre`), se comprueba UNA sola vez aquí, en el grupo
 * `web` (ver bootstrap/app.php). Así:
 *
 *  - un usuario cuyo rol fue desactivado NO puede seguir operando aunque ya
 *    tuviera la sesión abierta: cada petición se rechaza;
 *  - al REACTIVAR el rol recupera el acceso, sin volver a iniciar sesión.
 *
 * Es compatible ANTES y DESPUÉS de aplicar la migración:
 *  - Si la columna `roles.activo` no existe (migración pendiente) o la tabla
 *    no existe (esquemas de prueba), no se bloquea a nadie y no hay error SQL.
 *
 * NO toca datos, NO cierra la sesión y NO cambia asignaciones de usuarios.
 */
class VerificarRolActivo
{
    public function handle(Request $request, Closure $next): Response
    {
        // Cerrar sesión siempre debe poder hacerse, aunque el rol esté
        // desactivado: si no, un usuario bloqueado no podría cambiar de cuenta.
        if ($request->routeIs('logout')) {
            return $next($request);
        }

        // Sin usuario autenticado no hay rol que comprobar (p. ej. /login).
        $usuario = $request->user();

        if ($usuario === null) {
            return $next($request);
        }

        // La comprobación del estado solo aplica si el esquema ya la tiene.
        if (! Schema::hasColumn('roles', 'activo')) {
            return $next($request);
        }

        // Se consulta el rol de forma explícita (no la relación cacheada en el
        // modelo) para reflejar SIEMPRE el estado actual en la base: así una
        // desactivación se aplica de inmediato, incluso a sesiones abiertas.
        $rol = $usuario->rol()->first();

        // Sin rol asociado, el comportamiento previo no cambia: cada
        // controlador deniega el acceso por no reconocer el rol.
        if ($rol !== null && (int) $rol->activo === 0) {
            return $this->bloqueado($request);
        }

        return $next($request);
    }

    /**
     * Respuesta de bloqueo: JSON para peticiones AJAX y para el resto, 403.
     */
    private function bloqueado(Request $request)
    {
        $mensaje = 'Tu rol está desactivado. Contacta al administrador para que lo reactive.';

        if ($request->expectsJson()) {
            return response()->json([
                'exito' => false,
                'error' => $mensaje,
            ], 403);
        }

        abort(403, $mensaje);
    }
}
