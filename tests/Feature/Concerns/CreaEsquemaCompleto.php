<?php

namespace Tests\Feature\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

trait CreaEsquemaCompleto
{
    use CreaEsquemaTrasladosPagos;

    protected function crearEsquemaCompleto(): void
    {
        foreach ([
            'pagos_cuenta',
            'traslado_detalles',
            'traslados_cuenta',
            'movimientos_inventario',
            'inventario_producto',
            'comanda_detalles',
            'facturas',
            'anulaciones',
            'cuenta_detalles',
            'cuentas',
            'venta_detalles',
            'comandas',
            'ventas',
            'productos',
            'categorias',
            'mesas',
            'clientes',
            'usuarios',
            'roles',
            'caja_apertura',
            'configuraciones',
        ] as $tabla) {
            Schema::dropIfExists($tabla);
        }

        Schema::create('roles', function (Blueprint $t) {
            $t->increments('id');
            $t->string('nombre', 30);
        });

        Schema::create('clientes', function (Blueprint $t) {
            $t->increments('id');
            $t->string('nombre', 150);
            $t->string('telefono', 20)->nullable();
            $t->string('identificacion', 50)->nullable();
            $t->text('direccion')->nullable();
            $t->boolean('activo')->nullable()->default(true);
            $t->timestamp('creado_en')->nullable();
        });

        Schema::create('usuarios', function (Blueprint $t) {
            $t->increments('id');
            $t->string('nombre', 100)->nullable();
            $t->string('username', 50)->nullable();
            $t->string('password_hash', 255)->nullable();
            $t->integer('rol_id')->nullable();
        });

        Schema::create('mesas', function (Blueprint $t) {
            $t->increments('id');
            $t->string('numero', 20)->nullable();
            $t->integer('capacidad')->nullable();
            $t->string('estado', 20)->default('LIBRE');
        });

        Schema::create('categorias', function (Blueprint $t) {
            $t->increments('id');
            $t->string('nombre', 100);
        });

        Schema::create('productos', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('categoria_id')->nullable();
            $t->string('nombre', 150);
            $t->decimal('precio', 10, 2)->default(0);
            $t->boolean('requiere_cocina')->default(false);
            $t->boolean('controla_stock')->default(false);
            $t->boolean('activo')->default(true);
        });

        Schema::create('ventas', function (Blueprint $t) {
            $t->increments('id');
            $t->unsignedBigInteger('mesa_id')->nullable();
            $t->unsignedBigInteger('cliente_id')->nullable();
            $t->integer('apertura_id')->nullable();
            $t->string('descripcion')->nullable();
            $t->decimal('monto', 12, 2)->default(0);
            $t->string('metodo_pago', 30)->nullable();
            $t->string('banco', 30)->nullable();
            $t->decimal('monto_recibido', 12, 2)->nullable();
            $t->decimal('tasa_cambio', 12, 4)->nullable();
            $t->decimal('cambio', 12, 2)->nullable();
            $t->timestamp('fecha')->nullable();
            $t->string('estado', 20)->default('ACTIVA');
            $t->integer('usuario_id')->nullable();
            $t->decimal('descuento_porcentaje', 5, 2)->default(0);
        });

