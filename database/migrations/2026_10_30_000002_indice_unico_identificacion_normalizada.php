<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crea el índice UNIQUE sobre `clientes.identificacion_normalizada`.
 *
 * Requiere que la migración anterior (normalización + detección de duplicados)
 * se haya ejecutado con éxito; si quedan duplicados, el índice fallará en la
 * base real y este paso tendría que detenerse antes.
 *
 * Cliente General (ID 1) y clientes sin identificación (NULL) NO se afectan,
 * porque el índice solo cubre valores presentes y NULL se considera distinto
 * (ignorado por UNIQUE en MySQL).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('clientes')) {
            return;
        }

        if (!Schema::hasColumn('clientes', 'identificacion_normalizada')) {
            return;
        }

        // El índice no obliga a que todos los valores estén presentes; NULL
        // sigue siendo permitido.
        Schema::table('clientes', function (Blueprint $table) {
            $table->unique('identificacion_normalizada', 'clientes_identificacion_normalizada_unique');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('clientes')) {
            return;
        }

        Schema::table('clientes', function (Blueprint $table) {
            $table->dropUnique('clientes_identificacion_normalizada_unique');
        });
    }
};
