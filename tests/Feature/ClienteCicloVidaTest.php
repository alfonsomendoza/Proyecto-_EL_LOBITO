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
            $t->timestamp('creado_en')->nullable();
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
        $this->cliente('Activo', true);
        $this->cliente('Eliminado', false);

        $this->actingAs($admin)
            ->get(route('clientes.index'))
            ->assertStatus(200)
            ->assertViewHas('clientes', fn ($c) => $c->count() === 1 && $c->first()->nombre === 'Activo')
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
        $this->cliente('Cliente General', true, 1);

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
        $this->cliente('Cliente General', true, 1);

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
}
