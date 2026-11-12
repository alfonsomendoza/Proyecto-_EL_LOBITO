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
 * Regresión de los dos fallos P0 del flujo 2FA:
 *
 *  1. Clave de sesión unificada en `two_factor_verificado`: confirmar el QR,
 *     validar el TOTP o usar un código de recuperación debe permitir entrar,
 *     sin bucles de redirección entre `/inicio` y `/2fa/challenge`.
 *  2. `/2fa/recovery` no puede revelar los códigos antes de completar el
 *     segundo factor cuando la política lo exige.
 *
 * `EnsureTwoFactorVerified` se salta en el entorno `testing` para no romper
 * pruebas antiguas. Aquí se cambia el entorno de la aplicación a `local`
 * durante cada prueba para ejecutar el middleware REAL. La base sigue siendo
 * SQLite :memory:: no se toca MySQL, ni usuarios, ni sesiones reales.
 */
class TwoFactorP0Test extends TestCase
{
    private string $entornoOriginal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entornoOriginal = $this->app['env'];
        $this->app['env'] = 'local';

        // Al salir de `testing`, Laravel vuelve a exigir CSRF. Se desactiva
        // SOLO ese middleware para estas pruebas; el resto (sesión única,
        // 2FA, rol y dispositivo) se ejecuta de verdad.
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

    private function activarDosFactores(User $user, array $codigosRecuperacion = []): User
    {
        $servicio = app(TwoFactorService::class);

        // El secreto se guarda CIFRADO en la base de datos (con cifrado vía
        // Laravel Crypt / APP_KEY).
        $user->two_factor_secret = $servicio->encriptarSecret($servicio->generateSecret());
        $user->two_factor_enabled = true;
        $user->two_factor_confirmed_at = now();
        $user->setTwoFactorRecoveryCodes($codigosRecuperacion);
        $user->save();

        return $user->refresh();
    }

    private function codigoTotp(User $user): string
    {
        // El secreto se trabaja DESCIFRADO en memoria (nunca en claro).
        return app(TwoFactorService::class)->generateCode(
            (string) app(TwoFactorService::class)->secretPlano($user),
            intdiv(time(), 30)
        );
    }

    /**
     * La sesión ya no guarda un booleano: guarda la marca temporal de la
     * verificación y el sello SHA-256 que la ata a la política vigente y al
     * estado 2FA del usuario. Se comprueba el comportamiento REAL de
     * TwoFactorService, no solo un valor de sesión.
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

    private function login(string $username, string $password = 'secret123'): TestResponse
    {
        return $this->post(route('login.post'), [
            'usuario' => $username,
            'password' => $password,
        ]);
    }

    /**
     * Inicia sesión y devuelve la cookie de sesión para las peticiones
     * siguientes. El arnés de pruebas no reenvía cookies automáticamente;
     * sin esto, cada petición sería una sesión distinta y la política de
     * sesión única la rechazaría.
     */
    private function loginWeb(string $username): TestResponse
    {
        $respuesta = $this->login($username);
        $this->withCookie(config('session.cookie'), $this->cookieDe($respuesta));

        return $respuesta;
    }

    /* ------------------------------------------------------------------
     | 1. Confirmación TOTP y acceso posterior
     | ------------------------------------------------------------------ */

    public function test_confirmar_totp_permite_acceso_posterior_sin_bucle(): void
    {
        $this->modo('obligatorio');
        $user = $this->usuario('ADMIN', 'p0_admin');
        // Bajo global OBLIGATORIO el usuario queda habilitado (pendiente de
        // enrolle) al activarse la obligación; el flag individual lo refleja.
        $user->two_factor_enabled = true;
        $user->save();

        $this->loginWeb('p0_admin')->assertRedirect(route('2fa.setup'));

        $this->get(route('2fa.setup'))->assertOk();
        $user->refresh();
        $this->assertNotEmpty($user->two_factor_secret);

        $respuesta = $this->post(route('2fa.confirm'), ['code' => $this->codigoTotp($user)]);
        $respuesta->assertOk();
        $respuesta->assertViewIs('auth.2fa.recovery');

        $this->assertSesionVerificada($user);

        // Sin bucle: la aplicación deja pasar a una ruta protegida.
        $this->get(route('inicio'))->assertOk();
    }

    /* ------------------------------------------------------------------
     | 2. Challenge TOTP y acceso posterior
     | ------------------------------------------------------------------ */

