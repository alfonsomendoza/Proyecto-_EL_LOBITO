<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/*
 * Ciclo de vida de CLIENTES: eliminación lógica, listado de eliminados,
 * reactivación, protección del Cliente General (ID 1) y autorización.
 *
 * IMPORTANTE:
 * - Corren en SQLite :memory: (configurado en phpunit.xml).
 * - El esquema se construye a mano; ninguna prueba toca "sistemadecaja".
 * - setUp() falla a propósito si la conexión no es SQLite en memoria.
 */
class ClienteCicloVidaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('sqlite', DB::connection()->getDriverName(), 'Las pruebas deben ejecutarse en SQLite.');
        $this->assertSame(':memory:', (string) config('database.connections.sqlite.database'), 'La base de pruebas debe ser SQLite en memoria.');

        $this->crearEsquema();
    }

    private function crearEsquema(): void
    {
        foreach (['clientes', 'usuarios', 'roles'] as $tabla) {
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

        // Espejo del esquema real de clientes (sin UNIQUE más allá de la PK).
        Schema::create('clientes', function (Blueprint $t) {
            $t->increments('id');
            $t->string('nombre', 150);
            $t->string('telefono', 20)->nullable();
            $t->string('identificacion', 50)->nullable();
            $t->text('direccion')->nullable();
            $t->boolean('activo')->nullable()->default(true);
            $t->string('identificacion_normalizada', 50)->nullable();
            $t->string('telefono_normalizado', 30)->nullable();
            $t->timestamp('creado_en')->nullable();
        });

        // Índice único de identificación normalizada: solo en MySQL real.
        // En SQLite aislado no se crea para no afectar las pruebas aisladas.
        // Schema::table('clientes', fn ($t) => $t->unique('identificacion_normalizada'));

        // Cliente General (ID 1): en producción ya existe y está protegido.
        // El esquema manual de SQLite no ejecuta migraciones/seeders, así que
        // la prueba debe recrearlo para validar el listado real.
        DB::table('clientes')->insert([
            'id' => 1,
            'nombre' => 'Cliente General',
            'activo' => 1,
            'creado_en' => now(),
        ]);
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

    private function cliente(string $nombre, bool $activo = true, int $id = 0): int
    {
        // Los clientes "normales" usan ids >= 100 para no chocar con el
        // Cliente General (ID 1), que en la base real ya existe y está protegido.
        if ($id <= 0) {
            $id = max(100, ((int) DB::table('clientes')->max('id')) + 1);
        }

        DB::table('clientes')->insert([
            'id' => $id,
            'nombre' => $nombre,
            'telefono' => '8888-0000',
            'identificacion' => '000-000000-0000X',
            'identificacion_normalizada' => null,
            'telefono_normalizado' => null,
            'direccion' => 'Barrio Central',
            'activo' => $activo ? 1 : 0,
            'creado_en' => now(),
        ]);

        return $id;
    }

    /* ------------------------------------------------------------------
     | 1. Listado normal excluye eliminados
     | ------------------------------------------------------------------ */

    public function test_listado_normal_excluye_clientes_eliminados(): void
    {
        $admin = $this->usuario('ADMIN');
        $this->assertDatabaseHas('clientes', ['id' => 1, 'nombre' => 'Cliente General']);
        $this->cliente('Activo', true);
        $this->cliente('Eliminado', false);

        $this->actingAs($admin)
            ->get(route('clientes.index'))
            ->assertStatus(200)
            // El listado normal incluye el Cliente General (fila de sistema,
            // ID 1) y los activos, pero nunca los eliminados.
            ->assertViewHas('clientes', fn ($c) => $c->count() === 2
                && $c->contains('nombre', 'Cliente General')
                && $c->contains('nombre', 'Activo')
                && !$c->contains('nombre', 'Eliminado'))
            ->assertViewHas('clientesEliminados', fn ($c) => $c->count() === 1 && $c->first()->nombre === 'Eliminado');
    }

    /* ------------------------------------------------------------------
     | 2. Eliminación lógica
     | ------------------------------------------------------------------ */

    public function test_eliminar_marca_activo_cero_sin_borrar_la_fila(): void
    {
        $admin = $this->usuario('ADMIN');
        $id = $this->cliente('Juan Pérez', true);

        $this->actingAs($admin)
            ->post(route('clientes.api'), ['accion' => 'eliminar', 'id' => $id])
            ->assertStatus(200)
            ->assertJson(['exito' => true]);

        $this->assertSame(1, DB::table('clientes')->where('id', $id)->count());
        $this->assertDatabaseHas('clientes', ['id' => $id, 'activo' => 0]);
    }

    public function test_eliminar_cliente_inexistente_responde_404(): void
    {
        $admin = $this->usuario('ADMIN');

        $this->actingAs($admin)
            ->post(route('clientes.api'), ['accion' => 'eliminar', 'id' => 99999])
            ->assertStatus(404)
            ->assertJson(['exito' => false]);
    }

    /* ------------------------------------------------------------------
     | 3. Reactivación (mismo registro, sin duplicar)
     | ------------------------------------------------------------------ */

    public function test_reactivar_conserva_el_mismo_id_y_no_duplica(): void
    {
        $admin = $this->usuario('ADMIN');
        $id = $this->cliente('Juan Pérez', false);

        $totalAntes = DB::table('clientes')->count();

        $this->actingAs($admin)
            ->post(route('clientes.api'), ['accion' => 'reactivar', 'id' => $id])
            ->assertStatus(200)
            ->assertJson(['exito' => true]);

        $this->assertDatabaseHas('clientes', ['id' => $id, 'activo' => 1, 'nombre' => 'Juan Pérez']);
        // No se creó ningún cliente nuevo.
        $this->assertSame($totalAntes, DB::table('clientes')->count());
    }

    public function test_reactivar_cliente_inexistente_responde_404(): void
    {
        $admin = $this->usuario('ADMIN');

        $this->actingAs($admin)
            ->post(route('clientes.api'), ['accion' => 'reactivar', 'id' => 99999])
            ->assertStatus(404)
            ->assertJson(['exito' => false]);
    }

    /* ------------------------------------------------------------------
     | 4. Editar no reactiva
     | ------------------------------------------------------------------ */

    public function test_editar_no_reactiva_un_cliente_eliminado(): void
    {
        $admin = $this->usuario('ADMIN');
        $id = $this->cliente('Juan Pérez', false);

        $this->actingAs($admin)
            ->post(route('clientes.api'), [
                'accion' => 'editar',
                'id' => $id,
                'nombre' => 'Juan Pérez Actualizado',
                'telefono' => '9999-9999',
                'identificacion' => '111-111111-1111X',
                'direccion' => 'Otra dirección',
            ])
            ->assertStatus(200)
            ->assertJson(['exito' => true]);

        $this->assertDatabaseHas('clientes', [
            'id' => $id,
            'nombre' => 'Juan Pérez Actualizado',
            'activo' => 0,
        ]);
    }

    public function test_crear_cliente_nace_activo(): void
    {
        $admin = $this->usuario('ADMIN');

        $this->actingAs($admin)
            ->post(route('clientes.api'), [
                'accion' => 'crear',
                'nombre' => 'Nuevo Cliente',
                'telefono' => '7777-7777',
                'identificacion' => '222-222222-2222X',
                'direccion' => 'Centro',
            ])
            ->assertStatus(200)
            ->assertJson(['exito' => true]);

        $this->assertDatabaseHas('clientes', ['nombre' => 'Nuevo Cliente', 'activo' => 1]);
    }

    /* ------------------------------------------------------------------
     | 5. Cliente General (ID 1) protegido en backend
     | ------------------------------------------------------------------ */

    public function test_cliente_general_no_puede_eliminarse_por_peticion_directa(): void
    {
        $admin = $this->usuario('ADMIN');

        $this->actingAs($admin)
            ->post(route('clientes.api'), ['accion' => 'eliminar', 'id' => 1])
            ->assertStatus(403)
            ->assertJson(['exito' => false]);

        // No cambió su estado.
        $this->assertDatabaseHas('clientes', ['id' => 1, 'activo' => 1]);
    }

    public function test_cliente_general_no_puede_editarse_por_peticion_directa(): void
    {
        $admin = $this->usuario('ADMIN');

        $this->actingAs($admin)
            ->post(route('clientes.api'), [
                'accion' => 'editar',
                'id' => 1,
                'nombre' => 'Hackeado',
            ])
            ->assertStatus(403)
            ->assertJson(['exito' => false]);

        $this->assertDatabaseHas('clientes', ['id' => 1, 'nombre' => 'Cliente General']);
    }

    /* ------------------------------------------------------------------
     | 6. Autorización (matriz)
     | ------------------------------------------------------------------ */

    public function test_admin_y_gerente_pueden_eliminar_y_reactivar(): void
    {
        foreach (['ADMIN', 'GERENTE'] as $rol) {
            $this->actingAs($this->usuario($rol));
            $id = $this->cliente('Cliente ' . $rol, true);

            $this->post(route('clientes.api'), ['accion' => 'eliminar', 'id' => $id])
                ->assertStatus(200)->assertJson(['exito' => true]);
            $this->assertDatabaseHas('clientes', ['id' => $id, 'activo' => 0]);

            $this->post(route('clientes.api'), ['accion' => 'reactivar', 'id' => $id])
                ->assertStatus(200)->assertJson(['exito' => true]);
            $this->assertDatabaseHas('clientes', ['id' => $id, 'activo' => 1]);
        }
    }

    public function test_cajero_mesero_y_cocinero_no_pueden_eliminar_ni_reactivar(): void
    {
        foreach (['CAJERO', 'MESERO', 'COCINERO'] as $rol) {
            $this->actingAs($this->usuario($rol));
            $activo = $this->cliente('Activo ' . $rol, true);
            $eliminado = $this->cliente('Eliminado ' . $rol, false);

            $this->post(route('clientes.api'), ['accion' => 'eliminar', 'id' => $activo])
                ->assertStatus(403)->assertJson(['exito' => false]);
            $this->assertDatabaseHas('clientes', ['id' => $activo, 'activo' => 1]);

            $this->post(route('clientes.api'), ['accion' => 'reactivar', 'id' => $eliminado])
                ->assertStatus(403)->assertJson(['exito' => false]);
            $this->assertDatabaseHas('clientes', ['id' => $eliminado, 'activo' => 0]);
        }
    }

    public function test_se_conservan_los_permisos_de_creacion_y_edicion(): void
    {
        // CAJERO puede crear y editar.
        $this->actingAs($this->usuario('CAJERO'));
        $id = $this->cliente('Cliente Cajero', true);
        $this->post(route('clientes.api'), ['accion' => 'editar', 'id' => $id, 'nombre' => 'Editado por Cajero'])
            ->assertStatus(200)->assertJson(['exito' => true]);
        $this->post(route('clientes.api'), ['accion' => 'crear', 'nombre' => 'Creado por Cajero'])
            ->assertStatus(200)->assertJson(['exito' => true]);

        // MESERO puede crear (permiso existente) pero no editar ni eliminar.
        $this->actingAs($this->usuario('MESERO'));
        $this->post(route('clientes.api'), ['accion' => 'crear', 'nombre' => 'Creado por Mesero'])
            ->assertStatus(200)->assertJson(['exito' => true]);
        $id2 = $this->cliente('Cliente Mesero', true);
        $this->post(route('clientes.api'), ['accion' => 'editar', 'id' => $id2, 'nombre' => 'No permitido'])
            ->assertStatus(403)->assertJson(['exito' => false]);

        // COCINERO no puede crear, editar ni eliminar.
        $this->actingAs($this->usuario('COCINERO'));
        $this->post(route('clientes.api'), ['accion' => 'crear', 'nombre' => 'Creado por Cocinero'])
            ->assertStatus(403)->assertJson(['exito' => false]);
        $id3 = $this->cliente('Cliente Cocinero', true);
        $this->post(route('clientes.api'), ['accion' => 'editar', 'id' => $id3, 'nombre' => 'No permitido'])
            ->assertStatus(403)->assertJson(['exito' => false]);
    }

    /* ------------------------------------------------------------------
     | 7. Búsqueda AJAX (nombre, teléfono e identificación)
     | ------------------------------------------------------------------ */

    public function test_busqueda_encuentra_por_nombre_telefono_e_identificacion(): void
    {
        $cajero = $this->usuario('CAJERO');

        DB::table('clientes')->insert([
            [
                'id' => 101,
                'nombre' => 'María López',
                'telefono' => '6123-4567',
                'identificacion' => '8-888-8888',
                'identificacion_normalizada' => '88888888',
                'telefono_normalizado' => '61234567',
                'direccion' => 'Barrio Norte',
                'activo' => 1,
                'creado_en' => now(),
            ],
            [
                'id' => 102,
                'nombre' => 'Pedro Gómez',
                'telefono' => '6999-1111',
                'identificacion' => '9-999-9999',
                'identificacion_normalizada' => '99999999',
                'telefono_normalizado' => '69991111',
                'direccion' => 'Barrio Sur',
                'activo' => 1,
                'creado_en' => now(),
            ],
        ]);

        $this->actingAs($cajero);

        // Por nombre, sin acentos y sin distinguir mayúsculas.
        $this->post(route('clientes.api'), ['accion' => 'search', 'q' => 'maria lopez'])
            ->assertStatus(200)
            ->assertJsonPath('exito', true)
            ->assertJsonFragment(['nombre' => 'María López'])
            ->assertJsonMissing(['nombre' => 'Pedro Gómez']);

        // Por teléfono escrito sin guiones.
        $this->post(route('clientes.api'), ['accion' => 'search', 'q' => '61234567'])
            ->assertStatus(200)
            ->assertJsonFragment(['nombre' => 'María López'])
            ->assertJsonMissing(['nombre' => 'Pedro Gómez']);

        // Por identificación escrita sin guiones.
        $this->post(route('clientes.api'), ['accion' => 'search', 'q' => '99999999'])
            ->assertStatus(200)
            ->assertJsonFragment(['nombre' => 'Pedro Gómez'])
            ->assertJsonMissing(['nombre' => 'María López']);
    }

    /* ------------------------------------------------------------------
     | 8. Edición: el propio cliente no se considera duplicado
     | ------------------------------------------------------------------ */

    public function test_cajero_puede_editar_conservando_su_identificacion_y_nombre(): void
    {
        DB::table('clientes')->insert([
            'id' => 110,
            'nombre' => 'Ana Torres',
            'telefono' => '6111-2222',
            'identificacion' => '7-777-7777',
            'identificacion_normalizada' => '77777777',
            'telefono_normalizado' => '61112222',
            'direccion' => 'Calle 1',
            'activo' => 1,
            'creado_en' => now(),
        ]);

        // CAJERO no puede confirmar homónimos, pero editar su PROPIO registro
        // sin cambiar nombre ni identificación no debe tratarse como homónimo.
        $this->actingAs($this->usuario('CAJERO'))
            ->post(route('clientes.api'), [
                'accion' => 'editar',
                'id' => 110,
                'nombre' => 'Ana Torres',
                'telefono' => '6111-2222',
                'identificacion' => '7-777-7777',
                'direccion' => 'Calle 1, local 2',
            ])
            ->assertStatus(200)
            ->assertJson(['exito' => true]);

        $this->assertDatabaseHas('clientes', [
            'id' => 110,
            'nombre' => 'Ana Torres',
            'identificacion' => '7-777-7777',
            'direccion' => 'Calle 1, local 2',
        ]);
    }

    public function test_editar_no_permite_usar_la_identificacion_de_otro_cliente(): void
    {
        DB::table('clientes')->insert([
            [
                'id' => 111,
                'nombre' => 'Cliente Uno',
                'identificacion' => '1-111-1111',
                'identificacion_normalizada' => '11111111',
                'activo' => 1,
                'creado_en' => now(),
            ],
            [
                'id' => 112,
                'nombre' => 'Cliente Dos',
                'identificacion' => '2-222-2222',
                'identificacion_normalizada' => '22222222',
                'activo' => 1,
                'creado_en' => now(),
            ],
        ]);

        $this->actingAs($this->usuario('ADMIN'))
            ->post(route('clientes.api'), [
                'accion' => 'editar',
                'id' => 112,
                'nombre' => 'Cliente Dos',
                'identificacion' => '1-111-1111',
            ])
            ->assertStatus(422)
            ->assertJson(['exito' => false]);

        // La identificación original de Cliente Dos no cambió.
        $this->assertDatabaseHas('clientes', [
            'id' => 112,
            'identificacion' => '2-222-2222',
        ]);
    }

    /* ------------------------------------------------------------------
     | 9. Compatibilidad con la base real sin columnas normalizadas
     | ------------------------------------------------------------------ */

    public function test_funciona_sin_columnas_normalizadas(): void
    {
        // La base importada del sistema PHP puro no tiene estas columnas
        // hasta aplicar la migración. El servicio debe seguir funcionando.
        Schema::table('clientes', function (Blueprint $t) {
            $t->dropColumn(['identificacion_normalizada', 'telefono_normalizado']);
        });

        DB::table('clientes')->insert([
            'id' => 120,
            'nombre' => 'Juana Sin Columnas',
            'telefono' => '6555-4444',
            'identificacion' => '3-333-3333',
            'direccion' => 'Calle 3',
            'activo' => 1,
            'creado_en' => now(),
        ]);

        $cajero = $this->usuario('CAJERO');
        $this->actingAs($cajero);

        // Búsqueda por teléfono normalizado contra el campo crudo.
        $this->post(route('clientes.api'), ['accion' => 'search', 'q' => '65554444'])
            ->assertStatus(200)
            ->assertJsonFragment(['nombre' => 'Juana Sin Columnas']);

        // Búsqueda por identificación normalizada contra el campo crudo.
        $this->post(route('clientes.api'), ['accion' => 'search', 'q' => '33333333'])
            ->assertStatus(200)
            ->assertJsonFragment(['nombre' => 'Juana Sin Columnas']);

        // Crear y editar no deben fallar por columnas inexistentes.
        $this->post(route('clientes.api'), [
            'accion' => 'crear',
            'nombre' => 'Cliente Nuevo Sin Columnas',
            'identificacion' => '4-444-4444',
            'telefono' => '6444-3333',
        ])->assertStatus(200)->assertJson(['exito' => true]);

        $this->assertDatabaseHas('clientes', [
            'nombre' => 'Cliente Nuevo Sin Columnas',
            'identificacion' => '4-444-4444',
            'activo' => 1,
        ]);
    }
}
