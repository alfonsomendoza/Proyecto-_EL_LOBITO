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
 *  - Content-Security-Policy-Report-Only:
 *      Política provisional para descubrir qué recursos rompen si se adopta
 *      CSP de bloqueo. No rompe el POS: las vistas actuales usan scripts y
 *      estilos en línea, por lo que se permiten explícitamente.
 */
class CabecerasSeguridad
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Provisional: permite los recursos actuales y reporta violaciones.
        // El siguiente paso es retirar unsafe-inline y usar nonces/hashes.
        $cspReportOnly = implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' https:",
            "style-src 'self' 'unsafe-inline' https:",
            "img-src 'self' data: https:",
            "font-src 'self' https: data:",
            "connect-src 'self'",
            "frame-ancestors 'self'",
            "base-uri 'self'",
            "form-action 'self'",
        ]);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $response->headers->set('Content-Security-Policy-Report-Only', $cspReportOnly);

        return $response;
    }
}
