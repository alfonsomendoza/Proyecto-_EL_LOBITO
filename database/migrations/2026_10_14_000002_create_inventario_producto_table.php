<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Inventario (Etapa 1): existencia actual por producto controlado.
 *
 * Un registro por producto, con relación e integridad referencial hacia
 * "productos". El stock es informativo: NO bloquea ventas, por lo que se
 * admite stock negativo (columnas decimal sin signo y sin CHECK).
 *
 * Solo tiene filas para los productos con "controla_stock = 1"; el catálogo
 * se activa producto por producto, no de golpe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventario_producto', function (Blueprint $table) {
            $table->id();

            // Un solo registro de inventario por producto (por eso unique).
            // El tipo coincide con productos.id (int) para que la FK sea válida.
            $table->integer('producto_id');

            // Existencia actual. Puede ser negativa: el inventario es
            // informativo y nunca bloquea la venta.
            $table->decimal('stock_actual', 12, 3)->default(0);

            // Umbral de aviso (sin lógica todavía; alertas en una etapa posterior).
            $table->decimal('stock_minimo', 12, 3)->default(0);

            // Unidad de medida (unidad, kg, lt, gr...).
            $table->string('unidad', 20)->default('unidad');

            $table->timestamps();

            $table->unique('producto_id', 'inventario_producto_producto_id_unique');

            // Integridad referencial: el inventario no puede quedar huérfano.
            // El catálogo no se borra desde la aplicación (se desactiva con
            // "activo"), así que RESTRICT es seguro y evita pérdida silenciosa.
            $table->foreign('producto_id', 'inventario_producto_producto_id_foreign')
                ->references('id')
                ->on('productos')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventario_producto');
    }
};
