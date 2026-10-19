<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ejecutar la migración.
     */
    public function up(): void
    {
        // Guardas de instalación limpia y de bases importadas desde respaldo:
        //
        //  - Si la tabla `usuarios` no existe, esta migración no puede (ni
        //    debe) añadir nada; en una instalación limpia la crea
        //    2026_09_18_000001 (que corre ANTES que esta).
        //  - Si la columna `activo` ya existe (p. ej. respaldo de producción
        //    importado sin registrar la migración), no se vuelve a añadir.
        //
        // Así `php artisan migrate` no falla ni duplica la columna.
        if (!Schema::hasTable('usuarios') || Schema::hasColumn('usuarios', 'activo')) {
            return;
        }

        // Agregamos la columna activo a la tabla usuarios.
        // 1 = usuario activo
        // 0 = usuario desactivado
        Schema::table('usuarios', function (Blueprint $table) {
            $table->boolean('activo')
                ->default(1)
                ->after('rol_id');
        });
    }

    /**
     * Revertir la migración.
     */
    public function down(): void
    {
        // Solo eliminamos la columna si existe (misma guarda que up()).
        if (Schema::hasTable('usuarios') && Schema::hasColumn('usuarios', 'activo')) {
            Schema::table('usuarios', function (Blueprint $table) {
                $table->dropColumn('activo');
            });
        }
    }
};