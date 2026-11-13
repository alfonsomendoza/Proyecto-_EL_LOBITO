<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/*
 * ABRIR MESA SIN CAJA ABIERTA
 * ===========================
 *
 * Regresión del bug reportado: el Monitor de Mesas hace fetch a /mesas/api
 * sin cabeceras JSON, pero la respuesta llegaba como HTML (redirección del
 * middleware CajaAbierta) y el front la intentaba interpretar como JSON,
 * provocando el diálogo «Abriendo mesa...» seguido de un error genérico.
 *
 * Verifica:
 *  - Una solicitud API (fetch con Accept JSON) sin caja abierta recibe
 *    422 y un cuerpo JSON con el mensaje exacto y la marca caja_cerrada.
 *   - Una solicitud web tradicional (POST normal de navegador) SIN caja
 *    abierta conserva la redirección a /caja/apertura.
 *  - Ninguno de ADMIN, GERENTE, CAJERO, MESERO puede abrir mesa sin caja.
 *  - Con caja abierta, el flujo normal funciona.
 *  - Ningún intento bloqueado crea ventas ni cuentas.
 *  - El front del Monitor de Mesas envía Accept: application/json y comprueba
 *    el content-type antes de interpretar la respuesta.
 *
 * Corren en SQLite :memory: (phpunit.xml); nunca tocan MySQL real.
 */
class MesaAbrirSinCajaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('sqlite', DB::connection()->getDriverName(), 'Las pruebas deben ejecutarse en SQLite.');
        $this->assertSame(':memory:', (string) config('database.connections.sqlite.database'), 'La base de pruebas debe ser SQLite en memoria.');

        $this->crearEsquema();
    }

    private function crearEsquema(): void
    {
        foreach (['clientes', 'mesas', 'comandas', 'cuenta_detalles', 'venta_detalles', 'cuentas', 'ventas', 'usuarios', 'roles', 'caja_apertura', 'caja_bloqueo'] as $tabla) {
            Schema::dropIfExists($tabla);
        }

        Schema::create('roles', function (Blueprint $t) {
            $t->increments('id');
            $t->string('nombre', 30);
        });

        Schema::create('usuarios', function (Blueprint $t) {
            $t->increments('id');
            $t->string('nombre', 100)->nullable();
            $t->string('username', 50)->nullable();
            $t->string('password_hash', 255)->nullable();
            $t->integer('rol_id')->nullable();
            $t->boolean('activo')->default(true);
        });

        Schema::create('mesas', function (Blueprint $t) {
            $t->increments('id');
            $t->string('numero', 20)->nullable();
            $t->integer('capacidad')->nullable();
            $t->string('estado', 20)->default('LIBRE');
        });

        Schema::create('clientes', function (Blueprint $t) {
            $t->increments('id');
            $t->string('nombre', 150);
            $t->timestamp('creado_en')->nullable();
        });

        Schema::create('ventas', function (Blueprint $t) {
            $t->increments('id');
            $t->unsignedBigInteger('mesa_id')->nullable();
            $t->unsignedBigInteger('cliente_id')->nullable();
            $t->integer('apertura_id')->nullable();
            $t->string('descripcion')->nullable();
            $t->decimal('monto', 12, 2)->default(0);
            $t->string('metodo_pago', 30)->nullable();
            $t->string('banco', 30)->nullable();
            $t->decimal('monto_recibido', 12, 2)->nullable();
            $t->decimal('tasa_cambio', 12, 4)->nullable();
            $t->decimal('cambio', 12, 2)->nullable();
            $t->string('estado', 20)->default('ACTIVA');
            $t->timestamp('fecha')->nullable();
            $t->integer('usuario_id')->nullable();
            $t->decimal('descuento_porcentaje', 5, 2)->default(0);
            $t->integer('mesero_responsable_id')->nullable();
        });

        Schema::create('venta_detalles', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->integer('venta_id');
            $t->integer('producto_id')->nullable();
            $t->integer('cantidad')->default(1);
            $t->decimal('precio_unitario', 10, 2)->default(0);
            $t->decimal('subtotal', 10, 2)->default(0);
            $t->string('estado', 255)->default('ACTIVO');
            $t->timestamps();
        });

        Schema::create('comandas', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->integer('venta_id')->nullable();
            $t->unsignedBigInteger('mesa_id')->nullable();
            $t->integer('numero_ticket')->nullable();
            $t->integer('usuario_id')->nullable();
            $t->string('estado', 30)->default('ABIERTA');
            $t->timestamp('creado_en')->nullable();
        });

        Schema::create('cuentas', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('venta_id');
            $t->string('nombre', 255)->default('Cuenta Principal');
            $t->decimal('total', 10, 2)->default(0);
            $t->string('estado', 255)->default('ABIERTA');
            $t->integer('apertura_id')->nullable();
            $t->integer('mesero_responsable_id')->nullable();
            $t->timestamps();
        });

        Schema::create('cuenta_detalles', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('cuenta_id');
            $t->integer('venta_id');
            $t->integer('cantidad')->default(1);
            $t->decimal('subtotal', 10, 2)->default(0);
            $t->boolean('eliminado')->default(false);
            $t->timestamps();
        });

        Schema::create('caja_apertura', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('usuario_id')->nullable();
            $t->decimal('monto_inicial', 12, 2)->default(0);
            $t->dateTime('fecha_apertura')->nullable();
            $t->string('estado', 20)->default('ABIERTA');
            $t->text('observaciones')->nullable();
        });

        Schema::create('caja_bloqueo', function (Blueprint $t) {
            $t->integer('id')->primary();
        });
        DB::table('caja_bloqueo')->insertOrIgnore(['id' => 1]);
    }

    private function usuario(string $rolNombre): User
    {
        $rolId = DB::table('roles')->insertGetId(['nombre' => $rolNombre]);

        $usuarioId = DB::table('usuarios')->insertGetId([
            'nombre' => $rolNombre.' Test',
            'username' => strtolower($rolNombre).'_'.uniqid(),
            'password_hash' => bcrypt('secret'),
            'rol_id' => $rolId,
        ]);

        return User::findOrFail($usuarioId);
    }

    private function mesa(string $numero, string $estado = 'LIBRE'): int
    {
        return DB::table('mesas')->insertGetId([
            'numero' => $numero,
            'capacidad' => 4,
            'estado' => $estado,
        ]);
    }

    private function abrirCaja(): int
    {
        return DB::table('caja_apertura')->insertGetId([
            'usuario_id' => null,
            'monto_inicial' => 0,
            'fecha_apertura' => now(),
            'estado' => 'ABIERTA',
            'observaciones' => null,
        ]);
    }

    /* ------------------------------------------------------------------
     | API (fetch con cabecera Accept JSON) sin caja abierta
     | ------------------------------------------------------------------ */

    public function test_api_sin_caja_abierta_responde_422_json_con_mensaje_exacto(): void
    {
        $mesaId = $this->mesa('7');

        foreach (['ADMIN', 'GERENTE', 'CAJERO', 'MESERO'] as $rol) {
            $usuario = $this->usuario($rol);

            $respuesta = $this->actingAs($usuario)->postJson('/mesas/api', [
                'accion' => 'abrir_mesa',
                'mesa_id' => $mesaId,
            ]);

            $respuesta->assertStatus(422)
                ->assertJsonPath('exito', false)
                ->assertJsonPath('caja_cerrada', true)
                ->assertJsonPath('error', 'No hay una caja abierta. Debes abrir una caja antes de realizar operaciones.');

            $this->assertSame(0, DB::table('ventas')->count(), "Rol {$rol} no debe crear ventas sin caja abierta.");
            $this->assertSame(0, DB::table('cuentas')->count(), "Rol {$rol} no debe crear cuentas sin caja abierta.");
            $this->assertSame(0, DB::table('comandas')->count(), "Rol {$rol} no debe crear comandas sin caja abierta.");
        }
    }

    public function test_post_json_sin_caja_responde_422_y_mesa_permanece_libre(): void
    {
        $admin = $this->usuario('ADMIN');
        $mesaId = $this->mesa('3');

        $this->actingAs($admin)->postJson('/mesas/api', [
            'accion' => 'abrir_mesa',
            'mesa_id' => $mesaId,
        ])->assertStatus(422);

        $this->assertSame('LIBRE', DB::table('mesas')->where('id', $mesaId)->value('estado'));
    }

    /* ------------------------------------------------------------------
     | POST tradicional (sin Accept JSON) conserva la redirección
     | ------------------------------------------------------------------ */

    public function test_web_tradicional_sin_caja_conserva_redireccion_a_caja_apertura(): void
    {
        $admin = $this->usuario('ADMIN');
        $mesaId = $this->mesa('9');

        $respuesta = $this->actingAs($admin)->post('/mesas/api', [
            '_token' => csrf_token(),
            'accion' => 'abrir_mesa',
            'mesa_id' => $mesaId,
        ]);

        // Navegador clásico: redirección a /caja/apertura (no 422 ni HTML JSON).
        $respuesta->assertRedirect(route('caja.apertura'));
        $respuesta->assertSessionHas('caja_cerrada', 'No hay una caja abierta. Debes abrir una caja antes de realizar operaciones.');

        $this->assertSame(0, DB::table('ventas')->count());
        $this->assertSame(0, DB::table('cuentas')->count());
    }

    /* ------------------------------------------------------------------
     | Con caja abierta el flujo normal funciona
     | ------------------------------------------------------------------ */

    public function test_con_caja_abierta_abrir_mesa_funciona(): void
    {
        $admin = $this->usuario('ADMIN');
        $this->abrirCaja();
        $mesaId = $this->mesa('11');

        $respuesta = $this->actingAs($admin)->postJson('/mesas/api', [
            'accion' => 'abrir_mesa',
            'mesa_id' => $mesaId,
        ]);

        $respuesta->assertStatus(200)->assertJsonPath('exito', true);
        $this->assertSame(1, DB::table('ventas')->count());
        $this->assertSame(1, DB::table('cuentas')->count());
        $this->assertSame('LIBRE', DB::table('mesas')->where('id', $mesaId)->value('estado'));
    }

    /* ------------------------------------------------------------------
     | Frontend del Monitor de Mesas: nunca interpreta HTML como JSON
     | ------------------------------------------------------------------ */

    public function test_el_frontend_envia_headers_json_y_valida_content_type(): void
    {
        $html = file_get_contents(resource_path('views/mesas/index.blade.php'));

        // Headers de JSON en la llamada a /mesas/api.
        $this->assertStringContainsString("headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },", $html);
        // Se comprueba el content-type antes de interpretar la respuesta.
        $this->assertStringContainsString("response.headers.get('content-type')", $html);
        $this->assertStringContainsString("contentType.includes('application/json')", $html);
        // Rama específica de caja cerrada con botón a la apertura.
        $this->assertStringContainsString('data.caja_cerrada', $html);
        $this->assertStringContainsString('Ir a apertura de caja', $html);
        $this->assertStringContainsString("route('caja.apertura')", $html);
        $this->assertStringContainsString('No hay una caja abierta', $html);
        $this->assertStringNotContainsString("response.ok", $html) ;
    }

    public function test_no_se_crean_datos_en_ningun_bloqueo(): void
    {
        foreach (['ADMIN', 'GERENTE', 'CAJERO', 'MESERO'] as $rol) {
            $usuario = $this->usuario($rol);

            $this->actingAs($usuario)->postJson('/mesas/api', [
                'accion' => 'abrir_mesa',
                'mesa_id' => $this->mesa('13'),
            ])->assertStatus(422);

            $this->actingAs($usuario)->postJson('/mesas/api', [
                'accion' => 'crear_mesa',
                'numero' => 'Bloqueo '.$rol,
            ])->assertStatus(422);
        }

        $this->assertSame(0, DB::table('ventas')->count());
        $this->assertSame(0, DB::table('cuentas')->count());
        $this->assertSame(0, DB::table('comandas')->count());
    }
}
