<?php

use App\Http\Middleware\VerificarRolActivo;
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
        $middleware->web(append: [
            VerificarRolActivo::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
