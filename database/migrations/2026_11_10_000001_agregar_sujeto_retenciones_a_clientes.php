<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('clientes')) {
            return;
        }

        Schema::table('clientes', function (Blueprint $table) {
            if (!Schema::hasColumn('clientes', 'sujeto_retenciones')) {
                $table->boolean('sujeto_retenciones')->default(false)->after('activo');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('clientes')) {
            return;
        }

        Schema::table('clientes', function (Blueprint $table) {
            if (Schema::hasColumn('clientes', 'sujeto_retenciones')) {
                $table->dropColumn('sujeto_retenciones');
            }
        });
    }
};
