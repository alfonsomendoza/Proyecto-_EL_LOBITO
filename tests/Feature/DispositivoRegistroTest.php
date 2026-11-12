<?php

namespace Tests\Feature;

use App\Models\Dispositivo;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Registro y autenticación de dispositivos.
 *
 * Todas las pruebas usan SQLite :memory: y construyen su propio esquema; nunca
 * tocan MySQL ni datos reales. No se usan helpers destructivos: cada prueba
 * recibe una base en memoria nueva.
 *
 * Reglas comprobadas:
 * - El alta exige sesión y rol ADMIN.
 * - El token se entrega una única vez, al crear el dispositivo.
 * - Un identificador duplicado no devuelve ni rota credenciales.
 * - Los tokens nuevos se guardan como hash y los antiguos en claro siguen
 *   autenticando (compatibilidad con dispositivos ya registrados).
 * - Un token revocado deja de autenticar.
 * - Respuestas y auditoría no exponen tokens.
 */
class DispositivoRegistroTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Candado de seguridad: NUNCA se ejecuta contra MySQL.
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

        // Se omiten session_id/ultima_actividad a propósito: esta prueba no
        // valida sesión única y así el middleware correspondiente no interfiere
        // (mismo criterio que el resto de pruebas de módulos).
        Schema::create('usuarios', function (Blueprint $t) {
            $t->increments('id');
            $t->string('nombre', 100);
            $t->string('username', 50)->unique();
            $t->string('password_hash', 255);
            $t->integer('rol_id');
            $t->boolean('activo')->default(1);
            $t->integer('dispositivo_id')->nullable();
        });

        Schema::create('dispositivos', function (Blueprint $t) {
            $t->increments('id');
            $t->string('identificador', 255)->unique();
            $t->string('nombre', 100)->nullable();
            $t->string('token_dispositivo', 255)->nullable();
            $t->integer('usuario_id')->nullable();
            $t->string('ip_registro', 45)->nullable();
            $t->text('user_agent')->nullable();
            $t->timestamp('registrado_en')->nullable();
            $t->timestamp('ultimo_uso')->nullable();
            $t->boolean('autorizado')->default(false);
            $t->boolean('activo')->default(true);
            $t->timestamp('desautorizado_en')->nullable();
            $t->integer('desautorizado_por')->nullable();
        });

        Schema::create('auditoria_seguridad', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->integer('usuario_id')->nullable();
            $t->string('evento', 100);
            $t->string('ip', 45)->nullable();
            $t->text('user_agent')->nullable();
            $t->text('motivo')->nullable();
            $t->json('detalles')->nullable();
            $t->timestamp('creado_en')->useCurrent();
        });
    }

    private function usuario(string $rol, ?string $username = null): User
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
            'username' => $username ?? strtolower($rol),
            'password_hash' => Hash::make('secret123'),
            'rol_id' => $rolId,
            'activo' => 1,
        ]);

        return User::findOrFail($id);
    }

    private function dispositivo(array $atributos = []): Dispositivo
    {
        return Dispositivo::create(array_merge([
            'identificador' => 'PC-TEST',
            'nombre' => 'Equipo de prueba',
            'token_dispositivo' => null,
            'autorizado' => false,
            'activo' => true,
            'registrado_en' => now(),
        ], $atributos));
    }

    private function credencial(string $token): string
    {
        return 'sha256:'.hash('sha256', $token);
    }

    /* ------------------------------------------------------------------
     | Acceso al alta de dispositivos
     | ------------------------------------------------------------------ */

    public function test_usuario_no_autenticado_no_puede_registrar(): void
    {
        $respuesta = $this->postJson(route('dispositivo.registrar'), [
            'identificador' => 'PC-SIN-SESION',
            'nombre' => 'PC sin sesión',
        ]);

        $respuesta->assertStatus(401);
        $this->assertDatabaseCount('dispositivos', 0);
    }

    public function test_no_autenticado_no_puede_obtener_el_token_de_un_dispositivo_existente(): void
    {
        $tokenExistente = Str::random(64);
        $this->dispositivo([
            'identificador' => 'PC-EXISTENTE',
            'token_dispositivo' => $tokenExistente,
            'autorizado' => true,
        ]);

        $respuesta = $this->postJson(route('dispositivo.registrar'), [
            'identificador' => 'PC-EXISTENTE',
        ]);

        $respuesta->assertStatus(401);
        $this->assertStringNotContainsString($tokenExistente, $respuesta->getContent());
    }

    public function test_roles_distintos_de_admin_reciben_403(): void
    {
        foreach (['GERENTE', 'CAJERO', 'MESERO', 'COCINERO'] as $rol) {
            $usuario = $this->usuario($rol, strtolower($rol));

            $respuesta = $this->actingAs($usuario)->postJson(route('dispositivo.registrar'), [
                'identificador' => 'PC-'.$rol,
            ]);

            $respuesta->assertStatus(403);
        }

        $this->assertDatabaseCount('dispositivos', 0);
    }

    /* ------------------------------------------------------------------
     | Alta autorizada
     | ------------------------------------------------------------------ */

    public function test_admin_registra_dispositivo_y_recibe_el_token_una_sola_vez(): void
    {
        $admin = $this->usuario('ADMIN', 'admin');

        $respuesta = $this->actingAs($admin)->postJson(route('dispositivo.registrar'), [
            'identificador' => 'PC-CAJA-01',
            'nombre' => 'Caja 1',
        ]);

        $respuesta->assertOk()
            ->assertJsonPath('exito', true)
            ->assertJsonPath('autorizado', false)
            ->assertJsonStructure(['exito', 'token', 'autorizado']);

        $token = $respuesta->json('token');
        $this->assertIsString($token);
        $this->assertSame(64, strlen($token));

        $dispositivo = Dispositivo::where('identificador', 'PC-CAJA-01')->firstOrFail();
        $this->assertNotSame($token, $dispositivo->token_dispositivo);
        $this->assertSame($this->credencial($token), $dispositivo->token_dispositivo);
        $this->assertStringStartsWith('sha256:', $dispositivo->token_dispositivo);
        $this->assertFalse($dispositivo->autorizado);
        $this->assertTrue($dispositivo->activo);

        // La auditoría no contiene el token en claro.
        $auditoria = DB::table('auditoria_seguridad')->pluck('detalles')->implode(' ');
        $this->assertStringNotContainsString($token, (string) $auditoria);
    }

    public function test_admin_no_puede_registrar_sin_identificador(): void
    {
        $admin = $this->usuario('ADMIN', 'admin');

        $this->actingAs($admin)
            ->postJson(route('dispositivo.registrar'), ['nombre' => 'Sin identificador'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('identificador');

        $this->assertDatabaseCount('dispositivos', 0);
    }

    /* ------------------------------------------------------------------
     | Duplicados: no devolver ni rotar credenciales
     | ------------------------------------------------------------------ */

    public function test_registro_duplicado_no_devuelve_ni_rota_el_token(): void
    {
        $admin = $this->usuario('ADMIN', 'admin');
        $tokenExistente = Str::random(64);

        $this->dispositivo([
            'identificador' => 'PC-CAJA-02',
            'token_dispositivo' => $tokenExistente,
            'autorizado' => true,
        ]);

        $respuesta = $this->actingAs($admin)->postJson(route('dispositivo.registrar'), [
            'identificador' => 'PC-CAJA-02',
            'nombre' => 'Intento duplicado',
        ]);

        $respuesta->assertStatus(409)
            ->assertJsonPath('exito', false)
            ->assertJsonMissingPath('token');

        $this->assertStringNotContainsString($tokenExistente, $respuesta->getContent());

        $dispositivo = Dispositivo::where('identificador', 'PC-CAJA-02')->firstOrFail();
        $this->assertSame($tokenExistente, $dispositivo->token_dispositivo);
        $this->assertSame(1, Dispositivo::where('identificador', 'PC-CAJA-02')->count());
    }

    public function test_registro_duplicado_con_token_nulo_no_genera_credenciales(): void
    {
        $admin = $this->usuario('ADMIN', 'admin');

        $this->dispositivo([
            'identificador' => 'PC-SIN-TOKEN',
            'token_dispositivo' => null,
        ]);

        $respuesta = $this->actingAs($admin)->postJson(route('dispositivo.registrar'), [
            'identificador' => 'PC-SIN-TOKEN',
        ]);

        $respuesta->assertStatus(409)->assertJsonMissingPath('token');

        $dispositivo = Dispositivo::where('identificador', 'PC-SIN-TOKEN')->firstOrFail();
        $this->assertNull($dispositivo->token_dispositivo);
    }

    public function test_el_token_de_un_dispositivo_no_autentica_a_otro(): void
    {
        $tokenA = Str::random(64);
        $tokenB = Str::random(64);

        $this->dispositivo([
            'identificador' => 'PC-A',
            'token_dispositivo' => $this->credencial($tokenA),
            'autorizado' => true,
        ]);

        $this->dispositivo([
            'identificador' => 'PC-B',
            'token_dispositivo' => $this->credencial($tokenB),
            'autorizado' => true,
        ]);

        $this->postJson(route('dispositivo.verificar'), [
            'identificador' => 'PC-B',
            'token' => $tokenA,
        ])->assertStatus(401)->assertJsonPath('exito', false);
    }

    /* ------------------------------------------------------------------
     | Autenticación y revocación
     | ------------------------------------------------------------------ */

    public function test_token_hasheado_autentica_y_su_revocacion_lo_invalida(): void
    {
        $token = Str::random(64);

        $dispositivo = $this->dispositivo([
            'identificador' => 'PC-REVOCABLE',
            'token_dispositivo' => $this->credencial($token),
            'autorizado' => true,
            'activo' => true,
        ]);

        $this->postJson(route('dispositivo.verificar'), [
            'identificador' => 'PC-REVOCABLE',
            'token' => $token,
        ])->assertOk()->assertJsonPath('autorizado', true);

        // Revocado por estado.
        $dispositivo->activo = false;
        $dispositivo->save();

        $this->postJson(route('dispositivo.verificar'), [
            'identificador' => 'PC-REVOCABLE',
            'token' => $token,
        ])->assertStatus(403);

        // Reactivado pero pendiente de autorización.
        $dispositivo->activo = true;
        $dispositivo->autorizado = false;
        $dispositivo->save();

        $this->postJson(route('dispositivo.verificar'), [
            'identificador' => 'PC-REVOCABLE',
            'token' => $token,
        ])->assertStatus(403);

        // Credencial eliminada: ya no autentica.
        $dispositivo->autorizado = true;
        $dispositivo->token_dispositivo = null;
        $dispositivo->save();

        $this->postJson(route('dispositivo.verificar'), [
            'identificador' => 'PC-REVOCABLE',
            'token' => $token,
        ])->assertStatus(401);
    }

    public function test_dispositivo_ya_registrado_con_token_en_claro_sigue_autenticando(): void
    {
        $token = Str::random(64);

        $this->dispositivo([
            'identificador' => 'PC-LEGADO',
            'token_dispositivo' => $token,
            'autorizado' => true,
            'activo' => true,
        ]);

        $this->postJson(route('dispositivo.verificar'), [
            'identificador' => 'PC-LEGADO',
            'token' => $token,
        ])->assertOk()->assertJsonPath('autorizado', true);

        // Tras autenticar, el token heredado se migra a hash sin cambiarlo.
        $dispositivo = Dispositivo::where('identificador', 'PC-LEGADO')->firstOrFail();
        $this->assertSame($this->credencial($token), $dispositivo->token_dispositivo);
    }

    public function test_token_incorrecto_no_revela_el_token_almacenado(): void
    {
        $tokenReal = Str::random(64);

        $this->dispositivo([
            'identificador' => 'PC-PRIVADO',
            'token_dispositivo' => $tokenReal,
            'autorizado' => true,
        ]);

        $respuesta = $this->postJson(route('dispositivo.verificar'), [
            'identificador' => 'PC-PRIVADO',
            'token' => 'token-incorrecto',
        ]);

        $respuesta->assertStatus(401);
        $this->assertStringNotContainsString($tokenReal, $respuesta->getContent());
    }
}
