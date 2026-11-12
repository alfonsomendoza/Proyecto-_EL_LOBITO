<?php

namespace App\Http\Controllers;

use App\Helpers\QrCode;
use App\Services\AuditService;
use App\Services\TwoFactorPolicyService;
use App\Services\TwoFactorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

class TwoFactorController extends Controller
{
    protected TwoFactorService $twoFactorService;

    protected AuditService $auditService;

    protected TwoFactorPolicyService $twoFactorPolicy;

    public function __construct(TwoFactorService $twoFactorService, AuditService $auditService, TwoFactorPolicyService $twoFactorPolicy)
    {
        $this->twoFactorService = $twoFactorService;
        $this->auditService = $auditService;
        $this->twoFactorPolicy = $twoFactorPolicy;
    }

    public function setup()
    {
        $user = Auth::user();

        $debeExigir = $this->twoFactorPolicy->debeExigirTwoFactor($user);

        // Si la política no exige 2FA (global desactivado o excepción individual
        // autorizada: ADMIN/GERENTE deshabilitó el 2FA de este usuario), no se
        // permite configurar el segundo factor.
        if (! $debeExigir) {
            return redirect()->route('inicio');
        }

        if (method_exists($user, 'hasTwoFactorEnabled') && $user->hasTwoFactorEnabled()) {
            return redirect()->route('2fa.challenge');
        }

        // Si ya está confirmado, ir a challenge
        if (isset($user->two_factor_confirmed_at) && $user->two_factor_confirmed_at) {
            return redirect()->route('2fa.challenge');
        }

        // Generar secreto individual si no existe (no regenerar si ya existe sin confirmar).
        // El secreto se persiste CIFRADO (con migración en caliente para los
        // valores legacy en texto plano). La vista y el QR usan el valor en
        // memoria: nunca se envían ni se guardan el secreto cifrado en claro.
        if (empty($user->two_factor_secret)) {
            $secret = $this->twoFactorService->generateSecret();
            $this->twoFactorService->guardarSecret($user, $secret);
            try {
                $this->auditService->log('2fa_configuracion_iniciada', $user->id);
            } catch (\Throwable $e) {
                // Ignorar
            }
        } else {
            $user->refresh();
        }

        // El secreto se trabaja DESCIFRADO en memoria para QR y pantalla.
        $secretPlano = $this->twoFactorService->secretPlano($user);

        $qrCodeUrl = $this->twoFactorService->getQrCodeUrl('El Lobito', $user->username ?? ($user->nombre ?? 'usuario'), (string) $secretPlano);
        $otpauthUrl = method_exists($this->twoFactorService, 'getOtpAuthUrl')
            ? $this->twoFactorService->getOtpAuthUrl('El Lobito', $user->username ?? ($user->nombre ?? 'usuario'), (string) $secretPlano)
            : $qrCodeUrl;

        // QR real generado localmente con BaconQrCode (SVG). Devuelve null si
        // la librería no está instalada: nunca se simula un código QR.
        $qrDataUri = QrCode::svgDataUri($otpauthUrl, 200);

        return view('auth.2fa.setup', [
            'secret' => (string) $secretPlano,
            'qrCodeUrl' => $qrCodeUrl,
            'otpauthUrl' => $otpauthUrl,
            'qrDataUri' => $qrDataUri,
        ]);
    }

    public function confirm(Request $request)
    {
        $request->validate([
            'code' => 'required|string',
        ]);

        $user = Auth::user();
        $claveIntentos = $this->twoFactorService->claveIntentos($user->id, $request->ip());

        if ($this->twoFactorService->bloqueado($claveIntentos)) {
            $segundos = $this->twoFactorService->segundosBloqueo($claveIntentos);

            return back()->withErrors([
                'code' => "Demasiados intentos fallidos. Intenta de nuevo en {$segundos} segundos.",
            ]);
        }

        // El secreto se trabaja DESCIFRADO en memoria; si no existe o está
        // corrupto, no se permite la confirmación.
        if ($this->twoFactorService->secretPlano($user) === null) {
            return back()->withErrors(['code' => 'No hay secreto configurado.']);
        }

        if (! $this->twoFactorService->verifyCodeParaUsuario($user, $request->code)) {
            $this->twoFactorService->registrarIntentoFallido($claveIntentos);

            try {
                $this->auditService->log('2fa_validacion_fallida', $user->id, 'Código incorrecto en configuración');
            } catch (\Throwable $e) {
            }

            return back()->withErrors(['code' => 'Código inválido.']);
        }

        $this->twoFactorService->limpiarIntentos($claveIntentos);

        // Generar códigos de recuperación solo si no existen
        $recoveryCodes = [];
        if (method_exists($user, 'getTwoFactorRecoveryCodes')) {
            $existing = $user->getTwoFactorRecoveryCodes();
            if (! empty($existing)) {
                $recoveryCodes = $existing;
            } else {
                $recoveryCodes = $this->twoFactorService->generateRecoveryCodes();
                if (method_exists($user, 'setTwoFactorRecoveryCodes')) {
                    $user->setTwoFactorRecoveryCodes($recoveryCodes);
                } else {
                    $user->two_factor_recovery_codes = json_encode($recoveryCodes);
                }
            }
        } else {
            $recoveryCodes = $this->twoFactorService->generateRecoveryCodes();
            if (Schema::hasColumn('usuarios', 'two_factor_recovery_codes')) {
                $user->two_factor_recovery_codes = json_encode($recoveryCodes);
            }
        }

        if (Schema::hasColumn('usuarios', 'two_factor_enabled')) {
            $user->two_factor_enabled = true;
        }
        if (Schema::hasColumn('usuarios', 'two_factor_confirmed_at')) {
            $user->two_factor_confirmed_at = now();
        }
        // Garantía: el secreto siempre queda persistido CIFRADO (garantía contra
        // valores plaintext legados o nuevos).
        $user->two_factor_secret = $this->twoFactorService->encriptarSecret((string) $user->two_factor_secret);
        $user->save();

        try {
            $this->auditService->log('2fa_configuracion_completada', $user->id);
        } catch (\Throwable $e) {
        }

        $this->twoFactorService->marcarSesionVerificada($request->session(), $user);

        if (view()->exists('auth.2fa.recovery')) {
            return view('auth.2fa.recovery', ['recoveryCodes' => $recoveryCodes]);
        }

        return redirect()->route('inicio');
    }

