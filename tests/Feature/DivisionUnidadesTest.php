<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/*
 * Pruebas aisladas de la división de cuentas POR UNIDADES.
 *
 * IMPORTANTE:
 * - Corren en SQLite :memory: (configurado en phpunit.xml).
 * - El esquema se construye a mano porque los migrations reales usan SQL
 *   exclusivo de MySQL. Así ninguna prueba toca "sistemadecaja".
 * - setUp() falla a propósito si la conexión no es SQLite en memoria.
 *
 * LIMITACIÓN DE CONCURRENCIA:
 * SQLite en memoria no ejecuta bloques FOR UPDATE simultáneos, por lo que
 * esta suite NO verifica deadlocks ni carreras reales. Lo que sí se valida
 * es el invariante de cobertura exacta (suma de asignaciones == unidades de
 * la línea) y la sustitución idempotente ante un doble envío consecutivo.
 * La validación de concurrencia real requiere MySQL.
 */
class DivisionUnidadesTest extends TestCase
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
            'anulaciones',
            'cuenta_detalles',
            'cuentas',
            'venta_detalles',
            'ventas',
            'productos',
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

        Schema::create('productos', function (Blueprint $t) {
            $t->increments('id');
            $t->string('nombre', 150);
            $t->decimal('precio', 10, 2)->default(0);
            $t->boolean('requiere_cocina')->default(false);
            $t->boolean('activo')->default(true);
        });

        Schema::create('ventas', function (Blueprint $t) {
            $t->increments('id');
            $t->unsignedBigInteger('mesa_id')->nullable();
            $t->integer('apertura_id')->nullable();
            $t->string('descripcion')->nullable();
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
            $t->integer('comanda_id')->nullable();
            $t->integer('cuenta_id')->nullable();
            $t->string('estado', 255)->default('ACTIVO');
            $t->timestamps();
        });

        Schema::create('cuentas', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->integer('venta_id');
            $t->string('nombre', 255);
            $t->decimal('total', 10, 2)->default(0);
            $t->string('estado', 255)->default('ABIERTA');
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
            $t->unique(['cuenta_id', 'venta_detalle_id']);
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

    private function crearProducto(string $nombre, float $precio, bool $cocina = false): int
    {
        return DB::table('productos')->insertGetId([
            'nombre' => $nombre,
            'precio' => $precio,
            'requiere_cocina' => $cocina,
            'activo' => true,
        ]);
    }

    private function crearVenta(array $extra = []): int
    {
        return DB::table('ventas')->insertGetId(array_merge([
            'mesa_id' => 1,
            'descripcion' => 'Consumo Mesa 1',
            'monto' => 0,
            'estado' => 'ACTIVA',
        ], $extra));
    }

    private function crearDetalle(int $ventaId, int $productoId, int $cantidad, float $precio, array $extra = []): int
    {
        return DB::table('venta_detalles')->insertGetId(array_merge([
            'venta_id' => $ventaId,
            'producto_id' => $productoId,
            'cantidad' => $cantidad,
            'precio_unitario' => $precio,
            'subtotal' => $cantidad * $precio,
            'comanda_id' => null,
            'cuenta_id' => null,
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ], $extra));
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

    private function asignar(int $cuentaId, int $ventaId, int $detalleId, int $cantidad, float $subtotal): void
    {
        DB::table('cuenta_detalles')->insert([
            'cuenta_id' => $cuentaId,
            'venta_id' => $ventaId,
            'venta_detalle_id' => $detalleId,
            'cantidad' => $cantidad,
            'subtotal' => $subtotal,
            'eliminado' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function escenarioDivision(): array
    {
        $ventaId = $this->crearVenta();
        $producto = $this->crearProducto('Gaseosa', 30);
        $detalleId = $this->crearDetalle($ventaId, $producto, 2, 30);
        $cuenta1 = $this->crearCuenta($ventaId, ['nombre' => 'Mesa', 'total' => 60]);
        $cuenta2 = $this->crearCuenta($ventaId, ['nombre' => 'Ana']);
        $this->asignar($cuenta1, $ventaId, $detalleId, 2, 60);
        DB::table('ventas')->where('id', $ventaId)->update(['monto' => 60]);

        return [
            'ventaId' => $ventaId,
            'detalleId' => $detalleId,
            'cuenta1' => $cuenta1,
            'cuenta2' => $cuenta2,
        ];
    }

    private function repartoTipico(array $escenario): array
    {
        return [
            ['cuenta_id' => $escenario['cuenta1'], 'venta_detalle_id' => $escenario['detalleId'], 'cantidad' => 1],
            ['cuenta_id' => $escenario['cuenta2'], 'venta_detalle_id' => $escenario['detalleId'], 'cantidad' => 1],
        ];
    }

    private function escenarioCinco(): array
    {
        $ventaId = $this->crearVenta();
        $producto = $this->crearProducto('Gaseosa', 30);
        $detalleId = $this->crearDetalle($ventaId, $producto, 5, 30);
        $cuentaA = $this->crearCuenta($ventaId, ['nombre' => 'Mesa', 'total' => 150]);
        $cuentaB = $this->crearCuenta($ventaId, ['nombre' => 'Ana']);
        $cuentaC = $this->crearCuenta($ventaId, ['nombre' => 'Luis']);
        $this->asignar($cuentaA, $ventaId, $detalleId, 5, 150);
        DB::table('ventas')->where('id', $ventaId)->update(['monto' => 150]);

        return [
            'ventaId' => $ventaId,
            'detalleId' => $detalleId,
            'cuentaA' => $cuentaA,
            'cuentaB' => $cuentaB,
            'cuentaC' => $cuentaC,
        ];
    }

    private function dividir(int $mesaId, array $asignaciones, array $cuentasNuevas = []): \Illuminate\Testing\TestResponse
    {
        $payload = ['asignaciones' => $asignaciones];
        if ($cuentasNuevas !== []) {
            $payload['cuentas_nuevas'] = $cuentasNuevas;
        }

        return $this->postJson('/pos/' . $mesaId . '/cuentas/dividir', $payload);
    }

    public function test_entorno_aislado_no_usa_mysql(): void
    {
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', (string) config('database.connections.sqlite.database'));
        $this->assertStringNotContainsString('sistemadecaja', (string) config('database.connections.sqlite.database'));
    }

    public function test_reparte_dos_comidas_y_dos_bebidas_una_unidad_por_cuenta(): void
    {
        $admin = $this->usuario('ADMIN');
        $ventaId = $this->crearVenta();

        $pComida1 = $this->crearProducto('Carne Asada', 120, true);
        $pComida2 = $this->crearProducto('Pescado', 140, true);
        $pBebida1 = $this->crearProducto('Gaseosa', 30, false);
        $pBebida2 = $this->crearProducto('Jugo', 40, false);

        $dComida1 = $this->crearDetalle($ventaId, $pComida1, 1, 120);
        $dComida2 = $this->crearDetalle($ventaId, $pComida2, 1, 140);
        $dBebida1 = $this->crearDetalle($ventaId, $pBebida1, 1, 30);
        $dBebida2 = $this->crearDetalle($ventaId, $pBebida2, 1, 40);

        $cuenta1 = $this->crearCuenta($ventaId, ['nombre' => 'Mesa 1']);
        $cuenta2 = $this->crearCuenta($ventaId, ['nombre' => 'Ana']);

        // Estado inicial: todo en la cuenta 1.
        $this->asignar($cuenta1, $ventaId, $dComida1, 1, 120);
        $this->asignar($cuenta1, $ventaId, $dComida2, 1, 140);
        $this->asignar($cuenta1, $ventaId, $dBebida1, 1, 30);
        $this->asignar($cuenta1, $ventaId, $dBebida2, 1, 40);
        DB::table('ventas')->where('id', $ventaId)->update(['monto' => 330]);

        // Reparto explícito: una unidad de CADA producto por cuenta.
        $respuesta = $this->actingAs($admin)->dividir(1, [
            ['cuenta_id' => $cuenta1, 'venta_detalle_id' => $dComida1, 'cantidad' => 1],
            ['cuenta_id' => $cuenta1, 'venta_detalle_id' => $dBebida1, 'cantidad' => 1],
            ['cuenta_id' => $cuenta2, 'venta_detalle_id' => $dComida2, 'cantidad' => 1],
            ['cuenta_id' => $cuenta2, 'venta_detalle_id' => $dBebida2, 'cantidad' => 1],
        ]);

        $respuesta->assertStatus(200)->assertJson(['exito' => true]);

        $this->assertSame(150.0, (float) DB::table('cuentas')->where('id', $cuenta1)->value('total'));
        $this->assertSame(180.0, (float) DB::table('cuentas')->where('id', $cuenta2)->value('total'));

        // Sin importes duplicados: suma de cuentas == suma de líneas.
        $this->assertSame(330.0, (float) DB::table('cuentas')->where('venta_id', $ventaId)->sum('total'));
    }

    public function test_reparte_una_unidad_de_una_linea_cuya_cantidad_original_es_dos(): void
    {
        $admin = $this->usuario('ADMIN');
        $ventaId = $this->crearVenta();
        $p = $this->crearProducto('Gaseosa', 30);
        $d1 = $this->crearDetalle($ventaId, $p, 2, 30);
        $cuenta1 = $this->crearCuenta($ventaId, ['nombre' => 'Mesa']);
        $cuenta2 = $this->crearCuenta($ventaId, ['nombre' => 'Ana']);
        $this->asignar($cuenta1, $ventaId, $d1, 2, 60);
        DB::table('ventas')->where('id', $ventaId)->update(['monto' => 60]);

        // Trasladar UNA sola gaseosa, dejando la otra en la cuenta original.
        $this->actingAs($admin)->dividir(1, [
            ['cuenta_id' => $cuenta1, 'venta_detalle_id' => $d1, 'cantidad' => 1],
            ['cuenta_id' => $cuenta2, 'venta_detalle_id' => $d1, 'cantidad' => 1],
        ])->assertStatus(200)->assertJson(['exito' => true]);

        $this->assertSame(1, (int) DB::table('cuenta_detalles')->where('cuenta_id', $cuenta1)->where('venta_detalle_id', $d1)->value('cantidad'));
        $this->assertSame(1, (int) DB::table('cuenta_detalles')->where('cuenta_id', $cuenta2)->where('venta_detalle_id', $d1)->value('cantidad'));

        $this->assertSame(30.0, (float) DB::table('cuentas')->where('id', $cuenta1)->value('total'));
        $this->assertSame(30.0, (float) DB::table('cuentas')->where('id', $cuenta2)->value('total'));
        $this->assertSame(2, DB::table('cuenta_detalles')->count());
    }

    public function test_traslada_todas_las_unidades_de_una_linea_a_otra_cuenta(): void
    {
        $admin = $this->usuario('ADMIN');
        $ventaId = $this->crearVenta();
        $p = $this->crearProducto('Gaseosa', 30);
        $d1 = $this->crearDetalle($ventaId, $p, 2, 30);
        $cuenta1 = $this->crearCuenta($ventaId, ['nombre' => 'Mesa']);
        $cuenta2 = $this->crearCuenta($ventaId, ['nombre' => 'Ana']);
        $this->asignar($cuenta1, $ventaId, $d1, 2, 60);

        $this->actingAs($admin)->dividir(1, [
            ['cuenta_id' => $cuenta2, 'venta_detalle_id' => $d1, 'cantidad' => 2],
        ])->assertStatus(200)->assertJson(['exito' => true]);

        $this->assertSame(0, (int) DB::table('cuenta_detalles')->where('cuenta_id', $cuenta1)->where('venta_detalle_id', $d1)->count());
        $this->assertSame(2, (int) DB::table('cuenta_detalles')->where('cuenta_id', $cuenta2)->where('venta_detalle_id', $d1)->value('cantidad'));
        $this->assertSame(60.0, (float) DB::table('cuentas')->where('id', $cuenta2)->value('total'));
    }

    public function test_rechaza_cantidades_superiores_a_las_disponibles(): void
    {
        $admin = $this->usuario('ADMIN');
        $ventaId = $this->crearVenta();
        $p = $this->crearProducto('Gaseosa', 30);
        $d1 = $this->crearDetalle($ventaId, $p, 2, 30);
        $cuenta1 = $this->crearCuenta($ventaId, ['nombre' => 'Mesa', 'total' => 60]);
        $cuenta2 = $this->crearCuenta($ventaId, ['nombre' => 'Ana']);
        $this->asignar($cuenta1, $ventaId, $d1, 2, 60);

        // Una sola asignación con más unidades que la línea.
        $this->actingAs($admin)->dividir(1, [
            ['cuenta_id' => $cuenta2, 'venta_detalle_id' => $d1, 'cantidad' => 3],
        ])->assertStatus(422)->assertJson(['exito' => false]);

        // Nada cambió (rollback del reemplazo parcial).
        $this->assertSame(2, (int) DB::table('cuenta_detalles')->where('cuenta_id', $cuenta1)->where('venta_detalle_id', $d1)->value('cantidad'));
        $this->assertSame(0, DB::table('cuenta_detalles')->where('cuenta_id', $cuenta2)->count());
        $this->assertSame(60.0, (float) DB::table('cuentas')->where('id', $cuenta1)->value('total'));
    }

    public function test_rechaza_cantidades_cero_negativas_o_no_enteras(): void
    {
        $admin = $this->usuario('ADMIN');
        $ventaId = $this->crearVenta();
        $p = $this->crearProducto('Gaseosa', 30);
        $d1 = $this->crearDetalle($ventaId, $p, 2, 30);
        $cuenta1 = $this->crearCuenta($ventaId, ['nombre' => 'Mesa']);
        $cuenta2 = $this->crearCuenta($ventaId, ['nombre' => 'Ana']);
        $this->asignar($cuenta1, $ventaId, $d1, 2, 60);

        // Cero.
        $this->actingAs($admin)->dividir(1, [
            ['cuenta_id' => $cuenta1, 'venta_detalle_id' => $d1, 'cantidad' => 0],
            ['cuenta_id' => $cuenta2, 'venta_detalle_id' => $d1, 'cantidad' => 2],
        ])->assertStatus(422);

        // Negativa.
        $this->actingAs($admin)->dividir(1, [
            ['cuenta_id' => $cuenta1, 'venta_detalle_id' => $d1, 'cantidad' => -1],
            ['cuenta_id' => $cuenta2, 'venta_detalle_id' => $d1, 'cantidad' => 3],
        ])->assertStatus(422);

        // No entera.
        $this->actingAs($admin)->dividir(1, [
            ['cuenta_id' => $cuenta1, 'venta_detalle_id' => $d1, 'cantidad' => 1.5],
            ['cuenta_id' => $cuenta2, 'venta_detalle_id' => $d1, 'cantidad' => 1],
        ])->assertStatus(422)->assertJsonValidationErrors('asignaciones.0.cantidad');

        // Ninguna ejecución llegó a persistir cambios.
        $this->assertSame(2, (int) DB::table('cuenta_detalles')->where('cuenta_id', $cuenta1)->where('venta_detalle_id', $d1)->value('cantidad'));
        $this->assertSame(0, DB::table('cuenta_detalles')->where('cuenta_id', $cuenta2)->count());
    }

    public function test_rechaza_asignaciones_duplicadas(): void
    {
        $admin = $this->usuario('ADMIN');
        $ventaId = $this->crearVenta();
        $p = $this->crearProducto('Gaseosa', 30);
        $d1 = $this->crearDetalle($ventaId, $p, 2, 30);
        $cuenta1 = $this->crearCuenta($ventaId, ['nombre' => 'Mesa']);
        $this->asignar($cuenta1, $ventaId, $d1, 2, 60);

        $this->actingAs($admin)->dividir(1, [
            ['cuenta_id' => $cuenta1, 'venta_detalle_id' => $d1, 'cantidad' => 1],
            ['cuenta_id' => $cuenta1, 'venta_detalle_id' => $d1, 'cantidad' => 1],
        ])->assertStatus(422)->assertJson(['exito' => false]);

        // La primera fila del intento se descartó con el rollback.
        $this->assertSame(2, (int) DB::table('cuenta_detalles')->where('cuenta_id', $cuenta1)->where('venta_detalle_id', $d1)->value('cantidad'));
        $this->assertSame(1, DB::table('cuenta_detalles')->count());
    }

    public function test_conserva_intactas_las_asignaciones_de_cuentas_pagadas(): void
    {
        $admin = $this->usuario('ADMIN');
        $ventaId = $this->crearVenta();
        $p = $this->crearProducto('Gaseosa', 30);
        $d1 = $this->crearDetalle($ventaId, $p, 2, 30);

        $pagada = $this->crearCuenta($ventaId, ['nombre' => 'Pagada', 'estado' => 'PAGADA', 'total' => 30]);
        $abierta = $this->crearCuenta($ventaId, ['nombre' => 'Abierta', 'total' => 30]);
        $this->asignar($pagada, $ventaId, $d1, 1, 30);
        $this->asignar($abierta, $ventaId, $d1, 1, 30);

        // Solo la unidad abierta es reasignable; la pagada queda congelada.
        $this->actingAs($admin)->dividir(1, [
            ['cuenta_id' => $abierta, 'venta_detalle_id' => $d1, 'cantidad' => 1],
        ])->assertStatus(200)->assertJson(['exito' => true]);

        $this->assertSame(1, (int) DB::table('cuenta_detalles')->where('cuenta_id', $pagada)->where('venta_detalle_id', $d1)->value('cantidad'));
        $this->assertSame(1, (int) DB::table('cuenta_detalles')->where('cuenta_id', $abierta)->where('venta_detalle_id', $d1)->value('cantidad'));

        // Intentar reasignar la unidad pagada debe rechazarse (cobertura rota).
        $this->actingAs($admin)->dividir(1, [
            ['cuenta_id' => $abierta, 'venta_detalle_id' => $d1, 'cantidad' => 2],
        ])->assertStatus(422)->assertJson(['exito' => false]);

        $this->assertSame(30.0, (float) DB::table('cuentas')->where('id', $pagada)->value('total'));
    }

    public function test_reparte_unidades_restantes_tras_anulacion_parcial(): void
    {
        $admin = $this->usuario('ADMIN');
        $ventaId = $this->crearVenta();
        $p = $this->crearProducto('Gaseosa', 30);
        $d1 = $this->crearDetalle($ventaId, $p, 2, 30);
        $cuenta1 = $this->crearCuenta($ventaId, ['nombre' => 'Mesa', 'total' => 60]);
        $cuenta2 = $this->crearCuenta($ventaId, ['nombre' => 'Ana']);
        $this->asignar($cuenta1, $ventaId, $d1, 2, 60);
        DB::table('ventas')->where('id', $ventaId)->update(['monto' => 60]);

        // Anulación parcial real: queda 1 unidad en la cuenta abierta.
        $this->actingAs($admin)
            ->postJson('/pos/detalle/' . $d1 . '/anular', ['cantidad' => 1, 'motivo' => 'Una se sirvió mal'])
            ->assertStatus(200);

        $this->assertSame(1, (int) DB::table('venta_detalles')->where('id', $d1)->value('cantidad'));

        // La unidad restante se muda a la otra cuenta.
        $this->actingAs($admin)->dividir(1, [
            ['cuenta_id' => $cuenta2, 'venta_detalle_id' => $d1, 'cantidad' => 1],
        ])->assertStatus(200)->assertJson(['exito' => true]);

        $this->assertSame(0, DB::table('cuenta_detalles')->where('cuenta_id', $cuenta1)->where('venta_detalle_id', $d1)->count());
        $this->assertSame(1, (int) DB::table('cuenta_detalles')->where('cuenta_id', $cuenta2)->where('venta_detalle_id', $d1)->value('cantidad'));
        $this->assertSame(30.0, (float) DB::table('cuentas')->where('id', $cuenta2)->value('total'));
        $this->assertSame(30.0, (float) DB::table('ventas')->where('id', $ventaId)->value('monto'));
    }

    public function test_totales_de_cuentas_sin_importes_duplicados(): void
    {
        $admin = $this->usuario('ADMIN');
        $ventaId = $this->crearVenta();
        $p1 = $this->crearProducto('Carne', 120);
        $p2 = $this->crearProducto('Jugo', 40);
        $d1 = $this->crearDetalle($ventaId, $p1, 1, 120);
        $d2 = $this->crearDetalle($ventaId, $p2, 1, 40);
        $cuenta1 = $this->crearCuenta($ventaId, ['nombre' => 'Mesa']);
        $cuenta2 = $this->crearCuenta($ventaId, ['nombre' => 'Ana']);
        $this->asignar($cuenta1, $ventaId, $d1, 1, 120);
        $this->asignar($cuenta1, $ventaId, $d2, 1, 40);

        $this->actingAs($admin)->dividir(1, [
            ['cuenta_id' => $cuenta1, 'venta_detalle_id' => $d1, 'cantidad' => 1],
            ['cuenta_id' => $cuenta2, 'venta_detalle_id' => $d2, 'cantidad' => 1],
        ])->assertStatus(200);

        $this->assertSame(120.0, (float) DB::table('cuentas')->where('id', $cuenta1)->value('total'));
        $this->assertSame(40.0, (float) DB::table('cuentas')->where('id', $cuenta2)->value('total'));

        $subtotalLineas = (float) DB::table('venta_detalles')->where('venta_id', $ventaId)->sum('subtotal');
        $subtotalAsignaciones = (float) DB::table('cuenta_detalles')->where('venta_id', $ventaId)->where('eliminado', false)->sum('subtotal');
        $this->assertSame($subtotalLineas, $subtotalAsignaciones);
    }

    public function test_error_de_validacion_no_deja_cambios_parciales(): void
    {
        $admin = $this->usuario('ADMIN');
        $ventaId = $this->crearVenta();
        $p = $this->crearProducto('Gaseosa', 30);
        $d1 = $this->crearDetalle($ventaId, $p, 2, 30);
        $cuenta1 = $this->crearCuenta($ventaId, ['nombre' => 'Mesa', 'total' => 60]);
        $cuenta2 = $this->crearCuenta($ventaId, ['nombre' => 'Ana']);
        $this->asignar($cuenta1, $ventaId, $d1, 2, 60);

        // La primera fila es válida pero la segunda referencia una cuenta ajena:
        // el insert de la primera DEBE revertirse por la transacción.
        $this->actingAs($admin)->dividir(1, [
            ['cuenta_id' => $cuenta1, 'venta_detalle_id' => $d1, 'cantidad' => 1],
            ['cuenta_id' => 999999, 'venta_detalle_id' => $d1, 'cantidad' => 1],
        ])->assertStatus(422)->assertJson(['exito' => false]);

        $this->assertSame(1, DB::table('cuenta_detalles')->count());
        $this->assertSame(2, (int) DB::table('cuenta_detalles')->where('cuenta_id', $cuenta1)->where('venta_detalle_id', $d1)->value('cantidad'));
        $this->assertSame(60.0, (float) DB::table('cuentas')->where('id', $cuenta1)->value('total'));
    }

    public function test_crea_cuenta_nueva_sin_mover_lineas_a_otra_cuenta(): void
    {
        $admin = $this->usuario('ADMIN');
        $ventaId = $this->crearVenta();
        $p = $this->crearProducto('Gaseosa', 30);
        $d1 = $this->crearDetalle($ventaId, $p, 2, 30);
        $cuenta1 = $this->crearCuenta($ventaId, ['nombre' => 'Mesa', 'total' => 60]);
        $this->asignar($cuenta1, $ventaId, $d1, 2, 60);

        // Reproduce el paso A de la UI: crear la cuenta nueva conservando
        // exactamente la distribución actual entre cuentas abiertas.
        $this->actingAs($admin)->dividir(1, [
            ['cuenta_id' => $cuenta1, 'venta_detalle_id' => $d1, 'cantidad' => 2],
        ], ['Ana'])->assertStatus(200)->assertJson(['exito' => true]);

        $nueva = DB::table('cuentas')->where('venta_id', $ventaId)->where('nombre', 'Ana')->first();
        $this->assertNotNull($nueva);
        $this->assertSame('ABIERTA', $nueva->estado);
        $this->assertSame(0.0, (float) $nueva->total);

        // La cuenta original no cambió y sigue teniendo todas las unidades.
        $this->assertSame(60.0, (float) DB::table('cuentas')->where('id', $cuenta1)->value('total'));
        $this->assertSame(0, DB::table('cuenta_detalles')->where('cuenta_id', $nueva->id)->count());
    }

    public function test_doble_envio_consecutivo_no_duplica_unidades(): void
    {
        $admin = $this->usuario('ADMIN');
        $ventaId = $this->crearVenta();
        $p = $this->crearProducto('Gaseosa', 30);
        $d1 = $this->crearDetalle($ventaId, $p, 2, 30);
        $cuenta1 = $this->crearCuenta($ventaId, ['nombre' => 'Mesa']);
        $cuenta2 = $this->crearCuenta($ventaId, ['nombre' => 'Ana']);
        $this->asignar($cuenta1, $ventaId, $d1, 2, 60);

        $asignaciones = [
            ['cuenta_id' => $cuenta1, 'venta_detalle_id' => $d1, 'cantidad' => 1],
            ['cuenta_id' => $cuenta2, 'venta_detalle_id' => $d1, 'cantidad' => 1],
        ];

        // Dos envíos idénticos consecutivos: la división es sustitutiva, no acumulativa.
        $this->actingAs($admin)->dividir(1, $asignaciones)->assertStatus(200);
        $this->actingAs($admin)->dividir(1, $asignaciones)->assertStatus(200);

        $this->assertSame(2, DB::table('cuenta_detalles')->count());
        $this->assertSame(60.0, (float) DB::table('cuenta_detalles')->where('venta_id', $ventaId)->sum('subtotal'));
        $this->assertSame(30.0, (float) DB::table('cuentas')->where('id', $cuenta1)->value('total'));
        $this->assertSame(30.0, (float) DB::table('cuentas')->where('id', $cuenta2)->value('total'));
    }

    public function test_admin_puede_dividir_cuentas(): void
    {
        $admin = $this->usuario('ADMIN');
        $escenario = $this->escenarioDivision();

        $this->actingAs($admin)->dividir(1, $this->repartoTipico($escenario))
            ->assertStatus(200)->assertJson(['exito' => true]);

        $this->assertSame(30.0, (float) DB::table('cuentas')->where('id', $escenario['cuenta1'])->value('total'));
        $this->assertSame(30.0, (float) DB::table('cuentas')->where('id', $escenario['cuenta2'])->value('total'));
    }

    public function test_gerente_puede_dividir_cuentas(): void
    {
        $gerente = $this->usuario('GERENTE');
        $escenario = $this->escenarioDivision();

        $this->actingAs($gerente)->dividir(1, $this->repartoTipico($escenario))
            ->assertStatus(200)->assertJson(['exito' => true]);

        $this->assertSame(30.0, (float) DB::table('cuentas')->where('id', $escenario['cuenta1'])->value('total'));
        $this->assertSame(30.0, (float) DB::table('cuentas')->where('id', $escenario['cuenta2'])->value('total'));
    }

    public function test_cajero_puede_dividir_cuentas(): void
    {
        $cajero = $this->usuario('CAJERO');
        $escenario = $this->escenarioDivision();

        $this->actingAs($cajero)->dividir(1, $this->repartoTipico($escenario))
            ->assertStatus(200)->assertJson(['exito' => true]);

        $this->assertSame(30.0, (float) DB::table('cuentas')->where('id', $escenario['cuenta1'])->value('total'));
        $this->assertSame(30.0, (float) DB::table('cuentas')->where('id', $escenario['cuenta2'])->value('total'));
    }

    public function test_mesero_no_puede_dividir_y_no_modifica_datos(): void
    {
        $mesero = $this->usuario('MESERO');
        $escenario = $this->escenarioDivision();

        $this->actingAs($mesero)->dividir(1, $this->repartoTipico($escenario))
            ->assertStatus(403)->assertJson(['exito' => false]);

        // Nada cambió: una sola asignación con las 2 unidades en la cuenta 1.
        $this->assertSame(1, DB::table('cuenta_detalles')->count());
        $this->assertSame(2, (int) DB::table('cuenta_detalles')->where('cuenta_id', $escenario['cuenta1'])->where('venta_detalle_id', $escenario['detalleId'])->value('cantidad'));
        $this->assertSame(0, DB::table('cuenta_detalles')->where('cuenta_id', $escenario['cuenta2'])->count());
        $this->assertSame(60.0, (float) DB::table('cuentas')->where('id', $escenario['cuenta1'])->value('total'));
        $this->assertSame(0.0, (float) DB::table('cuentas')->where('id', $escenario['cuenta2'])->value('total'));
    }

    public function test_usuario_con_otro_rol_no_puede_dividir_y_no_modifica_datos(): void
    {
        $supervisor = $this->usuario('SUPERVISOR');
        $escenario = $this->escenarioDivision();

        $this->actingAs($supervisor)->dividir(1, $this->repartoTipico($escenario))
            ->assertStatus(403)->assertJson(['exito' => false]);

        $this->assertSame(1, DB::table('cuenta_detalles')->count());
        $this->assertSame(2, (int) DB::table('cuenta_detalles')->where('cuenta_id', $escenario['cuenta1'])->where('venta_detalle_id', $escenario['detalleId'])->value('cantidad'));
        $this->assertSame(0, DB::table('cuenta_detalles')->where('cuenta_id', $escenario['cuenta2'])->count());
        $this->assertSame(60.0, (float) DB::table('cuentas')->where('id', $escenario['cuenta1'])->value('total'));
        $this->assertSame(0.0, (float) DB::table('cuentas')->where('id', $escenario['cuenta2'])->value('total'));
    }

    /*
     * Reparto "en vivo": el caso base de 5 unidades que se reparten 3/2.
     * El backend debe aceptar la cobertura exacta y recalcular los importes
     * (cantidad × precio_unitario) sin guardar nada a mano.
     */
    public function test_reparte_2_de_5_unidades_y_actualiza_importes(): void
    {
        $admin = $this->usuario('ADMIN');
        $e = $this->escenarioCinco();

        $this->actingAs($admin)->dividir(1, [
            ['cuenta_id' => $e['cuentaA'], 'venta_detalle_id' => $e['detalleId'], 'cantidad' => 3],
            ['cuenta_id' => $e['cuentaB'], 'venta_detalle_id' => $e['detalleId'], 'cantidad' => 2],
        ])->assertStatus(200)->assertJson(['exito' => true]);

        // A queda con 3 (90 = 3×30); B queda con 2 (60 = 2×30).
        $this->assertSame(90.0, (float) DB::table('cuentas')->where('id', $e['cuentaA'])->value('total'));
        $this->assertSame(60.0, (float) DB::table('cuentas')->where('id', $e['cuentaB'])->value('total'));
        $this->assertSame(150.0, (float) DB::table('cuentas')->where('venta_id', $e['ventaId'])->sum('total'));
        $this->assertSame(0, DB::table('cuenta_detalles')->where('cuenta_id', $e['cuentaC'])->count());
    }

    /*
     * Cambiar 2 -> 3 en B no debe descontar dos veces: la división es
     * sustitutiva, así que A pasa de 3 a 2 y B de 2 a 3.
     */
    public function test_cambiar_de_2_a_3_no_descuenta_dos_veces(): void
    {
        $admin = $this->usuario('ADMIN');
        $e = $this->escenarioCinco();

        // Primer reparto: 2 unidades para B.
        $this->actingAs($admin)->dividir(1, [
            ['cuenta_id' => $e['cuentaA'], 'venta_detalle_id' => $e['detalleId'], 'cantidad' => 3],
            ['cuenta_id' => $e['cuentaB'], 'venta_detalle_id' => $e['detalleId'], 'cantidad' => 2],
        ])->assertStatus(200)->assertJson(['exito' => true]);

        // Corregir 2 -> 3: los totales se recalculan desde cero, no se acumulan.
        $this->actingAs($admin)->dividir(1, [
            ['cuenta_id' => $e['cuentaA'], 'venta_detalle_id' => $e['detalleId'], 'cantidad' => 2],
            ['cuenta_id' => $e['cuentaB'], 'venta_detalle_id' => $e['detalleId'], 'cantidad' => 3],
        ])->assertStatus(200)->assertJson(['exito' => true]);

        $this->assertSame(60.0, (float) DB::table('cuentas')->where('id', $e['cuentaA'])->value('total'));
        $this->assertSame(90.0, (float) DB::table('cuentas')->where('id', $e['cuentaB'])->value('total'));
        $this->assertSame(150.0, (float) DB::table('cuentas')->where('venta_id', $e['ventaId'])->sum('total'));
        $this->assertSame(2, DB::table('cuenta_detalles')->count());
        $this->assertSame(150.0, (float) DB::table('cuenta_detalles')->where('venta_id', $e['ventaId'])->sum('subtotal'));
    }

    /*
     * Devolver la asignación a 0: toda la línea regresa a A y B queda sin fila.
     */
    public function test_devolver_asignacion_a_cero(): void
    {
        $admin = $this->usuario('ADMIN');
        $e = $this->escenarioCinco();

        $this->actingAs($admin)->dividir(1, [
            ['cuenta_id' => $e['cuentaA'], 'venta_detalle_id' => $e['detalleId'], 'cantidad' => 3],
            ['cuenta_id' => $e['cuentaB'], 'venta_detalle_id' => $e['detalleId'], 'cantidad' => 2],
        ])->assertStatus(200);

        // B vuelve a 0: se omite su fila y A recupera las 5 unidades.
        $this->actingAs($admin)->dividir(1, [
            ['cuenta_id' => $e['cuentaA'], 'venta_detalle_id' => $e['detalleId'], 'cantidad' => 5],
        ])->assertStatus(200)->assertJson(['exito' => true]);

        $this->assertSame(150.0, (float) DB::table('cuentas')->where('id', $e['cuentaA'])->value('total'));
        $this->assertSame(0.0, (float) DB::table('cuentas')->where('id', $e['cuentaB'])->value('total'));
        $this->assertSame(1, DB::table('cuenta_detalles')->count());
        $this->assertSame(0, DB::table('cuenta_detalles')->where('cuenta_id', $e['cuentaB'])->count());
    }

    /*
     * Reparto entre tres cuentas: 2 / 2 / 1 sobre las mismas 5 unidades.
     */
    public function test_reparte_entre_tres_cuentas(): void
    {
        $admin = $this->usuario('ADMIN');
        $e = $this->escenarioCinco();

        $this->actingAs($admin)->dividir(1, [
            ['cuenta_id' => $e['cuentaA'], 'venta_detalle_id' => $e['detalleId'], 'cantidad' => 2],
            ['cuenta_id' => $e['cuentaB'], 'venta_detalle_id' => $e['detalleId'], 'cantidad' => 2],
            ['cuenta_id' => $e['cuentaC'], 'venta_detalle_id' => $e['detalleId'], 'cantidad' => 1],
        ])->assertStatus(200)->assertJson(['exito' => true]);

        $this->assertSame(60.0, (float) DB::table('cuentas')->where('id', $e['cuentaA'])->value('total'));
        $this->assertSame(60.0, (float) DB::table('cuentas')->where('id', $e['cuentaB'])->value('total'));
        $this->assertSame(30.0, (float) DB::table('cuentas')->where('id', $e['cuentaC'])->value('total'));
        $this->assertSame(150.0, (float) DB::table('cuentas')->where('venta_id', $e['ventaId'])->sum('total'));
    }

    /*
     * No se puede superar lo disponible, ni por una sola fila ni por la suma.
     */
    public function test_no_puede_superar_las_cinco_unidades(): void
    {
        $admin = $this->usuario('ADMIN');
        $e = $this->escenarioCinco();

        // Una sola cuenta pide más unidades que la línea.
        $this->actingAs($admin)->dividir(1, [
            ['cuenta_id' => $e['cuentaB'], 'venta_detalle_id' => $e['detalleId'], 'cantidad' => 6],
        ])->assertStatus(422)->assertJson(['exito' => false]);

        // Aunque ninguna fila individual exceda, la suma global (6 > 5) rompe la cobertura.
        $this->actingAs($admin)->dividir(1, [
            ['cuenta_id' => $e['cuentaA'], 'venta_detalle_id' => $e['detalleId'], 'cantidad' => 3],
            ['cuenta_id' => $e['cuentaB'], 'venta_detalle_id' => $e['detalleId'], 'cantidad' => 3],
        ])->assertStatus(422)->assertJson(['exito' => false]);

        // Ningún intento persistió cambios: sigue todo en A.
        $this->assertSame(1, DB::table('cuenta_detalles')->count());
        $this->assertSame(5, (int) DB::table('cuenta_detalles')->where('cuenta_id', $e['cuentaA'])->where('venta_detalle_id', $e['detalleId'])->value('cantidad'));
        $this->assertSame(150.0, (float) DB::table('cuentas')->where('id', $e['cuentaA'])->value('total'));
        $this->assertSame(0.0, (float) DB::table('cuentas')->where('id', $e['cuentaB'])->value('total'));
    }
}