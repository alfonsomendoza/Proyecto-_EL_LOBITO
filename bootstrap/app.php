<?php

use App\Http\Middleware\VerificarRolActivo;
use App\Http\Middleware\VerificarSesionUnica;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
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
        $middleware->web(append: [
            VerificarSesionUnica::class,
            \Illuminate\Session\Middleware\AuthenticateSession::class,
            VerificarRolActivo::class,
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
