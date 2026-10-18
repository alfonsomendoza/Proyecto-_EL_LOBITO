<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Preferencias de módulos de El Lobito (solo las cambia ADMIN).
 *
 * Persisten en la tabla `configuraciones` (clave/valor de texto '1'/'0').
 * No se cachean: cada lectura va a la base, para que un cambio hecho por
 * ADMIN se aplique de inmediato en las demás sesiones (requisito explícito).
 *
 * Valores iniciales seguros y EXPLÍCITOS cuando la preferencia aún no existe:
 *  - cocina digital  -> DESHABILITADA (es un módulo nuevo).
 *  - inventario      -> HABILITADO   (es un módulo preexistente y ya operativo;
 *                        apagarlo por defecto rompería la operación actual).
 *
 * Si la tabla todavía no existe (migración no aplicada), se devuelven esos
 * mismos valores por defecto en lugar de fallar.
 */
class ConfiguracionService
{
    public const CLAVE_COCINA_DIGITAL = 'cocina_digital_habilitada';
    public const CLAVE_INVENTARIO = 'inventario_habilitado';

    /** ¿La tabla de configuración ya existe? */
    public function tablaDisponible(): bool
    {
        return Schema::hasTable('configuraciones');
    }

    /** ¿La cocina digital está habilitada? */
    public function cocinaDigitalHabilitada(): bool
    {
        return $this->obtenerBooleano(self::CLAVE_COCINA_DIGITAL, false);
    }

    /** ¿El módulo de inventario está habilitado? */
    public function inventarioHabilitado(): bool
    {
        return $this->obtenerBooleano(self::CLAVE_INVENTARIO, true);
    }

    /** Habilita una preferencia concreta. Devuelve el nuevo estado. */
    public function habilitar(string $clave): bool
    {
        $this->guardar($clave, true);

        return true;
    }

    /** Deshabilita una preferencia concreta. Devuelve el nuevo estado. */
    public function deshabilitar(string $clave): bool
    {
        $this->guardar($clave, false);

        return false;
    }

    /**
     * Lee una preferencia booleana. Si la tabla o la fila no existen, devuelve
     * el valor por defecto indicado (sin lanzar excepción).
     */
    private function obtenerBooleano(string $clave, bool $porDefecto): bool
    {
        if (! $this->tablaDisponible()) {
            return $porDefecto;
        }

        $valor = DB::table('configuraciones')
            ->where('clave', $clave)
            ->value('valor');

        if ($valor === null) {
            return $porDefecto;
        }

        return (string) $valor === '1';
    }

    /**
     * Guarda una preferencia. Crea la fila si no existe (updateOrInsert) para
     * no depender de que la migración haya insertado el valor inicial.
     */
    private function guardar(string $clave, bool $valor): void
    {
        $texto = $valor ? '1' : '0';

        $existe = DB::table('configuraciones')->where('clave', $clave)->exists();

        if ($existe) {
            // Se conserva created_at original.
            DB::table('configuraciones')
                ->where('clave', $clave)
                ->update(['valor' => $texto, 'updated_at' => now()]);

            return;
        }

        DB::table('configuraciones')->insert([
            'clave' => $clave,
            'valor' => $texto,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
