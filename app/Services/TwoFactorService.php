<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class TwoFactorService
{
    /** Intentos fallidos antes del bloqueo temporal. */
    public const MAX_INTENTOS = 5;

    /** Duración del bloqueo temporal, en segundos. */
    public const SEGUNDOS_BLOQUEO = 300;

    /** Marca temporal de la verificación en la sesión actual. */
    public const CLAVE_SESION = 'two_factor_verificado';

    /**
     * Marca temporal (timestamp de servidor) de inicio del desafío 2FA.
     * No depende de JavaScript ni del cliente.
     */
    public const CLAVE_DESAFIO_INICIO = 'two_factor_desafio_inicio';

    /** Segundos máximos para completar el desafío 2FA. */
    public const SEGUNDOS_DESAFIO = 60;

    /**
     * Huella que liga la verificación a la política vigente y al estado 2FA
     * del usuario. Sin ella, una sesión abierta antes de activar OBLIGATORIO
     * (o antes de un restablecimiento) seguiría contando como verificada.
     */
    public const CLAVE_SELLO = 'two_factor_sello';

    private const CLAVE_ULTIMO_TIMESTEP = '2fa-ultimo-timestep:';

    public function __construct(protected TwoFactorPolicyService $twoFactorPolicy) {}

    /* ------------------------------------------------------------------
     | Cifrado del secreto TOTP
     * ------------------------------------------------------------------ */

    /**
     * Devuelve el secreto TOTP DESCIFRADO en memoria (nunca se persiste en
     * claro). Si el valor almacenado sigue siendo un secreto legacy en texto
     * plano, se re-cifra de forma segura y se persiste (migración en caliente).
     *
     * Devuelve null si no hay secreto o si es inválido/corrupto: la verificación
     * debe fallar de forma segura y nunca descifrar con un valor roto.
     */
    public function secretPlano(User $user): ?string
    {
        $stored = (string) ($user->two_factor_secret ?? '');

        if ($stored === '') {
            return null;
        }

        try {
            // Si se descifra correctamente, el valor almacenado ya es un secreto
            // cifrado con la APP_KEY: se devuelve el valor en memoria.
            return (string) Crypt::decryptString($stored);
        } catch (\Throwable $e) {
            // No se pudo descifrar: puede ser un secreto legacy en texto plano
            // (formato Base32) o un valor corrupto. Solo se acepta Base32 válido.
            if ($this->esSecretoBase32($stored)) {
                // Migración en caliente: re-cifrar y persistir el secreto legacy
                // sin perder el enrolle (confirmed_at se mantiene intacto).
                $user->two_factor_secret = $this->encriptarSecret($stored);
                $user->save();

                return $stored;
            }

            // Secreto corrupto: no se devuelve.
            return null;
        }
    }

    /**
     * Cifra un secreto TOTP para persistirlo. No cifra dos veces un valor ya
     * cifrado (verificando descifrándolo antes).
     */
    public function encriptarSecret(string $secret): string
    {
        try {
            $descifrado = (string) Crypt::decryptString($secret);

            if ($descifrado !== '') {
                // Ya era un secreto cifrado válido.
                return $secret;
            }
        } catch (\Throwable $e) {
            // No era cifrado: era texto plano (legacy o nuevo).
        }

        return (string) Crypt::encryptString($secret);
    }

    /**
     * Valida el formato Base32 de un secreto legacy en texto plano.
     * 32 caracteres = 160 bits (RFC 4226 recomienda >= 128).
     */
    private function esSecretoBase32(string $secret): bool
    {
        $secret = strtoupper(trim($secret));

        return strlen($secret) >= 16 && preg_match('/^[A-Z2-7]+$/', $secret) === 1;
    }

    /**
     * Guarda el secreto TOTP CIFRADO en la base de datos.
     */
    public function guardarSecret(User $user, string $secret): void
    {
        $user->two_factor_secret = $this->encriptarSecret($secret);
        $user->save();
    }

    /**
     * Secreto Base32. 32 caracteres = 160 bits (RFC 4226 recomienda >=128).
     */
    public function generateSecret(int $length = 32): string
    {
        $secret = '';
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; // Base32 alphabet

        for ($i = 0; $i < $length; $i++) {
            $secret .= $alphabet[random_int(0, 31)];
        }

        return $secret;
    }

    /**
     * URI otpauth:// del usuario. El QR real se genera en el servidor con
     * App\Helpers\QrCode (BaconQrCode); no se dibuja en el cliente.
     *
     * El label se compone de emisor y cuenta codificados por separado y unidos
     * por ":" literal, como en el formato Key URI de Google Authenticator.
     */
    public function getQrCodeUrl(string $companyName, string $companyUsername, string $secret): string
    {
        return $this->getOtpAuthUrl($companyName, $companyUsername, $secret);
    }

    public function getOtpAuthUrl(string $companyName, string $companyUsername, string $secret): string
    {
        return 'otpauth://totp/'
            .rawurlencode($companyName).':'.rawurlencode($companyUsername)
            .'?secret='.$secret
            .'&issuer='.rawurlencode($companyName);
    }

    public function verifyCode(string $secret, string $code): bool
    {
        return $this->timestepValido($secret, $code) !== null;
    }

    /**
     * Verifica TOTP y además impide reutilizar un código ya aceptado.
     *
     * El timestep usado se guarda en caché por usuario durante 5 minutos. Si
     * ya se aceptó ese mismo timestep (o uno posterior), el código se rechaza
     * aunque siga dentro de la ventana ±1 (30/60/90 s).
     *
     * El secreto se trabaja siempre DESCIFRADO en memoria (nunca se vuelve a
     * persistir en claro): un secreto corrupto devuelve false de forma segura.
     */
    public function verifyCodeParaUsuario(User $user, string $code): bool
    {
        $secret = $this->secretPlano($user);

        if ($secret === null) {
            return false;
        }

        $timestep = $this->timestepValido($secret, $code);

        if ($timestep === null) {
            return false;
        }

        $clave = self::CLAVE_ULTIMO_TIMESTEP.$user->getKey();

        try {
            $ultimo = Cache::get($clave);

            if ($ultimo !== null && $timestep <= (int) $ultimo) {
                return false;
            }

            Cache::put($clave, $timestep, now()->addMinutes(5));
        } catch (\Throwable $e) {
            // Si el almacén de caché no responde no se puede comprobar la
            // reutilización. Se acepta el código (ya validado por TOTP) para
            // no dejar a nadie fuera del sistema; el intento sigue sujeto al
            // bloqueo por intentos fallidos.
            report($e);
        }

        return true;
    }

    /* ------------------------------------------------------------------
     | Estado de verificación de la sesión
     | ------------------------------------------------------------------ */

    public function marcarSesionVerificada(Session $session, User $user): void
    {
        $session->put(self::CLAVE_SESION, time());
        $session->put(self::CLAVE_SELLO, $this->selloSesion($user));
    }

    /**
     * Borra la marca de verificación de la sesión actual.
     *
     * Se usa al iniciar sesión cuando la política exige el segundo factor:
     * la sesión nace sin verificación y no puede heredar un `true`/`false`
     * de una sesión anterior en el mismo navegador.
     */
    public function limpiarSesionVerificada(Session $session): void
    {
        $session->forget([self::CLAVE_SESION, self::CLAVE_SELLO]);
    }

    /* ------------------------------------------------------------------
     | Inicio y expiración del desafío 2FA (60 segundos)
     | ------------------------------------------------------------------ */

    /**
     * Registra la marca de inicio del desafío. El temporizador es del
     * servidor: recargar la pantalla o enviar códigos no lo reinicia.
     *
     * @param  bool  $reiniciar  True solo al llegar desde el login.
     */
    public function iniciarDesafio(Session $session, bool $reiniciar = false): void
    {
        $inicio = $session->get(self::CLAVE_DESAFIO_INICIO);

        if ($reiniciar || ! is_numeric($inicio) || (int) $inicio <= 0) {
            $session->put(self::CLAVE_DESAFIO_INICIO, now()->getTimestamp());
        }
    }

    public function limpiarDesafio(Session $session): void
    {
        $session->forget(self::CLAVE_DESAFIO_INICIO);
    }

    public function desafioExpirado(Session $session): bool
    {
        $inicio = $session->get(self::CLAVE_DESAFIO_INICIO);

        // Sin marca no se crea un bucle de cierre: el challenge la escribe.
        if (! is_numeric($inicio) || (int) $inicio <= 0) {
            return false;
        }

        return now()->getTimestamp() - (int) $inicio >= self::SEGUNDOS_DESAFIO;
    }

    public function segundosRestantesDesafio(Session $session): int
    {
        $inicio = $session->get(self::CLAVE_DESAFIO_INICIO);

        if (! is_numeric($inicio) || (int) $inicio <= 0) {
            return self::SEGUNDOS_DESAFIO;
        }

        return max(0, self::SEGUNDOS_DESAFIO - (now()->getTimestamp() - (int) $inicio));
    }

    /**
     * ¿La sesión actual completó el segundo factor para ESTA política y para
     * el estado 2FA ACTUAL del usuario?
     *
     * Solo acepta la marca temporal que escriben los controladores. Los
     * valores heredados `true`/`false` se rechazan a propósito: una sesión
     * abierta con la política DESACTIVADA no debe saltarse el desafío cuando
     * ADMIN activa OBLIGATORIO.
     */
    public function sesionVerificada(Session $session, User $user): bool
    {
        $marca = $session->get(self::CLAVE_SESION);

        if (! is_numeric($marca) || (int) $marca <= 0) {
            return false;
        }

        $sello = $session->get(self::CLAVE_SELLO);

        return is_string($sello) && hash_equals($this->selloSesion($user), $sello);
    }

    /**
     * Huella de la verificación: modo de política vigente + estado 2FA del
     * usuario. Cambiar el modo global o restablecer el 2FA del usuario cambia
     * la huella, así que las sesiones anteriores dejan de estar verificadas.
     */
    public function selloSesion(User $user): string
    {
        $confirmado = $user->two_factor_confirmed_at;

        if ($confirmado !== null && $confirmado !== '') {
            $confirmado = Carbon::parse($confirmado)->format('Y-m-d H:i:s');
        } else {
            $confirmado = '';
        }

        return hash('sha256', implode('|', [
            '2fa-v1',
            $this->twoFactorPolicy->modoGlobal(),
            (string) $user->getKey(),
            $confirmado,
            (int) $user->two_factor_enabled,
        ]));
    }

    /**
     * Devuelve el timestep que coincide con el código, o null si no es válido.
     */
    private function timestepValido(string $secret, string $code): ?int
    {
        $code = preg_replace('/\s+/', '', $code);
        if (strlen($code) !== 6 || ! ctype_digit($code)) {
            return null;
        }

        $timestamp = (int) floor(time() / 30);

        // Ventana ±1 para tolerar desfases de reloj.
        for ($i = -1; $i <= 1; $i++) {
            $candidato = $timestamp + $i;
            if (hash_equals($this->generateCode($secret, $candidato), $code)) {
                return $candidato;
            }
        }

        return null;
    }

    /* ------------------------------------------------------------------
     | Bloqueo temporal por intentos fallidos
     | ------------------------------------------------------------------ */

    public function claveIntentos(int $usuarioId, ?string $ip): string
    {
        return '2fa-intentos:'.$usuarioId.':'.($ip !== null && $ip !== '' ? $ip : 'sin-ip');
    }

    public function bloqueado(string $clave): bool
    {
        try {
            return RateLimiter::tooManyAttempts($clave, self::MAX_INTENTOS);
        } catch (\Throwable $e) {
            // Sin caché disponible no se puede bloquear; se prefiere permitir
            // el intento antes que bloquear el acceso a todos los usuarios.
            report($e);

            return false;
        }
    }

    public function segundosBloqueo(string $clave): int
    {
        try {
            return RateLimiter::availableIn($clave);
        } catch (\Throwable $e) {
            report($e);

            return 0;
        }
    }

    public function registrarIntentoFallido(string $clave): void
    {
        try {
            RateLimiter::hit($clave, self::SEGUNDOS_BLOQUEO);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function limpiarIntentos(string $clave): void
    {
        try {
            RateLimiter::clear($clave);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /* ------------------------------------------------------------------
     | Códigos de recuperación
     | ------------------------------------------------------------------ */

    /**
     * Consume un código de recuperación de forma ATÓMICA.
     *
     * Se relee el usuario con bloqueo dentro de una transacción: si dos
     * peticiones intentan usar el mismo código, solo una lo elimina y la otra
     * recibe false. No se elimina nada si el código no existe.
     */
    public function consumirCodigoRecuperacion(User $user, string $code): bool
    {
        $code = strtoupper(trim($code));

        if ($code === '') {
            return false;
        }

        return DB::transaction(function () use ($user, $code) {
            $fresco = User::query()->whereKey($user->getKey())->lockForUpdate()->first();

            if (! $fresco) {
                return false;
            }

            $codigos = $fresco->getTwoFactorRecoveryCodes();

            foreach ($codigos as $indice => $candidato) {
                if (hash_equals(strtoupper((string) $candidato), $code)) {
                    unset($codigos[$indice]);
                    $fresco->setTwoFactorRecoveryCodes(array_values($codigos));
                    $fresco->save();

                    return true;
                }
            }

            return false;
        });
    }

    public function generateCode(string $secret, ?int $timestamp = null): string
    {
        if ($timestamp === null) {
            $timestamp = floor(time() / 30);
        }

        $secretKey = $this->base32Decode($secret);
        $time = pack('N*', 0).pack('N*', $timestamp);
        $hm = hash_hmac('sha1', $time, $secretKey, true);
        $offset = ord(substr($hm, -1)) & 0x0F;
        $hashpart = substr($hm, $offset, 4);
        $value = unpack('N', $hashpart)[1];
        $value = $value & 0x7FFFFFFF;
        $mod = $value % 1000000;

        return str_pad($mod, 6, '0', STR_PAD_LEFT);
    }

    private function base32Decode(string $secret): string
    {
        $secret = strtoupper($secret);
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $buffer = 0;
        $bitsLeft = 0;
        $result = '';

        for ($i = 0; $i < strlen($secret); $i++) {
            $char = $secret[$i];
            if ($char === '=') {
                break;
            }
            $val = strpos($alphabet, $char);
            if ($val === false) {
                continue;
            }
            $buffer = ($buffer << 5) | $val;
            $bitsLeft += 5;
            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $result .= chr(($buffer >> $bitsLeft) & 0xFF);
            }
        }

        return $result;
    }

    public function generateRecoveryCodes(int $count = 8): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = strtoupper(Str::random(10));
        }

        return $codes;
    }
}
