<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LoginConSesionActivaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->crearEsquema();
    }

    private function crearEsquema(): void
    {
        foreach (['roles', 'usuarios'] as $tabla) {
            Schema::dropIfExists($tabla);
        }

        Schema::create('roles', function (Blueprint $t) {
            $t->increments('id');
            $t->string('nombre', 30)->unique();
            $t->boolean('activo')->default(1);
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
        $id = DB::table('roles')->where('nombre', $nombre)->value('id');

        if ($id !== null) {
            return (int) $id;
        }

        return (int) DB::table('roles')->insertGetId([
            'nombre' => $nombre,
            'activo' => 1,
        ]);
    }

    private function usuario(string $rol, string $username): User
    {
        $id = DB::table('usuarios')->insertGetId([
            'nombre' => $rol.' Test',
            'username' => $username,
            'password_hash' => Hash::make('secret123'),
            'rol_id' => $this->rolId($rol),
            'activo' => 1,
        ]);

        return User::findOrFail($id);
    }

    public function test_login_get_con_sesion_activa_redirige_y_no_muestra_el_formulario(): void
    {
        $usuario = $this->usuario('CAJERO', 'cajero_sesion_activa');

        $this->actingAs($usuario);

        $respuesta = $this->get(route('login'));

        $respuesta->assertStatus(302);
        $respuesta->assertRedirect(route('inicio'));
        // Una redirección no renderiza ninguna vista: no hay formulario que mostrar.
        $this->assertNull($respuesta->getOriginalContent());
    }

    public function test_login_get_con_sesion_activa_lleva_anti_cache(): void
    {
        $usuario = $this->usuario('GERENTE', 'gerente_sesion_activa');

        $this->actingAs($usuario);

        $respuesta = $this->get(route('login'));

        $respuesta->assertStatus(302);

        $cacheControl = (string) $respuesta->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('no-cache', $cacheControl);
        $this->assertStringContainsString('must-revalidate', $cacheControl);
        $this->assertStringContainsString('max-age=0', $cacheControl);

        $respuesta->assertHeader('Pragma', 'no-cache');
        $respuesta->assertHeader('Expires', '0');
    }

    #[DataProvider('rolesAutorizadosProvider')]
    public function test_login_get_con_sesion_activa_redirige_al_panel_del_rol(string $rol, string $username): void
    {
        $usuario = $this->usuario($rol, $username);

        $this->actingAs($usuario);

        $respuesta = $this->get(route('login'));

        $respuesta->assertStatus(302);
        $respuesta->assertRedirect(route('inicio'));
    }

    public static function rolesAutorizadosProvider(): array
    {
        return [
            ['ADMIN', 'admin_sesion_activa'],
            ['GERENTE', 'gerente_sesion_activa'],
            ['CAJERO', 'cajero_sesion_activa'],
            ['MESERO', 'mesero_sesion_activa'],
            ['COCINERO', 'cocinero_sesion_activa'],
        ];
    }

    public function test_login_get_sin_sesion_sigue_mostrando_el_formulario(): void
    {
        $respuesta = $this->get(route('login'));

        $respuesta->assertStatus(200);
        $respuesta->assertViewIs('auth.login');
    }
}
