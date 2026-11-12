<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    // Apuntamos a tu tabla real
    protected $table = 'usuarios';
    public $timestamps = false;

    // Los campos que se pueden llenar
    protected $fillable = [
        'nombre',
        'username',
        'password_hash',
        'rol_id',
        'activo',
        'session_id',
        'ultima_actividad',
        'dispositivo_id',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_confirmed_at',
        'two_factor_enabled',
    ];

    // Laravel busca la columna 'password' por defecto, le decimos que use la tuya
    public function getAuthPassword()
    {
        return $this->password_hash;
    }

    /**
     * Nombre real de la columna de contraseña.
     *
     * Laravel lo usa al re-hashear contraseñas (`rehashPasswordIfRequired`) y
     * AuthenticateSession para comparar el hash guardado en la sesión. Sin
     * este método, Laravel intentaría escribir en `password` (columna que no
     * existe en `usuarios`) y fallaría.
     */
    public function getAuthPasswordName()
    {
        return 'password_hash';
    }

    // Relación para obtener el nombre del ROL automáticamente
    public function rol()
    {
        return $this->belongsTo(Rol::class, 'rol_id');
    }

    public function dispositivo()
    {
        return $this->belongsTo(Dispositivo::class, 'dispositivo_id');
    }

    public function hasTwoFactorEnabled(): bool
    {
        return (bool) ($this->two_factor_enabled && $this->two_factor_confirmed_at);
    }

    /**
     * Devuelve los códigos de recuperación en claro.
     *
     * Se almacenan cifrados con la APP_KEY. El fallback mantiene
     * compatibilidad con valores antiguos en JSON plano (no hay ninguno en
     * producción, pero evita romper datos heredados).
     */
    public function getTwoFactorRecoveryCodes(): array
    {
        if (! $this->two_factor_recovery_codes) {
            return [];
        }

        try {
            $json = decrypt($this->two_factor_recovery_codes);
        } catch (\Throwable $e) {
            $json = $this->two_factor_recovery_codes;
        }

        $codes = json_decode((string) $json, true);

        return is_array($codes) ? array_values($codes) : [];
    }

    /**
     * Guarda los códigos cifrados. Una lista vacía se persiste como NULL.
     */
    public function setTwoFactorRecoveryCodes(array $codes): void
    {
        $codes = array_values($codes);

        $this->two_factor_recovery_codes = $codes === []
            ? null
            : encrypt(json_encode($codes));
    }
}