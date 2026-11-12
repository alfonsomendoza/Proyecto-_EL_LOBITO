<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Schema;

class TwoFactorPolicyService
{
    public const MODO_DESACTIVADO = 'desactivado';

    public const MODO_OBLIGATORIO = 'obligatorio';

    /**
     * Obtiene el modo global de 2FA.
     * Por defecto DESACTIVADO hasta que ADMIN lo active explícitamente.
     */
    public function modoGlobal(): string
    {
        // Intentar leer desde configuraciones si existe
        if (Schema::hasTable('configuraciones')) {
            try {
                $config = \DB::table('configuraciones')->where('clave', 'two_factor_modo')->first();
                if ($config && $config->valor) {
                    $modo = strtolower(trim($config->valor));
                    if (in_array($modo, [self::MODO_DESACTIVADO, self::MODO_OBLIGATORIO], true)) {
                        return $modo;
                    }
                }
            } catch (\Throwable $e) {
                // Ignorar errores
            }
        }

        // Comportamiento por defecto: DESACTIVADO (ADMIN decide activarlo)
        return self::MODO_DESACTIVADO;
    }

    /**
     * Determina si se debe exigir 2FA para un usuario dado.
     *
     * PRECEDENCIA (ver docs/ o MEMORY.md: control global + individual):
     *
     *   1. Obligatoriedad global DESACTIVADA (`two_factor_modo = desactivado`):
     *      no se exige a nadie.
     *   2. Obligatoriedad global OBLIGATORIO (`two_factor_modo = obligatorio`):
     *      se exige a los usuarios con el control individual HABILITADO
     *      (`usuarios.two_factor_enabled = true`: pendiente de enrolle o
     *      confirmado). El control individual permite establecer excepciones
     *      autorizadas: un usuario con el flag en OFF no se exige, aunque el
     *      global sea OBLIGATORIO.
     *   3. Los demás modos (p. ej. OPCIONAL) no activan excepción global.
     *
     * El flag individual `two_factor_enabled` se mantiene en true desde la
     * activación (pendiente de enrolle hasta `two_factor_confirmed_at`) y en
     * false desde la desactivación (con secret y códigos invalidados). Por eso
     * un flag en true siempre implica "exige o debe completar el enrolle".
     */
    public function debeExigirTwoFactor(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        $modo = $this->modoGlobal();

        // Si está desactivado globalmente, NO se exige a nadie.
        if ($modo === self::MODO_DESACTIVADO) {
            return false;
        }

        // Si es obligatorio globalmente, se exige a los usuarios con el control
        // individual habilitado. La excepción individual (ADMIN/GERENTE
        // deshabilitó el 2FA de este usuario) se resuelve con el flag en OFF.
        if ($modo === self::MODO_OBLIGATORIO) {
            return (bool) ($user->two_factor_enabled ?? false);
        }

        return false;
    }

    /**
     * Verifica si el rol del usuario tiene 2FA obligatorio
     */
    protected function rolObligatorio(User $user): bool
    {
        if (! Schema::hasTable('configuraciones')) {
            return false;
        }

        try {
            $rol = $user->rol;
            if (! $rol) {
                return false;
            }
            $config = \DB::table('configuraciones')->where('clave', 'two_factor_obligatorio_roles')->first();
            if ($config && $config->valor) {
                $roles = json_decode($config->valor, true);
                if (is_array($roles) && in_array(strtoupper($rol->nombre), array_map('strtoupper', $roles), true)) {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            // Ignorar
        }

        return false;
    }

    /**
     * Verifica si el usuario tiene 2FA obligatorio
     */
    protected function usuarioObligatorio(User $user): bool
    {
        if (! Schema::hasTable('configuraciones')) {
            return false;
        }

        try {
            $config = \DB::table('configuraciones')->where('clave', 'two_factor_obligatorio_usuarios')->first();
            if ($config && $config->valor) {
                $usuarios = json_decode($config->valor, true);
                if (is_array($usuarios) && in_array($user->id, $usuarios, true)) {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            // Ignorar
        }

        return false;
    }

    /**
     * Obtiene métodos permitidos
     */
    public function metodosPermitidos(): array
    {
        if (Schema::hasTable('configuraciones')) {
            try {
                $config = \DB::table('configuraciones')->where('clave', 'two_factor_metodos_permitidos')->first();
                if ($config && $config->valor) {
                    $metodos = json_decode($config->valor, true);
                    if (is_array($metodos) && ! empty($metodos)) {
                        return $metodos;
                    }
                }
            } catch (\Throwable $e) {
                // Ignorar
            }
        }

        // Por defecto permitir ambos métodos si existen
        return ['codigo_administrado', 'totp', 'correo'];
    }

    /**
     * Verifica si un método está permitido
     */
    public function metodoPermitido(string $metodo): bool
    {
        return in_array($metodo, $this->metodosPermitidos(), true);
    }
}
