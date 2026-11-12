<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Navegación y endurecimiento del acceso.
 *
 * Cubre, sin tocar MySQL ni datos reales (SQLite :memory: con esquema propio):
 *
 *  - El formulario de login y sus redirecciones no se guardan en caché.
 *  - Todas las respuestas web llevan cabeceras de seguridad básicas.
 *  - El login regenera el id de sesión (evita fijación de sesión).
 *  - Los fallos de credenciales se limitan por usuario + IP.
 *  - El mensaje de error no revela si el usuario existe.
 *  - Las pantallas incluyen la revalidación al volver con «Atrás» (bfcache).
 */
class SesionNavegacionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Arranque limpio, igual que el resto de pruebas de sesión.
        $this->defaultCookies = [];
        $this->withCredentials = false;
        $this->app['auth']->forgetGuards();
        $this->app['session']->flush();

        // Barrera de seguridad: NUNCA se ejecuta contra MySQL.
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', (string) config('database.connections.sqlite.database'));

        $this->crearEsquema();
    }

    private function crearEsquema(): void
    {
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
            $t->string('session_id', 100)->nullable();
            $t->timestamp('ultima_actividad')->nullable();
        });
    }

    private function usuario(string $username, string $rol = 'CAJERO'): User
    {
        $rolId = DB::table('roles')->where('nombre', $rol)->value('id');

        if ($rolId === null) {
            $rolId = DB::table('roles')->insertGetId([
                'nombre' => $rol,
                'activo' => 1,
            ]);
        }

        $id = DB::table('usuarios')->insertGetId([
            'nombre' => $rol.' Test',
            'username' => $username,
            'password_hash' => Hash::make('secret123'),
            'rol_id' => $rolId,
            'activo' => 1,
        ]);

        return User::findOrFail($id);
    }

    private function iniciarSesionWeb(string $username): TestResponse
    {
        $respuesta = $this->post(route('login.post'), [
            'usuario' => $username,
            'password' => 'secret123',
        ]);

        $this->withCookie(config('session.cookie'), $this->cookieDe($respuesta));

        return $respuesta;
    }

    private function cookieDe(TestResponse $respuesta): string
    {
        $cookie = $respuesta->getCookie(config('session.cookie'), true);

        $this->assertNotNull($cookie, 'La respuesta no incluyó cookie de sesión.');

        return $cookie->getValue();
    }

    private function assertSinCache(TestResponse $respuesta): void
    {
        $cacheControl = (string) $respuesta->headers->get('Cache-Control');

        $directivas = array_values(array_unique(array_map(
            'trim',
            explode(',', strtolower($cacheControl))
        )));

        foreach (['no-store', 'no-cache', 'must-revalidate', 'max-age=0'] as $directiva) {
            $this->assertTrue(
                in_array($directiva, $directivas, true),
                "Falta la directiva [{$directiva}] en Cache-Control: [{$cacheControl}]"
            );
        }

        $respuesta->assertHeader('Pragma', 'no-cache');
        $respuesta->assertHeader('Expires', '0');
    }

    private function assertCabecerasSeguridad(TestResponse $respuesta): void
    {
        $respuesta->assertHeader('X-Content-Type-Options', 'nosniff');
        $respuesta->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $respuesta->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $respuesta->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
    }

    public function test_el_login_de_invitado_no_se_guarda_en_cache_y_lleva_cabeceras(): void
    {
        $respuesta = $this->get(route('login'));

        $respuesta->assertOk();
        $respuesta->assertViewIs('auth.login');
        $this->assertSinCache($respuesta);
        $this->assertCabecerasSeguridad($respuesta);
    }

    public function test_el_login_incluye_la_revalidacion_para_la_bfcache(): void
    {
        $respuesta = $this->get(route('login'));

        $respuesta->assertOk();
        $respuesta->assertSee('event.persisted', false);
        $respuesta->assertSee('window.location.reload()', false);
    }

    public function test_un_login_fallido_redirige_al_login_sin_cache(): void
    {
        $respuesta = $this->post(route('login.post'), [
            'usuario' => 'usuario_que_no_existe',
            'password' => 'incorrecta',
        ]);

        $respuesta->assertRedirect(route('login'));
        $this->assertSinCache($respuesta);
        $this->assertCabecerasSeguridad($respuesta);
    }

    public function test_el_login_correcto_regenera_la_sesion_y_no_deja_cache(): void
    {
        $usuario = $this->usuario('cajero_nav_login');
        $sesionAntes = session()->getId();

        $respuesta = $this->iniciarSesionWeb('cajero_nav_login');

        $respuesta->assertRedirect(route('inicio'));
        $this->assertAuthenticatedAs($usuario);
        $this->assertNotSame(
            $sesionAntes,
            session()->getId(),
            'El id de sesión debe cambiar tras autenticarse (anti fijación).'
        );
        $this->assertSinCache($respuesta);

        // La pantalla privada también queda fuera de la caché y contiene la
        // revalidación de la bfcache.
        $inicio = $this->get(route('inicio'));
        $inicio->assertOk();
        $this->assertSinCache($inicio);
        $this->assertCabecerasSeguridad($inicio);
        $inicio->assertSee('event.persisted', false);
    }

    public function test_un_usuario_autenticado_no_puede_ver_el_login(): void
    {
        $this->usuario('cajero_nav_autenticado');

        // Login real: registra la sesión única y evita que el middleware de
        // sesión única cierre un `actingAs` sin sesión reclamada.
        $this->iniciarSesionWeb('cajero_nav_autenticado')->assertRedirect(route('inicio'));

        $respuesta = $this->get(route('login'));

        $respuesta->assertRedirect(route('inicio'));
        $this->assertSinCache($respuesta);
    }

    public function test_el_login_bloquea_los_intentos_repetidos(): void
    {
        $this->usuario('cajero_nav_limite');

        // Cinco fallos permitidos.
        for ($intento = 1; $intento <= 5; $intento++) {
            $this->post(route('login.post'), [
                'usuario' => 'cajero_nav_limite',
                'password' => 'incorrecta',
            ])->assertRedirect(route('login'));
        }

        // El sexto intento se corta aunque la contraseña sea correcta.
        $bloqueado = $this->post(route('login.post'), [
            'usuario' => 'cajero_nav_limite',
            'password' => 'secret123',
        ]);

        $bloqueado->assertRedirect(route('login'));
        $bloqueado->assertSessionHasErrors('usuario');
        $this->assertStringContainsString(
            'Demasiados intentos',
            (string) session('errors')->first('usuario')
        );
        $this->assertGuest();
    }

    public function test_el_error_de_credenciales_no_revela_si_el_usuario_existe(): void
    {
        $this->usuario('cajero_nav_enum');

        $this->post(route('login.post'), [
            'usuario' => 'cajero_nav_enum',
            'password' => 'incorrecta',
        ])->assertSessionHasErrors('usuario');
        $mensajeUsuarioReal = (string) session('errors')->first('usuario');

        $this->post(route('login.post'), [
            'usuario' => 'usuario_inexistente_enum',
            'password' => 'incorrecta',
        ])->assertSessionHasErrors('usuario');
        $mensajeUsuarioInexistente = (string) session('errors')->first('usuario');

        $this->assertSame($mensajeUsuarioReal, $mensajeUsuarioInexistente);
    }
}
