<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\GastoService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/*
 * Pruebas aisladas del módulo de Gastos.
 *
 * IMPORTANTE:
 * - Corren en SQLite :memory: (configurado en phpunit.xml).
 * - El esquema se construye a mano porque la tabla real vive en MySQL; así
 *   ninguna prueba escribe en la base "sistemadecaja".
 * - setUp() falla a propósito si la conexión no es SQLite en memoria.
 *
 * Reglas comprobadas:
 * - ADMIN, GERENTE y CAJERO: registrar, consultar e imprimir.
 * - ADMIN y GERENTE: anular. CAJERO y MESERO: 403 al intentar anular.
 * - MESERO: sin acceso al módulo.
 * - Apertura y usuario se toman de la sesión, no del request.
 * - Registro multi-fila transaccional.
 * - Anulación lógica: estado = ANULADO, sin borrar la fila.
 * - Gastos anulados excluidos de totales, listado activo y ticket.
 */
class GastoModuleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Candado de seguridad: nunca apuntar a la base MySQL real.
        $this->assertSame(
            'sqlite',
            DB::connection()->getDriverName(),
            'Las pruebas deben ejecutarse en SQLite.'
        );
        $this->assertSame(
            ':memory:',
            (string) config('database.connections.sqlite.database'),
            'La base de pruebas debe ser SQLite en memoria.'
        );

        $this->crearEsquema();
    }

    private function crearEsquema(): void
    {
        foreach (['caja_gastos', 'caja_apertura', 'usuarios', 'roles'] as $tabla) {
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
        });

        // Refleja el esquema real de caja_apertura (columnas usadas).
        Schema::create('caja_apertura', function (Blueprint $t) {
            $t->increments('id');
            $t->dateTime('fecha')->nullable();
            $t->string('cajero', 100)->nullable();
            $t->string('turno', 50)->nullable();
            $t->integer('caja_id')->default(1);
            $t->decimal('monto_inicial', 12, 2)->default(0);
            $t->text('conteo_json')->nullable();
            $t->string('estado', 20)->default('ABIERTA');
            $t->timestamp('creado_en')->nullable();
            $t->integer('usuario_id')->nullable();
        });

        // Refleja el esquema real de caja_gastos.
        Schema::create('caja_gastos', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('apertura_id');
            $t->string('concepto', 255);
            $t->decimal('monto', 12, 2);
            $t->dateTime('fecha')->nullable();
            $t->string('estado', 20)->default('ACTIVO');
            $t->integer('usuario_id')->nullable();
        });
    }

    private function usuario(string $rolNombre): User
    {
        $rolId = DB::table('roles')->insertGetId(['nombre' => $rolNombre]);

        $usuarioId = DB::table('usuarios')->insertGetId([
            'nombre' => $rolNombre . ' Test',
            'username' => strtolower($rolNombre) . '_test',
            'password_hash' => bcrypt('secret'),
            'rol_id' => $rolId,
        ]);

        return User::findOrFail($usuarioId);
    }

    private function apertura(string $estado = 'ABIERTA', ?int $usuarioId = null): int
    {
        return DB::table('caja_apertura')->insertGetId([
            'fecha' => now(),
            'cajero' => 'Cajero Test',
            'turno' => 'Mañana',
            'caja_id' => 1,
            'monto_inicial' => 1000,
            'conteo_json' => null,
            'estado' => $estado,
            'usuario_id' => $usuarioId,
        ]);
    }

    private function gasto(int $aperturaId, string $concepto, float $monto, string $estado = 'ACTIVO', ?int $usuarioId = null): int
    {
        return DB::table('caja_gastos')->insertGetId([
            'apertura_id' => $aperturaId,
            'concepto' => $concepto,
            'monto' => $monto,
            'fecha' => now(),
            'estado' => $estado,
            'usuario_id' => $usuarioId,
        ]);
    }

    /* ------------------------------------------------------------------
     | 1. Acceso al módulo
     | ------------------------------------------------------------------ */

    public function test_admin_gerente_y_cajero_pueden_ver_el_modulo(): void
    {
        foreach (['ADMIN', 'GERENTE', 'CAJERO'] as $rol) {
            $this->actingAs($this->usuario($rol))
                ->get(route('gastos.index'))
                ->assertStatus(200);
        }
    }

    public function test_mesero_no_puede_ver_el_modulo(): void
    {
        $this->actingAs($this->usuario('MESERO'))
            ->get(route('gastos.index'))
            ->assertStatus(403);
    }

    public function test_no_autenticado_es_redirigido_al_login(): void
    {
        $this->get(route('gastos.index'))->assertRedirect(route('login'));
        $this->post(route('gastos.store'), [
            'conceptos' => ['X'],
            'montos' => [10],
        ])->assertRedirect(route('login'));

        $this->assertDatabaseCount('caja_gastos', 0);
    }

    /* ------------------------------------------------------------------
     | 2. Registro
     | ------------------------------------------------------------------ */

    public function test_admin_gerente_y_cajero_pueden_registrar_gastos(): void
    {
        foreach (['ADMIN', 'GERENTE', 'CAJERO'] as $rol) {
            $usuario = $this->usuario($rol);
            $aperturaId = $this->apertura('ABIERTA', $usuario->id);

            $this->actingAs($usuario)
                ->post(route('gastos.store'), [
                    'conceptos' => ['Pago de luz'],
                    'montos' => [5000],
                ])
                ->assertStatus(200)
                ->assertJson(['exito' => true]);

            $this->assertDatabaseHas('caja_gastos', [
                'apertura_id' => $aperturaId,
                'usuario_id' => $usuario->id,
                'concepto' => 'Pago de luz',
                'estado' => 'ACTIVO',
            ]);
        }
    }

    public function test_mesero_no_puede_registrar_gastos(): void
    {
        $this->apertura('ABIERTA');

        $this->actingAs($this->usuario('MESERO'))
            ->post(route('gastos.store'), [
                'conceptos' => ['Pago de agua'],
                'montos' => [100],
            ])
            ->assertStatus(403)
            ->assertJson(['exito' => false]);

        $this->assertDatabaseCount('caja_gastos', 0);
    }

    public function test_no_se_puede_registrar_sin_caja_abierta(): void
    {
        // No se crea ninguna apertura.
        $this->actingAs($this->usuario('CAJERO'))
            ->post(route('gastos.store'), [
                'conceptos' => ['Pago de agua'],
                'montos' => [100],
            ])
            ->assertStatus(409)
            ->assertJson(['exito' => false]);

        $this->assertDatabaseCount('caja_gastos', 0);
    }

    public function test_monto_invalido_es_rechazado(): void
    {
        $this->apertura('ABIERTA');

        $this->actingAs($this->usuario('CAJERO'))
            ->postJson(route('gastos.store'), [
                'conceptos' => ['Pago de agua'],
                'montos' => [0],
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('caja_gastos', 0);
    }

    public function test_registro_multifila_es_transaccional(): void
    {
        $usuario = $this->usuario('CAJERO');
        $aperturaId = $this->apertura('ABIERTA', $usuario->id);

        // La segunda fila tiene un concepto que, tras recortar, queda vacío:
        // el servicio debe abortar y no dejar la primera fila a medias.
        $resultado = app(GastoService::class)->registrarGastos(
            $aperturaId,
            $usuario->id,
            ['Gasto válido', '   '],
            [100, 50]
        );

        $this->assertFalse($resultado['exito']);
        $this->assertDatabaseCount('caja_gastos', 0);
    }

    public function test_la_apertura_y_el_usuario_se_toman_de_la_sesion(): void
    {
        $usuario = $this->usuario('CAJERO');
        $aperturaActiva = $this->apertura('ABIERTA', $usuario->id);
        $otraApertura = $this->apertura('CERRADA', $usuario->id);

        // El navegador intenta forzar apertura_id y usuario_id; deben ignorarse.
        $this->actingAs($usuario)
            ->post(route('gastos.store'), [
                'conceptos' => ['Compra forzada'],
                'montos' => [10],
                'apertura_id' => $otraApertura,
                'usuario_id' => 99999,
            ])
            ->assertStatus(200)
            ->assertJson(['exito' => true]);

        $this->assertDatabaseHas('caja_gastos', [
            'concepto' => 'Compra forzada',
            'apertura_id' => $aperturaActiva,
            'usuario_id' => $usuario->id,
        ]);
        $this->assertDatabaseMissing('caja_gastos', [
            'concepto' => 'Compra forzada',
            'apertura_id' => $otraApertura,
        ]);
    }

    /* ------------------------------------------------------------------
     | 3. Anulación
     | ------------------------------------------------------------------ */

    public function test_cajero_y_mesero_no_pueden_anular_gasto(): void
    {
        $usuario = $this->usuario('CAJERO');
        $aperturaId = $this->apertura('ABIERTA', $usuario->id);
        $gastoId = $this->gasto($aperturaId, 'Pago de internet', 300, 'ACTIVO', $usuario->id);

        // CAJERO: petición directa al endpoint -> 403.
        $this->actingAs($usuario)
            ->post(route('gastos.anular', $gastoId))
            ->assertStatus(403)
            ->assertJson(['exito' => false]);

        $this->assertDatabaseHas('caja_gastos', ['id' => $gastoId, 'estado' => 'ACTIVO']);

        // MESERO: petición directa al endpoint -> 403.
        $this->actingAs($this->usuario('MESERO'))
            ->post(route('gastos.anular', $gastoId))
            ->assertStatus(403)
            ->assertJson(['exito' => false]);

        $this->assertDatabaseHas('caja_gastos', ['id' => $gastoId, 'estado' => 'ACTIVO']);
    }

    public function test_admin_y_gerente_pueden_anular_gasto(): void
    {
        $aperturaId = $this->apertura('ABIERTA');

        $gastoAdmin = $this->gasto($aperturaId, 'Gasto admin', 100, 'ACTIVO');
        $this->actingAs($this->usuario('ADMIN'))
            ->post(route('gastos.anular', $gastoAdmin))
            ->assertStatus(200)
            ->assertJson(['exito' => true]);
        $this->assertDatabaseHas('caja_gastos', ['id' => $gastoAdmin, 'estado' => 'ANULADO']);

        $gastoGerente = $this->gasto($aperturaId, 'Gasto gerente', 200, 'ACTIVO');
        $this->actingAs($this->usuario('GERENTE'))
            ->post(route('gastos.anular', $gastoGerente))
            ->assertStatus(200)
            ->assertJson(['exito' => true]);
        $this->assertDatabaseHas('caja_gastos', ['id' => $gastoGerente, 'estado' => 'ANULADO']);
    }

    public function test_anulacion_es_logica_no_borra_el_registro(): void
    {
        $admin = $this->usuario('ADMIN');
        $aperturaId = $this->apertura('ABIERTA', $admin->id);
        $gastoId = $this->gasto($aperturaId, 'Pago de inss', 2000, 'ACTIVO', $admin->id);

        $this->actingAs($admin)
            ->post(route('gastos.anular', $gastoId))
            ->assertStatus(200);

        // La fila sigue existiendo: solo cambió su estado.
        $this->assertDatabaseCount('caja_gastos', 1);
        $this->assertDatabaseHas('caja_gastos', ['id' => $gastoId, 'estado' => 'ANULADO']);
    }

    public function test_anular_gasto_inexistente_devuelve_404(): void
    {
        $this->actingAs($this->usuario('ADMIN'))
            ->post(route('gastos.anular', 9999))
            ->assertStatus(404)
            ->assertJson(['exito' => false]);
    }

    /* ------------------------------------------------------------------
     | 4. Totales, listado y ticket excluyen anulados
     | ------------------------------------------------------------------ */

    public function test_totales_excluyen_gastos_anulados(): void
    {
        $aperturaId = $this->apertura('ABIERTA');
        $this->gasto($aperturaId, 'Activo 1', 100, 'ACTIVO');
        $this->gasto($aperturaId, 'Activo 2', 50, 'ACTIVO');
        $this->gasto($aperturaId, 'Anulado', 999, 'ANULADO');

        $service = app(GastoService::class);
        $gastos = $service->gastosDeApertura($aperturaId);

        $this->assertSame(150.0, $service->totalActivos($gastos));
    }

    public function test_listado_normal_muestra_activos_y_excluye_anulados(): void
    {
        $usuario = $this->usuario('CAJERO');
        $aperturaId = $this->apertura('ABIERTA', $usuario->id);
        $this->gasto($aperturaId, 'GastoActivoVisible', 100, 'ACTIVO', $usuario->id);
        $this->gasto($aperturaId, 'GastoAnuladoOculto', 200, 'ANULADO', $usuario->id);

        $respuesta = $this->actingAs($usuario)->get(route('gastos.index'));

        $respuesta->assertStatus(200);
        $respuesta->assertSee('GastoActivoVisible');
        $respuesta->assertDontSee('GastoAnuladoOculto');
    }

    public function test_ticket_solo_muestra_gastos_activos(): void
    {
        $usuario = $this->usuario('CAJERO');
        $aperturaId = $this->apertura('ABIERTA', $usuario->id);
        $this->gasto($aperturaId, 'GastoVisibleActivo', 100, 'ACTIVO', $usuario->id);
        $this->gasto($aperturaId, 'GastoOcultoAnulado', 200, 'ANULADO', $usuario->id);

        $respuesta = $this->actingAs($usuario)->get(route('gastos.ticket'));

        $respuesta->assertStatus(200);
        $respuesta->assertSee('GastoVisibleActivo');
        $respuesta->assertDontSee('GastoOcultoAnulado');
    }

    public function test_mesero_no_puede_imprimir_ticket(): void
    {
        $this->apertura('ABIERTA');

        $this->actingAs($this->usuario('MESERO'))
            ->get(route('gastos.ticket'))
            ->assertStatus(403);
    }
}
