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
        // Eliminamos la columna si hacemos rollback.
        Schema::table('usuarios', function (Blueprint $table) {
            $table->dropColumn('activo');
        });
    }
};