    public function test_validar_challenge_totp_permite_acceso_posterior_sin_bucle(): void
    {
        $this->modo('obligatorio');
        $user = $this->activarDosFactores($this->usuario('GERENTE', 'p0_gerente'));

        $this->loginWeb('p0_gerente')->assertRedirect(route('2fa.challenge'));
        $this->get(route('2fa.challenge'))->assertOk();

        $this->post(route('2fa.verify'), ['code' => $this->codigoTotp($user)])
            ->assertRedirect(route('inicio'));

        $this->assertSesionVerificada($user);
        $this->get(route('inicio'))->assertOk();
    }

    /* ------------------------------------------------------------------
     | 3. Código de recuperación válido y acceso posterior
     | ------------------------------------------------------------------ */

    public function test_codigo_de_recuperacion_permite_acceso_y_se_consume(): void
    {
        $this->modo('obligatorio');
        $user = $this->activarDosFactores(
            $this->usuario('CAJERO', 'p0_cajero'),
            ['ABCDEFGHIJ', 'KLMNOPQRST']
        );

        $this->loginWeb('p0_cajero')->assertRedirect(route('2fa.challenge'));

        $this->post(route('2fa.verify'), ['code' => 'ABCDEFGHIJ'])
            ->assertRedirect(route('inicio'));

        $this->assertSesionVerificada($user);
        $this->get(route('inicio'))->assertOk();

        $user->refresh();
        $this->assertSame(['KLMNOPQRST'], $user->getTwoFactorRecoveryCodes());
    }

    /* ------------------------------------------------------------------
     | 4. `/2fa/recovery` bloqueado antes de verificar
     | ------------------------------------------------------------------ */

    public function test_recovery_bloqueado_antes_de_verificar_con_2fa_configurado(): void
    {
        $this->modo('obligatorio');
        $this->activarDosFactores(
            $this->usuario('MESERO', 'p0_mesero'),
            ['ABCDEFGHIJ']
        );

        $this->loginWeb('p0_mesero')->assertRedirect(route('2fa.challenge'));
        $this->assertFalse((bool) session('two_factor_verificado'));

        // Con solo la contraseña NO se puede llegar a los códigos.
        $this->get(route('2fa.recovery'))->assertRedirect(route('2fa.challenge'));
        $this->assertFalse((bool) session('two_factor_verificado'));
    }

    public function test_recovery_bloqueado_antes_de_verificar_sin_2fa_configurado(): void
    {
        $this->modo('obligatorio');
        $this->usuario('COCINERO', 'p0_cocinero');
        // Con el control individual, un usuario bajo global OBLIGATORIO queda
        // habilitado (pendiente de enrolle) al activarse la obligación.
        $user = User::where('username', 'p0_cocinero')->first();
        $user->two_factor_enabled = true;
        $user->save();

        $this->loginWeb('p0_cocinero')->assertRedirect(route('2fa.setup'));

        $this->get(route('2fa.recovery'))->assertRedirect(route('2fa.setup'));
    }

    /* ------------------------------------------------------------------
     | 5. Acceso legítimo a recovery después de verificar
     | ------------------------------------------------------------------ */

    public function test_recovery_accesible_despues_de_verificar(): void
    {
        $this->modo('obligatorio');
        $user = $this->activarDosFactores(
            $this->usuario('ADMIN', 'p0_recovery'),
            ['ABCDEFGHIJ']
        );

        $this->loginWeb('p0_recovery')->assertRedirect(route('2fa.challenge'));
        $this->post(route('2fa.verify'), ['code' => $this->codigoTotp($user)])
            ->assertRedirect(route('inicio'));

        $respuesta = $this->get(route('2fa.recovery'));
        $respuesta->assertOk();
        $respuesta->assertViewIs('auth.2fa.recovery-show');
        $respuesta->assertSee('ABCDEFGHIJ');
    }

    /* ------------------------------------------------------------------
     | 6. DESACTIVADO permite el login normal
     | ------------------------------------------------------------------ */

    public function test_desactivado_permite_login_normal_sin_2fa(): void
    {
        $this->modo('desactivado');
        $this->usuario('CAJERO', 'p0_desactivado');

        $this->loginWeb('p0_desactivado')->assertRedirect(route('inicio'));
        $this->assertTrue((bool) session('two_factor_verificado'));
        $this->get(route('inicio'))->assertOk();
    }

    /* ------------------------------------------------------------------
     | 7. La sesión única sigue funcionando
     | ------------------------------------------------------------------ */

