<?php

use App\Http\Middleware\CajaAbierta;
use App\Http\Middleware\CabecerasSeguridad;
use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Http\Middleware\PrevenirCachePaginasPrivadas;
use App\Http\Middleware\VerificarDispositivo;
use App\Http\Middleware\VerificarRolActivo;
use App\Http\Middleware\VerificarSesionUnica;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * Destinos de las redirecciones de autenticación. Se declaran aquí
         * para no depender de la ruta que figure en `Referer` (que el cliente
         * puede manipular). Un invitado siempre vuelve al login; un usuario
         * ya autenticado que intenta abrir el login va al panel.
         */
        $middleware->redirectGuestsTo(fn (Request $request) => route('login'));
        $middleware->redirectUsersTo(fn (Request $request) => route('inicio'));

        // Punto ÚNICO de control del estado del rol: si el rol del usuario
        // autenticado está desactivado, se rechaza la petición (403) en toda
        // la aplicación, sin duplicar la comprobación en cada controlador.
        // No bloquea si `roles.activo` no existe todavía (migración pendiente).
        //
        // ORDEN:
        //  1. VerificarSesionUnica: sesión única por usuario, actividad,
        //     usuario desactivado y contraseña cambiada. Va PRIMERO para poder
        //     liberar en la base el registro de una sesión invalidada por
        //     cambio de contraseña (401/403 según corresponda).
        //  2. AuthenticateSession: refuerzo estándar de Laravel; invalida la
        //     sesión si el hash de contraseña no coincide.
        //  3. VerificarRolActivo: rol desactivado -> 403.
        //  4. VerificarDispositivo: control de dispositivo
        //  5. EnsureTwoFactorVerified: 2FA obligatorio
        //
        // Además, PrevenirCachePaginasPrivadas marca como no almacenables las
        // respuestas autenticadas: tras el logout, el botón «Atrás» no puede
        // mostrar una copia cacheada de una pantalla privada.
        $middleware->alias([
            'caja.abierta' => CajaAbierta::class,
        ]);

        $middleware->web(append: [
            CabecerasSeguridad::class,
            PrevenirCachePaginasPrivadas::class,
            VerificarSesionUnica::class,
            \Illuminate\Session\Middleware\AuthenticateSession::class,
            VerificarRolActivo::class,
            VerificarDispositivo::class,
            EnsureTwoFactorVerified::class,
        ]);

        // AuthenticateSession está en la lista de prioridad del kernel (por su
        // interfaz AuthenticatesSessions) y se reordena ANTES de los middleware
        // personalizados. Para que VerificarSesionUnica pueda liberar en la
        // base el registro de una sesión invalidada por cambio de contraseña
        // (antes de que AuthenticateSession lance la excepción), se declara
        // explícitamente ANTES de AuthenticateSession.
        $middleware->prependToPriorityList(
            \Illuminate\Contracts\Session\Middleware\AuthenticatesSessions::class,
            VerificarSesionUnica::class
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
