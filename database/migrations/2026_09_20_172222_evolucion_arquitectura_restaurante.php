<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Ejecuta los cambios de arquitectura del restaurante.
     *
     * Esta migración:
     * - Agrega soporte de cocina a productos.
     * - Relaciona ventas con mesas y clientes.
     * - Relaciona comandas con ventas.
     * - Permite facturar cuentas individuales.
     * - Crea las cuentas de una venta.
     * - Crea los detalles de cada venta.
     */
    public function up(): void
    {
        /*
        |--------------------------------------------------------------------------
        | 1. PRODUCTOS
        |--------------------------------------------------------------------------
        |
        | Determina si un producto debe aparecer en una comanda de cocina.
        |
        | Ejemplo:
        | Hamburguesa -> requiere_cocina = 1
        | Gaseosa     -> requiere_cocina = 0
        |
        */

        if (!Schema::hasColumn('productos', 'requiere_cocina')) {
            Schema::table('productos', function (Blueprint $table) {
                $table
                    ->boolean('requiere_cocina')
                    ->default(true)
                    ->after('precio');
            });
        }

        /*
        |--------------------------------------------------------------------------
        | 2. VENTAS
        |--------------------------------------------------------------------------
        |
        | Una venta representa el consumo de una mesa.
        |
        | IMPORTANTE:
        | Los datos de pago siguen existiendo porque tu sistema actual
        | de caja los utiliza.
        |
        */

        if (!Schema::hasColumn('ventas', 'mesa_id')) {
            Schema::table('ventas', function (Blueprint $table) {
                $table->integer('mesa_id')->nullable()->after('id');
            });
        }

        if (!Schema::hasColumn('ventas', 'cliente_id')) {
            Schema::table('ventas', function (Blueprint $table) {
                $table->integer('cliente_id')->nullable()->after('mesa_id');
            });
        }

        /*
        |--------------------------------------------------------------------------
        | 3. COMANDAS
        |--------------------------------------------------------------------------
        |
        | Una comanda pertenece a una venta.
        |
        | Esto permite conservar el historial:
        |
        | Comanda 1 -> hamburguesas iniciales
        | Comanda 2 -> segunda orden
        | Comanda 3 -> otra orden
        |
        | Reimprimir una comanda no crea otra.
        |
        */

        if (!Schema::hasColumn('comandas', 'venta_id')) {
            Schema::table('comandas', function (Blueprint $table) {
                $table->integer('venta_id')->nullable()->after('mesa_id');
            });
        }

        if (!Schema::hasColumn('comandas', 'numero_ticket')) {
            Schema::table('comandas', function (Blueprint $table) {
                $table->integer('numero_ticket')->nullable()->after('venta_id');
            });
        }

        /*
        |--------------------------------------------------------------------------
        | 4. FACTURAS
        |--------------------------------------------------------------------------
        |
        | Una factura puede corresponder a una cuenta específica.
        |
        */

        if (!Schema::hasColumn('facturas', 'cuenta_id')) {
            Schema::table('facturas', function (Blueprint $table) {
                $table->integer('cuenta_id')->nullable()->after('venta_id');
            });
        }

        /*
        |--------------------------------------------------------------------------
        | 5. CUENTAS
        |--------------------------------------------------------------------------
        |
        | Una venta puede tener varias cuentas.
        |
        | Ejemplo:
        |
        | Venta 100
        | ├── Cuenta Juan
        | ├── Cuenta Pedro
        | └── Cuenta María
        |
        | Cada cuenta puede pagarse por separado.
        |
        */

        if (!Schema::hasTable('cuentas')) {
            Schema::create('cuentas', function (Blueprint $table) {
                $table->id();

                // Venta principal a la que pertenece la cuenta.
                $table->integer('venta_id');

                // Nombre identificativo de la cuenta.
                $table->string('nombre', 100);

                // Total calculado de la cuenta.
                $table->decimal('total', 10, 2)->default(0);

                // ABIERTA / PAGADA / CANCELADA.
                $table->string('estado', 20)->default('ABIERTA');

                /*
                |--------------------------------------------------------------------------
                | Datos del pago
                |--------------------------------------------------------------------------
                */

                $table->integer('apertura_id')->nullable();

                $table->string('metodo_pago', 30)->nullable();

                $table->string('banco', 30)->nullable();

                $table->decimal('monto_recibido', 10, 2)->nullable();

                $table->decimal('cambio', 10, 2)->nullable();

                // Usuario que realizó el cobro.
                $table->integer('usuario_cobro')->nullable();

                $table->timestamps();

                /*
                |--------------------------------------------------------------------------
                | Índices
                |--------------------------------------------------------------------------
                |
                | Mejoran las búsquedas de cuentas por venta.
                |
                */

                $table->index('venta_id');
                $table->index('estado');
            });
        }

        /*
        |--------------------------------------------------------------------------
        | 6. DETALLES DE VENTA
        |--------------------------------------------------------------------------
        |
        | Aquí vive realmente el consumo.
        |
        | IMPORTANTE:
        | No debemos depender de las comandas para calcular la factura.
        |
        */

        if (!Schema::hasTable('venta_detalles')) {
            Schema::create('venta_detalles', function (Blueprint $table) {
                $table->id();

                // Venta principal.
                $table->integer('venta_id');

                // Producto vendido.
                $table->integer('producto_id');

                // Cantidad original agregada a la venta.
                $table->integer('cantidad');

                // Precio congelado al momento de vender.
                $table->decimal('precio_unitario', 10, 2);

                // cantidad × precio_unitario.
                $table->decimal('subtotal', 10, 2);

                /*
                |--------------------------------------------------------------------------
                | Comanda
                |--------------------------------------------------------------------------
                |
                | Indica a qué comanda pertenece el producto.
                |
                */

                $table->integer('comanda_id')->nullable();

                /*
                |--------------------------------------------------------------------------
                | Cuenta
                |--------------------------------------------------------------------------
                |
                | Para una primera asignación puede indicar la cuenta.
                |
                | La lógica posterior de división debe controlar cantidades
                | para permitir:
                |
                | 5 hamburguesas
                | ├── Cuenta A -> 2
                | └── Cuenta B -> 3
                |
                */

                $table->integer('cuenta_id')->nullable();

                // ACTIVO / ELIMINADO.
                $table->string('estado', 20)->default('ACTIVO');

                $table->timestamps();

                $table->index('venta_id');
                $table->index('producto_id');
                $table->index('cuenta_id');
                $table->index('comanda_id');
            });
        }
    }

    /**
     * Revierte los cambios realizados por esta migración.
     */
    public function down(): void
    {
        /*
        |--------------------------------------------------------------------------
        | El orden es importante:
        | primero eliminamos las tablas nuevas.
        |--------------------------------------------------------------------------
        */

        Schema::dropIfExists('venta_detalles');

        Schema::dropIfExists('cuentas');

        /*
        |--------------------------------------------------------------------------
        | FACTURAS
        |--------------------------------------------------------------------------
        */

        if (Schema::hasColumn('facturas', 'cuenta_id')) {
            Schema::table('facturas', function (Blueprint $table) {
                $table->dropColumn('cuenta_id');
            });
        }

        /*
        |--------------------------------------------------------------------------
        | COMANDAS
        |--------------------------------------------------------------------------
        */

        $columnasComandas = [];

        if (Schema::hasColumn('comandas', 'numero_ticket')) {
            $columnasComandas[] = 'numero_ticket';
        }

        if (Schema::hasColumn('comandas', 'venta_id')) {
            $columnasComandas[] = 'venta_id';
        }

        if (!empty($columnasComandas)) {
            Schema::table('comandas', function (Blueprint $table) use ($columnasComandas) {
                $table->dropColumn($columnasComandas);
            });
        }

        /*
        |--------------------------------------------------------------------------
        | VENTAS
        |--------------------------------------------------------------------------
        */

        $columnasVentas = [];

        if (Schema::hasColumn('ventas', 'cliente_id')) {
            $columnasVentas[] = 'cliente_id';
        }

        if (Schema::hasColumn('ventas', 'mesa_id')) {
            $columnasVentas[] = 'mesa_id';
        }

        if (!empty($columnasVentas)) {
            Schema::table('ventas', function (Blueprint $table) use ($columnasVentas) {
                $table->dropColumn($columnasVentas);
            });
        }

        /*
        |--------------------------------------------------------------------------
        | PRODUCTOS
        |--------------------------------------------------------------------------
        */

        if (Schema::hasColumn('productos', 'requiere_cocina')) {
            Schema::table('productos', function (Blueprint $table) {
                $table->dropColumn('requiere_cocina');
            });
        }
    }
};