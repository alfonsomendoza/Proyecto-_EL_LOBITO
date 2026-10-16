<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/*
 * Pruebas aisladas de la edición de nombre y username de usuarios.
 *
 * IMPORTANTE:
 * - Corren en SQLite :memory: (configurado en phpunit.xml).
 * - El esquema se construye a mano: ninguna prueba toca la base real
 *   "sistemadecaja".
 */
class UsuarioEditarTest extends TestCase
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
        foreach (['usuarios', 'roles'] as $tabla) {
            Schema::dropIfExists($tabla);
        }

        Schema::create('roles', function (Blueprint $t) {
            $t->increments('id');
            $t->string('nombre', 30)->unique();
        });

        // Refleja el esquema real: username UNIQUE, password_hash, activo.
        Schema::create('usuarios', function (Blueprint $t) {
            $t->increments('id');
            $t->string('nombre', 100);
            $t->string('username', 50)->unique();
            $t->string('password_hash', 255);
            $t->integer('rol_id');
            $t->boolean('activo')->default(1);
        });
    }

    private function rolId(string $nombre): int
    {
        $existe = DB::table('roles')->where('nombre', $nombre)->first();
        if ($existe) {
            return (int) $existe->id;
        }

        return (int) DB::table('roles')->insertGetId(['nombre' => $nombre]);
    }

    private function usuario(string $rolNombre, ?string $username = null, ?string $nombre = null): User
    {
        $username = $username ?? (strtolower($rolNombre).'_test_'.uniqid());

        $id = DB::table('usuarios')->insertGetId([
            'nombre' => $nombre ?? ($rolNombre.' Test'),
            'username' => $username,
            'password_hash' => Hash::make('secret123'),
            'rol_id' => $this->rolId($rolNombre),
            'activo' => 1,
        ]);

        return User::findOrFail($id);
    }

    /* ==================================================================
     | 1. ADMIN puede editar cualquier rol
     | ================================================================== */

    public function test_admin_edita_nombre_y_username_de_cada_rol(): void
    {
        $admin = $this->usuario('ADMIN', 'admin_edit', 'Admin Edit');

        foreach (['GERENTE', 'CAJERO', 'MESERO', 'COCINERO'] as $rol) {
            $objetivo = $this->usuario($rol);

            $this->actingAs($admin)
                ->post(route('usuarios.api'), [
                    'accion' => 'editar',
                    'usuario_id' => $objetivo->id,
                    'nombre' => 'Editado '.$rol,
                    'username' => 'editado_'.strtolower($rol),
                ])
                ->assertStatus(200)
                ->assertJson(['exito' => true]);

            $this->assertDatabaseHas('usuarios', [
                'id' => $objetivo->id,
                'nombre' => 'Editado '.$rol,
                'username' => 'editado_'.strtolower($rol),
            ]);
        }
    }

    /* ==================================================================
     | 2. GERENTE solo puede editar CAJERO, MESERO y COCINERO
     | ================================================================== */

    public function test_gerente_edita_cajero_mesero_cocinero(): void
    {
        $gerente = $this->usuario('GERENTE', 'gerente_edit', 'Gerente Edit');

        foreach (['CAJERO', 'MESERO', 'COCINERO'] as $rol) {
            $objetivo = $this->usuario($rol);

            $this->actingAs($gerente)
                ->post(route('usuarios.api'), [
                    'accion' => 'editar',
                    'usuario_id' => $objetivo->id,
                    'nombre' => 'Editado G '.$rol,
                    'username' => 'editado_g_'.strtolower($rol),
                ])
                ->assertStatus(200)
                ->assertJson(['exito' => true]);

            $this->assertDatabaseHas('usuarios', [
                'id' => $objetivo->id,
                'nombre' => 'Editado G '.$rol,
                'username' => 'editado_g_'.strtolower($rol),
            ]);
        }
    }

    /* ==================================================================
     | 3. GERENTE no puede editar usuarios ADMIN ni GERENTE
     | ================================================================== */

    public function test_gerente_no_puede_editar_admin_ni_gerente(): void
    {
        $gerente = $this->usuario('GERENTE', 'gerente_noedit', 'Gerente NoEdit');
        $otroGerente = $this->usuario('GERENTE', 'otro_gerente', 'Otro Gerente');

        // Objetivo ADMIN
        $adminObjetivo = $this->usuario('ADMIN', 'admin_objetivo', 'Admin Objetivo');
        $hashOriginal = DB::table('usuarios')->where('id', $adminObjetivo->id)->value('password_hash');

        $this->actingAs($gerente)
            ->post(route('usuarios.api'), [
                'accion' => 'editar',
                'usuario_id' => $adminObjetivo->id,
                'nombre' => 'Intento',
                'username' => 'intentoadmin',
            ])
            ->assertStatus(403)
            ->assertJson(['exito' => false]);

        // Objetivo otro GERENTE
        $this->actingAs($gerente)
            ->post(route('usuarios.api'), [
                'accion' => 'editar',
                'usuario_id' => $otroGerente->id,
                'nombre' => 'Intento',
                'username' => 'intentogerente',
            ])
            ->assertStatus(403)
            ->assertJson(['exito' => false]);

        // Nada cambió.
        $this->assertDatabaseHas('usuarios', ['id' => $adminObjetivo->id, 'nombre' => 'Admin Objetivo', 'username' => 'admin_objetivo']);
        $this->assertSame($hashOriginal, DB::table('usuarios')->where('id', $adminObjetivo->id)->value('password_hash'));
        $this->assertDatabaseHas('usuarios', ['id' => $otroGerente->id, 'nombre' => 'Otro Gerente', 'username' => 'otro_gerente']);
    }

    /* ==================================================================
     | 4. CAJERO, MESERO y COCINERO no pueden editar usuarios
     | ================================================================== */

    public function test_cajero_mesero_cocinero_no_pueden_editar(): void
    {
        $objetivo = $this->usuario('CAJERO', 'victima_edit', 'Víctima Edit');

        foreach (['CAJERO', 'MESERO', 'COCINERO'] as $rol) {
            $actor = $this->usuario($rol, 'actor_'.strtolower($rol), 'Actor '.$rol);

            $this->actingAs($actor)
                ->post(route('usuarios.api'), [
                    'accion' => 'editar',
                    'usuario_id' => $objetivo->id,
                    'nombre' => 'Intruso',
                    'username' => 'invasor_'.strtolower($rol),
                ])
                ->assertStatus(403)
                ->assertJson(['exito' => false]);
        }

        // El objetivo no cambió.
        $this->assertDatabaseHas('usuarios', ['id' => $objetivo->id, 'nombre' => 'Víctima Edit', 'username' => 'victima_edit']);
    }

    /* ==================================================================
     | 5. Username duplicado rechazado
     | ================================================================== */

    public function test_username_duplicado_es_rechazado(): void
    {
        $admin = $this->usuario('ADMIN', 'admin_dup', 'Admin Dup');
        $otro = $this->usuario('CAJERO', 'ocupado', 'Ocupado');
        $objetivo = $this->usuario('MESERO', 'libre', 'Libre');

        $this->actingAs($admin)
            ->post(route('usuarios.api'), [
                'accion' => 'editar',
                'usuario_id' => $objetivo->id,
                'nombre' => 'Intento',
                'username' => 'ocupado',
            ])
            ->assertStatus(422)
            ->assertJson(['exito' => false]);

        $this->assertDatabaseHas('usuarios', ['id' => $objetivo->id, 'username' => 'libre']);
    }

    /* ==================================================================
     | 6. Permitir conservar el username actual
     | ================================================================== */

    public function test_se_permite_conservar_el_username_actual(): void
    {
        $admin = $this->usuario('ADMIN', 'admin_keep', 'Admin Keep');
        $objetivo = $this->usuario('CAJERO', 'igual_que', 'Igual Que');

        $this->actingAs($admin)
            ->post(route('usuarios.api'), [
                'accion' => 'editar',
                'usuario_id' => $objetivo->id,
                'nombre' => 'Renombrado',
                'username' => 'igual_que',
            ])
            ->assertStatus(200)
            ->assertJson(['exito' => true]);

        $this->assertDatabaseHas('usuarios', ['id' => $objetivo->id, 'nombre' => 'Renombrado', 'username' => 'igual_que']);
    }

    /* ==================================================================
     | 7. No se pueden modificar rol, contraseña ni estado por campos
     |    adicionales manipulados
     | ================================================================== */

    public function test_no_permite_modificar_rol_password_ni_activo(): void
    {
        $admin = $this->usuario('ADMIN', 'admin_manip', 'Admin Manip');
        $objetivo = $this->usuario('CAJERO', 'cajero_obj', 'Cajero Obj');

        $hashOriginal = DB::table('usuarios')->where('id', $objetivo->id)->value('password_hash');
        $rolOriginal = (int) DB::table('usuarios')->where('id', $objetivo->id)->value('rol_id');

        $this->actingAs($admin)
            ->post(route('usuarios.api'), [
                'accion' => 'editar',
                'usuario_id' => $objetivo->id,
                'nombre' => 'Nuevo nombre',
                'username' => 'nuevo_user',
                // Campos manipulados que deben ser ignorados.
                'rol_id' => $this->rolId('ADMIN'),
                'activo' => 0,
                'password' => 'hackeada123',
                'password_confirmation' => 'hackeada123',
            ])
            ->assertStatus(200)
            ->assertJson(['exito' => true]);

        $this->assertDatabaseHas('usuarios', [
            'id' => $objetivo->id,
            'nombre' => 'Nuevo nombre',
            'username' => 'nuevo_user',
            'rol_id' => $rolOriginal,
            'activo' => 1,
        ]);
        $this->assertSame($hashOriginal, DB::table('usuarios')->where('id', $objetivo->id)->value('password_hash'));
    }

    /* ==================================================================
     | 8. ID inexistente
     | ================================================================== */

    public function test_id_inexistente_devuelve_404(): void
    {
        $admin = $this->usuario('ADMIN', 'admin_404', 'Admin 404');

        $this->actingAs($admin)
            ->post(route('usuarios.api'), [
                'accion' => 'editar',
                'usuario_id' => 99999,
                'nombre' => 'Fantasma',
                'username' => 'fantasma_user',
            ])
            ->assertStatus(404)
            ->assertJson(['exito' => false]);

        $this->assertDatabaseMissing('usuarios', ['username' => 'fantasma_user']);
    }

    /* ==================================================================
     | 9. No autenticado
     | ================================================================== */

    public function test_no_autenticado_es_rechazado(): void
    {
        $this->post(route('usuarios.api'), [
            'accion' => 'editar',
            'usuario_id' => 1,
            'nombre' => 'X',
            'username' => 'x',
        ])->assertRedirect(route('login'));
    }

    /* ==================================================================
     | 10. La vista muestra el botón Editar a quien está autorizado
     | ================================================================== */

    public function test_boton_editar_visible_solo_para_roles_autorizados(): void
    {
        $admin = $this->usuario('ADMIN', 'admin_btn', 'Admin Btn');
        $gerente = $this->usuario('GERENTE', 'gerente_btn', 'Gerente Btn');
        $cajero = $this->usuario('CAJERO', 'cajero_btn', 'Cajero Btn');

        // ADMIN ve el botón.
        $this->actingAs($admin)
            ->get(route('usuarios.index'))
            ->assertStatus(200)
            ->assertSee('btn-editar-usuario', false);

        // GERENTE ve el botón sobre CAJERO.
        $this->actingAs($gerente)
            ->get(route('usuarios.index'))
            ->assertStatus(200)
            ->assertSee('btn-editar-usuario', false);

        // CAJERO no puede acceder a la pantalla.
        $this->actingAs($cajero)
            ->get(route('usuarios.index'))
            ->assertRedirect(route('inicio'));
    }
}
