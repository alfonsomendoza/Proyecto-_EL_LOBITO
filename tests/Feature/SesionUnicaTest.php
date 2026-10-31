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
 * Política de UNA SOLA SESIÓN ACTIVA POR USUARIO.
 *
 * Todas las pruebas usan SQLite :memory: y construyen su propio esquema, como
 * el resto de la suite. Cada "dispositivo" es una sesión real de Laravel:
 * se inicia sesión por HTTP, se guarda la cookie y se cambia de dispositivo
 * limpiando la sesión en memoria y las cookies.
 */
class SesionUnicaTest extends TestCase
{
    private const MENSAJE_SEGUNDO_EQUIPO = 'Este usuario ya tiene una sesión abierta en otro equipo. '
        . 'Ciérrala o pide al administrador que la libere.';

    protected function setUp(): void
    {
        parent::setUp();

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

            // Columnas de la migración de sesión única.
            $t->string('session_id', 100)->nullable();
            $t->timestamp('ultima_actividad')->nullable();
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

    private function usuario(string $rol, string $username, int $activo = 1): User
    {
        $id = DB::table('usuarios')->insertGetId([
            'nombre' => $rol.' Test',
            'username' => $username,
            'password_hash' => Hash::make('secret123'),
            'rol_id' => $this->rolId($rol),
            'activo' => $activo,
        ]);

        return User::findOrFail($id);
    }

    /* ------------------------------------------------------------------
     | Utilidades de "dispositivo"
     | ------------------------------------------------------------------ */

    /**
     * Deja el arnés como un navegador nuevo: sin usuario en memoria, sin
     * cookies y sin datos de sesión en memoria. El handler de sesiones
     * conserva las sesiones ya escritas para poder volver a un dispositivo.
     */
    private function nuevoDispositivo(): void
    {
        $this->defaultCookies = [];
        $this->withCredentials = false;
        $this->app['auth']->forgetGuards();
        $this->app['session']->flush();
    }

    /**
     * Vuelve a un dispositivo concreto a partir del identificador de sesión
     * capturado en su login. Simula la siguiente petición de ESE navegador.
     *
     * Se envía el ID en claro con `withCookie`: el arnés de pruebas lo cifra
     * antes de la petición (prepareCookiesForRequest), igual que haría el
     * navegador al devolver la cookie. `withCredentials` hace que las
     * peticiones JSON también incluyan la cookie de sesión.
     */
    private function usarDispositivo(string $sesionId): void
    {
        $this->nuevoDispositivo();
        $this->withCookie(config('session.cookie'), $sesionId);
        $this->withCredentials();
    }

    /**
     * Identificador de sesión que Laravel envió en la respuesta (ya
     * descifrado). Es el valor que el navegador devolvería en la cookie.
     */
    private function cookieDe(TestResponse $respuesta): string
    {
        $cookie = $respuesta->getCookie(config('session.cookie'), true);

        $this->assertNotNull($cookie, 'La respuesta no incluyó cookie de sesión.');

        return $cookie->getValue();
    }

    private function iniciarSesion(string $username, string $password = 'secret123'): TestResponse
    {
        return $this->post(route('login.post'), [
            'usuario' => $username,
            'password' => $password,
        ]);
    }

    private function sesionRegistrada(int $usuarioId): ?string
    {
        return DB::table('usuarios')->where('id', $usuarioId)->value('session_id');
    }

    /* ==================================================================
     | Caso 1: primer inicio de sesión
     | ================================================================== */

    public function test_primer_login_registra_la_sesion_y_permite_entrar(): void
    {
        $usuario = $this->usuario('ADMIN', 'admin_uno');

        $respuesta = $this->iniciarSesion('admin_uno');

        $respuesta->assertRedirect(route('inicio'));

        $fila = DB::table('usuarios')->where('id', $usuario->id)->first();
        $this->assertNotNull($fila->session_id);
        $this->assertNotNull($fila->ultima_actividad);

        $this->usarDispositivo($this->cookieDe($respuesta));
        $this->get(route('inicio'))->assertStatus(200);
    }

    /* ==================================================================
     | Caso 2: segundo inicio de sesión rechazado (la primera sigue viva)
     | ================================================================== */

    public function test_segundo_login_se_rechaza_y_la_sesion_original_sigue_funcionando(): void
    {
        $usuario = $this->usuario('CAJERO', 'cajero_dos');

        $primera = $this->iniciarSesion('cajero_dos');
        $primera->assertRedirect(route('inicio'));

        $sesionOriginal = $this->sesionRegistrada($usuario->id);
        $this->assertNotNull($sesionOriginal);
        $cookieOriginal = $this->cookieDe($primera);

        // Segundo dispositivo: navegador limpio, sin la cookie original.
        $this->nuevoDispositivo();
        $segunda = $this->iniciarSesion('cajero_dos');

        $segunda->assertSessionHasErrors('usuario');
        $this->assertSame(
            self::MENSAJE_SEGUNDO_EQUIPO,
            session('errors')->first('usuario')
        );

        // El registro sigue perteneciendo a la sesión original.
        $this->assertSame($sesionOriginal, $this->sesionRegistrada($usuario->id));

        // La sesión original continúa trabajando sin interrupciones.
        $this->usarDispositivo($cookieOriginal);
        $this->get(route('inicio'))->assertStatus(200);
    }

    /* ==================================================================
     | Caso 3: logout libera la sesión y permite entrar desde otro equipo
     | ================================================================== */

    public function test_logout_libera_la_sesion_y_permite_el_siguiente_equipo(): void
    {
        $usuario = $this->usuario('GERENTE', 'gerente_tres');

        $login = $this->iniciarSesion('gerente_tres');
        $cookie = $this->cookieDe($login);

        $this->usarDispositivo($cookie);
        $this->post(route('logout'))->assertRedirect(route('login'));

        $fila = DB::table('usuarios')->where('id', $usuario->id)->first();
        $this->assertNull($fila->session_id);
        $this->assertNull($fila->ultima_actividad);

        // Otro dispositivo ya puede iniciar sesión de inmediato.
        $this->nuevoDispositivo();
        $this->iniciarSesion('gerente_tres')->assertRedirect(route('inicio'));
        $this->assertNotNull($this->sesionRegistrada($usuario->id));
    }

    /* ==================================================================
     | Caso 4: dos usuarios distintos trabajan a la vez
     | ================================================================== */

    public function test_dos_usuarios_diferentes_pueden_trabajar_simultaneamente(): void
    {
        $usuarioA = $this->usuario('ADMIN', 'admin_cuatro');
        $usuarioB = $this->usuario('MESERO', 'mesero_cuatro');

        $loginA = $this->iniciarSesion('admin_cuatro');
        $cookieA = $this->cookieDe($loginA);

        $this->nuevoDispositivo();
        $loginB = $this->iniciarSesion('mesero_cuatro');
        $cookieB = $this->cookieDe($loginB);

        $sesionA = $this->sesionRegistrada($usuarioA->id);
        $sesionB = $this->sesionRegistrada($usuarioB->id);

        $this->assertNotNull($sesionA);
        $this->assertNotNull($sesionB);
        $this->assertNotSame($sesionA, $sesionB);

        $this->usarDispositivo($cookieA);
        $this->get(route('inicio'))->assertStatus(200);

        $this->usarDispositivo($cookieB);
        $this->get(route('inicio'))->assertStatus(200);

        // Ninguno desplazó al otro.
        $this->assertSame($sesionA, $this->sesionRegistrada($usuarioA->id));
        $this->assertSame($sesionB, $this->sesionRegistrada($usuarioB->id));
    }

    /* ==================================================================
     | Caso 5: usuario desactivado pierde el acceso en su siguiente petición
     | ================================================================== */

    public function test_usuario_desactivado_pierde_el_acceso_en_su_siguiente_peticion(): void
    {
        $usuario = $this->usuario('COCINERO', 'cocinero_cinco');

        $login = $this->iniciarSesion('cocinero_cinco');
        $cookie = $this->cookieDe($login);

        $this->usarDispositivo($cookie);
        $this->get(route('inicio'))->assertStatus(200);

        DB::table('usuarios')->where('id', $usuario->id)->update(['activo' => 0]);

        $this->usarDispositivo($cookie);
        $this->getJson(route('inicio'))
            ->assertStatus(403)
            ->assertJson(['exito' => false]);

        // La sesión registrada se liberó (solo porque era la propia).
        $this->assertNull($this->sesionRegistrada($usuario->id));
    }

    /* ==================================================================
     | Caso 6: la política aplica a TODOS los roles
     | ================================================================== */

    public function test_la_politica_aplica_a_los_cinco_roles(): void
    {
        $roles = ['ADMIN', 'GERENTE', 'CAJERO', 'MESERO', 'COCINERO'];

        foreach ($roles as $rol) {
            $username = strtolower($rol).'_seis';
            $usuario = $this->usuario($rol, $username);

            $this->nuevoDispositivo();
            $login = $this->iniciarSesion($username);
            $login->assertRedirect(route('inicio'));
            $cookie = $this->cookieDe($login);

            $this->assertNotNull(
                $this->sesionRegistrada($usuario->id),
                "El rol $rol no registró su sesión."
            );

            // Un segundo equipo para el mismo rol también es rechazado.
            $this->nuevoDispositivo();
            $this->iniciarSesion($username)->assertSessionHasErrors('usuario');

            // Y la sesión original sigue entrando.
            $this->usarDispositivo($cookie);
            $this->get(route('inicio'))->assertStatus(200);
        }
    }

    /* ==================================================================
     | Caso 7: vencimiento por 15 minutos de inactividad
     | ================================================================== */

    public function test_tras_15_minutos_sin_actividad_otro_equipo_puede_reclamar_la_sesion(): void
    {
        $usuario = $this->usuario('CAJERO', 'cajero_siete');

        $login = $this->iniciarSesion('cajero_siete');
        $cookieVieja = $this->cookieDe($login);
        $sesionVieja = $this->sesionRegistrada($usuario->id);

        // 16 minutos sin actividad: la sesión se considera vencida.
        DB::table('usuarios')->where('id', $usuario->id)->update([
            'ultima_actividad' => now()->subMinutes(16),
        ]);

        $this->nuevoDispositivo();
        $loginNuevo = $this->iniciarSesion('cajero_siete');
        $loginNuevo->assertRedirect(route('inicio'));

        $sesionNueva = $this->sesionRegistrada($usuario->id);
        $this->assertNotNull($sesionNueva);
        $this->assertNotSame($sesionVieja, $sesionNueva);

        // La sesión vieja ya no puede operar.
        $this->usarDispositivo($cookieVieja);
        $this->getJson(route('inicio'))->assertStatus(401);
    }

    /* ==================================================================
     | Caso 8: una sesión antigua no puede renovar la actividad de la nueva
     | ================================================================== */

    public function test_sesion_antigua_no_puede_actualizar_la_actividad_de_la_nueva(): void
    {
        $usuario = $this->usuario('ADMIN', 'admin_ocho');

        $loginViejo = $this->iniciarSesion('admin_ocho');
        $cookieVieja = $this->cookieDe($loginViejo);

        DB::table('usuarios')->where('id', $usuario->id)->update([
            'ultima_actividad' => now()->subMinutes(16),
        ]);

        $this->nuevoDispositivo();
        $loginNuevo = $this->iniciarSesion('admin_ocho');
        $cookieNueva = $this->cookieDe($loginNuevo);

        $this->usarDispositivo($cookieNueva);
        $this->get(route('inicio'))->assertStatus(200);

        $sesionNueva = $this->sesionRegistrada($usuario->id);
        $actividadNueva = (string) DB::table('usuarios')->where('id', $usuario->id)->value('ultima_actividad');

        // La sesión vieja intenta operar: se rechaza sin tocar la nueva.
        $this->usarDispositivo($cookieVieja);
        $this->getJson(route('inicio'))->assertStatus(401);

        $this->assertSame($sesionNueva, $this->sesionRegistrada($usuario->id));
        $this->assertSame(
            $actividadNueva,
            (string) DB::table('usuarios')->where('id', $usuario->id)->value('ultima_actividad')
        );
    }

    /* ==================================================================
     | Caso 9: el logout de la sesión antigua no libera la sesión nueva
     | ================================================================== */

    public function test_logout_de_la_sesion_antigua_no_libera_la_sesion_nueva(): void
    {
        $usuario = $this->usuario('GERENTE', 'gerente_nueve');

        $loginViejo = $this->iniciarSesion('gerente_nueve');
        $cookieVieja = $this->cookieDe($loginViejo);

        DB::table('usuarios')->where('id', $usuario->id)->update([
            'ultima_actividad' => now()->subMinutes(16),
        ]);

        $this->nuevoDispositivo();
        $loginNuevo = $this->iniciarSesion('gerente_nueve');
        $cookieNueva = $this->cookieDe($loginNuevo);
        $sesionNueva = $this->sesionRegistrada($usuario->id);

        // El dispositivo viejo pulsa Salir: NO debe borrar el registro nuevo.
        $this->usarDispositivo($cookieVieja);
        $this->post(route('logout'))->assertRedirect(route('login'));

        $this->assertSame($sesionNueva, $this->sesionRegistrada($usuario->id));

        // La sesión nueva sigue trabajando.
        $this->usarDispositivo($cookieNueva);
        $this->get(route('inicio'))->assertStatus(200);
    }

    /* ==================================================================
     | Caso 10: concurrencia
     | ================================================================== */

    public function test_dos_intentos_de_login_solo_otorgan_una_sesion(): void
    {
        // SQLite :memory: no reproduce dos transacciones concurrentes reales.
        // Lo que sí se verifica es el resultado exigido: tras dos intentos,
        // SOLO una sesión queda autorizada y la segunda es rechazada.
        // La exclusión real la da el UPDATE condicional único de MySQL, que
        // bloquea la fila y reevalúa el WHERE (ver AuthController).
        $usuario = $this->usuario('ADMIN', 'admin_diez');

        $primerIntento = $this->iniciarSesion('admin_diez');
        $primerIntento->assertRedirect(route('inicio'));
        $primeraSesion = $this->sesionRegistrada($usuario->id);

        $this->nuevoDispositivo();
        $segundoIntento = $this->iniciarSesion('admin_diez');
        $segundoIntento->assertSessionHasErrors('usuario');

        $sesiones = DB::table('usuarios')
            ->where('id', $usuario->id)
            ->whereNotNull('session_id')
            ->pluck('session_id');

        $this->assertCount(1, $sesiones);
        $this->assertSame($primeraSesion, $sesiones->first());
    }

    /* ==================================================================
     | Caso 11: AJAX/API
     | ================================================================== */

    public function test_peticion_json_con_sesion_no_autorizada_recibe_401(): void
    {
        $usuario = $this->usuario('CAJERO', 'cajero_once');

        $loginViejo = $this->iniciarSesion('cajero_once');
        $cookieVieja = $this->cookieDe($loginViejo);

        DB::table('usuarios')->where('id', $usuario->id)->update([
            'ultima_actividad' => now()->subMinutes(16),
        ]);

        $this->nuevoDispositivo();
        $this->iniciarSesion('cajero_once')->assertRedirect(route('inicio'));

        $this->usarDispositivo($cookieVieja);
        $this->getJson(route('inicio'))
            ->assertStatus(401)
            ->assertJson(['exito' => false]);
    }

    /* ==================================================================
     | Caso 12: cambio de contraseña invalida la sesión anterior
     | ================================================================== */

    public function test_cambio_de_contrasena_invalida_la_sesion_anterior(): void
    {
        $usuario = $this->usuario('ADMIN', 'admin_doce');

        $login = $this->iniciarSesion('admin_doce');
        $cookie = $this->cookieDe($login);

        // Primera petición válida: AuthenticateSession guarda el hash actual.
        $this->usarDispositivo($cookie);
        $this->get(route('inicio'))->assertStatus(200);

        // Simula el reset de contraseña que hace un administrador.
        DB::table('usuarios')->where('id', $usuario->id)->update([
            'password_hash' => Hash::make('nuevaClave123'),
        ]);

        // La sesión anterior ya no puede continuar: se redirige al login.
        $this->usarDispositivo($cookie);
        $this->get(route('inicio'))->assertRedirect(route('login'));

        // Y no vuelve a autenticarse con la cookie antigua.
        $this->usarDispositivo($cookie);
        $this->get(route('inicio'))->assertRedirect(route('login'));

        // El registro de sesión quedó libre: el usuario puede entrar DE
        // INMEDIATO con la contraseña nueva (no queda bloqueado 15 minutos).
        $this->assertNull($this->sesionRegistrada($usuario->id));

        $this->nuevoDispositivo();
        $this->iniciarSesion('admin_doce', 'nuevaClave123')->assertRedirect(route('inicio'));
        $this->assertNotNull($this->sesionRegistrada($usuario->id));
    }
}
