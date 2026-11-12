<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega `cuentas.cliente_id` nullable para permitir clientes independientes
 * por subcuenta. Compatible con MySQL 8.4.
 *
 * - No backfilla datos.
 * - Las cuentas existentes quedan con NULL y usan el fallback de la venta.
 * - Rollback seguro: elimina solo la columna y su FK.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('cuentas')) {
            return;
        }

        Schema::table('cuentas', function (Blueprint $table) {
            if (!Schema::hasColumn('cuentas', 'cliente_id')) {
                $table->integer('cliente_id')->nullable()->after('venta_id');
                $table->foreign('cliente_id')
                    ->references('id')
                    ->on('clientes')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('cuentas')) {
            return;
        }

        Schema::table('cuentas', function (Blueprint $table) {
            if (Schema::hasColumn('cuentas', 'cliente_id')) {
                $table->dropForeign(['cliente_id']);
                $table->dropColumn('cliente_id');
            }
        });
    }
};
