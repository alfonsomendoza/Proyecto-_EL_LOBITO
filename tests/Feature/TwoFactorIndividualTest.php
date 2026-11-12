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
 * Control global e individual del 2FA (ADMIN/GERENTE).
 *
 * Cubre el requerimiento de administración global e individual:
 *
 *  - ADMIN y GERENTE pueden ACTIVAR / DESACTIVAR el 2FA de un usuario.
 *  - Ambos pueden gestionar su propio 2FA.
 *  - Solo ADMIN y GERENTE pueden modificar los controles (403 para otros).
 *  - Desactivar invalida el enrolle y no afecta a los demás usuarios.
 *  - Reautenticación obligatoria para cambios sensibles.
 *  - Auditoría de quién modificó el 2FA, a qué usuario y cuándo.
 *  - Interacción global (obligatorio) e individual (excepción).
 *  - Login con 2FA desactivado no exige código TOTP.
 *  - Reenrolamiento (activar → setup → confirmar) tras desactivar.
 *
 * `EnsureTwoFactorVerified` se salta en `testing`; aquí se cambia el entorno a
 * `local` durante cada prueba para ejecutar el middleware REAL (mismo patrón que
 * TwoFactorP0Test). La base sigue siendo SQLite `:memory:`.
 */
class TwoFactorIndividualTest extends TestCase
{
    private string $entornoOriginal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entornoOriginal = $this->app['env'];
        $this->app['env'] = 'local';

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
            $t->integer('dispositivo_id')->nullable();
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

