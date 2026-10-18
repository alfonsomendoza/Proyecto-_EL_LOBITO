<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/*
 * Ciclo de vida de PRODUCTOS: eliminación lógica, listado de eliminados,
 * reactivación y autorización en el backend.
 *
 * IMPORTANTE:
 * - Corren en SQLite :memory: (configurado en phpunit.xml).
 * - El esquema se construye a mano porque las migraciones reales usan SQL de
 *   MySQL. Ninguna prueba toca "sistemadecaja".
 * - setUp() falla a propósito si la conexión no es SQLite en memoria.
 */
class CatalogoCicloVidaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Candado de seguridad: nunca apuntar a la base MySQL real.
        $this->assertSame('sqlite', DB::connection()->getDriverName(), 'Las pruebas deben ejecutarse en SQLite.');
        $this->assertSame(':memory:', (string) config('database.connections.sqlite.database'), 'La base de pruebas debe ser SQLite en memoria.');

        $this->crearEsquema();
    }

    private function crearEsquema(): void
    {
        foreach ([
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

        Schema::create('categorias', function (Blueprint $t) {
            $t->increments('id');
            $t->string('nombre', 100);
            $t->boolean('activa')->default(true);
        });

        // Refleja el esquema real de productos.
        Schema::create('productos', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('categoria_id')->nullable();
            $t->string('nombre', 100);
            $t->decimal('precio', 12, 2)->default(0);
            $t->boolean('requiere_cocina')->default(true);
            $t->boolean('controla_stock')->default(false);
            $t->boolean('activo')->default(true);
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
            // Módulo de cocina: observación por línea y estado de preparación.
            // Se recrean aquí porque los tests usan SQLite en memoria.
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
    }

    private function usuario(string $rolNombre): User
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

    private function categoria(string $nombre = 'Bebidas'): int
    {
        return DB::table('categorias')->insertGetId(['nombre' => $nombre, 'activa' => 1]);
    }

    private function producto(int $categoriaId, string $nombre, float $precio, bool $activo = true): int
    {
        return DB::table('productos')->insertGetId([
            'categoria_id' => $categoriaId,
            'nombre' => $nombre,
            'precio' => $precio,
            'requiere_cocina' => 0,
            'controla_stock' => 0,
            'activo' => $activo ? 1 : 0,
        ]);
    }

    /* ------------------------------------------------------------------
     | 1. Catálogo normal excluye eliminados
     | ------------------------------------------------------------------ */

    public function test_catalogo_normal_excluye_productos_eliminados(): void
    {
        $admin = $this->usuario('ADMIN');
        $cat = $this->categoria();
        $this->producto($cat, 'Activo', 10, true);
        $this->producto($cat, 'Eliminado', 20, false);

        $this->actingAs($admin)
            ->get(route('catalogo.index'))
            ->assertStatus(200)
            ->assertViewHas('productos', fn ($p) => $p->count() === 1 && $p->first()->nombre === 'Activo')
            ->assertViewHas('productosEliminados', fn ($p) => $p->count() === 1 && $p->first()->nombre === 'Eliminado');
    }

    /* ------------------------------------------------------------------
     | 2. Eliminación lógica
     | ------------------------------------------------------------------ */

    public function test_eliminar_marca_activo_cero_sin_borrar_la_fila(): void
    {
        $admin = $this->usuario('ADMIN');
        $cat = $this->categoria();
        $id = $this->producto($cat, 'Coca Cola', 25);

        $this->actingAs($admin)
            ->post(route('catalogo.api'), ['accion' => 'eliminar_producto', 'id' => $id])
            ->assertStatus(200)
            ->assertJson(['exito' => true]);

        // La fila sigue existiendo y conserva su ID y categoría.
        $this->assertSame(1, DB::table('productos')->where('id', $id)->count());
        $this->assertDatabaseHas('productos', ['id' => $id, 'activo' => 0, 'categoria_id' => $cat]);
    }

    public function test_eliminar_producto_inexistente_responde_404(): void
    {
        $admin = $this->usuario('ADMIN');

        $this->actingAs($admin)
            ->post(route('catalogo.api'), ['accion' => 'eliminar_producto', 'id' => 99999])
            ->assertStatus(404)
            ->assertJson(['exito' => false]);
    }

    /* ------------------------------------------------------------------
     | 3. Reactivación
     | ------------------------------------------------------------------ */

    public function test_reactivar_recupera_el_mismo_id_y_categoria(): void
    {
        $admin = $this->usuario('ADMIN');
        $cat = $this->categoria();
        $id = $this->producto($cat, 'Coca Cola', 25, false);

        $this->actingAs($admin)
            ->post(route('catalogo.api'), ['accion' => 'reactivar_producto', 'id' => $id])
            ->assertStatus(200)
            ->assertJson(['exito' => true]);

        $this->assertSame(1, DB::table('productos')->where('id', $id)->count());
        $this->assertDatabaseHas('productos', [
            'id' => $id,
            'activo' => 1,
            'categoria_id' => $cat,
            'nombre' => 'Coca Cola',
        ]);
    }

    public function test_reactivar_producto_inexistente_responde_404(): void
    {
        $admin = $this->usuario('ADMIN');

        $this->actingAs($admin)
            ->post(route('catalogo.api'), ['accion' => 'reactivar_producto', 'id' => 99999])
            ->assertStatus(404)
            ->assertJson(['exito' => false]);
    }

    /* ------------------------------------------------------------------
     | 4. Editar no reactiva
     | ------------------------------------------------------------------ */

    public function test_editar_producto_no_reactiva_un_eliminado(): void
    {
        $admin = $this->usuario('ADMIN');
        $cat = $this->categoria();
        $id = $this->producto($cat, 'Coca Cola', 25, false);

        $this->actingAs($admin)
            ->post(route('catalogo.api'), [
                'accion' => 'editar_producto',
                'id' => $id,
                'categoria_id' => $cat,
                'nombre' => 'Coca Cola Lata',
                'precio' => 30,
                'requiere_cocina' => 0,
            ])
            ->assertStatus(200)
            ->assertJson(['exito' => true]);

        // Se editó el nombre y el precio, pero el producto SIGUE eliminado.
        $this->assertDatabaseHas('productos', [
            'id' => $id,
            'nombre' => 'Coca Cola Lata',
            'precio' => 30,
            'activo' => 0,
        ]);
    }

    public function test_crear_producto_nace_activo(): void
    {
        $admin = $this->usuario('ADMIN');
        $cat = $this->categoria();

        $this->actingAs($admin)
            ->post(route('catalogo.api'), [
                'accion' => 'crear_producto',
                'categoria_id' => $cat,
                'nombre' => 'Coca Cola',
                'precio' => 25,
                'requiere_cocina' => 0,
            ])
            ->assertStatus(200)
            ->assertJson(['exito' => true]);

        $this->assertDatabaseHas('productos', ['nombre' => 'Coca Cola', 'activo' => 1]);
    }

    /* ------------------------------------------------------------------
     | 5. Autorización (matriz)
     | ------------------------------------------------------------------ */

    public function test_admin_y_gerente_pueden_eliminar_y_reactivar(): void
    {
        foreach (['ADMIN', 'GERENTE'] as $rol) {
            $this->actingAs($this->usuario($rol));
            $cat = $this->categoria('Bebidas ' . $rol);
            $id = $this->producto($cat, 'Producto ' . $rol, 25);

            $this->post(route('catalogo.api'), ['accion' => 'eliminar_producto', 'id' => $id])
                ->assertStatus(200)->assertJson(['exito' => true]);
            $this->assertDatabaseHas('productos', ['id' => $id, 'activo' => 0]);

            $this->post(route('catalogo.api'), ['accion' => 'reactivar_producto', 'id' => $id])
                ->assertStatus(200)->assertJson(['exito' => true]);
            $this->assertDatabaseHas('productos', ['id' => $id, 'activo' => 1]);
        }
    }

    public function test_cajero_mesero_y_cocinero_no_pueden_eliminar_ni_reactivar(): void
    {
        foreach (['CAJERO', 'MESERO', 'COCINERO'] as $rol) {
            $this->actingAs($this->usuario($rol));
            $cat = $this->categoria('Cat ' . $rol);
            $activo = $this->producto($cat, 'Activo ' . $rol, 10, true);
            $eliminado = $this->producto($cat, 'Eliminado ' . $rol, 10, false);

            // Eliminar -> 403 y el producto sigue activo.
            $this->post(route('catalogo.api'), ['accion' => 'eliminar_producto', 'id' => $activo])
                ->assertStatus(403)
                ->assertJson(['exito' => false]);
            $this->assertDatabaseHas('productos', ['id' => $activo, 'activo' => 1]);

            // Reactivar -> 403 y el producto sigue eliminado.
            $this->post(route('catalogo.api'), ['accion' => 'reactivar_producto', 'id' => $eliminado])
                ->assertStatus(403)
                ->assertJson(['exito' => false]);
            $this->assertDatabaseHas('productos', ['id' => $eliminado, 'activo' => 0]);
        }
    }

    /* ------------------------------------------------------------------
     | 6. Punto de venta: un producto eliminado no se puede vender
     | ------------------------------------------------------------------ */

    public function test_producto_eliminado_no_puede_agregarse_a_una_venta(): void
    {
        $admin = $this->usuario('ADMIN');
        $cat = $this->categoria();
        $productoId = $this->producto($cat, 'Coca Cola', 25, false); // eliminado

        $ventaId = DB::table('ventas')->insertGetId([
            'mesa_id' => null, 'cliente_id' => null, 'apertura_id' => null,
            'descripcion' => 'Consumo', 'monto' => 0, 'fecha' => now(),
            'estado' => 'ACTIVA', 'usuario_id' => null, 'descuento_porcentaje' => 0,
        ]);
        $cuentaId = DB::table('cuentas')->insertGetId([
            'venta_id' => $ventaId, 'nombre' => 'Cuenta Principal', 'total' => 0,
            'estado' => 'ABIERTA', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($admin)
            ->postJson('/pos/api', [
                'accion' => 'enviar_cocina',
                'venta_id' => $ventaId,
                'cuenta_id' => $cuentaId,
                'carrito' => json_encode([['id' => $productoId, 'cantidad' => 1]]),
            ])
            ->assertStatus(422)
            ->assertJson(['exito' => false]);

        // No se creó ningún consumo.
        $this->assertSame(0, DB::table('venta_detalles')->count());
    }

    public function test_producto_activo_si_puede_agregarse_a_una_venta(): void
    {
        $admin = $this->usuario('ADMIN');
        $cat = $this->categoria();
        $productoId = $this->producto($cat, 'Coca Cola', 25, true);

        $ventaId = DB::table('ventas')->insertGetId([
            'mesa_id' => null, 'cliente_id' => null, 'apertura_id' => null,
            'descripcion' => 'Consumo', 'monto' => 0, 'fecha' => now(),
            'estado' => 'ACTIVA', 'usuario_id' => null, 'descuento_porcentaje' => 0,
        ]);
        $cuentaId = DB::table('cuentas')->insertGetId([
            'venta_id' => $ventaId, 'nombre' => 'Cuenta Principal', 'total' => 0,
            'estado' => 'ABIERTA', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($admin)
            ->postJson('/pos/api', [
                'accion' => 'enviar_cocina',
                'venta_id' => $ventaId,
                'cuenta_id' => $cuentaId,
                'carrito' => json_encode([['id' => $productoId, 'cantidad' => 1]]),
            ])
            ->assertStatus(200)
            ->assertJson(['exito' => true]);

        $this->assertSame(1, DB::table('venta_detalles')->count());
    }
}
