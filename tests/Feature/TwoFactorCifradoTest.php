<?php

namespace Tests\Feature;

use App\Models\AuditoriaSeguridad;
use App\Models\Rol;
use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Cifrado seguro del secreto TOTP en `usuarios.two_factor_secret`.
 *
 * El secreto se persiste con Laravel `Crypt` (clave derivada de la APP_KEY).
 * La verificación y el QR trabajan con el valor DESCIFRADO en memoria. Los
 * secretos legacy en texto plano se migran en caliente (re-cifrado) al detectarse.
 *
 * `EnsureTwoFactorVerified` se salta en `testing`; aquí se cambia el entorno a
 * `local` durante cada prueba para ejecutar el middleware REAL (mismo patrón que
 * TwoFactorP0Test). La base sigue siendo SQLite `:memory:`.
 */
class TwoFactorCifradoTest extends TestCase
{
    private string $entornoOriginal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entornoOriginal = $this->app['env'];
        $this->app['env'] = 'local';

        $this->withoutMiddleware(ValidateCsrfToken::class);

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

    protected function tearDown(): void
    {
        $this->app['env'] = $this->entornoOriginal;

        parent::tearDown();
    }

    private function crearUsuario(string $username = 'admin_cifrado_test'): User
    {
        $rol = Rol::create(['nombre' => 'ADMIN', 'activo' => 1]);

        return User::create([
            'nombre' => 'Admin Cifrado',
            'username' => $username,
            'password_hash' => Hash::make('password'),
            'rol_id' => $rol->id,
            'activo' => 1,
        ]);
    }

    private function modo(string $modo): void
    {
        DB::table('configuraciones')->updateOrInsert(
            ['clave' => 'two_factor_modo'],
            ['valor' => $modo]
        );
    }

    /* ------------------------------------------------------------------
     | 1. Los nuevos secretos se almacenan cifrados
     * ------------------------------------------------------------------ */

