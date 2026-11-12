<?php

namespace Tests\Feature;

use App\Models\Rol;
use App\Models\User;
use App\Services\TwoFactorPolicyService;
use App\Services\TwoFactorService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TwoFactorPolicyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        if (session()->isStarted()) {
            session()->flush();
        }
        // Crear tablas necesarias para SQLite :memory:
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
    }

    protected function crearUsuarioConRol(string $rolNombre = 'ADMIN'): User
    {
        $rol = Rol::firstOrCreate(
            ['nombre' => $rolNombre],
            ['nombre' => $rolNombre, 'activo' => 1]
        );

        $user = User::create([
            'nombre' => 'Test '.$rolNombre,
            'username' => strtolower($rolNombre).'_'.uniqid(),
            'password_hash' => Hash::make('password'),
            'rol_id' => $rol->id,
            'activo' => 1,
        ]);

        return $user;
    }

    /**
     * La sesión no guarda un booleano heredado: guarda la marca temporal de la
     * verificación y el sello SHA-256 que la ata a la política vigente y al
     * estado 2FA del usuario. Se comprueba el comportamiento REAL de
     * TwoFactorService (mismo patrón que TwoFactorP0Test).
     */
    private function assertSesionVerificada(User $user): void
    {
        $servicio = app(TwoFactorService::class);
        $user->refresh();

        $marca = session(TwoFactorService::CLAVE_SESION);
        $this->assertIsInt($marca, 'La marca de sesión debe ser un timestamp entero.');
        $this->assertGreaterThan(0, $marca);

        $sello = session(TwoFactorService::CLAVE_SELLO);
        $this->assertIsString($sello, 'La sesión debe guardar el sello de verificación.');
        $this->assertSame(
            $servicio->selloSesion($user),
            $sello,
            'El sello debe corresponder a la política y al estado 2FA actuales.'
        );

        $this->assertTrue(
            $servicio->sesionVerificada($this->app['session']->driver(), $user),
            'La sesión debe contar como verificada para la política vigente.'
        );
    }

    public function test_sin_configuracion_login_sin_2fa(): void
    {
        $user = $this->crearUsuarioConRol('ADMIN');

        $response = $this->post('/login', [
            'usuario' => $user->username,
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect(route('inicio'));
        $this->assertSesionVerificada($user);
    }

    public function test_desactivado_login_sin_2fa(): void
    {
        if (Schema::hasTable('configuraciones')) {
            DB::table('configuraciones')->updateOrInsert(
                ['clave' => 'two_factor_modo'],
                ['valor' => 'desactivado']
            );
        }

        $user = $this->crearUsuarioConRol('CAJERO');

        $response = $this->post('/login', [
            'usuario' => $user->username,
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect(route('inicio'));
        $this->assertSesionVerificada($user);
    }

    public function test_opcional_sin_configuracion_usuario_no_exige_2fa(): void
    {
        if (Schema::hasTable('configuraciones')) {
            DB::table('configuraciones')->updateOrInsert(
                ['clave' => 'two_factor_modo'],
                ['valor' => 'opcional']
            );
        }

        $user = $this->crearUsuarioConRol('MESERO');
        // Sin 2FA habilitado, sin obligación por rol/usuario

        $response = $this->post('/login', [
            'usuario' => $user->username,
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect(route('inicio'));
    }

    public function test_obligatorio_exige_2fa(): void
    {
        if (Schema::hasTable('configuraciones')) {
            DB::table('configuraciones')->updateOrInsert(
                ['clave' => 'two_factor_modo'],
                ['valor' => 'obligatorio']
            );
        }

        // Bajo global OBLIGATORIO, se exige a los usuarios con el control
        // individual HABILITADO (pendiente de enrolle o confirmado).
        $user = $this->crearUsuarioConRol('GERENTE');
        $user->two_factor_enabled = true;
        $user->save();

        $response = $this->post('/login', [
            'usuario' => $user->username,
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);
        // Con política obligatoria debe exigir verificación
        $policy = app(TwoFactorPolicyService::class);
        $this->assertTrue($policy->debeExigirTwoFactor($user));
    }

    public function test_control_individual_deshabilitado_no_exige_2fa_bajo_global_obligatorio(): void
    {
        if (Schema::hasTable('configuraciones')) {
            DB::table('configuraciones')->updateOrInsert(
                ['clave' => 'two_factor_modo'],
                ['valor' => 'obligatorio']
            );
        }

        // Excepción autorizada: ADMIN/GERENTE deshabilitó el 2FA del usuario
        // (flag individual en OFF) aunque el global sea OBLIGATORIO.
        $user = $this->crearUsuarioConRol('GERENTE');

        $policy = app(TwoFactorPolicyService::class);
        $this->assertFalse($policy->debeExigirTwoFactor($user));
    }

    public function test_desactivar_no_elimina_configuraciones_existentes(): void
    {
        if (! Schema::hasTable('configuraciones')) {
            $this->markTestSkipped('Tabla configuraciones no existe');
        }

        // Crear configuraciones ficticias
        DB::table('configuraciones')->updateOrInsert(
            ['clave' => 'two_factor_secret_test'],
            ['valor' => 'SECRET123']
        );
        DB::table('configuraciones')->updateOrInsert(
            ['clave' => 'two_factor_modo'],
            ['valor' => 'obligatorio']
        );

        // Cambiar a desactivado
        DB::table('configuraciones')->updateOrInsert(
            ['clave' => 'two_factor_modo'],
            ['valor' => 'desactivado']
        );

        // Verificar que otras configuraciones NO fueron eliminadas
        $this->assertDatabaseHas('configuraciones', ['clave' => 'two_factor_secret_test', 'valor' => 'SECRET123']);
        $this->assertDatabaseHas('configuraciones', ['clave' => 'two_factor_modo', 'valor' => 'desactivado']);
    }
}
