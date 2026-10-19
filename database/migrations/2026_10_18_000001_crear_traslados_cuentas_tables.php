<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ==========================================================================
 * TRASLADO INDIVIDUAL DE CUENTAS: TRAZABILIDAD
 * ==========================================================================
 *
 * Crea las tablas de auditoría del traslado de consumo entre mesas y la
 * columna que distingue en `venta_detalles` las líneas que LLEGARON por
 * traslado de las líneas añadidas directamente en el POS:
 *
 *  - `traslados_cuenta`: una fila por traslado ejecutado (quién, desde dónde,
 *    hacia dónde, cuántas líneas y qué monto se movió). `clave_operacion`
 *    es UNIQUE y sirve de idempotencia: reenviar la misma petición no
 *    duplica el movimiento.
 *  - `traslado_detalles`: una fila por línea movida, enlazando el
 *    `venta_detalles` de ORIGEN con el recién creado de DESTINO.
 *  - `venta_detalles.trasladado_desde_id`: en la línea de destino guarda el
 *    id de la línea de origen (NULL en líneas normales del POS). Permite
 *    pintar el aviso «Este pedido fue trasladado a otra mesa» y evitar que
 *    se reimprima a cocina por error.
 *
 * SIN CLAVES FORÁNEAS A PROPÓSITO: las columnas de referencia apuntan a
 * tablas históricas (ventas, venta_detalles, cuentas...). La integridad ya
 * la valida VentaController::trasladarConsumoCuenta dentro de su transacción
 * (locks + comprobaciones), y al no declarar FK esta migración es segura de
 * aplicar en producción aunque el tipo exacto de las PK difiera levemente
 * del esquema canónico. Es una decisión de despliegue, no un descuido.
 *
 * Todas las creaciones van protegidas por `Schema::hasTable`/`hasColumn`
 * para que la migración sea inocua si se reejecuta.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---------------------------------------------------------------
        // 1. Control de cada traslado ejecutado.
        // ---------------------------------------------------------------
        if (!Schema::hasTable('traslados_cuenta')) {
            Schema::create('traslados_cuenta', function (Blueprint $table) {
                $table->bigIncrements('id');

                // Clave única de idempotencia (la genera el navegador).
                $table->string('clave_operacion', 64)->nullable()->unique();

                $table->unsignedBigInteger('cuenta_origen_id');
                $table->unsignedBigInteger('cuenta_destino_id');
                $table->integer('venta_origen_id');
                $table->integer('venta_destino_id');
                $table->integer('mesa_origen_id')->nullable();
                $table->integer('mesa_destino_id');
                $table->integer('usuario_id')->nullable();

                $table->integer('cantidad_lineas')->default(0);
                $table->decimal('monto_total', 12, 2)->default(0);
                $table->timestamp('creado_en')->nullable();

                $table->index('cuenta_origen_id');
                $table->index('venta_origen_id');
            });
        }

        // ---------------------------------------------------------------
        // 2. Líneas concretas de cada traslado (origen ↔ destino).
        // ---------------------------------------------------------------
        if (!Schema::hasTable('traslado_detalles')) {
            Schema::create('traslado_detalles', function (Blueprint $table) {
                $table->bigIncrements('id');

                $table->unsignedBigInteger('traslado_id');
                $table->unsignedBigInteger('venta_detalle_origen_id');
                $table->unsignedBigInteger('venta_detalle_destino_id');
                $table->integer('producto_id');
                $table->integer('cantidad');
                $table->decimal('precio_unitario', 10, 2);
                $table->decimal('subtotal', 10, 2);
                $table->timestamp('creado_en')->nullable();

                $table->index('traslado_id');
                $table->index('venta_detalle_origen_id');
                $table->index('venta_detalle_destino_id');
            });
        }

        // ---------------------------------------------------------------
        // 3. Marcador en la línea de destino dentro de la venta.
        // ---------------------------------------------------------------
        if (Schema::hasTable('venta_detalles') && !Schema::hasColumn('venta_detalles', 'trasladado_desde_id')) {
            Schema::table('venta_detalles', function (Blueprint $table) {
                $table->unsignedBigInteger('trasladado_desde_id')->nullable();
            });
        }
    }

    /**
     * Deshacer SOLO lo creado por esta migración: la columna de marcado y
     * las dos tablas nuevas de trazabilidad. No toca datos de negocio.
     */
    public function down(): void
    {
        if (Schema::hasTable('venta_detalles') && Schema::hasColumn('venta_detalles', 'trasladado_desde_id')) {
            Schema::table('venta_detalles', function (Blueprint $table) {
                $table->dropColumn('trasladado_desde_id');
            });
        }

        Schema::dropIfExists('traslado_detalles');
        Schema::dropIfExists('traslados_cuenta');
    }
};
