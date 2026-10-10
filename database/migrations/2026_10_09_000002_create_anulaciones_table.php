<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Auditoría de anulaciones lógicas de ítems.
 *
 * Registra quién anuló, qué producto, de qué venta y por qué motivo.
 * No se agregan claves foráneas: es una tabla histórica que debe
 * conservarse aunque luego cambie la venta, la cuenta o el detalle.
 *
 * Los tipos se alinean con el esquema real:
 * - ventas.id               INT
 * - venta_detalles.id       BIGINT UNSIGNED
 * - comandas.id             INT
 * - usuarios.id             INT
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('anulaciones', function (Blueprint $table) {
            $table->id();

            // Tipo de evento lógico, por ahora solo "ITEM_ANULADO".
            $table->string('tipo', 30);

            // Referencias sin clave foránea (auditoría histórica).
            $table->integer('venta_id')->nullable();
            $table->unsignedBigInteger('venta_detalle_id')->nullable();
            $table->integer('comanda_id')->nullable();
            $table->integer('producto_id')->nullable();

            // Copia del ítem anulado.
            $table->integer('cantidad')->nullable();
            $table->decimal('monto', 10, 2)->nullable();

            // Obligatorio: motivo indicado por el usuario.
            // text admite hasta 500 caracteres de validación.
            $table->text('motivo');

            // Usuario que anuló (usuarios.id) y estado previo del detalle.
            $table->integer('usuario_id')->nullable();
            $table->string('estado_previo', 255)->nullable();

            // Fecha de creación del registro de auditoría.
            $table->timestamp('creado_en')->useCurrent();

            $table->index('venta_id', 'anulaciones_venta_id_index');
            $table->index('venta_detalle_id', 'anulaciones_venta_detalle_id_index');
            $table->index('comanda_id', 'anulaciones_comanda_id_index');
            $table->index('usuario_id', 'anulaciones_usuario_id_index');
            $table->index(['venta_id', 'creado_en'], 'anulaciones_venta_creado_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('anulaciones');
    }
};
