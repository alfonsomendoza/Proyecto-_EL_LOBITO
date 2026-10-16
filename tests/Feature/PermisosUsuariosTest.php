<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PermisosUsuariosTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Aseguramos que se use SQLite en memoria para no tocar la base real
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
        foreach (['usuarios', 'roles'] as $tabla) {
            Schema::dropIfExists($tabla);
        }

        Schema::create('roles', function (Blueprint $t) {
            $t->increments('id');
            $t->string('nombre', 30)->unique();
        });

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

    private function usuario(string $rolNombre, ?string $username = null, ?string $nombre = null, int $activo = 1): User
    {
        $username = $username ?? strtolower($rolNombre).'_test_'.uniqid();
        $id = DB::table('usuarios')->insertGetId([
            'nombre' => $nombre ?? ($rolNombre.' Test'),
            'username' => $username,
            'password_hash' => Hash::make('secret123'),
            'rol_id' => $this->rolId($rolNombre),
            'activo' => $activo,
        ]);

        return User::findOrFail($id);
    }

    public function test_gerente_puede_acceder_a_listado(): void
    {
        $gerente = $this->usuario('GERENTE', 'gerente_test', 'Gerente Test');
        $cajero = $this->usuario('CAJERO', 'cajero_test', 'Cajero Test');

        $this->actingAs($gerente)
            ->get(route('usuarios.index'))
            ->assertStatus(200)
            ->assertSee('Cajero Test');
    }

    public function test_gerente_no_ve_admin_ni_gerente(): void
    {
        $gerente = $this->usuario('GERENTE', 'gerente_test', 'Gerente Test');
        $admin = $this->usuario('ADMIN', 'admin_test', 'Admin Test');
        $otroGerente = $this->usuario('GERENTE', 'otro_gerente', 'Otro Gerente');

        $this->actingAs($gerente)
            ->get(route('usuarios.index'))
            ->assertStatus(200)
            ->assertDontSee('Admin Test')
            ->assertDontSee('Otro Gerente');
    }

    public function test_gerente_ve_cajero_mesero_cocinero_activos_y_eliminados(): void
    {
        $gerente = $this->usuario('GERENTE', 'gerente_test', 'Gerente Test');
        $cajero = $this->usuario('CAJERO', 'cajero_activo', 'Cajero Activo', 1);
        $mesero = $this->usuario('MESERO', 'mesero_elim', 'Mesero Elim', 0);
        $cocinero = $this->usuario('COCINERO', 'cocinero_test', 'Cocinero Test', 1);

        $resp = $this->actingAs($gerente)->get(route('usuarios.index'));
        $resp->assertStatus(200)->assertSee('Cajero Activo')->assertSee('Cocinero Test');
        $resp2 = $this->actingAs($gerente)->get(route('usuarios.index', ['estado' => 'eliminados']));
        $resp2->assertStatus(200)->assertSee('Mesero Elim');
    }

    public function test_gerente_cambia_contraseña_cajero_mesero_cocinero(): void
    {
        $gerente = $this->usuario('GERENTE', 'gerente_test', 'Gerente Test');
        $cajero = $this->usuario('CAJERO', 'cajero_test', 'Cajero Test');
        $mesero = $this->usuario('MESERO', 'mesero_test', 'Mesero Test');
        $cocinero = $this->usuario('COCINERO', 'cocinero_test', 'Cocinero Test');

        // cambiar cajero
        $this->actingAs($gerente)
            ->post(route('usuarios.api'), [
                'accion' => 'reset_password',
                'usuario_id' => $cajero->id,
                'password' => 'nuevaClave456',
                'password_confirmation' => 'nuevaClave456',
            ])
            ->assertStatus(200)->assertJson(['exito' => true]);

        // cambiar mesero
        $this->actingAs($gerente)
            ->post(route('usuarios.api'), [
                'accion' => 'reset_password',
                'usuario_id' => $mesero->id,
                'password' => 'nuevaClave456',
                'password_confirmation' => 'nuevaClave456',
            ])
            ->assertStatus(200)->assertJson(['exito' => true]);

        // cambiar cocinero
        $this->actingAs($gerente)
            ->post(route('usuarios.api'), [
                'accion' => 'reset_password',
                'usuario_id' => $cocinero->id,
                'password' => 'nuevaClave456',
                'password_confirmation' => 'nuevaClave456',
            ])
            ->assertStatus(200)->assertJson(['exito' => true]);

        $this->assertTrue(Hash::check('nuevaClave456', DB::table('usuarios')->where('id', $cajero->id)->value('password_hash')));
    }

    public function test_gerente_no_cambia_contraseña_admin_ni_otro_gerente(): void
    {
        $gerente = $this->usuario('GERENTE', 'gerente_test', 'Gerente Test');
        $admin = $this->usuario('ADMIN', 'admin_test', 'Admin Test');
        $otro = $this->usuario('GERENTE', 'otro_test', 'Otro Test');

        $this->actingAs($gerente)
            ->post(route('usuarios.api'), [
                'accion' => 'reset_password',
                'usuario_id' => $admin->id,
                'password' => 'nuevaClave456',
                'password_confirmation' => 'nuevaClave456',
            ])
            ->assertStatus(403);

        $this->actingAs($gerente)
            ->post(route('usuarios.api'), [
                'accion' => 'reset_password',
                'usuario_id' => $otro->id,
                'password' => 'nuevaClave456',
                'password_confirmation' => 'nuevaClave456',
            ])
            ->assertStatus(403);
    }

    public function test_gerente_no_puede_crear_desactivar_reactivar(): void
    {
        $gerente = $this->usuario('GERENTE', 'gerente_test', 'Gerente Test');
        $cajero = $this->usuario('CAJERO', 'cajero_test', 'Cajero Test');

        $this->actingAs($gerente)
            ->post(route('usuarios.api'), [
                'accion' => 'crear',
                'nombre' => 'Intruso',
                'username' => 'intruso',
                'password' => 'claveSegura123',
                'rol_id' => $this->rolId('CAJERO'),
            ])
            ->assertStatus(403);

        $this->actingAs($gerente)
            ->post(route('usuarios.api'), ['accion' => 'eliminar', 'id' => $cajero->id])
            ->assertStatus(403);

        $this->actingAs($gerente)
            ->post(route('usuarios.api'), ['accion' => 'reactivar', 'id' => $cajero->id])
            ->assertStatus(403);
    }

    public function test_cajero_mesero_cocinero_no_pueden_acceder_a_modulo(): void
    {
        foreach (['CAJERO', 'MESERO', 'COCINERO'] as $rol) {
            $user = $this->usuario($rol, strtolower($rol).'_noacc', $rol.' NoAcc');
            $this->actingAs($user)->get(route('usuarios.index'))->assertRedirect(route('inicio'));
        }
    }

    public function test_cajero_mesero_cocinero_no_pueden_usar_api(): void
    {
        foreach (['CAJERO', 'MESERO', 'COCINERO'] as $rol) {
            $user = $this->usuario($rol, strtolower($rol).'_api', $rol.' Api');
            $this->actingAs($user)
                ->post(route('usuarios.api'), ['accion' => 'crear', 'nombre' => 'X', 'username' => 'X', 'password' => '123456', 'rol_id' => $this->rolId('CAJERO')])
                ->assertStatus(403);
        }
    }
}
