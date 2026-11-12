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
 * Logout y botón «Atrás».
 *
 * Al cerrar sesión, el navegador no debe poder restaurar una copia cacheada
 * de una pantalla privada. Se comprueban dos cosas:
 *
 *  1. Las respuestas de una petición autenticada llevan `Cache-Control:
 *     no-store` (y `Pragma`/`Expires`), así que el navegador debe pedirlas de
 *     nuevo al servidor al pulsar «Atrás».
 *  2. Esa nueva petición, ya sin sesión válida, termina en el login: la
 *     sesión se invalidó en el servidor y el token CSRF se regeneró.
 *
 * Igual que el resto de la suite: SQLite :memory: y esquema propio. No se
 * toca MySQL ni datos reales.
 */
class LogoutBotonAtrasTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        /*
         * Arranque limpio: sin usuario en memoria, sin cookies y sin datos de
         * sesión de otra prueba. Sin esto, la política de sesión única podría
         * interpretar la sesión anterior como un segundo equipo y cerrar la
         * sesión antes de comprobar el logout.
         */
        $this->defaultCookies = [];
        $this->withCredentials = false;
        $this->app['auth']->forgetGuards();
        $this->app['session']->flush();

        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', (string) config('database.connections.sqlite.database'));

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

    private function usuario(string $username = 'cajero_atras'): User
    {
        $rolId = (int) DB::table('roles')->insertGetId([
            'nombre' => 'CAJERO',
            'activo' => 1,
        ]);

        $id = DB::table('usuarios')->insertGetId([
            'nombre' => 'Cajero Test',
            'username' => $username,
            'password_hash' => Hash::make('secret123'),
            'rol_id' => $rolId,
            'activo' => 1,
        ]);

        return User::findOrFail($id);
    }

    /**
     * Inicia sesión y deja la cookie de sesión para las peticiones
     * siguientes: el arnés de pruebas no reenvía cookies automáticamente y,
     * sin ella, cada petición sería una sesión distinta que la política de
     * sesión única rechazaría.
     */
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

    /**
     * Comprueba las directivas por separado: Symfony puede reordenarlas y
     * añadir `private` al recalcular la cabecera. Lo que NO se admite es
     * perder `no-store`, `no-cache`, `must-revalidate` ni `max-age=0`.
     */
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

    public function test_las_respuestas_privadas_no_se_guardan_en_la_cache_del_navegador(): void
    {
        $this->usuario('cajero_atras_cache');
        $this->iniciarSesionWeb('cajero_atras_cache')->assertRedirect(route('inicio'));

        $respuesta = $this->get(route('inicio'));

        $respuesta->assertOk();
        $this->assertSinCache($respuesta);
    }

    public function test_el_logout_invalida_la_sesion_regenera_el_token_y_el_atras_vuelve_al_login(): void
    {
        $usuario = $this->usuario('cajero_atras_logout');
        $this->iniciarSesionWeb('cajero_atras_logout')->assertRedirect(route('inicio'));

        $sesionAntes = session()->getId();
        $tokenAntes = session()->token();
        $this->assertAuthenticated();

        $logout = $this->post(route('logout'));

        $logout->assertRedirect(route('login'));
        // La propia redirección del logout tampoco queda almacenada.
        $this->assertSinCache($logout);

        $this->assertGuest();

        // Sesión invalidada en el servidor: nuevo id y nuevo token CSRF.
        $this->assertNotSame($sesionAntes, session()->getId());
        $this->assertNotSame($tokenAntes, session()->token());

        // El logout libera el registro de sesión única del usuario.
        $fila = DB::table('usuarios')->where('id', $usuario->id)->first();
        $this->assertNull($fila->session_id);
        $this->assertNull($fila->ultima_actividad);

        // El navegador guarda la cookie nueva que devolvió el logout.
        $this->withCookie(config('session.cookie'), $this->cookieDe($logout));

        /*
         * Pulsar «Atrás»: como la pantalla privada no se guardó en caché, el
         * navegador la pide de nuevo y el servidor responde con el login.
         */
        $this->get(route('inicio'))->assertRedirect(route('login'));
    }
}