    public function test_sesion_unica_sigue_funcionando(): void
    {
        $this->modo('desactivado');
        $user = $this->usuario('ADMIN', 'p0_sesion');

        $primera = $this->loginWeb('p0_sesion');
        $primera->assertRedirect(route('inicio'));

        $sesionOriginal = DB::table('usuarios')->where('id', $user->id)->value('session_id');
        $this->assertNotNull($sesionOriginal);
        $cookieOriginal = $this->cookieDe($primera);

        // Segundo dispositivo: navegador limpio, sin la cookie original.
        $this->nuevoDispositivo();
        $segunda = $this->login('p0_sesion');
        $segunda->assertSessionHasErrors('usuario');

        $this->assertSame(
            $sesionOriginal,
            DB::table('usuarios')->where('id', $user->id)->value('session_id')
        );

        // La sesión original sigue trabajando.
        $this->usarDispositivo($cookieOriginal);
        $this->get(route('inicio'))->assertOk();
    }

    /* ------------------------------------------------------------------
     | 8. Sesión abierta antes de activar OBLIGATORIO
     | ------------------------------------------------------------------ */

    public function test_sesion_abierta_antes_de_activar_obligatorio_debe_pasar_por_challenge(): void
    {
        $this->modo('desactivado');
        $user = $this->activarDosFactores($this->usuario('GERENTE', 'p0_politica'));

        // Con la política DESACTIVADA la sesión entra sin segundo factor y
        // queda marcada como verificada por el login normal.
        $this->loginWeb('p0_politica')->assertRedirect(route('inicio'));
        $this->get(route('inicio'))->assertOk();

        // ADMIN activa OBLIGATORIO por la ruta administrativa existente.
        $this->modo('obligatorio');

        // La marca anterior no sirve: hay que superar el desafío TOTP.
        $this->get(route('inicio'))->assertRedirect(route('2fa.challenge'));

        $this->post(route('2fa.verify'), ['code' => $this->codigoTotp($user)])
            ->assertRedirect(route('inicio'));

        $this->get(route('inicio'))->assertOk();
    }

    /* ------------------------------------------------------------------
     | 9. Restablecimiento de 2FA: exclusivo de ADMIN
     | ------------------------------------------------------------------ */

    public function test_gerente_no_puede_restablecer_el_2fa_de_otro_usuario(): void
    {
        $this->modo('desactivado');
        $objetivo = $this->activarDosFactores(
            $this->usuario('CAJERO', 'p0_objetivo'),
            ['ABCDEFGHIJ']
        );

        $this->usuario('GERENTE', 'p0_gerente_reset');
        $this->loginWeb('p0_gerente_reset')->assertRedirect(route('inicio'));

        $this->post(route('admin.2fa.reset', $objetivo->id))->assertForbidden();

        $objetivo->refresh();
        $this->assertTrue((bool) $objetivo->two_factor_enabled);
        $this->assertNotEmpty($objetivo->two_factor_secret);
        $this->assertSame(['ABCDEFGHIJ'], $objetivo->getTwoFactorRecoveryCodes());
    }

    public function test_admin_puede_restablecer_el_2fa_de_otro_usuario(): void
    {
        $this->modo('desactivado');
        $objetivo = $this->activarDosFactores(
            $this->usuario('MESERO', 'p0_objetivo_mesero'),
            ['ABCDEFGHIJ']
        );

        $this->usuario('ADMIN', 'p0_admin_reset');
        $this->loginWeb('p0_admin_reset')->assertRedirect(route('inicio'));

        $this->post(route('admin.2fa.reset', $objetivo->id))->assertRedirect();

        $objetivo->refresh();
        $this->assertNull($objetivo->two_factor_secret);
        $this->assertNull($objetivo->two_factor_confirmed_at);
        $this->assertFalse((bool) $objetivo->two_factor_enabled);
        $this->assertSame([], $objetivo->getTwoFactorRecoveryCodes());
    }

    /* ------------------------------------------------------------------
     | Utilidades de "dispositivo" (mismo patrón que SesionUnicaTest)
     | ------------------------------------------------------------------ */

    private function nuevoDispositivo(): void
    {
        $this->defaultCookies = [];
        $this->withCredentials = false;
        $this->app['auth']->forgetGuards();
        $this->app['session']->flush();
    }

    private function usarDispositivo(string $sesionId): void
    {
        $this->nuevoDispositivo();
        $this->withCookie(config('session.cookie'), $sesionId);
        $this->withCredentials();
    }

    private function cookieDe(TestResponse $respuesta): string
    {
        $cookie = $respuesta->getCookie(config('session.cookie'), true);

        $this->assertNotNull($cookie, 'La respuesta no incluyó cookie de sesión.');

        return $cookie->getValue();
    }
}
