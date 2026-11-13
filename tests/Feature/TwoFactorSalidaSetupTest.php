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
 * Salida segura desde la pantalla "Configuración 2FA Obligatoria".
 *
 * Se ejecuta el middleware REAL cambiando el entorno a `local` (igual que
 * TwoFactorP0Test). La base sigue siendo SQLite :memory: y NO se toca MySQL.
 *
 * Comprueba que:
 * - El botón "Salir sin activar 2FA" existe durante la configuración.
 * - La salida usa el logout oficial, invalida la sesión y regenera el token.
 * - No se activa, borra ni modifica el secreto ni el estado 2FA.
 * - La política se vuelve a aplicar en el siguiente inicio de sesión.
 * - La activación correcta y los usuarios ya enrolados no cambian.
 */
class TwoFactorSalidaSetupTest extends TestCase
{
    private string $entornoOriginal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entornoOriginal = $this->app['env'];
        $this->app['env'] = 'local';

        // Se desactiva SOLO el middleware CSRF para poder simular el envío de
        // formularios. La prueba de CSRF lo vuelve a activar expresamente.
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

    /** Usuario pendiente: la obligación existe, pero el enrolle no se completó. */
    private function usuarioPendiente(string $username): User
    {
        $user = $this->usuario('ADMIN', $username);
        $user->two_factor_enabled = true;
        $user->save();

        return $user->refresh();
    }

