<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/*
 * Pruebas de la separación entre:
 *   - Reimpresión HISTÓRICA de una comanda (cantidad original completa).
 *   - Pedido ACTUAL de una subcuenta (?cuenta=) tras dividir.
 *
 * Caso base (Fase 6): una línea de 3 unidades de cocina enviada en una
 * comanda, luego dividida entre la cuenta A (1) y la cuenta B (2).
 *
 * Se ejecutan en SQLite :memory: y nunca tocan "sistemadecaja".
 */
class ComandaCuentaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', (string) config('database.connections.sqlite.database'));

        $this->crearEsquema();
    }

    private function crearEsquema(): void
    {
        foreach (['cuenta_detalles', 'cuentas', 'venta_detalles', 'comandas', 'productos', 'ventas', 'mesas', 'usuarios', 'roles'] as $tabla) {
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
            $t->string('numero', 20)->nullable();
            $t->integer('capacidad')->nullable();
            $t->string('estado', 20)->default('LIBRE');
        });

        Schema::create('ventas', function (Blueprint $t) {
            $t->increments('id');
            $t->unsignedBigInteger('mesa_id')->nullable();
            $t->unsignedBigInteger('cliente_id')->nullable();
            $t->integer('apertura_id')->nullable();
            $t->string('descripcion')->nullable();
            $t->decimal('monto', 12, 2)->default(0);
            $t->timestamp('fecha')->nullable();
            $t->string('estado', 20)->default('ACTIVA');
            $t->integer('usuario_id')->nullable();
        });

        Schema::create('productos', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('categoria_id')->nullable();
            $t->string('nombre', 150);
            $t->decimal('precio', 10, 2)->default(0);
            $t->boolean('requiere_cocina')->default(false);
            $t->boolean('activo')->default(true);
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
    }

    private function usuario(string $rolNombre = 'ADMIN'): User
    {
        $rolId = DB::table('roles')->insertGetId(['nombre' => $rolNombre]);
        $usuarioId = DB::table('usuarios')->insertGetId([
            'nombre' => $rolNombre . ' Test',
            'username' => strtolower($rolNombre) . '_' . uniqid(),
            'password_hash' => bcrypt('secret'),
            'rol_id' => $rolId,
        ]);

        return User::findOrFail($usuarioId);
    }

    /**
     * Escenario base: comanda de cocina con una línea de 3 unidades,
     * dividida en cuenta A (1) y cuenta B (2).
     *
     * @return array{comandaId:int, detalleId:int, ventaId:int, cuentaA:int, cuentaB:int, otraCuenta:int}
     */
    private function escenario(): array
    {
        $mesaId = DB::table('mesas')->insertGetId(['numero' => '1', 'capacidad' => 4, 'estado' => 'OCUPADA']);
        $ventaId = DB::table('ventas')->insertGetId(['mesa_id' => $mesaId, 'monto' => 90, 'fecha' => now(), 'estado' => 'ACTIVA']);
        $comandaId = DB::table('comandas')->insertGetId([
            'venta_id' => $ventaId,
            'mesa_id' => $mesaId,
            'numero_ticket' => 7,
            'estado' => 'ABIERTA',
            'creado_en' => now(),
        ]);
        $productoId = DB::table('productos')->insertGetId([
            'nombre' => 'Hamburguesa',
            'precio' => 30,
            'requiere_cocina' => true,
            'activo' => true,
        ]);
        $detalleId = DB::table('venta_detalles')->insertGetId([
            'venta_id' => $ventaId,
            'producto_id' => $productoId,
            'cantidad' => 3,
            'precio_unitario' => 30,
            'subtotal' => 90,
            'comanda_id' => $comandaId,
            'cuenta_id' => null,
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $cuentaA = DB::table('cuentas')->insertGetId(['venta_id' => $ventaId, 'nombre' => 'A', 'total' => 30, 'estado' => 'ABIERTA', 'created_at' => now(), 'updated_at' => now()]);
        $cuentaB = DB::table('cuentas')->insertGetId(['venta_id' => $ventaId, 'nombre' => 'B', 'total' => 60, 'estado' => 'ABIERTA', 'created_at' => now(), 'updated_at' => now()]);
        $otraCuenta = DB::table('cuentas')->insertGetId(['venta_id' => $ventaId, 'nombre' => 'C', 'total' => 0, 'estado' => 'ABIERTA', 'created_at' => now(), 'updated_at' => now()]);

        DB::table('cuenta_detalles')->insert([
            ['cuenta_id' => $cuentaA, 'venta_id' => $ventaId, 'venta_detalle_id' => $detalleId, 'cantidad' => 1, 'subtotal' => 30, 'eliminado' => false, 'created_at' => now(), 'updated_at' => now()],
            ['cuenta_id' => $cuentaB, 'venta_id' => $ventaId, 'venta_detalle_id' => $detalleId, 'cantidad' => 2, 'subtotal' => 60, 'eliminado' => false, 'created_at' => now(), 'updated_at' => now()],
        ]);

        return compact('comandaId', 'detalleId', 'ventaId', 'cuentaA', 'cuentaB', 'otraCuenta');
    }

    private function cantidadDeComanda($respuesta): int
    {
        return (int) $respuesta->viewData('detalles')->sum('cantidad');
    }

    public function test_reimpresion_historica_muestra_cantidad_original(): void
    {
        $admin = $this->usuario();
        $e = $this->escenario();

        $respuesta = $this->actingAs($admin)->get('/pos/comanda/' . $e['comandaId']);
        $respuesta->assertStatus(200);

        // Histórico: la línea completa (3 unidades), sin importar la división.
        $this->assertSame(3, $this->cantidadDeComanda($respuesta));
    }

    public function test_reimpresion_por_cuenta_muestra_pedido_actual(): void
    {
        $admin = $this->usuario();
        $e = $this->escenario();

        $a = $this->actingAs($admin)->get('/pos/comanda/' . $e['comandaId'] . '?cuenta=' . $e['cuentaA']);
        $a->assertStatus(200);
        $this->assertSame(1, $this->cantidadDeComanda($a));

        $b = $this->actingAs($admin)->get('/pos/comanda/' . $e['comandaId'] . '?cuenta=' . $e['cuentaB']);
        $b->assertStatus(200);
        $this->assertSame(2, $this->cantidadDeComanda($b));
    }

    public function test_cuenta_invalida_en_comanda_cae_al_historico(): void
    {
        $admin = $this->usuario();
        $e = $this->escenario();

        $respuesta = $this->actingAs($admin)->get('/pos/comanda/' . $e['comandaId'] . '?cuenta=999999');
        $respuesta->assertStatus(200);
        $this->assertSame(3, $this->cantidadDeComanda($respuesta));
    }

    public function test_reimpresion_historica_de_plato(): void
    {
        $admin = $this->usuario();
        $e = $this->escenario();

        $respuesta = $this->actingAs($admin)->get('/pos/comanda-detalle/' . $e['detalleId']);
        $respuesta->assertStatus(200);
        $this->assertSame(3, (int) $respuesta->viewData('detalles')->first()->cantidad);
    }

    public function test_reimpresion_de_plato_por_cuenta_usa_unidades_actuales(): void
    {
        $admin = $this->usuario();
        $e = $this->escenario();

        $respuesta = $this->actingAs($admin)->get('/pos/comanda-detalle/' . $e['detalleId'] . '?cuenta=' . $e['cuentaB']);
        $respuesta->assertStatus(200);
        $this->assertSame(2, (int) $respuesta->viewData('detalles')->first()->cantidad);
    }

    public function test_plato_que_no_pertenece_a_la_cuenta_se_rechaza(): void
    {
        $admin = $this->usuario();
        $e = $this->escenario();

        // La cuenta C existe en la venta pero no tiene asignada la línea.
        $respuesta = $this->actingAs($admin)->get('/pos/comanda-detalle/' . $e['detalleId'] . '?cuenta=' . $e['otraCuenta']);
        $respuesta->assertStatus(404);
    }

    public function test_comanda_completa_historica_y_por_cuenta(): void
    {
        $admin = $this->usuario();
        $e = $this->escenario();

        $historica = $this->actingAs($admin)->get('/pos/comanda-completa/' . $e['ventaId']);
        $historica->assertStatus(200);
        $this->assertSame(3, (int) $historica->viewData('detalles')->sum('cantidad'));

        $cuenta = $this->actingAs($admin)->get('/pos/comanda-completa/' . $e['ventaId'] . '?cuenta=' . $e['cuentaA']);
        $cuenta->assertStatus(200);
        $this->assertSame(1, (int) $cuenta->viewData('detalles')->sum('cantidad'));
    }

    public function test_impresion_masiva_historica_y_por_cuenta_no_duplica(): void
    {
        $admin = $this->usuario();
        $e = $this->escenario();

        $historica = $this->actingAs($admin)->get('/pos/comandas/' . $e['ventaId']);
        $historica->assertStatus(200);
        $totalHistorico = $historica->viewData('comandas')->sum(fn ($c) => $c->detalles->sum('cantidad'));
        $this->assertSame(3, (int) $totalHistorico);

        $cuenta = $this->actingAs($admin)->get('/pos/comandas/' . $e['ventaId'] . '?cuenta=' . $e['cuentaB']);
        $cuenta->assertStatus(200);
        $totalCuenta = $cuenta->viewData('comandas')->sum(fn ($c) => $c->detalles->sum('cantidad'));
        $this->assertSame(2, (int) $totalCuenta);
    }
}
