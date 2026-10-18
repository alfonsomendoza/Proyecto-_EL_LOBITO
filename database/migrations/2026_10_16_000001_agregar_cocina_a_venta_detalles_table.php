<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Cocina digital (observaciones + estado de preparación) en venta_detalles.
 *
 * venta_detalles es la fuente de verdad de la cocina: tanto las impresiones
 * como el monitor leen de aquí (comanda_detalles es legado y solo se conserva).
 * Se agregan dos columnas ADITIVAS y opcionales:
 *
 *  - notas (TEXT NULL): observación de una línea concreta
 *    ("sin cebolla", "bien cocido", "para llevar"). Máximo 500 caracteres
 *    validado en el servidor; el texto no se usa como identidad del producto.
 *  - estado_preparacion (VARCHAR(20) NULL): PENDIENTE / EN_PREPARACION / LISTO.
 *
 * IMPORTANTE:
 *  - El valor por defecto es NULL (NO 'PENDIENTE'). Así los pedidos históricos
 *    no reaparecen como tareas nuevas por el mero hecho de instalar el módulo.
 *    El estado inicial 'PENDIENTE' lo escribe el POS al crear líneas nuevas de
 *    cocina.
 *  - Es aditiva y reversible. No modifica registros existentes.
 *  - Se añade un índice pensado para el panel de cocina.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guardas de idempotencia: no falla si la columna ya existe.
        if (! Schema::hasColumn('venta_detalles', 'notas')) {
            Schema::table('venta_detalles', function (Blueprint $table) {
                $table->text('notas')->nullable()->after('estado');
            });
        }

        if (! Schema::hasColumn('venta_detalles', 'estado_preparacion')) {
            Schema::table('venta_detalles', function (Blueprint $table) {
                // Se usa string (no enum) para ser compatible con MySQL y SQLite.
                $table->string('estado_preparacion', 20)->nullable()->after('notas');
            });
        }

        // Índice compuesto para el panel de cocina. Se comprueba por nombre para
        // no duplicarlo si la migración se reejecuta.
        if (! Schema::hasIndex('venta_detalles', 'venta_detalles_cocina_index')) {
            Schema::table('venta_detalles', function (Blueprint $table) {
                $table->index(
                    ['comanda_id', 'estado', 'estado_preparacion'],
                    'venta_detalles_cocina_index'
                );
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('venta_detalles', 'venta_detalles_cocina_index')) {
            Schema::table('venta_detalles', function (Blueprint $table) {
                $table->dropIndex('venta_detalles_cocina_index');
            });
        }

        if (Schema::hasColumn('venta_detalles', 'estado_preparacion')) {
            Schema::table('venta_detalles', function (Blueprint $table) {
                $table->dropColumn('estado_preparacion');
            });
        }

        if (Schema::hasColumn('venta_detalles', 'notas')) {
            Schema::table('venta_detalles', function (Blueprint $table) {
                $table->dropColumn('notas');
            });
        }
    }
};
