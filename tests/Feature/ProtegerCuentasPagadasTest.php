<?php

namespace Tests\Feature;

use App\Http\Controllers\VentaController;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/*
 * Pruebas aisladas de la protección de cuentas pagadas y de las
 * transiciones de estado de venta (cancelar()).
 *
 * IMPORTANTE:
 * - Corren en SQLite :memory: (configurado en phpunit.xml).
 * - El esquema se construye a mano para no tocar "sistemadecaja".
 * - setUp() falla a propósito si la conexión no es SQLite en memoria.
 * - Como los métodos eliminarCuenta() y cancelar() todavía no tienen ruta
 *   HTTP propia, se invocan directamente sobre el controlador autenticado.
 */
class ProtegerCuentasPagadasTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Candado de seguridad: nunca apuntar a la base MySQL real.
        $this->assertSame(
            'sqlite',
            DB::connection()->getDriverName(),
            'Las pruebas deben ejecutarse en SQLite.'
        );
        $this->assertSame(
            ':memory:',
            (string) config('database.connections.sqlite.database'),
            'La base de pruebas debe ser SQLite en memoria.'
        );

        $this->crearEsquema();
    }

    private function crearEsquema(): void
    {
        foreach ([
            'movimientos_inventario',
            'cuenta_detalles',
            'cuentas',
            'venta_detalles',
            'ventas',
            'mesas',
            'usuarios',
            'roles',
        ] as $tabla) {
            Schema::dropIfExists($tabla);
        }

        Schema::create('roles', function (Blueprint $t) {
            $t->increments('id');
            $t->string('nombre', 30);
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
            $t->string('numero', 50)->nullable();
            $t->string('estado', 20)->default('LIBRE');
        });

        // Mesa 1 por defecto, usada por crearVenta() en estas pruebas.
        DB::table('mesas')->insert(['id' => 1, 'numero' => '1', 'estado' => 'LIBRE']);

        Schema::create('ventas', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('mesa_id')->nullable();
            $t->decimal('monto', 12, 2)->default(0);
            $t->string('estado', 20)->default('ACTIVA');
            $t->integer('usuario_id')->nullable();
        });

        Schema::create('venta_detalles', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->integer('venta_id');
            $t->integer('producto_id');
            $t->integer('cantidad');
            $t->decimal('precio_unitario', 10, 2);
            $t->decimal('subtotal', 10, 2);
            $t->string('estado', 20)->default('ACTIVO');
            // Módulo de cocina: observación por línea y estado de preparación.
            // Se recrean aquí porque los tests usan SQLite en memoria.
            $t->text('notas')->nullable();
            $t->string('estado_preparacion', 20)->nullable();
            $t->timestamps();
        });

        Schema::create('cuentas', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->integer('venta_id');
            $t->string('nombre', 100);
            $t->decimal('total', 10, 2)->default(0);
            $t->string('estado', 20)->default('ABIERTA');
            $t->timestamps();
        });

        Schema::create('cuenta_detalles', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('cuenta_id');
            $t->integer('venta_id');
            $t->unsignedBigInteger('venta_detalle_id');
            $t->unsignedInteger('cantidad');
            $t->decimal('subtotal', 10, 2);
            $t->timestamps();
        });

        // Inventario (Etapa 2): libro de movimientos que consulta cancelar().
        // No se crea "productos": la reversión usa solo el historial, así que
        // el servicio no necesita la tabla de catálogo para cancelar.
        Schema::create('movimientos_inventario', function (Blueprint $t) {
            $t->id();
            $t->integer('producto_id');
            $t->string('tipo', 30);
            $t->decimal('cantidad', 12, 3);
            $t->string('origen_tipo', 30)->nullable();
            $t->unsignedBigInteger('origen_id')->nullable();
            $t->integer('usuario_id')->nullable();
            $t->string('observacion', 500)->nullable();
            $t->timestamp('creado_en')->useCurrent();
            $t->unique(['origen_tipo', 'origen_id']);
        });
    }

    private function usuario(string $rolNombre): User
    {
        $rolId = DB::table('roles')->insertGetId(['nombre' => $rolNombre]);

        $usuarioId = DB::table('usuarios')->insertGetId([
            'nombre' => $rolNombre . ' Test',
            'username' => strtolower($rolNombre) . '_test',
            'password_hash' => bcrypt('secret'),
            'rol_id' => $rolId,
        ]);

        return User::findOrFail($usuarioId);
    }

    private function crearVenta(array $extra = []): int
    {
        return DB::table('ventas')->insertGetId(array_merge([
            'mesa_id' => 1,
            'monto' => 0,
            'estado' => 'ACTIVA',
        ], $extra));
    }

    private function crearDetalle(int $ventaId, int $cantidad = 2, float $precio = 100): int
    {
        return DB::table('venta_detalles')->insertGetId([
            'venta_id' => $ventaId,
            'producto_id' => 1,
            'cantidad' => $cantidad,
            'precio_unitario' => $precio,
            'subtotal' => $cantidad * $precio,
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function crearCuenta(int $ventaId, array $extra = []): int
    {
        return DB::table('cuentas')->insertGetId(array_merge([
            'venta_id' => $ventaId,
            'nombre' => 'Cuenta Principal',
            'total' => 0,
            'estado' => 'ABIERTA',
            'created_at' => now(),
            'updated_at' => now(),
        ], $extra));
    }

    public function test_entorno_aislado_no_usa_mysql(): void
    {
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', (string) config('database.connections.sqlite.database'));
        $this->assertStringNotContainsString(
            'sistemadecaja',
            (string) config('database.connections.sqlite.database')
        );
    }

    public function test_cuenta_pagada_no_puede_cancelarse_y_no_modifica_datos(): void
    {
        $admin = $this->usuario('ADMIN');
        $ventaId = $this->crearVenta(['monto' => 200]);
        $detalleId = $this->crearDetalle($ventaId);
        $cuentaId = $this->crearCuenta($ventaId, [
            'estado' => 'PAGADA',
            'total' => 200,
        ]);

        // Una asignación cobrada que no debe borrarse.
        DB::table('cuenta_detalles')->insert([
            'cuenta_id' => $cuentaId,
            'venta_id' => $ventaId,
            'venta_detalle_id' => $detalleId,
            'cantidad' => 2,
            'subtotal' => 200,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $respuesta = $this->actingAs($admin)->app->make(VentaController::class)
            ->eliminarCuenta($cuentaId);

        $this->assertSame(422, $respuesta->getStatusCode());
        $this->assertFalse($respuesta->getData()->exito);
        $this->assertStringContainsString(
            'ya fue pagada',
            $respuesta->getData()->error
        );

        // Los rechazos no modifican datos.
        $this->assertDatabaseHas('cuentas', ['id' => $cuentaId, 'estado' => 'PAGADA', 'total' => 200]);
        $this->assertSame(1, DB::table('cuenta_detalles')->count());
        $this->assertDatabaseHas('ventas', ['id' => $ventaId, 'estado' => 'ACTIVA', 'monto' => 200]);
    }

    public function test_eliminar_cuenta_abierta_sigue_funcionando(): void
    {
        $gerente = $this->usuario('GERENTE');
        $ventaId = $this->crearVenta(['monto' => 100]);
        $detalleId = $this->crearDetalle($ventaId, 1);
        $cuentaId = $this->crearCuenta($ventaId, ['total' => 100]);

        DB::table('cuenta_detalles')->insert([
            'cuenta_id' => $cuentaId,
            'venta_id' => $ventaId,
            'venta_detalle_id' => $detalleId,
            'cantidad' => 1,
            'subtotal' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $respuesta = $this->actingAs($gerente)->app->make(VentaController::class)
            ->eliminarCuenta($cuentaId);

        $this->assertSame(200, $respuesta->getStatusCode());
        $this->assertTrue($respuesta->getData()->exito);
        $this->assertDatabaseHas('cuentas', ['id' => $cuentaId, 'estado' => 'CANCELADA']);

        // Comportamiento válido conservado: la cuenta abierta se cancela y
        // sus asignaciones no cobradas se retiran.
        $this->assertSame(0, DB::table('cuenta_detalles')->where('cuenta_id', $cuentaId)->count());
        $this->assertDatabaseHas('ventas', ['id' => $ventaId, 'estado' => 'ACTIVA']);
    }

    public function test_venta_pagada_no_puede_cancelarse_y_no_modifica_datos(): void
    {
        $admin = $this->usuario('ADMIN');
        $ventaId = $this->crearVenta([
            'estado' => 'PAGADA',
            'monto' => 150,
        ]);
        $cuentaId = $this->crearCuenta($ventaId, ['estado' => 'PAGADA', 'total' => 150]);
        DB::table('mesas')->where('id', 1)->update(['estado' => 'OCUPADA']);

        $respuesta = $this->actingAs($admin)->app->make(VentaController::class)
            ->cancelar($ventaId);

        $this->assertSame(422, $respuesta->getStatusCode());
        $this->assertFalse($respuesta->getData()->exito);
        $this->assertStringContainsString(
            'ya está pagada o cancelada',
            $respuesta->getData()->error
        );

        // Nada cambió: venta pagada, cuenta pagada, detalle intacto.
        $this->assertDatabaseHas('ventas', ['id' => $ventaId, 'estado' => 'PAGADA', 'monto' => 150]);
        $this->assertDatabaseHas('cuentas', ['id' => $cuentaId, 'estado' => 'PAGADA']);
        $this->assertDatabaseHas('mesas', ['id' => 1, 'estado' => 'OCUPADA']);
    }

    public function test_venta_cancelada_no_se_vuelve_a_cancelar(): void
    {
        $admin = $this->usuario('ADMIN');
        $ventaId = $this->crearVenta(['estado' => 'CANCELADA']);
        $cuentaId = $this->crearCuenta($ventaId, ['estado' => 'CANCELADA']);

        $respuesta = $this->actingAs($admin)->app->make(VentaController::class)
            ->cancelar($ventaId);

        $this->assertSame(422, $respuesta->getStatusCode());
        $this->assertFalse($respuesta->getData()->exito);

        $this->assertDatabaseHas('ventas', ['id' => $ventaId, 'estado' => 'CANCELADA']);
        $this->assertDatabaseHas('cuentas', ['id' => $cuentaId, 'estado' => 'CANCELADA']);
    }

    public function test_cancelar_venta_activa_sigue_funcionando(): void
    {
        $admin = $this->usuario('ADMIN');
        $ventaId = $this->crearVenta(['monto' => 100]);
        $detalleId = $this->crearDetalle($ventaId, 1);
        $cuentaId = $this->crearCuenta($ventaId, ['total' => 100]);
        DB::table('mesas')->where('id', 1)->update(['estado' => 'OCUPADA']);

        $respuesta = $this->actingAs($admin)->app->make(VentaController::class)
            ->cancelar($ventaId);

        $this->assertSame(200, $respuesta->getStatusCode());
        $this->assertTrue($respuesta->getData()->exito);

        // La venta activa sí se puede cancelar y libera la mesa.
        $this->assertDatabaseHas('ventas', ['id' => $ventaId, 'estado' => 'CANCELADA']);
        $this->assertDatabaseHas('cuentas', ['id' => $cuentaId, 'estado' => 'CANCELADA']);
        $this->assertDatabaseHas('venta_detalles', ['id' => $detalleId, 'estado' => 'CANCELADO']);
        $this->assertDatabaseHas('mesas', ['id' => 1, 'estado' => 'LIBRE']);
    }
}