    public function challenge(Request $request)
    {
        $user = Auth::user();

        if (method_exists($user, 'hasTwoFactorEnabled') && $user->hasTwoFactorEnabled()) {
            if ($this->twoFactorService->sesionVerificada($request->session(), $user)) {
                return redirect()->route('inicio');
            }
            if (view()->exists('auth.2fa.challenge')) {
                return view('auth.2fa.challenge');
            }

            return redirect()->route('2fa.verificar.mostrar'); // fallback
        }

        // Si no está configurado pero se exige por política, ir a setup
        if ($this->twoFactorPolicy->debeExigirTwoFactor($user)) {
            return redirect()->route('2fa.setup');
        }

        return redirect()->route('inicio');
    }

    public function verify(Request $request)
    {
        $request->validate([
            'code' => 'required|string',
        ]);

        $user = Auth::user();
        $claveIntentos = $this->twoFactorService->claveIntentos($user->id, $request->ip());
        $claveRecuperacion = '2fa-recuperacion:'.$user->id.':'.($request->ip() ?: 'sin-ip');

        // Los códigos de recuperación son de un solo uso y NO deben quedar
        // bloqueados por los intentos fallidos del TOTP (ni viceversa).
        // Se intentan PRIMERO, con su propio limitador.
        try {
            if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($claveRecuperacion, 5)) {
                $segundos = \Illuminate\Support\Facades\RateLimiter::availableIn($claveRecuperacion);

                return back()->withErrors([
                    'code' => "Demasiados intentos fallidos. Intenta de nuevo en {$segundos} segundos.",
                ]);
            }
        } catch (\Throwable $e) {
            // Ignorar si el almacén de caché no responde.
        }

        if ($this->twoFactorService->consumirCodigoRecuperacion($user, $request->code)) {
            $this->twoFactorService->limpiarIntentos($claveIntentos);
            try {
                \Illuminate\Support\Facades\RateLimiter::clear($claveRecuperacion);
            } catch (\Throwable $e) {
            }
            $this->twoFactorService->marcarSesionVerificada($request->session(), $user);
            try {
                $this->auditService->log('2fa_codigo_recuperacion_usado', $user->id);
            } catch (\Throwable $e) {
            }

            return redirect()->intended(route('inicio'));
        }

        // Bloqueo propio del TOTP (no afecta a los códigos de recuperación).
        if ($this->twoFactorService->bloqueado($claveIntentos)) {
            $segundos = $this->twoFactorService->segundosBloqueo($claveIntentos);

            return back()->withErrors([
                'code' => "Demasiados intentos fallidos. Intenta de nuevo en {$segundos} segundos.",
            ]);
        }

        // TOTP: además de verificar la ventana ±1, se impide reutilizar un
        // código ya aceptado (protección contra replay). El secreto se trabaja
        // DESCIFRADO en memoria.
        if ($this->twoFactorService->secretPlano($user) !== null
            && strlen($request->code) === 6
            && ctype_digit($request->code)
        ) {
            if ($this->twoFactorService->verifyCodeParaUsuario($user, $request->code)) {
                $this->twoFactorService->limpiarIntentos($claveIntentos);
                $this->twoFactorService->marcarSesionVerificada($request->session(), $user);
                try {
                    $this->auditService->log('2fa_validacion_exitosa', $user->id);
                } catch (\Throwable $e) {
                }

                return redirect()->intended(route('inicio'));
            }
        }

        $this->twoFactorService->registrarIntentoFallido($claveIntentos);

        try {
            \Illuminate\Support\Facades\RateLimiter::hit($claveRecuperacion, 300);
        } catch (\Throwable $e) {
        }

        try {
            $this->auditService->log('2fa_validacion_fallida', $user->id, 'Código TOTP o de recuperación inválido');
        } catch (\Throwable $e) {
        }

        return back()->withErrors(['code' => 'Código inválido.']);
    }

    public function recovery(Request $request)
    {
        $user = Auth::user();

        // Defensa en profundidad: esta vista muestra los códigos en claro.
        // El middleware ya bloquea la ruta antes del segundo factor, pero no
        // debe depender solo de él. Con la política DESACTIVADA se permite,
        // igual que el resto del sistema.
        if ($this->twoFactorPolicy->debeExigirTwoFactor($user)
            && ! $this->twoFactorService->sesionVerificada($request->session(), $user)) {
            if (method_exists($user, 'hasTwoFactorEnabled') && $user->hasTwoFactorEnabled()) {
                return redirect()->route('2fa.challenge');
            }

            return redirect()->route('2fa.setup');
        }

        $recoveryCodes = $user->getTwoFactorRecoveryCodes();

        return view('auth.2fa.recovery-show', ['recoveryCodes' => $recoveryCodes]);
    }
}
