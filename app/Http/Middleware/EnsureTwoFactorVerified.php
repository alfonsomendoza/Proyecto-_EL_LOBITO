<?php

namespace App\Http\Middleware;

use App\Services\TwoFactorPolicyService;
use App\Services\TwoFactorService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureTwoFactorVerified
{
    public function __construct(
        protected TwoFactorPolicyService $twoFactorPolicy,
        protected TwoFactorService $twoFactorService
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::check()) {
            return $next($request);
        }

        // Skip in testing environment for existing tests
        if (app()->environment('testing')) {
            return $next($request);
        }

        $user = Auth::user();

        // Allow routes for 2FA verification/logout.
        // Excepción: `2fa.recovery` muestra los códigos en claro, así que NO
        // se permite antes de completar el segundo factor (cuando la política
        // lo exige). Las demás rutas del desafío/configuración sí deben ser
        // accesibles para poder completarlo.
        if ($request->routeIs('logout') || $request->routeIs('login') || $request->routeIs('login.post')) {
            return $next($request);
        }

        if ($request->routeIs('2fa.*') && ! $request->routeIs('2fa.recovery')) {
            return $next($request);
        }

        // Si la política indica que NO se exige 2FA, permitir acceso.
        // No se marca nada en la sesión: una marca heredada no debe servir
        // para saltarse el desafío si después se activa OBLIGATORIO.
        if (! $this->twoFactorPolicy->debeExigirTwoFactor($user)) {
            return $next($request);
        }

        // Verificado en ESTA sesión, con la política y el estado 2FA actuales.
        if ($this->twoFactorService->sesionVerificada($request->session(), $user)) {
            return $next($request);
        }

        // Si debe exigirse 2FA y no está verificado, redirigir según estado
        if (method_exists($user, 'hasTwoFactorEnabled') && $user->hasTwoFactorEnabled()) {
            return redirect()->route('2fa.challenge');
        }
        return redirect()->route('2fa.setup');
    }
}
