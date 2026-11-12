<?php

namespace Tests\Feature;

use App\Models\Rol;
use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Flujo TOTP con QR real.
 *
 * La política global de 2FA se mantiene DESACTIVADA en producción. Estas
 * pruebas activan el modo "obligatorio" solo en la base SQLite :memory: para
 * poder recorrer la configuración sin tocar datos reales.
 */
class TwoFactorQrTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('roles', function ($table) {
            $table->increments('id');
            $table->string('nombre', 30)->unique();
            $table->boolean('activo')->default(true);
        });

        // Esquema mínimo de usuarios. A propósito NO se crean las columnas de
        // sesión única: estas pruebas no validan sesión por dispositivo.
        Schema::create('usuarios', function ($table) {
            $table->increments('id');
            $table->string('nombre', 100);
            $table->string('username', 50)->unique();
            $table->string('password_hash', 255);
            $table->integer('rol_id');
            $table->boolean('activo')->default(true);
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->boolean('two_factor_enabled')->default(false);
        });

        Schema::create('configuraciones', function ($table) {
            $table->id();
            $table->string('clave', 100)->unique();
            $table->string('valor', 255)->nullable();
        });
    }

    private function activarModoObligatorio(): void
    {
        DB::table('configuraciones')->updateOrInsert(
            ['clave' => 'two_factor_modo'],
            ['valor' => 'obligatorio']
        );
    }

    private function crearUsuario(): User
    {
        $rol = Rol::create(['nombre' => 'ADMIN', 'activo' => 1]);

        return User::create([
            'nombre' => 'Admin QR',
            'username' => 'admin_qr_test',
            'password_hash' => Hash::make('password'),
            'rol_id' => $rol->id,
            'activo' => 1,
        ]);
    }

    public function test_setup_genera_qr_svg_real_y_no_regenera_el_secreto_al_recargar(): void
    {
        $this->activarModoObligatorio();
        $user = $this->crearUsuario();
        // Bajo global OBLIGATORIO el usuario queda habilitado (pendiente de
        // enrolle) al activarse la obligación.
        $user->two_factor_enabled = true;
        $user->save();
        $this->actingAs($user);

        $primera = $this->get(route('2fa.setup'));
        $primera->assertOk();
        $primera->assertViewIs('auth.2fa.setup');

        $user->refresh();
        // El valor persistido debe estar CIFRADO (nunca en claro). El secreto
        // en memoria se obtiene DESCIFRADO vía TwoFactorService::secretPlano().
        $servicio = app(TwoFactorService::class);
        $secreto = (string) $servicio->secretPlano($user);

        $this->assertNotEmpty($user->two_factor_secret);
        // La BD no debe contener el secreto en texto plano.
        $this->assertNotSame($secreto, $user->two_factor_secret);

        // generateSecret() genera 32 caracteres Base32 = 160 bits (RFC 4226
        // recomienda >= 128). Se valida la longitud y el alfabeto reales.
        $this->assertSame(32, strlen($secreto));
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secreto);

        $qrDataUri = $primera->viewData('qrDataUri');
        $otpauthUrl = $primera->viewData('otpauthUrl');

        $this->assertIsString($qrDataUri);
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $qrDataUri);
        $this->assertStringStartsWith(
            'otpauth://totp/El%20Lobito:admin_qr_test?secret='.$secreto,
            $otpauthUrl
        );
        $this->assertStringContainsString('&issuer=El%20Lobito', $otpauthUrl);

        // La vista debe incrustar el SVG y mostrar la clave manual alternativa.
        $primera->assertSee('data:image/svg+xml;base64,', false);
        $primera->assertSee($secreto);

        // El SVG debe ser un documento real con geometría, no un patrón simulado.
        $svg = base64_decode(substr($qrDataUri, strlen('data:image/svg+xml;base64,')));
        $this->assertNotFalse($svg);
        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString('<path', $svg);

        // Recargar la pantalla NO debe regenerar ni reemplazar el secreto.
        $segunda = $this->get(route('2fa.setup'));
        $segunda->assertOk();

        // Tras recargar, el secreto sigue siendo el mismo (descifrado en memoria)
        // y el valor persistido sigue siendo el cifrado anterior (no doble cifrado).
        $user->refresh();
        $this->assertSame($secreto, (string) $servicio->secretPlano($user));
        $this->assertSame($qrDataUri, $segunda->viewData('qrDataUri'));
    }

    public function test_confirmar_con_codigo_totp_activa_2fa_y_entrega_codigos_de_recuperacion(): void
    {
        $this->activarModoObligatorio();
        $user = $this->crearUsuario();
        // Bajo global OBLIGATORIO el usuario queda habilitado (pendiente de
        // enrolle) al activarse la obligación; el flag individual lo refleja.
        $user->two_factor_enabled = true;
        $user->save();
        $this->actingAs($user);

        $this->get(route('2fa.setup'))->assertOk();
        $user->refresh();

        $servicio = app(TwoFactorService::class);
        $secreto = (string) $servicio->secretPlano($user);
        $codigo = $servicio->generateCode($secreto, intdiv(time(), 30));

        $respuesta = $this->post(route('2fa.confirm'), ['code' => $codigo]);
        $respuesta->assertOk();
        $respuesta->assertViewIs('auth.2fa.recovery');

        $user->refresh();
        $this->assertTrue((bool) $user->two_factor_enabled);
        $this->assertNotNull($user->two_factor_confirmed_at);

        // El secreto persistido debe seguir siendo el CIFRADO (nunca en claro).
        $this->assertNotSame($secreto, $user->two_factor_secret);

        $codigosRecuperacion = $user->getTwoFactorRecoveryCodes();
        $this->assertCount(8, $codigosRecuperacion);
        $this->assertSame($codigosRecuperacion, $respuesta->viewData('recoveryCodes'));

        // Con la sesión ya verificada, el challenge redirige al inicio.
        $this->get(route('2fa.challenge'))->assertRedirect(route('inicio'));
    }

    /**
     * Código de 6 dígitos que NO corresponde a la ventana actual ni a las
     * ventanas de tolerancia (±1) usadas por verifyCode().
     */
    private function codigoInvalido(TwoFactorService $servicio, string $secreto): string
    {
        $ahora = intdiv(time(), 30);
        $validos = [
            $servicio->generateCode($secreto, $ahora - 1),
            $servicio->generateCode($secreto, $ahora),
            $servicio->generateCode($secreto, $ahora + 1),
        ];

        $invalido = '000000';
        while (in_array($invalido, $validos, true)) {
            $invalido = str_pad((string) (((int) $invalido + 1) % 1000000), 6, '0', STR_PAD_LEFT);
        }

        return $invalido;
    }

    public function test_confirmar_con_codigo_invalido_no_activa_2fa(): void
    {
        $this->activarModoObligatorio();
        $user = $this->crearUsuario();
        // Habilitado (pendiente) por el control individual bajo obligatorio.
        $user->two_factor_enabled = true;
        $user->save();
        $this->actingAs($user);

        $this->get(route('2fa.setup'))->assertOk();
        $user->refresh();

        $servicio = app(TwoFactorService::class);
        $invalido = $this->codigoInvalido($servicio, (string) $servicio->secretPlano($user));

        $respuesta = $this->from(route('2fa.setup'))->post(route('2fa.confirm'), ['code' => $invalido]);
        $respuesta->assertRedirect(route('2fa.setup'));
        $respuesta->assertSessionHasErrors('code');

        $user->refresh();
        // El código inválido no confirma: el usuario sigue PENDIENTE (habilitado
        // sin confirmar) y sin secret regenerado.
        $this->assertTrue((bool) $user->two_factor_enabled);
        $this->assertNull($user->two_factor_confirmed_at);
    }

    public function test_challenge_verifica_totp_y_rechaza_codigo_incorrecto(): void
    {
        $this->activarModoObligatorio();
        $user = $this->crearUsuario();
        $servicio = app(TwoFactorService::class);

        // El secreto se guarda CIFRADO en la base de datos; la verificación
        // TOTP trabaja con el valor DESCIFRADO en memoria.
        $secreto = $servicio->generateSecret();
        $user->two_factor_secret = $servicio->encriptarSecret($secreto);
        $user->two_factor_enabled = true;
        $user->two_factor_confirmed_at = now();
        $user->save();

        $this->actingAs($user);

        $codigo = $servicio->generateCode($secreto, intdiv(time(), 30));

        $this->get(route('2fa.challenge'))->assertOk();
        $respuesta = $this->post(route('2fa.verify'), ['code' => $codigo]);
        $respuesta->assertRedirect(route('inicio'));

        // La sesión guarda marca temporal + sello SHA-256, no un booleano.
        $usuario = $user->refresh();
        $this->assertIsInt(session(TwoFactorService::CLAVE_SESION));
        $this->assertSame(
            $servicio->selloSesion($usuario),
            session(TwoFactorService::CLAVE_SELLO)
        );
        $this->assertTrue(
            $servicio->sesionVerificada($this->app['session']->driver(), $usuario)
        );

        // Un código fuera de la ventana TOTP no debe autenticar.
        $invalido = $this->codigoInvalido($servicio, $secreto);
        $fallo = $this->from(route('2fa.challenge'))->post(route('2fa.verify'), ['code' => $invalido]);
        $fallo->assertRedirect(route('2fa.challenge'));
        $fallo->assertSessionHasErrors('code');
    }
}
