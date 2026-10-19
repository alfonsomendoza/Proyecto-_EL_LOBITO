<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * ==========================================================================
 * ESQUEMA BASE LEGACY DEL RESTAURANTE (INSTALACIÓN LIMPIA)
 * ==========================================================================
 *
 * PROBLEMA QUE RESUELVE:
 *
 * Cinco tablas históricas (usuarios, productos, ventas, comandas y facturas)
 * eran ALTERADAS por migraciones posteriores sin que ninguna migración las
 * CREARA. En una base de datos recién instalada, `php artisan migrate`
 * fallaba con "Table 'sistemadecaja.usuarios' doesn't exist" porque:
 *
 *  - 2026_09_19_221340 ejecuta Schema::table('usuarios', ...) sin más.
 *  - 2026_09_20_172222 usa Schema::hasColumn(...) y luego Schema::table(...):
 *    si la tabla no existe, hasColumn devuelve false y Schema::table revienta.
 *
 * En producción la base se creó importando un respaldo, por eso NUNCA se
 * detectó el fallo hasta intentar una instalación limpia.
 *
 * SOLUCIÓN:
 *
 * Esta migración recrea con el Schema builder (portable entre MySQL y
 * PostgreSQL) el esquema base del restaurante ANTES de que las migraciones
 * posteriores lo alteren, de modo que una instalación limpia termina con el
 * MISMO esquema real que usa el negocio:
 *
 *  - `usuarios` nace SIN la columna `activo`: la añade 2026_09_19_221340.
 *  - `productos` nace SIN `requiere_cocina` ni `controla_stock`: las añaden
 *    2026_09_20_172222 y 2026_10_14_000001 (ambas con guarda hasColumn).
 *  - `ventas`, `comandas` y `facturas` nacen sin las columnas que añade
 *    2026_09_20_172222 (mesa_id/cliente_id, venta_id/numero_ticket y
 *    cuenta_id respectivamente), todas con guarda hasColumn.
 *
 * COMPATIBILIDAD CON INSTALACIONES EXISTENTES (PRODUCCIÓN):
 *
 * Cada creación va protegida por Schema::hasTable(). Si las tablas ya
 * existen (producción, o un respaldo importado), la migración NO hace nada
 * y solo se registra como ejecutada. Nunca toca datos existentes.
 *
 * TIPOS:
 *
 * Las PK son integer()->autoIncrement() (ENTEROS FIRMADOS), igual que el
 * esquema real de MySQL y igual que el patrón ya usado en
 * 2026_10_17_000001/2 (caja_cierre y caja_gastos), que declaran claves
 * foráneas firmadas hacia `caja_apertura.id` y `usuarios.id`. Los `enum` de
 * MySQL se recrean como string con la misma longitud: los valores los
 * valida el código de la aplicación y así el esquema es portable.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---------------------------------------------------------------
        // 1. roles: catálogo mínimo (ADMIN, GERENTE, CAJERO, MESERO,
        //    COCINERO). Los permisos se validan en cada controlador.
        // ---------------------------------------------------------------
        if (!Schema::hasTable('roles')) {
            Schema::create('roles', function (Blueprint $table) {
                $table->integer('id')->autoIncrement();
                $table->string('nombre', 20)->unique();
            });
        }

        // ---------------------------------------------------------------
        // 2. usuarios: tabla real de autenticación (App\Models\User).
        //    SIN `activo` todavía: lo añade 2026_09_19_221340.
        // ---------------------------------------------------------------
        if (!Schema::hasTable('usuarios')) {
            Schema::create('usuarios', function (Blueprint $table) {
                $table->integer('id')->autoIncrement();
                $table->string('nombre', 100)->nullable();
                $table->string('username', 50)->nullable()->unique();
                $table->string('password_hash', 255)->nullable();
                $table->integer('rol_id')->nullable();
                $table->foreign('rol_id')->references('id')->on('roles');
            });
        }

        // ---------------------------------------------------------------
        // 3. categorias y productos (base). `activo` es de la tabla real;
        //    `requiere_cocina` y `controla_stock` las añaden sus migraciones.
        // ---------------------------------------------------------------
        if (!Schema::hasTable('categorias')) {
            Schema::create('categorias', function (Blueprint $table) {
                $table->integer('id')->autoIncrement();
                $table->string('nombre', 100);
                $table->boolean('activa')->default(true);
            });
        }

        if (!Schema::hasTable('productos')) {
            Schema::create('productos', function (Blueprint $table) {
                $table->integer('id')->autoIncrement();
                $table->integer('categoria_id')->nullable();
                $table->string('nombre', 100);
                $table->decimal('precio', 12, 2);
                $table->boolean('activo')->default(true);
                $table->foreign('categoria_id')->references('id')->on('categorias');
            });
        }

        // ---------------------------------------------------------------
        // 4. mesas y clientes. `mesas.estado` guarda LIBRE/OCUPADA/COBRANDO.
        // ---------------------------------------------------------------
        if (!Schema::hasTable('mesas')) {
            Schema::create('mesas', function (Blueprint $table) {
                $table->integer('id')->autoIncrement();
                $table->string('numero', 20)->unique();
                $table->integer('capacidad')->default(2);
                $table->string('estado', 20)->default('LIBRE');
            });
        }

        if (!Schema::hasTable('clientes')) {
            Schema::create('clientes', function (Blueprint $table) {
                $table->integer('id')->autoIncrement();
                $table->string('nombre', 150);
                $table->string('telefono', 20)->nullable();
                $table->string('identificacion', 50)->nullable();
                $table->text('direccion')->nullable();
                $table->boolean('activo')->default(true);
                $table->timestamp('creado_en')->nullable();
            });
        }

        // ---------------------------------------------------------------
        // 5. caja_apertura: apertura de caja REAL del negocio. Es la tabla
        //    que usa App\Models\CajaApertura (no "caja_aperturas", que es
        //    un tablón vacío creado por 2026_09_17_233014). ventas,
        //    caja_cierre y caja_gastos apuntan aquí por clave foránea.
        //    `conteo_json` es texto: la aplicación guarda ahí un JSON.
        // ---------------------------------------------------------------
        if (!Schema::hasTable('caja_apertura')) {
            Schema::create('caja_apertura', function (Blueprint $table) {
                $table->integer('id')->autoIncrement();
                $table->dateTime('fecha')->nullable();
                $table->string('cajero', 100)->nullable();
                $table->string('turno', 50)->nullable();
                $table->integer('caja_id')->nullable();
                $table->decimal('monto_inicial', 12, 2)->default(0);
                $table->text('conteo_json')->nullable();
                $table->string('estado', 20)->default('ABIERTA');
                $table->timestamp('creado_en')->nullable();
                $table->integer('usuario_id')->nullable();
                $table->foreign('usuario_id')->references('id')->on('usuarios');
            });
        }

        // ---------------------------------------------------------------
        // 6. ventas (base). `mesa_id` y `cliente_id` las añade
        //    2026_09_20_172222. El importe vive en `monto` (NO `total`) y
        //    la tabla no tiene created_at/updated_at, igual que el negocio.
        // ---------------------------------------------------------------
        if (!Schema::hasTable('ventas')) {
            Schema::create('ventas', function (Blueprint $table) {
                $table->integer('id')->autoIncrement();
                $table->integer('apertura_id')->nullable();
                $table->string('descripcion')->nullable();
                $table->decimal('monto', 12, 2)->default(0);
                $table->string('metodo_pago', 255)->nullable();
                $table->string('banco', 30)->nullable();
                $table->decimal('monto_recibido', 10, 2)->nullable();
                $table->decimal('tasa_cambio', 10, 4)->nullable();
                $table->decimal('cambio', 10, 2)->nullable();
                $table->dateTime('fecha')->nullable();
                $table->string('estado', 20)->default('ACTIVA');
                $table->integer('usuario_id')->nullable();
                $table->decimal('descuento_porcentaje', 5, 2)->default(0);
                $table->foreign('apertura_id')->references('id')->on('caja_apertura');
                $table->foreign('usuario_id')->references('id')->on('usuarios');
            });
        }

        // ---------------------------------------------------------------
        // 7. comandas (base). `venta_id` y `numero_ticket` las añade
        //    2026_09_20_172222. `mesa_id` y `usuario_id` quedan nullable
        //    para no rechazar inserciones históricas sin esos datos.
        // ---------------------------------------------------------------
        if (!Schema::hasTable('comandas')) {
            Schema::create('comandas', function (Blueprint $table) {
                $table->integer('id')->autoIncrement();
                $table->integer('mesa_id')->nullable();
                $table->integer('usuario_id')->nullable();
                $table->integer('cliente_id')->nullable();
                $table->text('descripcion')->nullable();
                $table->string('estado', 30)->default('ABIERTA');
                $table->decimal('total', 10, 2)->nullable();
                $table->timestamp('creado_en')->nullable();
                $table->integer('conteo_impresiones')->default(0);
                $table->foreign('mesa_id')->references('id')->on('mesas');
                $table->foreign('usuario_id')->references('id')->on('usuarios');
                $table->foreign('cliente_id')->references('id')->on('clientes')->nullOnDelete();
            });
        }

        // ---------------------------------------------------------------
        // 8. facturas (base). `cuenta_id` la añade 2026_09_20_172222.
        //    `detalle_consumo` guarda el desglose en JSON como texto.
        // ---------------------------------------------------------------
        if (!Schema::hasTable('facturas')) {
            Schema::create('facturas', function (Blueprint $table) {
                $table->integer('id')->autoIncrement();
                $table->string('numero_factura', 20)->nullable()->unique();
                $table->integer('venta_id');
                $table->string('mesa', 50)->nullable();
                $table->text('detalle_consumo');
                $table->decimal('total', 10, 2)->default(0);
                $table->dateTime('fecha_emision')->nullable();
                $table->integer('usuario_id');
                $table->string('estado', 20)->default('EMITIDA');
                $table->foreign('venta_id')->references('id')->on('ventas');
                $table->foreign('usuario_id')->references('id')->on('usuarios');
            });
        }

        // ---------------------------------------------------------------
        // 9. comanda_detalles: espejo de cocina de cada comanda. Lo escribe
        //    VentaController::enviarPedidoDesdePos junto con venta_detalles.
        // ---------------------------------------------------------------
        if (!Schema::hasTable('comanda_detalles')) {
            Schema::create('comanda_detalles', function (Blueprint $table) {
                $table->integer('id')->autoIncrement();
                $table->integer('comanda_id');
                $table->integer('producto_id');
                $table->integer('cantidad');
                $table->decimal('precio_unitario', 12, 2);
                $table->decimal('subtotal', 12, 2);
                $table->string('estado', 20)->default('ACTIVO');
                $table->integer('eliminado_por')->nullable();
                $table->timestamp('eliminado_en')->nullable();
                $table->string('notas', 255)->nullable();
                $table->boolean('impreso_cocina')->default(false);
                $table->string('estado_pago', 20)->default('PENDIENTE');
                $table->foreign('comanda_id')->references('id')->on('comandas');
                $table->foreign('eliminado_por')->references('id')->on('usuarios');
            });
        }
    }

    /**
     * SIN BAJA APLICABLE.
     *
     * Un rollback de esta migración borraría tablas con datos reales del
     * negocio (usuarios, ventas, comandas, facturas...). Se deja vacía a
     * propósito: para deshacer una instalación limpia existe el respaldo.
     */
    public function down(): void
    {
        //
    }
};
