<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Jerarquía de cuentas: cuenta_origen_id
|--------------------------------------------------------------------------
|
| Añade de forma ADITIVA y NO destructiva la columna cuenta_origen_id a la
| tabla `cuentas`. Se usa para registrar de qué cuenta derivó una subcuenta
| cuando se divide una subcuenta (subdivisión). Es NULL para la cuenta
| principal y para las cuentas creadas por la división a nivel de mesa.
|
| No se agrega una clave foránea autorreferente para mantener la migración
| simple, segura y reversible en MySQL/SQLite; solo un índice.
|
*/

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('cuentas', 'cuenta_origen_id')) {
            return;
        }

        Schema::table('cuentas', function (Blueprint $table) {
            $table->unsignedBigInteger('cuenta_origen_id')
                ->nullable()
                ->after('venta_id');
            $table->index('cuenta_origen_id');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('cuentas', 'cuenta_origen_id')) {
            return;
        }

        Schema::table('cuentas', function (Blueprint $table) {
            $table->dropIndex(['cuenta_origen_id']);
            $table->dropColumn('cuenta_origen_id');
        });
    }
};