        Schema::create('auditoria_seguridad', function (Blueprint $t) {
            $t->bigInteger('id')->autoIncrement();
            $t->integer('usuario_id')->nullable();
            $t->string('evento', 100);
            $t->string('ip', 45)->nullable();
            $t->text('user_agent')->nullable();
            $t->text('motivo')->nullable();
            $t->json('detalles')->nullable();
            $t->timestamp('creado_en')->useCurrent();
        });
    }

    /* ------------------------------------------------------------------
     | Utilidades
     * ------------------------------------------------------------------ */

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

        // El secreto se guarda CIFRADO en la base de datos.
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

    private function login(string $username, string $password = 'secret123'): TestResponse
    {
        return $this->post(route('login.post'), [
            'usuario' => $username,
            'password' => $password,
        ]);
    }

    /**
     * Login REAL (con cookie de sesión) para poder pasar el middleware de sesión
     * única y llegar al controlador.
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
     | 1. ADMIN gestiona el 2FA de OTRO usuario
     * ------------------------------------------------------------------ */

    public function test_admin_puede_desactivar_y_activar_el_2fa_de_un_usuario(): void
    {
        $this->modo('desactivado');

        $objetivo = $this->activarDosFactores($this->usuario('CAJERO', 'tfa_objetivo'), ['ABCDEFGHIJ']);
        $otro = $this->activarDosFactores($this->usuario('MESERO', 'tfa_otro'), ['KLMNOPQRST']);

        $admin = $this->usuario('ADMIN', 'tfa_admin_gestion');
        $this->loginWeb('tfa_admin_gestion')->assertRedirect(route('inicio'));

        // Desactivar: invalida enrolle (secret, códigos, confirmación).
        $this->post(route('admin.2fa.desactivar', $objetivo->id))
            ->assertRedirect();

        $objetivo->refresh();
        $this->assertFalse((bool) $objetivo->two_factor_enabled);
        // Desactivar conserva el enrolle (secreto cifrado, confirmación, códigos).
        $this->assertNotNull($objetivo->two_factor_confirmed_at);
        $this->assertNotNull($objetivo->two_factor_secret, 'Desactivar no debe eliminar el secreto cifrado.');
        $this->assertSame(['ABCDEFGHIJ'], $objetivo->getTwoFactorRecoveryCodes());

        // El otro usuario NO se ve afectado.
        $otro->refresh();
        $this->assertTrue((bool) $otro->two_factor_enabled);
        $this->assertNotNull($otro->two_factor_confirmed_at);

        // Activar: habilita el control individual (conserva el enrolle existente).
        $this->post(route('admin.2fa.activar', $objetivo->id))
            ->assertRedirect();

        $objetivo->refresh();
        $this->assertTrue((bool) $objetivo->two_factor_enabled);
        $this->assertNotNull($objetivo->two_factor_confirmed_at);
        $this->assertNotNull($objetivo->two_factor_secret);
    }

    /* ------------------------------------------------------------------
     | 2. GERENTE gestiona el 2FA de OTRO usuario
     * ------------------------------------------------------------------ */

    public function test_gerente_puede_desactivar_y_activar_el_2fa_de_un_usuario(): void
    {
        $this->modo('desactivado');

        $objetivo = $this->activarDosFactores($this->usuario('CAJERO', 'tfa_ger_objetivo'), ['ABCDEFGHIJ']);

        $gerente = $this->usuario('GERENTE', 'tfa_gerente_gestion');
        $this->loginWeb('tfa_gerente_gestion')->assertRedirect(route('inicio'));

        $this->post(route('admin.2fa.desactivar', $objetivo->id))
            ->assertRedirect();

        $objetivo->refresh();
        $this->assertFalse((bool) $objetivo->two_factor_enabled);
        $this->assertNotNull($objetivo->two_factor_secret, 'Desactivar no debe eliminar el secreto cifrado.');

        $this->post(route('admin.2fa.activar', $objetivo->id))
            ->assertRedirect();

        $objetivo->refresh();
        $this->assertTrue((bool) $objetivo->two_factor_enabled);
        $this->assertNotNull($objetivo->two_factor_confirmed_at);
    }

    /* ------------------------------------------------------------------
     | 3. Usuarios sin permiso no pueden administrar el 2FA
     * ------------------------------------------------------------------ */

    public function test_usuario_sin_permiso_no_puede_modificar_el_2fa(): void
    {
        $admin = $this->usuario('ADMIN', 'tfa_admin_perm');
        $cajero = $this->usuario('CAJERO', 'tfa_cajero_perm');

        $this->loginWeb('tfa_cajero_perm')->assertRedirect(route('inicio'));

        $this->post(route('admin.2fa.desactivar', $admin->id))
            ->assertForbidden();

        $this->post(route('admin.2fa.activar', $admin->id))
            ->assertForbidden();

        $admin->refresh();
        $this->assertFalse((bool) $admin->two_factor_enabled);
    }

    /* ------------------------------------------------------------------
     | 4. ADMIN y GERENTE gestionan su PROPIO 2FA (con reautenticación)
     * ------------------------------------------------------------------ */

    public function test_admin_activa_y_desactiva_su_propio_2fa(): void
    {
        $this->modo('desactivado');

        $admin = $this->activarDosFactores($this->usuario('ADMIN', 'tfa_admin_propio'), ['ABCDEFGHIJ']);
        $this->loginWeb('tfa_admin_propio')->assertRedirect(route('inicio'));

        $this->post(route('admin.2fa.desactivar', $admin->id))
            ->assertRedirect();

        $admin->refresh();
        $this->assertFalse((bool) $admin->two_factor_enabled);
        // Desactivar conserva el enrolle (secreto cifrado y confirmación).
        $this->assertNotNull($admin->two_factor_confirmed_at);
        $this->assertNotNull($admin->two_factor_secret);

        // Reactivar.
        $this->post(route('admin.2fa.activar', $admin->id))
            ->assertRedirect();

        $admin->refresh();
        $this->assertTrue((bool) $admin->two_factor_enabled);
        $this->assertNotNull($admin->two_factor_confirmed_at);
        $this->assertNotNull($admin->two_factor_secret);
    }

    public function test_gerente_activa_y_desactiva_su_propio_2fa(): void
    {
        $this->modo('desactivado');

        $gerente = $this->activarDosFactores($this->usuario('GERENTE', 'tfa_gerente_propio'), ['ABCDEFGHIJ']);
        $this->loginWeb('tfa_gerente_propio')->assertRedirect(route('inicio'));

        $this->post(route('admin.2fa.desactivar', $gerente->id))
            ->assertRedirect();

        $gerente->refresh();
        $this->assertFalse((bool) $gerente->two_factor_enabled);

        $this->post(route('admin.2fa.activar', $gerente->id))
            ->assertRedirect();

        $gerente->refresh();
        $this->assertTrue((bool) $gerente->two_factor_enabled);
        $this->assertNotNull($gerente->two_factor_confirmed_at);
    }

    /* ------------------------------------------------------------------
     | 5. Interacción entre control global e individual
     * ------------------------------------------------------------------ */

    public function test_interaccion_global_desactivado_con_individual_habilitado(): void
    {
        // Global DESACTIVADO + control individual habilitado → NO exige 2FA
        // (la obligatoriedad global desactivada manda).
        $this->modo('desactivado');
        $user1 = $this->activarDosFactores($this->usuario('MESERO', 'tfa_mesero_int'), ['ABCDEFGHIJ']);

        $this->loginWeb('tfa_mesero_int')->assertRedirect(route('inicio'));
    }

    public function test_interaccion_global_obligatorio_individual_deshabilitado(): void
    {
        // Global OBLIGATORIO + control individual DESHABILITADO (excepción
        // autorizada por ADMIN/GERENTE) → NO exige 2FA.
        $this->modo('obligatorio');

        $admin = $this->usuario('ADMIN', 'tfa_admin_int');
        $user2 = $this->activarDosFactores($this->usuario('MESERO', 'tfa_mesero_int2'), ['ABCDEFGHIJ']);
        $this->loginWeb('tfa_admin_int')->assertRedirect(route('inicio'));

        $this->post(route('admin.2fa.desactivar', $user2->id))
            ->assertRedirect();

        $user2->refresh();
        $this->assertFalse((bool) $user2->two_factor_enabled);

        // Tras la excepción, el login del usuario deshabilitado va directo al inicio.
        $this->post(route('logout'));
        $this->loginWeb('tfa_mesero_int2')->assertRedirect(route('inicio'));
    }

    public function test_interaccion_global_obligatorio_individual_habilitado(): void
    {
        // Global OBLIGATORIO + control individual HABILITADO → exige 2FA
        // (challenge TOTP) hasta que el usuario confirme.
        $this->modo('obligatorio');
        $user3 = $this->activarDosFactores($this->usuario('MESERO', 'tfa_mesero_int3'), ['ABCDEFGHIJ']);

        $this->loginWeb('tfa_mesero_int3')->assertRedirect(route('2fa.challenge'));
    }

    /* ------------------------------------------------------------------
     | 6. Login con 2FA desactivado no pide código TOTP
     * ------------------------------------------------------------------ */

    public function test_login_con_2fa_desactivado_no_pide_codigo_totp(): void
    {
        $this->modo('obligatorio');

        $admin = $this->usuario('ADMIN', 'tfa_admin_bl');
        $objetivo = $this->usuario('CAJERO', 'tfa_cajero_bl');
        $this->loginWeb('tfa_admin_bl')->assertRedirect(route('inicio'));
        $this->post(route('admin.2fa.desactivar', $objetivo->id))
            ->assertRedirect();

        // Con 2FA desactivado (excepción a un global OBLIGATORIO) el login va
        // directo al inicio, sin challenge TOTP.
        $this->loginWeb('tfa_cajero_bl')->assertRedirect(route('inicio'));
    }

    /* ------------------------------------------------------------------
     | 7. Login con 2FA activado exige challenge TOTP
     * ------------------------------------------------------------------ */

    public function test_login_con_2fa_activado_exige_challenge_totp(): void
    {
        $this->modo('obligatorio');

        $user = $this->activarDosFactores($this->usuario('CAJERO', 'tfa_cajero_activo'));

        $this->loginWeb('tfa_cajero_activo')->assertRedirect(route('2fa.challenge'));

        $servicio = app(TwoFactorService::class);
        $codigo = $servicio->generateCode((string) $servicio->secretPlano($user), intdiv(time(), 30));

        $this->post(route('2fa.verify'), ['code' => $codigo])
            ->assertRedirect(route('inicio'));

        $this->assertTrue(
            $servicio->sesionVerificada($this->app['session']->driver(), $user->refresh())
        );
    }

    /* ------------------------------------------------------------------
     | 8. Reenrolamiento tras reactivar el 2FA
     * ------------------------------------------------------------------ */

    public function test_reactivar_2fa_redirige_a_enrolle_y_verificacion(): void
    {
        $this->modo('obligatorio');

        $admin = $this->usuario('ADMIN', 'tfa_admin_re');
        $objetivo = $this->usuario('CAJERO', 'tfa_cajero_re');
        $this->loginWeb('tfa_admin_re')->assertRedirect(route('inicio'));
        $respuestaActivar = $this->post(route('admin.2fa.activar', $objetivo->id));
        $respuestaActivar->assertRedirect();

        // El usuario queda habilitado en estado PENDIENTE (sin confirmar): al
        // reactivar debe pasar por setup (enrolle) y confirmar.
        $objetivo->refresh();
        $this->assertTrue((bool) $objetivo->two_factor_enabled);
        $this->assertNull($objetivo->two_factor_confirmed_at);

        // Cerrar la sesión del admin para poder volver a entrar como el usuario.
        $this->post(route('logout'));

        $respuestaLogin = $this->loginWeb('tfa_cajero_re');
        $respuestaLogin->assertRedirect(route('2fa.setup'));

        $this->get(route('2fa.setup'))->assertOk();

        $user = User::where('username', 'tfa_cajero_re')->first();
        $user->refresh();
        $this->assertNotEmpty($user->two_factor_secret);

        $this->post(route('2fa.confirm'), ['code' => $this->codigoTotp($user)])
            ->assertOk();

        $user->refresh();
        $this->assertTrue((bool) $user->two_factor_enabled);
        $this->assertNotNull($user->two_factor_confirmed_at);

        // Con el enrolle confirmado, la sesión queda verificada y el acceso pasa.
        $this->get(route('inicio'))->assertOk();
    }

    /* ------------------------------------------------------------------
     | 9. Auditoría de quién modificó el 2FA
     * ------------------------------------------------------------------ */

    public function test_auditoria_registra_modificaciones_de_2fa(): void
    {
        $this->modo('desactivado');

        $admin = $this->usuario('ADMIN', 'tfa_admin_audit');
        $objetivo = $this->usuario('CAJERO', 'tfa_cajero_audit');
        $this->loginWeb('tfa_admin_audit')->assertRedirect(route('inicio'));

        $this->post(route('admin.2fa.desactivar', $objetivo->id))
            ->assertRedirect();

        $this->assertDatabaseHas('auditoria_seguridad', [
            'usuario_id' => $objetivo->id,
            'evento' => '2fa_desactivado',
        ]);

        $this->post(route('admin.2fa.activar', $objetivo->id))
            ->assertRedirect();

        $this->assertDatabaseHas('auditoria_seguridad', [
            'usuario_id' => $objetivo->id,
            'evento' => '2fa_activado',
        ]);
    }

    /* ------------------------------------------------------------------
     | 10. Global OBLIGATORIO + GERENTE individual DESACTIVADO: sin TOTP
     * ------------------------------------------------------------------ */

    public function test_global_obligatorio_gerente_individual_desactivado_login_sin_totp(): void
    {
        $this->modo('obligatorio');

        $gerente = $this->activarDosFactores($this->usuario('GERENTE', 'tfa_ger_desact'));
        $admin = $this->usuario('ADMIN', 'tfa_admin_desact_ger');
        $this->loginWeb('tfa_admin_desact_ger')->assertRedirect(route('inicio'));

        $this->post(route('admin.2fa.desactivar', $gerente->id))
            ->assertRedirect();

        $gerente->refresh();
        $this->assertFalse((bool) $gerente->two_factor_enabled);

        $this->post(route('logout'));
        $this->loginWeb('tfa_ger_desact')->assertRedirect(route('inicio'));
    }

    /* ------------------------------------------------------------------
     | 11. Global OBLIGATORIO + ADMIN individual ACTIVADO: exige TOTP
     * ------------------------------------------------------------------ */

    public function test_global_obligatorio_admin_individual_activado_login_exige_totp(): void
    {
        $this->modo('obligatorio');

        $admin = $this->activarDosFactores($this->usuario('ADMIN', 'tfa_admin_act'));
        $this->loginWeb('tfa_admin_act')->assertRedirect(route('2fa.challenge'));
    }
}
