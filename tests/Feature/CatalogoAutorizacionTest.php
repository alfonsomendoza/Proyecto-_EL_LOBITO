<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/*
 * Pruebas aisladas de la autorización por rol del catálogo.
 *
 * IMPORTANTE:
 * - Corren en SQLite :memory: (configurado en phpunit.xml).
 * - El esquema se construye a mano porque los migrations reales usan SQL
 *   exclusivo de MySQL. Así ninguna prueba toca "sistemadecaja".
 * - setUp() falla a propósito si la conexión no es SQLite en memoria.
 *
 * Roles y permisos definitivos del catálogo:
 * - ADMIN  : consultar, crear y editar productos/categorías; desactivar.
 * - GERENTE: ídem.
 * - CAJERO : solo consultar.
 * - MESERO : solo consultar.
 * - No autenticado: conserva el comportamiento de autenticación (login).
 */
class CatalogoAutorizacionTest extends TestCase
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
        // Se eliminan primero por si una prueba reutiliza la conexión.
        foreach ([
            'productos',
            'categorias',
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

        // Refleja el esquema real del catálogo.
        Schema::create('categorias', function (Blueprint $t) {
            $t->increments('id');
            $t->string('nombre', 100);
            $t->boolean('activa')->default(true);
        });

        Schema::create('productos', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('categoria_id')->nullable();
            $t->string('nombre', 150);
            $t->decimal('precio', 10, 2)->default(0);
            $t->boolean('requiere_cocina')->default(false);
            $t->boolean('activo')->default(true);
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

    private function crearCategoria(string $nombre, bool $activa = true): int
    {
        return DB::table('categorias')->insertGetId([
            'nombre' => $nombre,
            'activa' => $activa ? 1 : 0,
        ]);
    }

    private function crearProducto(int $categoriaId, string $nombre, float $precio): int
    {
        return DB::table('productos')->insertGetId([
            'categoria_id' => $categoriaId,
            'nombre' => $nombre,
            'precio' => $precio,
            'requiere_cocina' => 0,
            'activo' => 1,
        ]);
    }

    /* ------------------------------------------------------------------
     | 1. Consulta del catálogo
     | ------------------------------------------------------------------ */

    public function test_los_cuatro_roles_pueden_consultar_el_catalogo(): void
    {
        foreach (['ADMIN', 'GERENTE', 'CAJERO', 'MESERO'] as $rol) {
            $this->actingAs($this->usuario($rol))
                ->get(route('catalogo.index'))
                ->assertStatus(200);
        }
    }

    /* ------------------------------------------------------------------
     | 2. Operaciones autorizadas (ADMIN y GERENTE)
     | ------------------------------------------------------------------ */

    public function test_admin_y_gerente_pueden_crear_categoria(): void
    {
        foreach (['ADMIN', 'GERENTE'] as $rol) {
            $this->actingAs($this->usuario($rol))
                ->post(route('catalogo.api'), [
                    'accion' => 'crear_categoria',
                    'nombre' => 'Postres',
                ])
                ->assertStatus(200)
                ->assertJson(['exito' => true]);
        }

        $this->assertDatabaseHas('categorias', ['nombre' => 'Postres', 'activa' => 1]);
    }

    public function test_admin_y_gerente_pueden_editar_categoria(): void
    {
        foreach (['ADMIN', 'GERENTE'] as $rol) {
            $this->actingAs($this->usuario($rol));

            $id = $this->crearCategoria('Bebidas');

            $this->post(route('catalogo.api'), [
                'accion' => 'editar_categoria',
                'id' => $id,
                'nombre' => 'Bebidas Frías',
            ])
                ->assertStatus(200)
                ->assertJson(['exito' => true]);

            $this->assertDatabaseHas('categorias', ['id' => $id, 'nombre' => 'Bebidas Frías']);
        }
    }

    public function test_admin_y_gerente_pueden_crear_producto(): void
    {
        foreach (['ADMIN', 'GERENTE'] as $rol) {
            $this->actingAs($this->usuario($rol));

            $categoriaId = $this->crearCategoria('Bebidas');

            $this->post(route('catalogo.api'), [
                'accion' => 'crear_producto',
                'categoria_id' => $categoriaId,
                'nombre' => 'Coca Cola',
                'precio' => 25,
                'requiere_cocina' => 0,
            ])
                ->assertStatus(200)
                ->assertJson(['exito' => true]);

            $this->assertDatabaseHas('productos', [
                'categoria_id' => $categoriaId,
                'nombre' => 'Coca Cola',
                'precio' => 25,
                'activo' => 1,
            ]);
        }
    }

    public function test_precio_con_formato_invalido_es_rechazado(): void
    {
        $this->actingAs($this->usuario('ADMIN'));
        $categoriaId = $this->crearCategoria('Bebidas');

        $invalidos = [
            'e',
            '0e',
            '0e13212312132121',
            '1e3',
            '10abc',
            '25aasasdasdasdasasd',
            '--10',
            '-5',
            '10.999',
            '10%%',
            '10000000000',
        ];

        foreach ($invalidos as $precio) {
            $this->post(route('catalogo.api'), [
                'accion' => 'crear_producto',
                'categoria_id' => $categoriaId,
                'nombre' => 'Producto inválido',
                'precio' => $precio,
                'requiere_cocina' => 0,
            ])->assertStatus(422);
        }

        $this->assertDatabaseCount('productos', 0);

        $this->post(route('catalogo.api'), [
            'accion' => 'crear_producto',
            'categoria_id' => $categoriaId,
            'nombre' => 'Producto válido',
            'precio' => '10.50',
            'requiere_cocina' => 0,
        ])->assertStatus(200)->assertJson(['exito' => true]);

        $this->assertDatabaseHas('productos', ['nombre' => 'Producto válido', 'precio' => 10.50]);
    }

    public function test_admin_y_gerente_pueden_editar_producto(): void
    {
        foreach (['ADMIN', 'GERENTE'] as $rol) {
            $this->actingAs($this->usuario($rol));

            $categoriaId = $this->crearCategoria('Bebidas');
            $id = $this->crearProducto($categoriaId, 'Coca Cola', 25);

            $this->post(route('catalogo.api'), [
                'accion' => 'editar_producto',
                'id' => $id,
                'categoria_id' => $categoriaId,
                'nombre' => 'Coca Cola Lata',
                'precio' => 30,
                'requiere_cocina' => 0,
            ])
                ->assertStatus(200)
                ->assertJson(['exito' => true]);

            // Se actualizó el registro existente y no se duplicó.
            $this->assertDatabaseHas('productos', [
                'id' => $id,
                'nombre' => 'Coca Cola Lata',
                'precio' => 30,
            ]);
            $this->assertSame(1, DB::table('productos')->where('id', $id)->count());
        }
    }

    public function test_admin_y_gerente_pueden_desactivar_producto(): void
    {
        foreach (['ADMIN', 'GERENTE'] as $rol) {
            $this->actingAs($this->usuario($rol));

            $categoriaId = $this->crearCategoria('Bebidas');
            $id = $this->crearProducto($categoriaId, 'Coca Cola', 25);

            $this->post(route('catalogo.api'), [
                'accion' => 'eliminar_producto',
                'id' => $id,
            ])
                ->assertStatus(200)
                ->assertJson(['exito' => true]);

            $this->assertDatabaseHas('productos', ['id' => $id, 'activo' => 0]);
        }
    }

    /* ------------------------------------------------------------------
     | 3. Operaciones denegadas (CAJERO y MESERO) — sin cambios en BD
     | ------------------------------------------------------------------ */

    public function test_cajero_y_mesero_no_pueden_crear_categoria(): void
    {
        foreach (['CAJERO', 'MESERO'] as $rol) {
            $this->actingAs($this->usuario($rol))
                ->post(route('catalogo.api'), [
                    'accion' => 'crear_categoria',
                    'nombre' => 'Postres',
                ])
                ->assertStatus(403)
                ->assertJson(['exito' => false]);
        }

        $this->assertDatabaseCount('categorias', 0);
    }

    public function test_cajero_y_mesero_no_pueden_editar_categoria(): void
    {
        foreach (['CAJERO', 'MESERO'] as $rol) {
            $this->actingAs($this->usuario($rol));

            $id = $this->crearCategoria('Bebidas');

            $this->post(route('catalogo.api'), [
                'accion' => 'editar_categoria',
                'id' => $id,
                'nombre' => 'Cambiado',
            ])
                ->assertStatus(403)
                ->assertJson(['exito' => false]);

            $this->assertDatabaseHas('categorias', ['id' => $id, 'nombre' => 'Bebidas']);
        }
    }

    public function test_cajero_y_mesero_no_pueden_crear_producto(): void
    {
        foreach (['CAJERO', 'MESERO'] as $rol) {
            $this->actingAs($this->usuario($rol));

            $categoriaId = $this->crearCategoria('Bebidas');

            $this->post(route('catalogo.api'), [
                'accion' => 'crear_producto',
                'categoria_id' => $categoriaId,
                'nombre' => 'Coca Cola',
                'precio' => 25,
                'requiere_cocina' => 0,
            ])
                ->assertStatus(403)
                ->assertJson(['exito' => false]);
        }

        $this->assertDatabaseCount('productos', 0);
    }

    public function test_cajero_y_mesero_no_pueden_editar_producto(): void
    {
        foreach (['CAJERO', 'MESERO'] as $rol) {
            $this->actingAs($this->usuario($rol));

            $categoriaId = $this->crearCategoria('Bebidas');
            $id = $this->crearProducto($categoriaId, 'Coca Cola', 25);

            $this->post(route('catalogo.api'), [
                'accion' => 'editar_producto',
                'id' => $id,
                'categoria_id' => $categoriaId,
                'nombre' => 'Cambiado',
                'precio' => 99,
                'requiere_cocina' => 0,
            ])
                ->assertStatus(403)
                ->assertJson(['exito' => false]);

            $this->assertDatabaseHas('productos', [
                'id' => $id,
                'nombre' => 'Coca Cola',
                'precio' => 25,
            ]);
        }
    }

    public function test_cajero_y_mesero_no_pueden_desactivar_producto(): void
    {
        foreach (['CAJERO', 'MESERO'] as $rol) {
            $this->actingAs($this->usuario($rol));

            $categoriaId = $this->crearCategoria('Bebidas');
            $id = $this->crearProducto($categoriaId, 'Coca Cola', 25);

            $this->post(route('catalogo.api'), [
                'accion' => 'eliminar_producto',
                'id' => $id,
            ])
                ->assertStatus(403)
                ->assertJson(['exito' => false]);

            $this->assertDatabaseHas('productos', ['id' => $id, 'activo' => 1]);
        }
    }

    /* ------------------------------------------------------------------
     | 4. Comportamiento de usuarios no autenticados
     | ------------------------------------------------------------------ */

    public function test_acceso_no_autenticado_conserva_redireccion_a_login(): void
    {
        $this->get(route('catalogo.index'))->assertRedirect(route('login'));

        $this->post(route('catalogo.api'), [
            'accion' => 'crear_categoria',
            'nombre' => 'Postres',
        ])->assertRedirect(route('login'));

        $this->assertDatabaseCount('categorias', 0);
        $this->assertDatabaseCount('productos', 0);
    }
}