        Schema::create('venta_detalles', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->integer('venta_id');
            $t->integer('producto_id');
            $t->integer('cantidad');
            $t->decimal('precio_unitario', 10, 2);
            $t->decimal('subtotal', 10, 2);
            $t->integer('comanda_id')->nullable();
            $t->integer('cuenta_id')->nullable();
            $t->string('estado', 255)->default('ACTIVO');
            $t->text('notas')->nullable();
            $t->string('estado_preparacion', 20)->nullable();
            $t->timestamps();
        });

        Schema::create('comandas', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->integer('venta_id')->nullable();
            $t->unsignedBigInteger('mesa_id')->nullable();
            $t->integer('numero_ticket')->nullable();
            $t->unsignedBigInteger('usuario_id')->nullable();
            $t->unsignedBigInteger('cliente_id')->nullable();
            $t->string('descripcion')->nullable();
            $t->string('estado', 30)->default('ABIERTA');
            $t->decimal('total', 10, 2)->default(0);
            $t->integer('conteo_impresiones')->default(0);
            $t->timestamp('creado_en')->nullable();
        });

        // Detalle de comanda: la escribe VentaController::enviarPedidoDesdePos
        // para los productos con requiere_cocina = 1. Sin esta tabla el envío de
        // un pedido de cocina falla con "no such table" (SQLSTATE HY000).
        // Columnas alineadas con el DDL real de MySQL:
        //   id, comanda_id, producto_id, cantidad, precio_unitario, subtotal,
        //   estado(ACTIVO/ELIMINADO), eliminado_por, eliminado_en, notas,
        //   impreso_cocina, estado_pago(PENDIENTE/PAGADO)
        Schema::create('comanda_detalles', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->integer('comanda_id');
            $t->integer('producto_id');
            $t->integer('cantidad')->default(0);
            $t->decimal('precio_unitario', 12, 2)->default(0);
            $t->decimal('subtotal', 12, 2)->default(0);
            $t->string('estado', 20)->default('ACTIVO');
            $t->unsignedBigInteger('eliminado_por')->nullable();
            $t->timestamp('eliminado_en')->nullable();
            $t->string('notas', 255)->nullable();
            $t->tinyInteger('impreso_cocina')->default(0);
            $t->string('estado_pago', 20)->default('PENDIENTE');
        });

        Schema::create('cuentas', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->integer('venta_id');
            $t->string('nombre', 255);
            $t->decimal('total', 10, 2)->default(0);
            $t->string('estado', 255)->default('ABIERTA');
            $t->integer('apertura_id')->nullable();
            $t->string('metodo_pago', 30)->nullable();
            $t->string('banco', 30)->nullable();
            $t->decimal('monto_recibido', 12, 2)->nullable();
            $t->decimal('cambio', 12, 2)->nullable();
            $t->unsignedBigInteger('usuario_cobro')->nullable();
            $t->timestamps();
        });

        Schema::create('cuenta_detalles', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('cuenta_id');
            $t->integer('venta_id');
            $t->unsignedBigInteger('venta_detalle_id');
            $t->unsignedInteger('cantidad');
            $t->decimal('subtotal', 10, 2);
            $t->boolean('eliminado')->default(false);
            $t->integer('eliminado_por')->nullable();
            $t->timestamp('eliminado_en')->nullable();
            $t->timestamps();
        });

        Schema::create('anulaciones', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('tipo', 30);
            $t->integer('venta_id')->nullable();
            $t->unsignedBigInteger('venta_detalle_id')->nullable();
            $t->integer('comanda_id')->nullable();
            $t->integer('producto_id')->nullable();
            $t->integer('cantidad')->nullable();
            $t->decimal('monto', 10, 2)->nullable();
            $t->text('motivo');
            $t->integer('usuario_id')->nullable();
            $t->string('estado_previo', 255)->nullable();
            $t->timestamp('creado_en')->nullable();
        });

        Schema::create('facturas', function (Blueprint $t) {
            $t->increments('id');
            $t->string('numero_factura', 20)->nullable();
            $t->integer('venta_id');
            $t->integer('cuenta_id')->nullable();
            $t->string('mesa', 50)->nullable();
            $t->text('detalle_consumo')->nullable();
            $t->decimal('total', 10, 2)->default(0);
            $t->dateTime('fecha_emision')->nullable();
            $t->integer('usuario_id')->nullable();
            $t->string('estado', 20)->default('EMITIDA');
        });

        Schema::create('caja_apertura', function (Blueprint $t) {
            $t->increments('id');
            $t->dateTime('fecha')->nullable();
            $t->string('cajero', 100)->nullable();
            $t->string('turno', 50)->nullable();
            $t->integer('caja_id')->nullable();
            $t->decimal('monto_inicial', 12, 2)->default(0);
            $t->text('conteo_json')->nullable();
            $t->string('estado', 20)->default('ABIERTA');
            $t->integer('usuario_id')->nullable();
        });

        Schema::create('configuraciones', function (Blueprint $t) {
            $t->id();
            $t->string('clave', 100)->unique();
            $t->string('valor', 255)->nullable();
            $t->timestamps();
        });

        Schema::create('inventario_producto', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('producto_id')->unique();
            $t->decimal('stock_actual', 12, 2)->default(0);
            $t->decimal('stock_minimo', 12, 2)->default(0);
            $t->string('unidad', 20)->default('unidad');
            $t->timestamps();
        });

        Schema::create('movimientos_inventario', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('producto_id');
            $t->string('tipo', 30);
            $t->decimal('cantidad', 12, 2);
            $t->decimal('stock_previo', 12, 2)->nullable();
            $t->decimal('stock_final', 12, 2)->nullable();
            $t->integer('venta_detalle_id')->nullable();
            $t->integer('anulacion_id')->nullable();
            $t->integer('usuario_id')->nullable();
            $t->text('observacion')->nullable();
            $t->timestamp('creado_en')->nullable();
        });

        // Traslados (pos() consulta traslado_detalles), pagos mixtos y
        // ajustes de cobro: replica 2026_10_18_000001 y 2026_10_18_000002.
        $this->crearEsquemaTrasladosPagos();
    }
}
