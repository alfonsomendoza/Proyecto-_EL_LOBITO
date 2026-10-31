<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

/**
 * ==========================================================================
 * SESIÓN ÚNICA POR USUARIO
 * ==========================================================================
 *
 * `usuarios.session_id` guarda el id de la ÚNICA sesión autorizada. Este
 * middleware comprueba en CADA petición autenticada (web, AJAX o JSON) que el
 * id de sesión actual siga siendo el registrado en la base. Por eso no basta
 * con rechazar el segundo login: una sesión que perdió la autorización tampoco
 * puede seguir operando.
 *
 * Reglas aplicadas:
 *  - Usuario desactivado (`usuarios.activo = 0`): se cierra su sesión y se
 *    responde 403 (JSON) o se redirige al login (navegador).
 *  - Sesión no autorizada (otra sesión la reemplazó, o el registro se liberó):
 *    se cierra y se responde 401 (JSON) o se redirige al login.
 *  - Cada petición válida actualiza `ultima_actividad` SOLO si el
 *    `session_id` sigue coincidiendo. Una sesión antigua nunca puede renovar
 *    ni liberar el registro de una sesión nueva.
 *  - `logout` se deja pasar para que el controlador libere únicamente su
 *    propia sesión (ver AuthController::logout).
 *
 * Compatibilidad: si la migración de `session_id`/`ultima_actividad` todavía
 * no se aplicó, no se bloquea a nadie (mismo patrón que VerificarRolActivo
 * con `roles.activo`). Una vez migrada la tabla, la política queda activa.
 */
class VerificarSesionUnica
{
    public function handle(Request $request, Closure $next): Response
    {
        // Sin usuario autenticado no hay sesión que validar.
        $usuario = $request->user();

        if ($usuario === null) {
            return $next($request);
        }

        // Cerrar sesión siempre debe poder hacerse, aunque el registro ya no
        // sea el autorizado: el controlador solo libera la sesión propia.
        if ($request->routeIs('logout')) {
            return $next($request);
        }

        // Esquema sin migrar: no se puede aplicar la política.
        if (! $this->columnasDisponibles()) {
            return $next($request);
        }

        $sesionActual = (string) $request->session()->getId();

        // Se consulta SIEMPRE el registro actual; nunca se confía en el
        // modelo cargado al inicio de la petición.
        $registro = DB::table('usuarios')
            ->where('id', $usuario->id)
            ->first(['id', 'activo', 'session_id']);

        if ($registro === null) {
            return $this->cerrarSesion(
                $request,
                401,
                'Tu sesión ya no es válida. Inicia sesión nuevamente.'
            );
        }

        // Usuario desactivado: pierde el acceso en su siguiente petición.
        if ((int) $registro->activo !== 1) {
            // Solo se libera si esta petición es la sesión registrada.
            $this->liberarSiEsPropia($request, $usuario->id, $sesionActual);

            return $this->cerrarSesion(
                $request,
                403,
                'Tu cuenta está desactivada.'
            );
        }

        // Contraseña cambiada por un administrador: la sesión anterior ya no
        // es válida. Además de cerrarla, se LIBERA el registro de la base para
        // que el usuario pueda iniciar sesión de inmediato con la contraseña
        // nueva (si no se liberara, la política de sesión única lo rechazaría
        // durante los 15 minutos siguientes). Este middleware corre ANTES de
        // AuthenticateSession para poder hacer esa limpieza (ver bootstrap/app.php).
        if ($this->contrasenaCambiada($request, $usuario)) {
            $this->liberarSiEsPropia($request, $usuario->id, $sesionActual);

            return $this->cerrarSesion(
                $request,
                401,
                'Tu contraseña cambió. Inicia sesión nuevamente.'
            );
        }

        // Sesión no autorizada: otra sesión reclamó el acceso o el registro
        // se liberó. No se toca el registro actual (podría ser de otra sesión).
        if ($registro->session_id === null
            || ! hash_equals((string) $registro->session_id, $sesionActual)) {
            return $this->cerrarSesion(
                $request,
                401,
                'Tu sesión se cerró porque este usuario ya tiene otra sesión autorizada en otro equipo.'
            );
        }

        // Renovación condicional de la actividad. El WHERE repite id,
        // session_id y activo: si entre la lectura y este UPDATE otra sesión
        // reclamó el acceso, se actualizan 0 filas y esta petición se rechaza.
        $actualizada = DB::table('usuarios')
            ->where('id', $usuario->id)
            ->where('session_id', $sesionActual)
            ->where('activo', 1)
            ->update([
                'ultima_actividad' => now(),
            ]);

        if ($actualizada !== 1) {
            return $this->cerrarSesion(
                $request,
                401,
                'Esta sesión ya no está autorizada. Inicia sesión nuevamente.'
            );
        }

        return $next($request);
    }

    /**
     * Libera el registro SOLO si pertenece a la sesión actual.
     */
    private function liberarSiEsPropia(Request $request, $usuarioId, string $sesionActual): void
    {
        DB::table('usuarios')
            ->where('id', $usuarioId)
            ->where('session_id', $sesionActual)
            ->update([
                'session_id' => null,
                'ultima_actividad' => null,
            ]);
    }

    /**
     * Cierra la sesión local y responde según el tipo de petición:
     * JSON (AJAX/API) con el código indicado, o redirección al login.
     */
    private function cerrarSesion(Request $request, int $codigo, string $mensaje): Response
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            return response()->json([
                'exito' => false,
                'error' => $mensaje,
            ], $codigo);
        }

        return redirect()
            ->route('login')
            ->withErrors([
                'usuario' => $mensaje,
            ]);
    }

    /**
     * ¿La contraseña del usuario cambió desde que se abrió esta sesión?
     *
     * AuthenticateSession guarda en la sesión `password_hash_<guard>` (un HMAC
     * del hash de contraseña). Si el hash actual ya no corresponde, la sesión
     * fue abierta con una contraseña anterior y debe cerrarse. Si la clave no
     * existe todavía (primera petición tras el login), no se bloquea nada:
     * AuthenticateSession la guardará en esa misma petición.
     */
    private function contrasenaCambiada(Request $request, $usuario): bool
    {
        $clave = 'password_hash_'.Auth::getDefaultDriver();
        $guardado = $request->session()->get($clave);

        if (! is_string($guardado) || $guardado === '') {
            return false;
        }

        $actual = (string) $usuario->getAuthPassword();

        if ($actual === '') {
            return false;
        }

        // Mismo criterio que AuthenticateSession: primero el formato HMAC
        // actual y, por compatibilidad, el hash en claro.
        $calculado = null;

        try {
            $guard = Auth::guard();

            if (method_exists($guard, 'hashPasswordForCookie')) {
                $calculado = (string) $guard->hashPasswordForCookie($actual);
            }
        } catch (\Throwable $e) {
            $calculado = null;
        }

        return ! (($calculado !== null && hash_equals($calculado, $guardado))
            || hash_equals($actual, $guardado));
    }

    /**
     * ¿La tabla `usuarios` ya tiene las dos columnas de sesión única?
     */
    private function columnasDisponibles(): bool
    {
        return Schema::hasTable('usuarios')
            && Schema::hasColumn('usuarios', 'session_id')
            && Schema::hasColumn('usuarios', 'ultima_actividad');
    }
}