    public function test_nuevo_secreto_se_almacena_cifrado_y_se_descifra_en_memoria(): void
    {
        $this->modo('obligatorio');
        $user = $this->crearUsuario();
        // Bajo global OBLIGATORIO el usuario queda habilitado (pendiente de enrolle).
        $user->two_factor_enabled = true;
        $user->save();
        $this->actingAs($user);

        $this->get(route('2fa.setup'))->assertOk();

        $user->refresh();
        $servicio = app(TwoFactorService::class);
        $secretoPlano = (string) $servicio->secretPlano($user);

        // El valor persistido en BD NO debe ser el secreto en claro.
        $this->assertNotEmpty($user->two_factor_secret);
        $this->assertNotSame($secretoPlano, $user->two_factor_secret);

        // Y debe ser recuperable con el mecanismo de cifrado de Laravel.
        $this->assertSame($secretoPlano, (string) Crypt::decryptString((string) $user->two_factor_secret));

        // Y en memoria sigue siendo un secreto TOTP válido (32 chars Base32).
        $this->assertSame(32, strlen($secretoPlano));
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secretoPlano);
    }

    /* ------------------------------------------------------------------
     | 2. Transición: secreto legacy en texto plano se migra al cifrado
     * ------------------------------------------------------------------ */

    public function test_secreto_legacy_en_texto_plano_se_migra_y_sigue_validando(): void
    {
        $user = $this->crearUsuario();
        $servicio = app(TwoFactorService::class);

        // Simula un secreto legacy persistido antes del cifrado.
        $secretoLegacy = $servicio->generateSecret();
        $user->two_factor_secret = $secretoLegacy;
        $user->two_factor_enabled = true;
        $user->two_factor_confirmed_at = now();
        $user->save();

        $this->actingAs($user);

        // La verificación con el secreto legacy funciona: al detectarlo se
        // re-cifra (migración en caliente) y el TOTP se valida con el valor en
        // memoria.
        $codigo = $servicio->generateCode($secretoLegacy, intdiv(time(), 30));
        $this->assertTrue($servicio->verifyCodeParaUsuario($user, $codigo));

        // Tras la migración el valor persistido es el CIFRADO (no el legacy).
        $user->refresh();
        $this->assertNotSame($secretoLegacy, $user->two_factor_secret);
        $this->assertSame($secretoLegacy, (string) Crypt::decryptString((string) $user->two_factor_secret));
    }

    /* ------------------------------------------------------------------
     | 3. No cifrar dos veces un secreto ya cifrado
     * ------------------------------------------------------------------ */

    public function test_encriptar_secret_no_cifra_dos_veces(): void
    {
        $servicio = app(TwoFactorService::class);
        $secreto = $servicio->generateSecret();

        $primero = $servicio->encriptarSecret($secreto);

        $this->assertSame($primero, $servicio->encriptarSecret($primero), 'No debe cifrar dos veces un secreto ya cifrado.');
        $this->assertNotSame($secreto, $primero);
        $this->assertSame($secreto, (string) Crypt::decryptString($primero));
    }

    /* ------------------------------------------------------------------
     | 4. Un secreto corrupto falla de forma segura
     * ------------------------------------------------------------------ */

    public function test_secreto_corrupto_falla_segura(): void
    {
        $user = $this->crearUsuario();
        $user->two_factor_secret = '!!!NO-ES-BASE32!!!';
        $user->two_factor_enabled = true;
        $user->two_factor_confirmed_at = now();
        $user->save();

        $this->actingAs($user);
        $servicio = app(TwoFactorService::class);

        $this->assertNull($servicio->secretPlano($user));
        $this->assertFalse($servicio->verifyCodeParaUsuario($user, '123456'));
    }

    /* ------------------------------------------------------------------
     | 5. Reactivación con nuevo secreto cifrado
     * ------------------------------------------------------------------ */

    public function test_reactivar_con_nuevo_secreto_cifrado_y_verificar_totp(): void
    {
        $this->modo('obligatorio');

        $user = $this->crearUsuario('admin_cifrado_reactivar');
        $servicio = app(TwoFactorService::class);
        $user->two_factor_secret = $servicio->encriptarSecret($servicio->generateSecret());
        $user->two_factor_enabled = true;
        $user->two_factor_confirmed_at = now();
        $user->save();

        // Simular desactivación (como haría el ADMIN/GERENTE).
        $user->two_factor_enabled = false;
        $user->two_factor_confirmed_at = null;
        $user->two_factor_secret = null;
        $user->setTwoFactorRecoveryCodes([]);
        $user->save();

        // Reactivación: el ADMIN vuelve a habilitar (pendiente de nuevo enrolle).
        $user->two_factor_enabled = true;
        $user->save();

        // Reactivación: nuevo enrolle con secreto nuevo (siempre cifrado).
        $this->actingAs($user);

        $this->get(route('2fa.setup'))->assertOk();

        $user->refresh();
        $this->assertNotEmpty($user->two_factor_secret);
        $this->assertNotSame((string) $servicio->secretPlano($user), $user->two_factor_secret);

        $nuevoSecret = (string) $servicio->secretPlano($user);
        $codigo = $servicio->generateCode($nuevoSecret, intdiv(time(), 30));

        $respuesta = $this->post(route('2fa.confirm'), ['code' => $codigo]);
        $respuesta->assertOk();

        $user->refresh();
        $this->assertTrue((bool) $user->two_factor_enabled);
        $this->assertNotNull($user->two_factor_confirmed_at);

        // El nuevo secreto también quedó cifrado en BD y el TOTP lo valida.
        $this->assertNotSame($nuevoSecret, $user->two_factor_secret);
        $this->assertSame($nuevoSecret, (string) Crypt::decryptString((string) $user->two_factor_secret));
    }

    /* ------------------------------------------------------------------
     | 6. Los códigos de recuperación siguen funcionando con secreto cifrado
     * ------------------------------------------------------------------ */

    public function test_codigo_de_recuperacion_funciona_con_secreto_cifrado(): void
    {
        $user = $this->crearUsuario();
        $servicio = app(TwoFactorService::class);
        $user->two_factor_secret = $servicio->encriptarSecret($servicio->generateSecret());
        $user->two_factor_enabled = true;
        $user->two_factor_confirmed_at = now();
        $user->setTwoFactorRecoveryCodes(['CODIGOA1B2C3D4E5F6']);
        $user->save();

        $this->actingAs($user);

        $this->assertTrue($servicio->consumirCodigoRecuperacion($user, 'CODIGOA1B2C3D4E5F6'));
        $user->refresh();

        $this->assertSame([], $user->getTwoFactorRecoveryCodes());
    }

    /* ------------------------------------------------------------------
     | 7. Errores y auditoría no exponen el secreto
     * ------------------------------------------------------------------ */

    public function test_error_y_auditoria_no_exponen_el_secreto(): void
    {
        $user = $this->crearUsuario();
        $servicio = app(TwoFactorService::class);
        $secreto = $servicio->generateSecret();
        $user->two_factor_secret = $servicio->encriptarSecret($secreto);
        $user->two_factor_enabled = true;
        $user->two_factor_confirmed_at = now();
        $user->save();

        $this->actingAs($user);

        // Código inválido: el error visible no incluye el secreto.
        $fallo = $this->from(route('2fa.challenge'))->post(route('2fa.verify'), ['code' => '000000']);
        $fallo->assertSessionHasErrors('code');
        $this->assertStringNotContainsString($secreto, $fallo->getContent());

        // La auditoría se registra el evento de fallo sin el secreto.
        $this->assertDatabaseHas('auditoria_seguridad', [
            'usuario_id' => $user->id,
            'evento' => '2fa_validacion_fallida',
        ]);

        $auditoria = AuditoriaSeguridad::where('usuario_id', $user->id)->latest('id')->first();
        $detalles = is_array($auditoria->detalles) ? $auditoria->detalles : [];
        $this->assertNotContains($secreto, $detalles);
    }

    /* ------------------------------------------------------------------
     | 8. Lecturas repetidas no modifican el secreto cifrado
     * ------------------------------------------------------------------ */

    public function test_lecturas_repetidas_no_modifican_el_secreto_cifrado(): void
    {
        $user = $this->crearUsuario();
        $servicio = app(TwoFactorService::class);
        $user->two_factor_secret = $servicio->encriptarSecret($servicio->generateSecret());
        $user->two_factor_enabled = true;
        $user->two_factor_confirmed_at = now();
        $user->save();

        $storedBefore = $user->two_factor_secret;

        $plain1 = $servicio->secretPlano($user);
        $user->refresh();
        $storedAfter1 = $user->two_factor_secret;

        $plain2 = $servicio->secretPlano($user);
        $user->refresh();
        $storedAfter2 = $user->two_factor_secret;

        $this->assertSame($storedBefore, $storedAfter1, 'La primera lectura no debe reescribir el secreto.');
        $this->assertSame($storedBefore, $storedAfter2, 'La segunda lectura no debe reescribir el secreto.');
        $this->assertSame($plain1, $plain2, 'El secreto descifrado debe ser estable.');
    }

    /* ------------------------------------------------------------------
     | 9. La persistencia se mantiene cifrada tras el flujo completo
     * ------------------------------------------------------------------ */

    public function test_persistencia_se_mantiene_cifrada_tras_flujo_completo(): void
    {
        $this->modo('obligatorio');

        $user = $this->crearUsuario('admin_cifrado_persistencia');
        $user->two_factor_enabled = true;
        $user->save();
        $this->actingAs($user);

        $this->get(route('2fa.setup'))->assertOk();
        $user->refresh();
        $storedTrasSetup = $user->two_factor_secret;
        $this->assertNotEmpty($storedTrasSetup);
        $this->assertNotSame((string) app(TwoFactorService::class)->secretPlano($user), $storedTrasSetup);

        $servicio = app(TwoFactorService::class);
        $codigo = $servicio->generateCode((string) $servicio->secretPlano($user), intdiv(time(), 30));
        $this->post(route('2fa.confirm'), ['code' => $codigo])->assertOk();

        $user->refresh();
        $this->assertNotSame((string) $servicio->secretPlano($user), $user->two_factor_secret);
        $this->assertSame(
            (string) $servicio->secretPlano($user),
            (string) Crypt::decryptString((string) $user->two_factor_secret)
        );
    }
}