    private function activarDosFactores(User $user): User
    {
        $servicio = app(TwoFactorService::class);

        $user->two_factor_secret = $servicio->encriptarSecret($servicio->generateSecret());
        $user->two_factor_enabled = true;
        $user->two_factor_confirmed_at = now();
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

    private function login(string $username): TestResponse
    {
        return $this->post(route('login.post'), [
            'usuario' => $username,
            'password' => 'secret123',
        ]);
    }

    /**
     * Inicia sesión y reenvía la cookie para que la sesión única no rechace
     * las peticiones siguientes (mismo patrón que TwoFactorP0Test).
     */
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

    /* ------------------------------------------------------------------
     | 1. El botón existe durante la configuración
     | ------------------------------------------------------------------ */

    public function test_usuario_pendiente_ve_la_opcion_de_salir_en_setup(): void
    {
        $this->modo('obligatorio');
        $this->usuarioPendiente('salida_pendiente');

        $this->loginWeb('salida_pendiente')->assertRedirect(route('2fa.setup'));

        $this->get(route('2fa.setup'))
            ->assertOk()
            ->assertSee('Salir sin activar 2FA')
            ->assertSee('name="_token"', false)
            ->assertSee(route('logout'), false);
    }

    /* ------------------------------------------------------------------
     | 2. Salir cierra la sesión y no activa ni modifica el 2FA
     | ------------------------------------------------------------------ */

    public function test_salir_sin_activar_cierra_la_sesion_y_no_modifica_el_2fa(): void
    {
        $this->modo('obligatorio');
        $user = $this->usuarioPendiente('salida_cierra');

        $this->loginWeb('salida_cierra')->assertRedirect(route('2fa.setup'));
        $this->get(route('2fa.setup'))->assertOk();

        $user->refresh();
        $secretoAntes = $user->two_factor_secret;
        $this->assertNotEmpty($secretoAntes, 'El setup debe haber generado el secreto pendiente.');

        $this->post(route('logout'), ['motivo' => '2fa_sin_activar'])
            ->assertRedirect(route('login'))
            ->assertSessionHas('info', 'Has salido sin activar la verificación en dos pasos.');

        // La sesión autenticada ya no existe.
        $this->assertGuest();
        $this->get(route('inicio'))->assertRedirect(route('login'));

        // El estado 2FA quedó EXACTAMENTE igual: sigue pendiente.
        $user->refresh();
        $this->assertSame($secretoAntes, $user->two_factor_secret);
        $this->assertTrue((bool) $user->two_factor_enabled);
        $this->assertNull($user->two_factor_confirmed_at);
    }

    public function test_el_login_muestra_el_mensaje_informativo(): void
    {
        $this->modo('obligatorio');
        $this->usuarioPendiente('salida_mensaje');

        $this->loginWeb('salida_mensaje')->assertRedirect(route('2fa.setup'));

        $this->followingRedirects()
            ->post(route('logout'), ['motivo' => '2fa_sin_activar'])
            ->assertOk()
            ->assertSee('Has salido sin activar la verificación en dos pasos.');
    }

    public function test_logout_normal_no_muestra_el_mensaje_de_2fa(): void
    {
        $this->modo('desactivado');
        $this->usuario('CAJERO', 'salida_normal');

        $this->loginWeb('salida_normal')->assertRedirect(route('inicio'));

        $this->post(route('logout'))
            ->assertRedirect(route('login'))
            ->assertSessionMissing('info');
    }

    /* ------------------------------------------------------------------
     | 3. La política se reaplica en el siguiente inicio de sesión
     | ------------------------------------------------------------------ */

    public function test_el_siguiente_login_vuelve_a_exigir_el_setup(): void
    {
        $this->modo('obligatorio');
        $user = $this->usuarioPendiente('salida_reingreso');

        $this->loginWeb('salida_reingreso')->assertRedirect(route('2fa.setup'));
        $this->post(route('logout'), ['motivo' => '2fa_sin_activar'])
            ->assertRedirect(route('login'));

        // La sesión única quedó liberada al salir.
        $this->assertNull(
            DB::table('usuarios')->where('id', $user->id)->value('session_id'),
            'La sesión anterior debe haber sido liberada.'
        );

        // Nuevo inicio de sesión: la obligación sigue vigente.
        $this->loginWeb('salida_reingreso')->assertRedirect(route('2fa.setup'));

        $user->refresh();
        $this->assertTrue((bool) $user->two_factor_enabled);
        $this->assertNull($user->two_factor_confirmed_at);
    }

    /* ------------------------------------------------------------------
     | 4. Usuario ya enrolado: no se le muestra el setup pendiente
     | ------------------------------------------------------------------ */

    public function test_usuario_con_2fa_activo_va_al_challenge_y_no_al_setup(): void
    {
        $this->modo('obligatorio');
        $this->activarDosFactores($this->usuario('GERENTE', 'salida_activo'));

        $this->loginWeb('salida_activo')->assertRedirect(route('2fa.challenge'));

        $this->get(route('2fa.setup'))->assertRedirect(route('2fa.challenge'));
    }

    /* ------------------------------------------------------------------
     | 5. La activación correcta sigue funcionando
     | ------------------------------------------------------------------ */

    public function test_confirmar_el_codigo_sigue_activando_el_2fa(): void
    {
        $this->modo('obligatorio');
        $user = $this->usuarioPendiente('salida_activacion');

        $this->loginWeb('salida_activacion')->assertRedirect(route('2fa.setup'));
        $this->get(route('2fa.setup'))->assertOk();

        $user->refresh();
        $secretoAntes = $user->two_factor_secret;

        $respuesta = $this->post(route('2fa.confirm'), ['code' => $this->codigoTotp($user)]);
        $respuesta->assertOk();
        $respuesta->assertViewIs('auth.2fa.recovery');

        $user->refresh();
        $this->assertTrue((bool) $user->two_factor_enabled);
        $this->assertNotNull($user->two_factor_confirmed_at);
        $this->assertSame($secretoAntes, $user->two_factor_secret, 'El secreto no debe cambiar al confirmar.');
    }

    /* ------------------------------------------------------------------
     | 6. La acción de salida está protegida contra CSRF
     | ------------------------------------------------------------------ */

    public function test_la_salida_requiere_token_csrf(): void
    {
        // Se vuelve a habilitar el middleware CSRF real para esta prueba.
        $this->withMiddleware(ValidateCsrfToken::class);

        $this->modo('obligatorio');
        $user = $this->usuarioPendiente('salida_csrf');

        $this->actingAs($user)
            ->post(route('logout'), ['motivo' => '2fa_sin_activar'])
            ->assertStatus(419);

        $this->assertAuthenticatedAs($user);
    }

    /* ------------------------------------------------------------------
     | 7. Desafío 2FA: usuario que ya tiene el segundo factor activo
     | ------------------------------------------------------------------ */

    public function test_usuario_enrolado_ve_el_boton_salir_en_el_desafio(): void
    {
        $this->modo('obligatorio');
        $this->activarDosFactores($this->usuario('ADMIN', 'desafio_visible'));

        $this->loginWeb('desafio_visible')->assertRedirect(route('2fa.challenge'));

        $this->get(route('2fa.challenge'))
            ->assertOk()
            ->assertSee('SALIR DEL SISTEMA')
            ->assertSee('name="_token"', false)
            ->assertSee(route('logout'), false);
    }

    public function test_salir_desde_el_desafio_sin_codigo_cierra_la_sesion_y_conserva_el_2fa(): void
    {
        $this->modo('obligatorio');
        $user = $this->activarDosFactores($this->usuario('GERENTE', 'desafio_sale'));
        $user->setTwoFactorRecoveryCodes(['CODIGO12345']);
        $user->save();
        $user->refresh();

        $secretoAntes = $user->two_factor_secret;
        $codigosAntes = $user->getTwoFactorRecoveryCodes();

        $this->loginWeb('desafio_sale')->assertRedirect(route('2fa.challenge'));
        $this->get(route('2fa.challenge'))->assertOk();

        // Sin introducir el código, una ruta protegida sigue bloqueada.
        $this->get(route('inicio'))->assertRedirect(route('2fa.challenge'));

        $this->post(route('logout'), ['motivo' => '2fa_challenge_salir'])
            ->assertRedirect(route('login'))
            ->assertSessionHas('info', 'Has cerrado tu sesión sin completar la verificación 2FA.');

        // La autenticación pendiente queda invalidada.
        $this->assertGuest();
        $this->get(route('inicio'))->assertRedirect(route('login'));

        // La sesión única quedó liberada.
        $this->assertNull(DB::table('usuarios')->where('id', $user->id)->value('session_id'));

        // El estado 2FA permanece intacto.
        $user->refresh();
        $this->assertSame($secretoAntes, $user->two_factor_secret);
        $this->assertSame($codigosAntes, $user->getTwoFactorRecoveryCodes());
        $this->assertTrue((bool) $user->two_factor_enabled);
        $this->assertNotNull($user->two_factor_confirmed_at);
    }

    public function test_el_siguiente_login_vuelve_a_pedir_el_codigo(): void
    {
        $this->modo('obligatorio');
        $this->activarDosFactores($this->usuario('CAJERO', 'desafio_reingreso'));

        $this->loginWeb('desafio_reingreso')->assertRedirect(route('2fa.challenge'));
        $this->post(route('logout'), ['motivo' => '2fa_challenge_salir'])
            ->assertRedirect(route('login'));

        $this->loginWeb('desafio_reingreso')->assertRedirect(route('2fa.challenge'));
        $this->get(route('inicio'))->assertRedirect(route('2fa.challenge'));
    }

    public function test_la_verificacion_correcta_sigue_funcionando(): void
    {
        $this->modo('obligatorio');
        $user = $this->activarDosFactores($this->usuario('GERENTE', 'desafio_verifica'));

        $this->loginWeb('desafio_verifica')->assertRedirect(route('2fa.challenge'));

        $this->post(route('2fa.verify'), ['code' => $this->codigoTotp($user)])
            ->assertRedirect(route('inicio'));

        $this->get(route('inicio'))->assertOk();
        $this->assertTrue(
            app(TwoFactorService::class)->sesionVerificada($this->app['session']->driver(), $user->refresh())
        );
    }

    public function test_la_salida_desde_el_desafio_requiere_token_csrf(): void
    {
        $this->withMiddleware(ValidateCsrfToken::class);

        $this->modo('obligatorio');
        $user = $this->activarDosFactores($this->usuario('ADMIN', 'desafio_csrf'));

        $this->actingAs($user)
            ->post(route('logout'), ['motivo' => '2fa_challenge_salir'])
            ->assertStatus(419);

        $this->assertAuthenticatedAs($user);
    }
}
