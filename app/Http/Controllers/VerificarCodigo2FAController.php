<?php

namespace App\Http\Controllers;

use App\Models\Codigo2FAAdministrado;
use App\Services\AuditService;
use App\Services\IntentoAccesoService;
use App\Services\TwoFactorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

class VerificarCodigo2FAController extends Controller
{
    protected AuditService $auditService;

    protected TwoFactorService $twoFactorService;

    protected IntentoAccesoService $intentoAcceso;

    public function __construct(AuditService $auditService, TwoFactorService $twoFactorService, ?IntentoAccesoService $intentoAcceso = null)
    {
        $this->auditService = $auditService;
        $this->twoFactorService = $twoFactorService;
        $this->intentoAcceso = $intentoAcceso ?? app(IntentoAccesoService::class);
    }

    private function cerrarSesionPorBloqueo(Request $request, string $motivo)
    {
        $request->merge(['motivo' => $motivo]);

        return app(AuthController::class)->logout($request);
    }

    public function mostrar(Request $request)
    {
        if ($this->twoFactorService->desafioExpirado($request->session())) {
            return $this->cerrarSesionPorBloqueo($request, '2fa_tiempo_expirado');
        }

        if ($this->intentoAcceso->segundosBloqueoDosFactores((int) Auth::id(), $request->ip()) > 0) {
            return $this->cerrarSesionPorBloqueo($request, '2fa_limite_intentos');
        }

        if ($this->twoFactorService->sesionVerificada($request->session(), Auth::user())) {
            return redirect()->route('inicio');
        }

        return view('auth.2fa.codigo-admin');
    }

    public function verificar(Request $request)
    {
        $request->validate([
            'codigo' => 'required|string|size:6',
        ]);

        $usuario = Auth::user();

        // La expiración manda sobre cualquier código, incluso uno válido.
        if ($this->twoFactorService->desafioExpirado($request->session())) {
            return $this->cerrarSesionPorBloqueo($request, '2fa_tiempo_expirado');
        }

        // Bloqueo por agotar los intentos 2FA.
        if ($this->intentoAcceso->segundosBloqueoDosFactores((int) $usuario->id, $request->ip()) > 0) {
            return $this->cerrarSesionPorBloqueo($request, '2fa_limite_intentos');
        }

        // Protección contra fuerza bruta a nivel de endpoint (no por código):
        // un código válido se acepta aunque haya intentos fallidos previos.
        $claveLimitador = '2fa-verificar:'.$usuario->id.':'.($request->ip() ?: 'sin-ip');

        try {
            if (RateLimiter::tooManyAttempts($claveLimitador, 5)) {
                $segundos = RateLimiter::availableIn($claveLimitador);

                $this->auditService->log('2fa_bloqueado', $usuario->id, 'Demasiados intentos fallidos de código 2FA');

                return back()->withErrors(['codigo' => "Demasiados intentos fallidos. Intenta de nuevo en {$segundos} segundos."]);
            }
        } catch (\Throwable $e) {
            // Si el almacén de caché no responde, se permite el intento.
        }

        // Buscar código válido pendiente
        $codigos = Codigo2FAAdministrado::where('usuario_id', $usuario->id)
            ->where('usado', false)
            ->where(function ($q) {
                $q->whereNull('expira_en')
                    ->orWhere('expira_en', '>', now());
            })
            ->get();

        $valido = false;
        $codigoEncontrado = null;

        foreach ($codigos as $codigo) {
            if (Hash::check($request->codigo, $codigo->codigo_hash)) {
                $valido = true;
                $codigoEncontrado = $codigo;
                break;
            }
        }

        if (! $valido) {
            try {
                RateLimiter::hit($claveLimitador, 300);
            } catch (\Throwable $e) {
                // Ignorar si el almacén no responde.
            }
            $this->auditService->log('2fa_codigo_invalido', $usuario->id, 'Código 2FA inválido');

            return back()->withErrors(['codigo' => 'Código inválido.']);
        }

        // Marcar código como usado
        $codigoEncontrado->usado = true;
        $codigoEncontrado->usado_en = now();
        $codigoEncontrado->save();

        try {
            RateLimiter::clear($claveLimitador);
        } catch (\Throwable $e) {
            // Ignorar.
        }

        $this->twoFactorService->marcarSesionVerificada($request->session(), $usuario);
        $this->auditService->log('2fa_verificado_exitoso', $usuario->id);

        return redirect()->intended(route('inicio'));
    }
}
