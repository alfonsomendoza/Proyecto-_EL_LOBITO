<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Inventario (Etapa 1): activar el control de inventario por producto.
 *
 * "controla_stock" es opcional y viene desactivado por defecto, de modo que
 * todos los productos actuales quedan SIN control de inventario hasta que un
 * administrador lo active individualmente. No se renombran ni alteran columnas
 * existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guarda de idempotencia: no falla si la columna ya existe.
        if (!Schema::hasColumn('productos', 'controla_stock')) {
            Schema::table('productos', function (Blueprint $table) {
                // 0 = sin control de inventario (comportamiento anterior).
                // 1 = controla stock (se le podrán registrar movimientos).
                $table->boolean('controla_stock')
                    ->default(false)
                    ->after('requiere_cocina');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('productos', 'controla_stock')) {
            Schema::table('productos', function (Blueprint $table) {
                $table->dropColumn('controla_stock');
            });
        }
    }
};
