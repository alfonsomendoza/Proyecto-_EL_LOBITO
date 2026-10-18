<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class InventarioCategoriaFiltroTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', (string) config('database.connections.sqlite.database'));

        $this->crearEsquema();

        DB::table('mesas')->insert(['id' => 1, 'numero' => '1', 'estado' => 'LIBRE']);
        DB::table('roles')->insert(['id' => 1, 'nombre' => 'ADMIN']);
        DB::table('usuarios')->insert([
            'id' => 1,
            'nombre' => 'Admin',
            'username' => 'admin',
            'password_hash' => bcrypt('123'),
            'rol_id' => 1,
        ]);
    }

    private function crearEsquema(): void
    {
        foreach ([
            'movimientos_inventario',
            'inventario_producto',
            'anulaciones',
            'cuenta_detalles',
            'cuentas',
            'venta_detalles',
            'ventas',
            'productos',
            'categorias',
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
            $t->string('estado', 20)->nullable();
            $t->integer('venta_activa_id')->nullable();
        });
        Schema::create('categorias', function (Blueprint $t) {
            $t->increments('id');
            $t->string('nombre', 100);
            $t->tinyInteger('activa')->default(1);
        });
        Schema::create('productos', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('categoria_id')->nullable();
            $t->string('nombre', 100);
            $t->decimal('precio', 10, 2);
            $t->tinyInteger('requiere_cocina')->default(0);
            $t->tinyInteger('controla_stock')->default(0);
            $t->tinyInteger('activo')->default(1);
        });
        Schema::create('ventas', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('mesa_id')->nullable();
            $t->decimal('monto', 10, 2);
            $t->string('estado', 15);
        });
        Schema::create('venta_detalles', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('venta_id')->nullable();
            $t->integer('producto_id')->nullable();
            $t->integer('cantidad')->nullable();
            $t->decimal('precio_unitario', 10, 2)->nullable();
            $t->decimal('subtotal', 10, 2)->nullable();
            // Módulo de cocina: observación por línea y estado de preparación.
            $t->text('notas')->nullable();
            $t->string('estado_preparacion', 20)->nullable();
        });
        Schema::create('cuentas', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('venta_id')->nullable();
            $t->integer('mesa_id')->nullable();
            $t->string('nombre', 50)->nullable();
            $t->decimal('total', 10, 2)->default(0);
            $t->string('estado', 15)->nullable();
            $t->integer('cuenta_origen_id')->nullable();
        });
        Schema::create('cuenta_detalles', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('venta_id')->nullable();
            $t->integer('cuenta_id')->nullable();
            $t->integer('venta_detalle_id')->nullable();
            $t->integer('producto_id')->nullable();
            $t->decimal('precio', 10, 2)->nullable();
            $t->decimal('cantidad', 10, 3)->nullable();
            $t->tinyInteger('borrado_logico')->default(0);
        });
        Schema::create('anulaciones', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('venta_detalle_id')->nullable();
            $t->integer('usuario_id')->nullable();
            $t->decimal('cantidad', 10, 3)->nullable();
            $t->timestamp('creado_en')->nullable();
        });
        Schema::create('inventario_producto', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('producto_id');
            $t->decimal('stock_actual', 12, 3)->default(0);
            $t->decimal('stock_minimo', 12, 3)->default(0);
            $t->string('unidad', 20)->default('unidad');
            $t->timestamps();
        });
        Schema::create('movimientos_inventario', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('producto_id');
            $t->string('tipo', 30);
            $t->decimal('cantidad', 12, 3);
            $t->string('origen_tipo', 30)->nullable();
            $t->integer('origen_id')->nullable();
            $t->integer('usuario_id')->nullable();
            $t->string('observacion', 500)->nullable();
            $t->timestamp('creado_en')->useCurrent();
        });
    }

    private function admin()
    {
        return User::find(1);
    }

    public function test_muestra_categorias_en_filtro()
    {
        DB::table('categorias')->insert(['id' => 1, 'nombre' => 'BEBIDAS']);
        DB::table('categorias')->insert(['id' => 2, 'nombre' => 'COMIDA']);
        DB::table('productos')->insert(['id' => 1, 'categoria_id' => 1, 'nombre' => 'COCA', 'precio' => 10, 'requiere_cocina' => 0, 'controla_stock' => 0, 'activo' => 1]);

        $response = $this->actingAs($this->admin())->get('/inventario');
        $response->assertStatus(200);
        $response->assertSee('BEBIDAS');
        $response->assertSee('COMIDA');
        $response->assertSee('Todas las categorías');
    }

    public function test_filtrar_por_categoria_restringe_productos()
    {
        DB::table('categorias')->insert(['id' => 1, 'nombre' => 'BEBIDAS']);
        DB::table('categorias')->insert(['id' => 2, 'nombre' => 'COMIDA']);
        DB::table('productos')->insert(['id' => 1, 'categoria_id' => 1, 'nombre' => 'COCA', 'precio' => 10, 'requiere_cocina' => 0, 'controla_stock' => 0, 'activo' => 1]);
        DB::table('productos')->insert(['id' => 2, 'categoria_id' => 2, 'nombre' => 'PIZZA', 'precio' => 15, 'requiere_cocina' => 1, 'controla_stock' => 0, 'activo' => 1]);

        $response = $this->actingAs($this->admin())->get('/inventario?categoria_id=2');
        $response->assertStatus(200);
        $response->assertSee('PIZZA');
        $response->assertDontSee('COCA');
    }

    public function test_busqueda_funciona_con_categoria()
    {
        DB::table('categorias')->insert(['id' => 1, 'nombre' => 'CERVEZAS']);
        DB::table('categorias')->insert(['id' => 2, 'nombre' => 'INTERNACIONALES']);
        DB::table('productos')->insert(['id' => 1, 'categoria_id' => 2, 'nombre' => 'MILLER', 'precio' => 10, 'requiere_cocina' => 0, 'controla_stock' => 0, 'activo' => 1]);
        DB::table('productos')->insert(['id' => 2, 'categoria_id' => 2, 'nombre' => 'SOL', 'precio' => 10, 'requiere_cocina' => 0, 'controla_stock' => 0, 'activo' => 1]);

        $response = $this->actingAs($this->admin())->get('/inventario?categoria_id=2&q=SOL');
        $response->assertStatus(200);
        $response->assertSee('SOL');
        $response->assertDontSee('MILLER');
    }

    public function test_limpiar_filtros_muestra_todo()
    {
        DB::table('categorias')->insert(['id' => 1, 'nombre' => 'BEBIDAS']);
        DB::table('productos')->insert(['id' => 1, 'categoria_id' => 1, 'nombre' => 'COCA', 'precio' => 10, 'requiere_cocina' => 0, 'controla_stock' => 0, 'activo' => 1]);

        $response = $this->actingAs($this->admin())->get('/inventario?categoria_id=1&q=COCA');
        $response->assertStatus(200);
        $response->assertSee('Limpiar filtros');
    }

    public function test_busqueda_sin_categoria()
    {
        DB::table('categorias')->insert(['id' => 1, 'nombre' => 'BEBIDAS']);
        DB::table('productos')->insert(['id' => 1, 'categoria_id' => 1, 'nombre' => 'COCA', 'precio' => 10, 'requiere_cocina' => 0, 'controla_stock' => 0, 'activo' => 1]);

        $response = $this->actingAs($this->admin())->get('/inventario?q=COCA');
        $response->assertStatus(200);
        $response->assertSee('COCA');
    }
}
