<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cabeceras HTTP de seguridad para todas las respuestas web.
 *
 * Son cabeceras de bajo riesgo que no cambian el funcionamiento de la
 * aplicación:
 *
 *  - X-Content-Type-Options: nosniff
 *      Evita que el navegador «adivine» un tipo distinto al declarado.
 *  - Referrer-Policy: strict-origin-when-cross-origin
 *      No envía la URL completa a sitios externos.
 *  - X-Frame-Options: SAMEORIGIN
 *      Impide incrustar las pantallas en otro sitio (clickjacking).
 *  - Permissions-Policy: camera=(), microphone=(), geolocation=()
 *      El POS no usa esos permisos, así que se desactivan.
 *
 * NO se añade Content-Security-Policy todavía: las vistas usan scripts y
 * estilos en línea, y una política estricta rompería el POS. Queda como
 * riesgo pendiente documentado.
 */
class CabecerasSeguridad
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        return $response;
    }
}
