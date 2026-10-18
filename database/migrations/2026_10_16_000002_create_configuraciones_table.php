<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Configuración de módulos de El Lobito (solo ADMIN).
 *
 * Almacena preferencias globales clave/valor. En esta etapa se usan dos:
 *
 *  - cocina_digital_habilitada : '1'/'0'  (módulo nuevo -> por defecto '0')
 *  - inventario_habilitado     : '1'/'0'  (módulo preexistente -> '1')
 *
 * Decisiones:
 *  - Tabla mínima clave/valor con UNIQUE en "clave" para evitar duplicados.
 *  - El valor se guarda como texto ('1'/'0'); el servicio lo interpreta.
 *  - Se insertan valores iniciales EXPLÍCITOS (insertOrIgnore, idempotente).
 *    El inventario se deja habilitado porque ya funcionaba antes del módulo de
 *    configuración: desactivarlo por defecto sería una regresión operativa.
 *    La cocina digital se deja deshabilitada porque es una función nueva.
 *  - No se toca ninguna otra tabla.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('configuraciones')) {
            Schema::create('configuraciones', function (Blueprint $table) {
                $table->id();
                $table->string('clave', 100)->unique();
                $table->string('valor', 255)->nullable();
                $table->timestamps();
            });
        }

        // Valores iniciales explícitos. insertOrIgnore respeta una configuración
        // ya guardada por el usuario si la migración se reejecutara.
        DB::table('configuraciones')->insertOrIgnore([
            [
                'clave' => 'cocina_digital_habilitada',
                'valor' => '0',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'clave' => 'inventario_habilitado',
                'valor' => '1',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('configuraciones');
    }
};
