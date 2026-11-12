<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * ==========================================================================
 * PÁGINAS PRIVADAS FUERA DE LA CACHÉ DEL NAVEGADOR
 * ==========================================================================
 *
 * Al cerrar sesión, el botón «Atrás» del navegador puede restaurar una copia
 * de una pantalla privada guardada en disco o en la bfcache (caché de avance
 * y retroceso). Aunque la sesión ya esté invalidada en el servidor, esa copia
 * seguiría mostrando montos, ventas, propinas o datos del usuario anterior.
 *
 * Este middleware marca como NO almacenables las respuestas de toda petición
 * autenticada (incluida la redirección del propio logout, que se atiende aún
 * con el usuario autenticado):
 *
 *   Cache-Control: no-store, no-cache, must-revalidate, max-age=0
 *   Pragma: no-cache
 *   Expires: 0
 *
 * `no-store` es la directiva que impide guardar la respuesta y que excluye la
 * página de la bfcache. Al pulsar «Atrás», el navegador debe pedir la pantalla
 * otra vez al servidor; como la sesión ya no existe, el middleware `auth`
 * redirige al login.
 *
 * NO sustituye a `session()->invalidate()` + `regenerateToken()` del logout:
 * los complementa. La sesión se invalida siempre en el servidor; este
 * middleware evita que el navegador muestre una copia ya renderizada.
 */
class PrevenirCachePaginasPrivadas
{
    public function handle(Request $request, Closure $next): Response
    {
        // Se evalúa ANTES de la petición: en el logout el usuario todavía
        // está autenticado cuando entra aquí, aunque el controlador lo cierre
        // antes de devolver la redirección.
        $autenticado = Auth::check();

        /*
         * El formulario de acceso también debe quedar fuera de la caché:
         * si un equipo queda desatendido y se pulsa «Atrás», no debe
         * reaparecer una pantalla de login ya enviada (con su token CSRF).
         * Lo mismo aplica a las redirecciones (por ejemplo, la que lleva al
         * login cuando la sesión ya no es válida).
         */
        $rutaSensible = $request->routeIs('login', 'login.post', 'logout');

        $response = $next($request);

        if (! $autenticado
            && ! Auth::check()
            && ! $rutaSensible
            && ! $response->isRedirection()) {
            return $response;
        }

        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');

        return $response;
    }
}
