<?php

namespace Tests\Feature;

use App\Models\Rol;
use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Reglas de seguridad del login y del desafío 2FA.
 *
 * Se ejecuta el middleware real cambiando el entorno a `local` (igual que
 * TwoFactorP0Test). La base sigue siendo SQLite :memory: y NO se toca MySQL.
 *
 * Cubre:
 *  - Bloqueo progresivo del login (3 fallos → 30/60/120/300/600 segundos).
 *  - Bloqueo por 3 códigos 2FA incorrectos: logout oficial + espera de 30 s.
 *  - Expiración del desafío 2FA a los 60 segundos (reloj del servidor).
 *  - Sin reinicio del temporizador al recargar o fallar un código.
 *  - Invalidación de la sesión pendiente en los cierres automáticos.
 */
class SeguridadIntentosAutenticacionTest extends TestCase
{
    private string $entornoOriginal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entornoOriginal = $this->app['env'];
        $this->app['env'] = 'local';

        // Solo CSRF queda fuera para poder simular formularios.
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $this->crearEsquema();
    }

    protected function tearDown(): void
    {
        $this->app['env'] = $this->entornoOriginal;

        parent::tearDown();
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
            $t->text('two_factor_secret')->nullable();
            $t->text('two_factor_recovery_codes')->nullable();
            $t->timestamp('two_factor_confirmed_at')->nullable();
            $t->boolean('two_factor_enabled')->default(false);
        });

        Schema::create('configuraciones', function (Blueprint $t) {
            $t->id();
            $t->string('clave', 100)->unique();
            $t->string('valor', 255)->nullable();
        });
    }

    /* ------------------------------------------------------------------
     | Utilidades
     | ------------------------------------------------------------------ */

    private function modo(string $modo): void
    {
        DB::table('configuraciones')->updateOrInsert(
            ['clave' => 'two_factor_modo'],
            ['valor' => $modo]
        );
    }

    private function usuario(string $rolNombre, string $username): User
    {
        $rol = Rol::firstOrCreate(
            ['nombre' => $rolNombre],
            ['nombre' => $rolNombre, 'activo' => 1]
        );

        return User::create([
            'nombre' => $rolNombre.' Test',
            'username' => $username,
            'password_hash' => Hash::make('secret123'),
            'rol_id' => $rol->id,
            'activo' => 1,
        ]);
    }

    private function activarDosFactores(User $user, array $codigos = []): User
    {
        $servicio = app(TwoFactorService::class);

        $user->two_factor_secret = $servicio->encriptarSecret($servicio->generateSecret());
        $user->two_factor_enabled = true;
        $user->two_factor_confirmed_at = now();
        $user->setTwoFactorRecoveryCodes($codigos);
        $user->save();

        return $user->refresh();
    }

    private function codigoTotp(User $user): string
    {
        $servicio = app(TwoFactorService::class);

        return $servicio->generateCode(
            (string) $servicio->secretPlano($user),
            intdiv(time(), 30)
        );
    }

    private function login(string $username, string $password = 'secret123'): TestResponse
    {
        return $this->post(route('login.post'), [
            'usuario' => $username,
            'password' => $password,
        ]);
    }

    private function loginWeb(string $username): TestResponse
    {
        $respuesta = $this->login($username);
        $this->withCookie(config('session.cookie'), $this->cookieDe($respuesta));

        return $respuesta;
    }

    private function cookieDe(TestResponse $respuesta): string
    {
        $cookie = $respuesta->getCookie(config('session.cookie'), true);

        $this->assertNotNull($cookie, 'La respuesta no incluyó cookie de sesión.');

        return $cookie->getValue();
    }

    private function error(TestResponse $respuesta, string $clave = 'usuario'): string
    {
        return (string) session('errors')->first($clave);
    }

    private function fallarPassword(string $username, int $veces): void
    {
        for ($i = 1; $i <= $veces; $i++) {
            $this->post(route('login.post'), [
                'usuario' => $username,
                'password' => 'incorrecta',
            ])->assertRedirect(route('login'));
        }
    }

    /* ------------------------------------------------------------------
     | 1. Login: bloqueo progresivo por contraseñas incorrectas
     | ------------------------------------------------------------------ */

    public function test_dos_errores_no_bloquean_todavia(): void
    {
        $this->usuario('CAJERO', 'seg_login_dos_errores');

        $respuestaUno = $this->post(route('login.post'), [
            'usuario' => 'seg_login_dos_errores',
            'password' => 'incorrecta',
        ]);
        $respuestaDos = $this->post(route('login.post'), [
            'usuario' => 'seg_login_dos_errores',
            'password' => 'incorrecta',
        ]);

        $this->assertStringContainsString(
            'credenciales',
            $this->error($respuestaUno)
        );
        $this->assertStringContainsString(
            'credenciales',
            $this->error($respuestaDos)
        );

        // Con los dos primeros fallos, el inicio correcto sigue permitido.
        $this->login('seg_login_dos_errores')->assertRedirect(route('inicio'));
    }

    public function test_el_tercer_error_activa_30_segundos_y_bloquea_el_acceso(): void
    {
        $this->usuario('CAJERO', 'seg_login_tercero');

        $this->fallarPassword('seg_login_tercero', 2);

        $bloqueado = $this->post(route('login.post'), [
            'usuario' => 'seg_login_tercero',
            'password' => 'incorrecta',
        ]);

        $this->assertStringContainsString('30 segundos', $this->error($bloqueado));

        // Durante el bloqueo tampoco se acepta la contraseña correcta.
        $bloqueado = $this->login('seg_login_tercero');

        $bloqueado->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertStringContainsString('30 segundos', $this->error($bloqueado));
    }

    public function test_la_escalada_de_bloqueos_respeta_la_escala_hasta_600(): void
    {
        $this->usuario('CAJERO', 'seg_login_escala');

        $esperados = [30, 60, 120, 300, 600, 600];

        foreach ($esperados as $segundos) {
            $this->fallarPassword('seg_login_escala', 2);

            $bloqueado = $this->post(route('login.post'), [
                'usuario' => 'seg_login_escala',
                'password' => 'incorrecta',
            ]);

            $this->assertStringContainsString(
                $segundos.' segundos',
                $this->error($bloqueado),
                "Se esperaba un bloqueo de {$segundos} segundos."
            );

            $this->travel($segundos + 1)->seconds();
        }

        // El tiempo máximo no supera los 600 segundos.
        $this->fallarPassword('seg_login_escala', 2);
        $bloqueado = $this->post(route('login.post'), [
            'usuario' => 'seg_login_escala',
            'password' => 'incorrecta',
        ]);
        $this->assertStringContainsString('600 segundos', $this->error($bloqueado));
    }

    public function test_un_login_correcto_reinicia_el_contador_y_la_escalada(): void
    {
        $this->usuario('CAJERO', 'seg_login_reset');

        $this->fallarPassword('seg_login_reset', 3);
        $this->travel(31)->seconds();

        // Login correcto tras el bloqueo: reinicia fallos y escalada.
        $this->login('seg_login_reset')->assertRedirect(route('inicio'));

        $this->post(route('logout'))->assertRedirect(route('login'));

        // El siguiente bloqueo vuelve a ser el primero (30 segundos).
        $this->fallarPassword('seg_login_reset', 3);

        $bloqueado = $this->login('seg_login_reset');
        $this->assertStringContainsString('30 segundos', $this->error($bloqueado));
    }

    public function test_el_bloqueo_persiste_aunque_cambie_la_sesion(): void
    {
        $this->usuario('CAJERO', 'seg_login_sesion');

        $this->fallarPassword('seg_login_sesion', 3);

        // Simular «otro navegador»: sesión nueva, sin cookies previas.
        $this->defaultCookies = [];
        $this->withCredentials = false;
        $this->flushSession();

        $bloqueado = $this->login('seg_login_sesion');

        $bloqueado->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertStringContainsString('30 segundos', $this->error($bloqueado));
    }

    public function test_el_error_no_revela_si_la_cuenta_existe(): void
    {
        $this->usuario('CAJERO', 'seg_login_enum');

        $real = $this->post(route('login.post'), [
            'usuario' => 'seg_login_enum',
            'password' => 'incorrecta',
        ]);
        $inexistente = $this->post(route('login.post'), [
            'usuario' => 'cuenta_que_no_existe',
            'password' => 'incorrecta',
        ]);

        $this->assertSame($this->error($real), $this->error($inexistente));
    }

    /* ------------------------------------------------------------------
     | 2. Desafío 2FA: tres códigos incorrectos
     | ------------------------------------------------------------------ */

    public function test_dos_codigos_incorrectos_no_cierran_y_el_tercero_si(): void
    {
        $this->modo('obligatorio');
        $user = $this->activarDosFactores(
            $this->usuario('GERENTE', 'seg_2fa_tercero'),
            ['ABCDEFGHIJ']
        );
        $secretoAntes = $user->two_factor_secret;

        $this->loginWeb('seg_2fa_tercero')->assertRedirect(route('2fa.challenge'));
        $this->get(route('2fa.challenge'))->assertOk();

        // Dos errores: la sesión pendiente sigue abierta.
        for ($i = 1; $i <= 2; $i++) {
            $this->from(route('2fa.challenge'))
                ->post(route('2fa.verify'), ['code' => '000000'])
                ->assertSessionHasErrors('code');

            $this->assertAuthenticatedAs($user);
            $this->get(route('2fa.challenge'))->assertOk();
        }

        // Tercer error: se cierra la sesión con el logout oficial.
        $this->from(route('2fa.challenge'))
            ->post(route('2fa.verify'), ['code' => '000000'])
            ->assertRedirect(route('login'))
            ->assertSessionHas(
                'info',
                'Has alcanzado el límite de intentos 2FA. Espera 30 segundos e inténtalo de nuevo.'
            );

        $this->assertGuest();
        $this->assertNull(DB::table('usuarios')->where('id', $user->id)->value('session_id'));

        // El 2FA no se toca.
        $user->refresh();
        $this->assertSame($secretoAntes, $user->two_factor_secret);
        $this->assertSame(['ABCDEFGHIJ'], $user->getTwoFactorRecoveryCodes());
        $this->assertTrue((bool) $user->two_factor_enabled);
        $this->assertNotNull($user->two_factor_confirmed_at);

        // Durante los 30 segundos no se puede volver a entrar.
        $bloqueado = $this->login('seg_2fa_tercero');
        $bloqueado->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertStringContainsString('30 segundos', $this->error($bloqueado));
    }

    public function test_tras_el_bloqueo_el_login_vuelve_a_pedir_contraseña_y_2fa(): void
    {
        $this->modo('obligatorio');
        $user = $this->activarDosFactores($this->usuario('GERENTE', 'seg_2fa_reingreso'));

        $this->loginWeb('seg_2fa_reingreso')->assertRedirect(route('2fa.challenge'));
        $this->get(route('2fa.challenge'))->assertOk();

        for ($i = 1; $i <= 3; $i++) {
            $this->from(route('2fa.challenge'))
                ->post(route('2fa.verify'), ['code' => '000000']);
        }

        $this->assertGuest();
        $this->travel(31)->seconds();

        // Contraseña + nuevo desafío 2FA.
        $this->loginWeb('seg_2fa_reingreso')->assertRedirect(route('2fa.challenge'));
        $this->get(route('inicio'))->assertRedirect(route('2fa.challenge'));
        $this->post(route('2fa.verify'), ['code' => $this->codigoTotp($user)])
            ->assertRedirect(route('inicio'));
        $this->get(route('inicio'))->assertOk();
    }

    public function test_un_codigo_correcto_sigue_funcionando_dentro_del_plazo(): void
    {
        $this->modo('obligatorio');
        $user = $this->activarDosFactores($this->usuario('GERENTE', 'seg_2fa_correcto'));

        $this->loginWeb('seg_2fa_correcto')->assertRedirect(route('2fa.challenge'));
        $this->get(route('2fa.challenge'))->assertOk();

        $this->travel(59)->seconds();

        $this->post(route('2fa.verify'), ['code' => $this->codigoTotp($user)])
            ->assertRedirect(route('inicio'));

        $this->get(route('inicio'))->assertOk();
    }

    /* ------------------------------------------------------------------
     | 3. Desafío 2FA: expiración a los 60 segundos
     | ------------------------------------------------------------------ */

    public function test_al_cumplirse_los_60_segundos_expira_la_sesion(): void
    {
        $this->modo('obligatorio');
        $user = $this->activarDosFactores($this->usuario('GERENTE', 'seg_2fa_timeout'));

        $this->loginWeb('seg_2fa_timeout')->assertRedirect(route('2fa.challenge'));
        $this->get(route('2fa.challenge'))->assertOk();

        $this->travel(60)->seconds();

        $this->get(route('2fa.challenge'))
            ->assertRedirect(route('login'))
            ->assertSessionHas(
                'info',
                'Tu sesión se cerró porque no completaste la verificación 2FA dentro del tiempo permitido'
            );

        $this->assertGuest();
        $this->assertNull(DB::table('usuarios')->where('id', $user->id)->value('session_id'));
    }

    public function test_recargar_el_challenge_no_reinicia_el_plazo(): void
    {
        $this->modo('obligatorio');
        $this->activarDosFactores($this->usuario('GERENTE', 'seg_2fa_reload'));

        $this->loginWeb('seg_2fa_reload')->assertRedirect(route('2fa.challenge'));
        $this->get(route('2fa.challenge'))->assertOk();

        // Recargas dentro del plazo: no reinician el temporizador.
        $this->travel(30)->seconds();
        $this->get(route('2fa.challenge'))->assertOk();
        $this->travel(15)->seconds();
        $this->get(route('2fa.challenge'))->assertOk();

        // 60 segundos desde el inicio, no desde la última recarga.
        $this->travel(15)->seconds();

        $this->get(route('2fa.challenge'))
            ->assertRedirect(route('login'))
            ->assertSessionHas(
                'info',
                'Tu sesión se cerró porque no completaste la verificación 2FA dentro del tiempo permitido'
            );
        $this->assertGuest();
    }

    public function test_un_codigo_incorrecto_no_reinicia_el_plazo(): void
    {
        $this->modo('obligatorio');
        $user = $this->activarDosFactores($this->usuario('GERENTE', 'seg_2fa_no_reset'));

        $this->loginWeb('seg_2fa_no_reset')->assertRedirect(route('2fa.challenge'));
        $this->get(route('2fa.challenge'))->assertOk();

        $this->travel(45)->seconds();

        $this->from(route('2fa.challenge'))
            ->post(route('2fa.verify'), ['code' => '000000'])
            ->assertSessionHasErrors('code');

        // 20 segundos después (65 desde el inicio): el código queda fuera de plazo.
        $this->travel(20)->seconds();

        $this->post(route('2fa.verify'), ['code' => $this->codigoTotp($user)])
            ->assertRedirect(route('login'))
            ->assertSessionHas(
                'info',
                'Tu sesión se cerró porque no completaste la verificación 2FA dentro del tiempo permitido'
            );
        $this->assertGuest();
    }

    public function test_un_codigo_correcto_despues_de_los_60_segundos_se_rechaza(): void
    {
        $this->modo('obligatorio');
        $user = $this->activarDosFactores($this->usuario('GERENTE', 'seg_2fa_tardio'));

        $this->loginWeb('seg_2fa_tardio')->assertRedirect(route('2fa.challenge'));
        $this->get(route('2fa.challenge'))->assertOk();

        $this->travel(61)->seconds();

        // Aunque sea el código correcto de la ventana actual.
        $this->post(route('2fa.verify'), ['code' => $this->codigoTotp($user)])
            ->assertRedirect(route('login'))
            ->assertSessionHas(
                'info',
                'Tu sesión se cerró porque no completaste la verificación 2FA dentro del tiempo permitido'
            );

        $this->assertGuest();
        $this->get(route('inicio'))->assertRedirect(route('login'));
    }

    public function test_la_expiracion_se_aplica_tambien_al_flujo_de_verificacion_administrado(): void
    {
        $this->modo('obligatorio');
        $this->activarDosFactores($this->usuario('GERENTE', 'seg_2fa_legado'));

        $this->loginWeb('seg_2fa_legado')->assertRedirect(route('2fa.challenge'));
        $this->travel(61)->seconds();

        $this->post(route('2fa.verificar'), ['codigo' => '000000'])
            ->assertRedirect(route('login'))
            ->assertSessionHas(
                'info',
                'Tu sesión se cerró porque no completaste la verificación 2FA dentro del tiempo permitido'
            );

        $this->assertGuest();
    }

    public function test_el_confirm_de_setup_no_puede_saltarse_el_desafio(): void
    {
        $this->modo('obligatorio');
        $user = $this->activarDosFactores($this->usuario('GERENTE', 'seg_2fa_confirm'));

        $this->loginWeb('seg_2fa_confirm')->assertRedirect(route('2fa.challenge'));
        $this->get(route('2fa.challenge'))->assertOk();

        // Un confirm directo dentro del plazo no completa la verificación.
        $this->post(route('2fa.confirm'), ['code' => $this->codigoTotp($user)])
            ->assertRedirect(route('2fa.challenge'));

        // Sigue pendiente: no completa la verificación.
        $this->get(route('inicio'))->assertRedirect(route('2fa.challenge'));

        // Fuera de plazo, el confirm cierra la sesión pendiente.
        $this->travel(61)->seconds();
        $this->post(route('2fa.confirm'), ['code' => $this->codigoTotp($user)])
            ->assertRedirect(route('login'))
            ->assertSessionHas(
                'info',
                'Tu sesión se cerró porque no completaste la verificación 2FA dentro del tiempo permitido'
            );

        $this->assertGuest();
    }

    public function test_una_sesion_expirada_no_puede_acceder_a_rutas_protegidas(): void
    {
        $this->modo('obligatorio');
        $this->activarDosFactores($this->usuario('GERENTE', 'seg_2fa_protegida'));

        $this->loginWeb('seg_2fa_protegida')->assertRedirect(route('2fa.challenge'));
        $this->travel(61)->seconds();

        // El middleware redirige al desafío; al abrirlo se cierra la sesión.
        $this->get(route('inicio'))->assertRedirect(route('2fa.challenge'));
        $this->get(route('2fa.challenge'))->assertRedirect(route('login'));

        $this->assertGuest();
        $this->get(route('inicio'))->assertRedirect(route('login'));
    }
}
