<?php

namespace App\Http\Middleware;

use App\Models\Dispositivo;
use App\Services\AuditService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class VerificarDispositivo
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::check()) {
            return $next($request);
        }

        // Skip in testing environment
        if (app()->environment('testing')) {
            return $next($request);
        }

        // Check if dispositivos table exists
        if (! Schema::hasTable('dispositivos')) {
            return $next($request);
        }

        $user = Auth::user();

        // Allow logout
        if ($request->routeIs('logout')) {
            return $next($request);
        }

        // Check if user has a device assigned
        if ($user->dispositivo_id) {
            $dispositivo = Dispositivo::find($user->dispositivo_id);
            if (! $dispositivo || ! $dispositivo->activo || ! $dispositivo->autorizado) {
                // Revoke session
                app(AuditService::class)->log('dispositivo_no_autorizado', $user->id, 'Dispositivo no autorizado o desactivado', [], $request);
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                return redirect()->route('login')->withErrors(['usuario' => 'Tu dispositivo no está autorizado.']);
            }
            // Update last use
            $dispositivo->ultimo_uso = now();
            $dispositivo->save();
        }

        return $next($request);
    }
}
