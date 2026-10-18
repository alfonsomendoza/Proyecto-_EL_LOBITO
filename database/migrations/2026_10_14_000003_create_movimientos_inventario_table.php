<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Inventario (Etapa 1): historial auditable de movimientos de stock.
 *
 * Registro inmutable (solo alta) que permite reconstruir el saldo de cada
 * producto. La idempotencia se apoya en UNIQUE(origen_tipo, origen_id):
 * - VENTA       -> origen_id = venta_detalles.id
 * - ANULACION   -> origen_id = anulaciones.id  (permite VARIAS anulaciones
 *                  parciales de la misma línea: cada una tiene su propio id)
 * - CANCELACION -> origen_id = venta_detalles.id (se distingue por origen_tipo)
 * - CARGA_INICIAL / AJUSTE_* -> origen_id = NULL (se permiten varios, porque
 *                  MySQL no aplica la UNIQUE cuando alguna columna es NULL)
 *
 * No se agregan claves foráneas al origen: es una referencia polimórfica
 * (venta_detalles o anulaciones según origen_tipo) y, además, esta tabla es
 * histórica y debe sobrevivir a cambios del origen (misma decisión que la
 * tabla "anulaciones").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movimientos_inventario', function (Blueprint $table) {
            $table->id();

            // Producto afectado. Sin FK a propósito: el historial de auditoría
            // debe conservarse aunque cambie el catálogo.
            $table->integer('producto_id');

            // VENTA, ANULACION, CANCELACION, CARGA_INICIAL, AJUSTE_ENTRADA, AJUSTE_SALIDA.
            $table->string('tipo', 30);

            // Cantidad del movimiento (signo según el tipo; ver nota arriba).
            $table->decimal('cantidad', 12, 3);

            // Tipo y referencia del evento que originó el movimiento.
            $table->string('origen_tipo', 30)->nullable();
            $table->unsignedBigInteger('origen_id')->nullable();

            // Usuario responsable, si aplica (los ajustes automáticos pueden no tenerlo).
            $table->integer('usuario_id')->nullable();

            // Observación libre (motivo del ajuste, nota, etc.).
            $table->string('observacion', 500)->nullable();

            // Fecha del movimiento. Historial inmutable: solo fecha de alta.
            $table->timestamp('creado_en')->useCurrent();

            // Impide duplicar el mismo evento; NO impide varias anulaciones
            // parciales, porque cada una usa su propio anulaciones.id.
            $table->unique(['origen_tipo', 'origen_id'], 'movimientos_inventario_origen_unique');

            $table->index('producto_id');
            $table->index('tipo');
            $table->index('origen_id');
            $table->index('creado_en');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movimientos_inventario');
    }
};
