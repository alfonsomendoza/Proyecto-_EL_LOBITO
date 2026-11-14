<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Contadores y bloqueos de autenticación.
 *
 * Centraliza las reglas de intentos fallidos para no duplicarlas entre el
 * login por contraseña y el desafío 2FA.
 *
 * Almacenamiento: el caché configurado por Laravel (en producción, la tabla
 * `cache`; en pruebas, `array`). No se guarda nada en la sesión del navegador,
 * así que un bloqueo no se evade cambiando de sesión o de navegador.
 *
 * Claves:
 *   - login: cuenta normalizada + IP.
 *   - 2FA:   id de usuario + IP.
 *
 * Las escrituras se hacen bajo un bloqueo de caché para evitar condiciones de
 * carrera entre peticiones simultáneas. Si el almacén no está disponible, se
 * falla en abierto (se permite el intento) para no dejar a todos fuera.
 */
class IntentoAccesoService
{
    /** Fallos consecutivos que activan cada bloqueo de login. */
    private const FALLOS_POR_BLOQUEO_LOGIN = 3;

    /**
     * Escala de bloqueos del login. La primera posición corresponde al primer
     * bloqueo; la última se repite para el quinto y siguientes.
     */
    private const ESCALA_BLOQUEO_LOGIN = [30, 60, 120, 300, 600];

    /** Fallos consecutivos que cierran el desafío 2FA. */
    private const FALLOS_POR_BLOQUEO_2FA = 3;

    /** Duración fija del bloqueo tras agotar los intentos 2FA. */
    private const SEGUNDOS_BLOQUEO_2FA = 30;

    /**
     * Vigencia del estado en caché. Pasado este tiempo sin actividad, los
     * contadores se descartan. No existe bloqueo permanente.
     */
    private const TTL_ESTADO = 86400;

    /* ------------------------------------------------------------------
     | Login por contraseña
     | ------------------------------------------------------------------ */

    /**
     * Segundos que faltan para que termine el bloqueo del login.
     * Devuelve 0 cuando se puede intentar de nuevo.
     */
    public function segundosBloqueoLogin(string $usuario, ?string $ip): int
    {
        try {
            $estado = $this->leerEstado($this->claveLogin($usuario, $ip), $this->estadoLoginVacio());

            return max(0, (int) $estado['bloqueado_hasta'] - $this->ahora());
        } catch (\Throwable $e) {
            report($e);

            return 0;
        }
    }

    /**
     * Registra un fallo de contraseña. Cuando se alcanza el tercer fallo
     * consecutivo activa el bloqueo que corresponda a la escalada.
     *
     * @return array{bloqueado: bool, segundos: int, fallos: int, bloqueos: int}
     */
    public function registrarFalloLogin(string $usuario, ?string $ip): array
    {
        $clave = $this->claveLogin($usuario, $ip);

        return $this->conBloqueo($clave, function () use ($clave) {
            $estado = $this->leerEstado($clave, $this->estadoLoginVacio());
            $ahora = $this->ahora();

            // Si ya estaba bloqueado, no se suman fallos: se informa el resto.
            if ((int) $estado['bloqueado_hasta'] > $ahora) {
                return [
                    'bloqueado' => true,
                    'segundos' => (int) $estado['bloqueado_hasta'] - $ahora,
                    'fallos' => (int) $estado['fallos'],
                    'bloqueos' => (int) $estado['bloqueos'],
                ];
            }

            $estado['bloqueado_hasta'] = 0;
            $estado['fallos'] = (int) $estado['fallos'] + 1;

            if ($estado['fallos'] >= self::FALLOS_POR_BLOQUEO_LOGIN) {
                $indice = min(
                    (int) $estado['bloqueos'],
                    count(self::ESCALA_BLOQUEO_LOGIN) - 1
                );
                $segundos = self::ESCALA_BLOQUEO_LOGIN[$indice];

                $estado['bloqueos'] = (int) $estado['bloqueos'] + 1;
                $estado['fallos'] = 0;
                $estado['bloqueado_hasta'] = $ahora + $segundos;

                $this->guardarEstado($clave, $estado);

                return [
                    'bloqueado' => true,
                    'segundos' => $segundos,
                    'fallos' => 0,
                    'bloqueos' => (int) $estado['bloqueos'],
                ];
            }

            $this->guardarEstado($clave, $estado);

            return [
                'bloqueado' => false,
                'segundos' => 0,
                'fallos' => (int) $estado['fallos'],
                'bloqueos' => (int) $estado['bloqueos'],
            ];
        });
    }

    /**
     * Aplica un bloqueo de login sin tocar la escalada de contraseñas.
     * Se usa cuando el desafío 2FA agota sus intentos.
     */
    public function bloquearLogin(string $usuario, ?string $ip, int $segundos): void
    {
        $clave = $this->claveLogin($usuario, $ip);

        $this->conBloqueo($clave, function () use ($clave, $segundos) {
            $estado = $this->leerEstado($clave, $this->estadoLoginVacio());
            $ahora = $this->ahora();

            $estado['fallos'] = 0;
            $estado['bloqueado_hasta'] = max(
                (int) $estado['bloqueado_hasta'],
                $ahora + max(0, $segundos)
            );

            $this->guardarEstado($clave, $estado);
        });
    }

