<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Borrado lógico de las asignaciones de una cuenta.
 *
 * cuenta_detalles es la fuente de verdad de las asignaciones entre cuentas
 * y detalles de venta. No tiene columna "estado", por eso agregamos un flag
 * "eliminado" para no borrar físicamente asignaciones al anular un producto.
 *
 * IMPORTANTE:
 * - No se agregan claves foráneas (coherente con la auditoría).
 * - eliminado_por se deja como entero simple que referencia usuarios.id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cuenta_detalles', function (Blueprint $table) {
            // Antes de recalcular totales se ignora todo lo marcado aquí.
            $table->boolean('eliminado')->default(false)->after('subtotal');

            // Quién y cuándo anuló la asignación.
            $table->integer('eliminado_por')->nullable()->after('eliminado');
            $table->timestamp('eliminado_en')->nullable()->after('eliminado_por');

            // Índice pensado para las consultas que suman solo lo no eliminado.
            $table->index(['cuenta_id', 'eliminado'], 'cuenta_detalles_cuenta_eliminado_index');
        });
    }

    public function down(): void
    {
        Schema::table('cuenta_detalles', function (Blueprint $table) {
            // MySQL exige quitar el índice antes de eliminar las columnas.
            $table->dropIndex('cuenta_detalles_cuenta_eliminado_index');
            $table->dropColumn(['eliminado', 'eliminado_por', 'eliminado_en']);
        });
    }
};
