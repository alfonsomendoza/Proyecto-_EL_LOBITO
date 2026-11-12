<?php

namespace Tests\Feature\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
                // Reversión lógica y vínculo con la factura
                // (migración 2026_11_11_000001). Se crean aquí para que los
                // esquemas manuales de pruebas reflejen el esquema real.
                $t->unsignedBigInteger('factura_id')->nullable();
                $t->string('estado', 20)->nullable();
                $t->timestamp('reversado_en')->nullable();
                $t->unsignedBigInteger('reversado_por')->nullable();
                $t->string('motivo_reversion', 255)->nullable();
                $t->unsignedBigInteger('anulacion_id')->nullable();
                $t->string('devolucion_estado', 20)->nullable();
                $t->timestamp('devolucion_en')->nullable();
                $t->unsignedBigInteger('devolucion_por')->nullable();
                $t->index('cuenta_id');
                $t->index('venta_id');
                $t->index('factura_id');
            });
        }

        // Esquemas antiguos que ya crearon `pagos_cuenta` sin estas columnas
        // (o despliegues parciales) se completan de forma aditiva.
        if (Schema::hasTable('pagos_cuenta')) {
            $columnasReversion = [
                'factura_id' => fn (Blueprint $t) => $t->unsignedBigInteger('factura_id')->nullable(),
                'estado' => fn (Blueprint $t) => $t->string('estado', 20)->nullable(),
                'reversado_en' => fn (Blueprint $t) => $t->timestamp('reversado_en')->nullable(),
                'reversado_por' => fn (Blueprint $t) => $t->unsignedBigInteger('reversado_por')->nullable(),
                'motivo_reversion' => fn (Blueprint $t) => $t->string('motivo_reversion', 255)->nullable(),
                'anulacion_id' => fn (Blueprint $t) => $t->unsignedBigInteger('anulacion_id')->nullable(),
                'devolucion_estado' => fn (Blueprint $t) => $t->string('devolucion_estado', 20)->nullable(),
                'devolucion_en' => fn (Blueprint $t) => $t->timestamp('devolucion_en')->nullable(),
                'devolucion_por' => fn (Blueprint $t) => $t->unsignedBigInteger('devolucion_por')->nullable(),
            ];

            foreach ($columnasReversion as $nombre => $definicion) {
                if (!Schema::hasColumn('pagos_cuenta', $nombre)) {
                    Schema::table('pagos_cuenta', function (Blueprint $t) use ($definicion) {
                        $definicion($t);
                    });
                }
            }
        }

        // ---------------------------------------------------------------
        // ---------------------------------------------------------------
        // 0. Cliente independiente por subcuenta (cuentas.cliente_id).
        // ---------------------------------------------------------------
        if (Schema::hasTable('cuentas') && !Schema::hasColumn('cuentas', 'cliente_id')) {
            Schema::table('cuentas', function (Blueprint $t) {
                $t->integer('cliente_id')->nullable();
            });
        }

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

        // -----------
        // Traslados + pagos/ajustes del cobro (replica migraciones 2026_10_18).
        // -----------
        // clientes minimalistas: tabla que los tests de cliente/subcuenta/factura/ traslado esperan.
        if (!Schema::hasTable('clientes')) {
            Schema::create('clientes', function (Blueprint $t) {
                $t->increments('id');
                $t->string('nombre', 150);
                $t->string('telefono', 30)->nullable();
                $t->string('identificacion', 50)->nullable();
                $t->text('direccion')->nullable();
                $t->boolean('activo')->default(true);
                $t->string('identificacion_normalizada', 50)->nullable();
                $t->string('telefono_normalizado', 30)->nullable();
                // La tabla real usa `creado_en`; no tiene created_at/updated_at.
                $t->timestamp('creado_en')->nullable();
            });
        }

        // Índice único de identificación normalizada: solo en MySQL real.
        if (Schema::hasTable('clientes') && Schema::getConnection()->getDriverName() === 'mysql') {
            Schema::table('clientes', function (Blueprint $t) {
                $t->unique('identificacion_normalizada');
            });
        }

        // Control de cuenta principal por mesa, tasas discount propina y resumen diario global (sin backfill).
        if (!Schema::hasTable('control_caja_mesas')) {
            Schema::create('control_caja_mesas', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->integer('mesa_id');
                $t->integer('usuario_id');
                $t->integer('apertura_id');
                $t->string('estado', 255)->default('ABIERTO');
                $t->timestamp('creado_en')->nullable();
            });
        }

        if (!Schema::hasTable('control_tasas_resumen')) {
            Schema::create('control_tasas_resumen', function (Blueprint $t) {
                $t->id();
                $t->string('modulo', 60)->nullable();
                $t->string('parametro', 60)->nullable();
                $t->text('lectura_json');
                $t->timestamp('creado_en');
            });
        }

        // snapshots de pymes en bloques dairy / snapshot_log: vacíos para tests.
        if (!Schema::hasTable('control_snapshots_pymes_dairy')) {
            Schema::create('control_snapshots_pymes_dairy', function (Blueprint $t) {
                $t->id();
                $t->string('parametro', 60)->nullable();
                $t->json('dato_json');
                $t->timestamp('creado_en');
            });
        }

        // Control de cliente general (ID 1) como cliente por defecto y su
        // snapshot de previsualización de factura liberado desde la cocina.
        if (Schema::hasTable('clientes') && !DB::table('clientes')->where('id', 1)->exists()) {
            // Cada prueba construye un esquema manual distinto (algunas usan
            // `creado_en` y otras timestamps de Eloquent). Se inserta solo en
            // las columnas que realmente existan para no romper su preparación.
            $datos = [
                'id' => 1,
                'nombre' => 'Cliente General',
                'activo' => true,
            ];

            foreach (['identificacion_normalizada', 'telefono_normalizado'] as $columna) {
                if (Schema::hasColumn('clientes', $columna)) {
                    $datos[$columna] = null;
                }
            }

            foreach (['creado_en', 'created_at', 'updated_at'] as $columna) {
                if (Schema::hasColumn('clientes', $columna)) {
                    $datos[$columna] = now();
                }
            }

            DB::table('clientes')->insert($datos);
        }
    }
}