    /**
     * Login correcto: se borran los fallos y toda la escalada acumulada.
     */
    public function limpiarLogin(string $usuario, ?string $ip): void
    {
        $clave = $this->claveLogin($usuario, $ip);

        $this->conBloqueo($clave, function () use ($clave) {
            try {
                Cache::forget($clave);
            } catch (\Throwable $e) {
                report($e);
            }
        });
    }

    /* ------------------------------------------------------------------
     | Desafío 2FA
     | ------------------------------------------------------------------ */

    /**
     * Segundos restantes del bloqueo del desafío 2FA (0 si no hay bloqueo).
     */
    public function segundosBloqueoDosFactores(int $usuarioId, ?string $ip): int
    {
        try {
            $estado = $this->leerEstado($this->claveDosFactores($usuarioId, $ip), $this->estadoDosFactoresVacio());

            return max(0, (int) $estado['bloqueado_hasta'] - $this->ahora());
        } catch (\Throwable $e) {
            report($e);

            return 0;
        }
    }

    /**
     * Registra un código 2FA incorrecto. Al tercero activa el bloqueo de 30 s.
     *
     * @return array{bloqueado: bool, segundos: int, fallos: int}
     */
    public function registrarFalloDosFactores(int $usuarioId, ?string $ip): array
    {
        $clave = $this->claveDosFactores($usuarioId, $ip);

        return $this->conBloqueo($clave, function () use ($clave) {
            $estado = $this->leerEstado($clave, $this->estadoDosFactoresVacio());
            $ahora = $this->ahora();

            if ((int) $estado['bloqueado_hasta'] > $ahora) {
                return [
                    'bloqueado' => true,
                    'segundos' => (int) $estado['bloqueado_hasta'] - $ahora,
                    'fallos' => (int) $estado['fallos'],
                ];
            }

            $estado['bloqueado_hasta'] = 0;
            $estado['fallos'] = (int) $estado['fallos'] + 1;

            if ($estado['fallos'] >= self::FALLOS_POR_BLOQUEO_2FA) {
                $estado['fallos'] = 0;
                $estado['bloqueado_hasta'] = $ahora + self::SEGUNDOS_BLOQUEO_2FA;

                $this->guardarEstado($clave, $estado);

                return [
                    'bloqueado' => true,
                    'segundos' => self::SEGUNDOS_BLOQUEO_2FA,
                    'fallos' => 0,
                ];
            }

            $this->guardarEstado($clave, $estado);

            return [
                'bloqueado' => false,
                'segundos' => 0,
                'fallos' => (int) $estado['fallos'],
            ];
        });
    }

    /**
     * Verificación 2FA correcta: se reinician sus contadores.
     */
    public function limpiarDosFactores(int $usuarioId, ?string $ip): void
    {
        $clave = $this->claveDosFactores($usuarioId, $ip);

        $this->conBloqueo($clave, function () use ($clave) {
            try {
                Cache::forget($clave);
            } catch (\Throwable $e) {
                report($e);
            }
        });
    }

    /* ------------------------------------------------------------------
     | Claves y estado
     | ------------------------------------------------------------------ */

    private function claveLogin(string $usuario, ?string $ip): string
    {
        $clave = Str::lower(trim($usuario)).'|'.$this->ipNormalizada($ip);

        // Cuenta normalizada; se hashea para acotar la clave y no exponer
        // datos en el almacén de caché. «Admin»/«ADMIN»/«admin» coinciden.
        return 'intentos-login:'.hash('sha256', $clave);
    }

    private function claveDosFactores(int $usuarioId, ?string $ip): string
    {
        return 'intentos-2fa:'.$usuarioId.':'.$this->ipNormalizada($ip);
    }

    private function ipNormalizada(?string $ip): string
    {
        return $ip !== null && trim($ip) !== '' ? trim($ip) : 'sin-ip';
    }

    private function estadoLoginVacio(): array
    {
        return [
            'fallos' => 0,
            'bloqueos' => 0,
            'bloqueado_hasta' => 0,
        ];
    }

    private function estadoDosFactoresVacio(): array
    {
        return [
            'fallos' => 0,
            'bloqueado_hasta' => 0,
        ];
    }

    private function leerEstado(string $clave, array $porDefecto): array
    {
        try {
            $estado = Cache::get($clave);
        } catch (\Throwable $e) {
            report($e);

            return $porDefecto;
        }

        return is_array($estado) ? array_merge($porDefecto, $estado) : $porDefecto;
    }

    private function guardarEstado(string $clave, array $estado): void
    {
        try {
            Cache::put($clave, $estado, self::TTL_ESTADO);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function ahora(): int
    {
        return now()->getTimestamp();
    }

    /**
     * Ejecuta la escritura bajo un bloqueo de caché. Si el bloqueo no se
     * consigue (o el almacén no lo soporta), se ejecuta igualmente: es
     * preferible un contador aproximado a bloquear el acceso a todos.
     */
    private function conBloqueo(string $clave, callable $callback): mixed
    {
        $bloqueo = null;

        try {
            $bloqueo = Cache::lock('bloqueo:'.$clave, 5);
            if (! $bloqueo->block(2)) {
                $bloqueo = null;
            }
        } catch (\Throwable $e) {
            report($e);
            $bloqueo = null;
        }

        try {
            return $callback();
        } finally {
            if ($bloqueo !== null) {
                try {
                    $bloqueo->release();
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        }
    }
}
