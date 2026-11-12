<?php

namespace Tests\Feature;

use App\Models\Rol;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LoginHttpFlowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        if (session()->isStarted()) {
            session()->flush();
        }
        if (! Schema::hasTable('roles')) {
            Schema::create('roles', function ($table) {
                $table->increments('id');
                $table->string('nombre', 20)->unique();
                $table->boolean('activo')->default(true);
            });
        }
        if (! Schema::hasTable('usuarios')) {
            Schema::create('usuarios', function ($table) {
                $table->increments('id');
                $table->string('nombre', 100);
                $table->string('username', 50)->unique();
                $table->string('password_hash', 255);
                $table->integer('rol_id');
                $table->boolean('activo')->default(true);
                $table->string('session_id', 100)->nullable();
                $table->timestamp('ultima_actividad')->nullable();
                $table->integer('dispositivo_id')->nullable();
                $table->text('two_factor_secret')->nullable();
                $table->text('two_factor_recovery_codes')->nullable();
                $table->timestamp('two_factor_confirmed_at')->nullable();
                $table->boolean('two_factor_enabled')->default(false);
            });
        }
        if (! Schema::hasTable('configuraciones')) {
            Schema::create('configuraciones', function ($table) {
                $table->id();
                $table->string('clave', 100)->unique();
                $table->string('valor', 255)->nullable();
            });
        }
        \DB::table('configuraciones')->where('clave', 'two_factor_modo')->delete();
    }

    public function test_login_admin_sin_config_sin_2fa(): void
    {
        $rol = Rol::firstOrCreate(['nombre' => 'ADMIN'], ['nombre' => 'ADMIN', 'activo' => 1]);
        $user = User::create(['nombre' => 'Admin', 'username' => 'admin_test', 'password_hash' => Hash::make('password'), 'rol_id' => $rol->id, 'activo' => 1]);
        $response = $this->post('/login', ['usuario' => 'admin_test', 'password' => 'password']);
        $this->assertAuthenticatedAs($user);
        $response->assertRedirect(route('inicio'));
    }

    public function test_login_gerente_sin_config_sin_2fa(): void
    {
        $rol = Rol::firstOrCreate(['nombre' => 'GERENTE'], ['nombre' => 'GERENTE', 'activo' => 1]);
        $user = User::create(['nombre' => 'Gerente', 'username' => 'gerente_test', 'password_hash' => Hash::make('password'), 'rol_id' => $rol->id, 'activo' => 1]);
        $response = $this->post('/login', ['usuario' => 'gerente_test', 'password' => 'password']);
        $this->assertAuthenticatedAs($user);
        $response->assertRedirect(route('inicio'));
    }

    public function test_login_usuario_normal_sin_config_sin_2fa(): void
    {
        $rol = Rol::firstOrCreate(['nombre' => 'MESERO'], ['nombre' => 'MESERO', 'activo' => 1]);
        $user = User::create(['nombre' => 'Mesero', 'username' => 'mesero_test', 'password_hash' => Hash::make('password'), 'rol_id' => $rol->id, 'activo' => 1]);
        $response = $this->post('/login', ['usuario' => 'mesero_test', 'password' => 'password']);
        $this->assertAuthenticatedAs($user);
        $response->assertRedirect(route('inicio'));
    }

    public function test_no_redirige_a_2fa_cuando_desactivado(): void
    {
        \DB::table('configuraciones')->updateOrInsert(['clave' => 'two_factor_modo'], ['valor' => 'desactivado']);
        $rol = Rol::firstOrCreate(['nombre' => 'CAJERO'], ['nombre' => 'CAJERO', 'activo' => 1]);
        $user = User::create(['nombre' => 'Cajero', 'username' => 'cajero_test', 'password_hash' => Hash::make('password'), 'rol_id' => $rol->id, 'activo' => 1]);
        $response = $this->post('/login', ['usuario' => 'cajero_test', 'password' => 'password']);
        $response->assertRedirect(route('inicio'));
        $this->assertStringNotContainsString('2fa', $response->headers->get('Location'));
    }

    public function test_2fa_setup_no_genera_secretos_cuando_desactivado(): void
    {
        \DB::table('configuraciones')->updateOrInsert(['clave' => 'two_factor_modo'], ['valor' => 'desactivado']);
        $rol = Rol::firstOrCreate(['nombre' => 'ADMIN'], ['nombre' => 'ADMIN', 'activo' => 1]);
        $user = User::create(['nombre' => 'Admin2', 'username' => 'admin2_test', 'password_hash' => Hash::make('password'), 'rol_id' => $rol->id, 'activo' => 1]);
        $this->actingAs($user);
        $response = $this->get('/2fa/setup');
        // Ruta no registrada - debe responder 404 o redirección segura
        $this->assertTrue(in_array($response->status(), [404, 302, 301]));
        $user->refresh();
        $this->assertNull($user->two_factor_secret);
    }
}
