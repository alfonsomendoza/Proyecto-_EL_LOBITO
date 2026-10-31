<?php

namespace Tests\Feature\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trazabilidad de traslados y ampliaciones del cobro para los esquemas
 * MANUALES de los tests (SQLite :memory: no ejecuta migraciones).
 *
 * Replica exactamente:
 *  - 2026_10_18_000001: `traslados_cuenta`, `traslado_detalles` y la columna
 *    `venta_detalles.trasladado_desde_id` (sin ellas VentaController::pos()
 *    falla con "no such table: traslado_detalles" y no funciona el traslado).
 *  - 2026_10_18_000002: `pagos_cuenta` y los ajustes de cobro en `cuentas`
 *    (descuento/propina), que escribe VentaController::pagarCuenta.
 *
 * USO: llamar a crearEsquemaTrasladosPagos() DESPUÉS de crear las tablas
 * ventas, venta_detalles y cuentas en el crearEsquema() de cada test.
 * Todas las creaciones van protegidas por hasTable/hasColumn.
 */
trait CreaEsquemaTrasladosPagos
{
    protected function crearEsquemaTrasladosPagos(): void
    {
        // ---------------------------------------------------------------
        // 1. Control de cada traslado ejecutado (idempotencia + auditoría).
        // ---------------------------------------------------------------
        if (!Schema::hasTable('traslados_cuenta')) {
            Schema::create('traslados_cuenta', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->string('clave_operacion', 64)->nullable()->unique();
                $t->unsignedBigInteger('cuenta_origen_id');
                $t->unsignedBigInteger('cuenta_destino_id');
                $t->integer('venta_origen_id');
                $t->integer('venta_destino_id');
                $t->integer('mesa_origen_id')->nullable();
                $t->integer('mesa_destino_id');
                $t->integer('usuario_id')->nullable();
                $t->integer('cantidad_lineas')->default(0);
                $t->decimal('monto_total', 12, 2)->default(0);
                $t->timestamp('creado_en')->nullable();
                $t->index('cuenta_origen_id');
                $t->index('venta_origen_id');
            });
        }

        // ---------------------------------------------------------------
        // 2. Líneas concretas de cada traslado (origen <-> destino).
        // ---------------------------------------------------------------
        if (!Schema::hasTable('traslado_detalles')) {
            Schema::create('traslado_detalles', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('traslado_id');
                $t->unsignedBigInteger('venta_detalle_origen_id');
                $t->unsignedBigInteger('venta_detalle_destino_id');
                $t->integer('producto_id');
                $t->integer('cantidad');
                $t->decimal('precio_unitario', 10, 2);
                $t->decimal('subtotal', 10, 2);
                $t->timestamp('creado_en')->nullable();
                $t->index('traslado_id');
                $t->index('venta_detalle_origen_id');
                $t->index('venta_detalle_destino_id');
            });
        }

        // ---------------------------------------------------------------
        // 3. Marcador en la línea de destino (aviso en el POS).
        // ---------------------------------------------------------------
        if (Schema::hasTable('venta_detalles') && !Schema::hasColumn('venta_detalles', 'trasladado_desde_id')) {
            Schema::table('venta_detalles', function (Blueprint $t) {
                $t->unsignedBigInteger('trasladado_desde_id')->nullable();
            });
        }

        // ---------------------------------------------------------------
        // 4. Líneas de pago de cada cobro (pagos mixtos).
        // ---------------------------------------------------------------
        if (!Schema::hasTable('pagos_cuenta')) {
            Schema::create('pagos_cuenta', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->string('clave_operacion', 64)->nullable()->unique();
                $t->unsignedBigInteger('cuenta_id');
                $t->integer('venta_id');
                $t->string('metodo_pago', 30);
                $t->string('banco', 30)->nullable();
                $t->string('moneda', 10)->default('NIO');
                $t->decimal('monto', 12, 2);
                $t->decimal('tasa_cambio', 10, 4)->nullable();
                $t->decimal('monto_cordobas', 12, 2);
                $t->integer('usuario_id')->nullable();
                $t->timestamp('creado_en')->nullable();
                // Número de transferencia (solo para líneas de transferencia
                // bancaria). Se añade con hasColumn para no romper esquemas
                // que aún no la tengan; la migración de producción la crea
                // formalmente y este guardia la ignora si ya existe.
                $t->string('numero_transferencia', 64)->nullable();
                $t->index('cuenta_id');
                $t->index('venta_id');
            });
        }

        // ---------------------------------------------------------------
        // 5. Ajustes opcionales de cobro en la cuenta.
        // ---------------------------------------------------------------
        if (Schema::hasTable('cuentas') && !Schema::hasColumn('cuentas', 'descuento')) {
            Schema::table('cuentas', function (Blueprint $t) {
                $t->decimal('descuento', 12, 2)->default(0);
                $t->string('descuento_motivo', 255)->nullable();
                $t->integer('descuento_usuario_id')->nullable();
                $t->decimal('propina', 12, 2)->default(0);
            });
        }
    }
}
