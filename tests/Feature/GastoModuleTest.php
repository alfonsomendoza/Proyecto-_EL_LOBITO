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

    /**
     * Crea un gasto. `$fecha` (Y-m-d H:i:s) permite simular gastos de días
     * pasados para probar el filtro por rango; si no se indica, se usa `now()`.
     */
    private function gasto(
        int $aperturaId,
        string $concepto,
        float $monto,
        string $estado = 'ACTIVO',
        ?int $usuarioId = null,
        ?string $fecha = null,
    ): int {
        return DB::table('caja_gastos')->insertGetId([
            'apertura_id' => $aperturaId,
            'concepto' => $concepto,
            'monto' => $monto,
            'fecha' => $fecha ?? now(),
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
            ->postJson(route('gastos.store'), [
                'conceptos' => ['Pago de agua'],
                'montos' => [100],
            ])
            ->assertStatus(422)
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

    public function test_montos_con_notacion_cientifica_o_caracteres_invalidos_son_rechazados(): void
    {
        $this->apertura('ABIERTA');
        $usuario = $this->usuario('CAJERO');

        $invalidos = [
            'e',
            '0e',
            '0e13212312132121',
            '1e3',
            '10abc',
            '25aasasdasdasdasdasd',
            '--10',
            '10%%',
            '10.125',
            '-10',
            '1,5',
            '10000000000',
        ];

        foreach ($invalidos as $valor) {
            $this->actingAs($usuario)
                ->postJson(route('gastos.store'), [
                    'conceptos' => ['Prueba de validación'],
                    'montos' => [$valor],
                ])
                ->assertStatus(422);
        }

        $this->assertDatabaseCount('caja_gastos', 0);
    }

    public function test_montos_validos_con_hasta_dos_decimales_son_aceptados(): void
    {
        $this->apertura('ABIERTA');
        $usuario = $this->usuario('CAJERO');

        foreach (['10', '10.50', '0.01'] as $valor) {
            $this->actingAs($usuario)
                ->postJson(route('gastos.store'), [
                    'conceptos' => ['Gasto válido'],
                    'montos' => [$valor],
                ])
                ->assertStatus(200)
                ->assertJson(['exito' => true]);
        }

        $this->assertDatabaseCount('caja_gastos', 3);
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
        $this->apertura('ABIERTA');

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

    /* ------------------------------------------------------------------
     | 5. Filtros del listado (activos / anulados / todos)
     | ------------------------------------------------------------------ */

    public function test_filtro_anulados_muestra_solo_anulados(): void
    {
        $usuario = $this->usuario('CAJERO');
        $aperturaId = $this->apertura('ABIERTA', $usuario->id);
        $this->gasto($aperturaId, 'GastoActivoFiltro', 100, 'ACTIVO', $usuario->id);
        $this->gasto($aperturaId, 'GastoAnuladoFiltro', 200, 'ANULADO', $usuario->id);

        $respuesta = $this->actingAs($usuario)->get(route('gastos.index', ['estado' => 'anulados']));

        $respuesta->assertStatus(200);
        $respuesta->assertSee('GastoAnuladoFiltro');
        $respuesta->assertDontSee('GastoActivoFiltro');
    }

    public function test_filtro_todos_muestra_activos_y_anulados(): void
    {
        $usuario = $this->usuario('CAJERO');
        $aperturaId = $this->apertura('ABIERTA', $usuario->id);
        $this->gasto($aperturaId, 'GastoActivoTodos', 100, 'ACTIVO', $usuario->id);
        $this->gasto($aperturaId, 'GastoAnuladoTodos', 200, 'ANULADO', $usuario->id);

        $respuesta = $this->actingAs($usuario)->get(route('gastos.index', ['estado' => 'todos']));

        $respuesta->assertStatus(200);
        $respuesta->assertSee('GastoActivoTodos');
        $respuesta->assertSee('GastoAnuladoTodos');
    }

    public function test_filtro_activos_mantiene_el_comportamiento_predeterminado(): void
    {
        $usuario = $this->usuario('CAJERO');
        $aperturaId = $this->apertura('ABIERTA', $usuario->id);
        $this->gasto($aperturaId, 'ActivoSinFiltrar', 100, 'ACTIVO', $usuario->id);
        $this->gasto($aperturaId, 'AnuladoSinFiltro', 300, 'ANULADO', $usuario->id);

        // Sin parámetro = estado predeterminado (activos).
        $respuesta = $this->actingAs($usuario)->get(route('gastos.index'));

        $respuesta->assertStatus(200);
        $respuesta->assertSee('ActivoSinFiltrar');
        $respuesta->assertDontSee('AnuladoSinFiltro');
    }

    public function test_filtros_invalidos_caidan_en_activos(): void
    {
        $usuario = $this->usuario('CAJERO');
        $aperturaId = $this->apertura('ABIERTA', $usuario->id);
        $this->gasto($aperturaId, 'GastoSoloActivo', 100, 'ACTIVO', $usuario->id);

        $respuesta = $this->actingAs($usuario)->get(route('gastos.index', ['estado' => 'requisitos']));

        $respuesta->assertStatus(200);
        $respuesta->assertSee('GastoSoloActivo');
    }

    /* ------------------------------------------------------------------
     | 6. Filtro por fechas (desde / hasta)
     | ------------------------------------------------------------------ */

    public function test_consulta_por_una_fecha_muestra_un_solo_dia(): void
    {
        $admin = $this->usuario('ADMIN');
        $ap = $this->apertura();
        $this->gasto($ap, 'GastoDia18', 100, 'ACTIVO', $admin->id, '2026-10-18 09:00:00');
        $this->gasto($ap, 'GastoDia19', 200, 'ACTIVO', $admin->id, '2026-10-19 09:00:00');

        $r = $this->actingAs($admin)->get(route('gastos.index', [
            'desde' => '2026-10-18',
            'hasta' => '2026-10-18',
        ]));

        $r->assertStatus(200)
            ->assertSee('GastoDia18')
            ->assertDontSee('GastoDia19')
            ->assertSee('C$ 100.00')
            ->assertDontSee('C$ 200.00')
            ->assertSee('Gastos del período 2026-10-18 → 2026-10-18');
    }

    public function test_rango_completo_incluye_varios_dias_y_calcula_totales(): void
    {
        $admin = $this->usuario('ADMIN');
        $ap = $this->apertura(); // ABIERTA → también incluye gasto en el mismo turno actual
        $this->gasto($ap, 'Gasto18A', 100, 'ACTIVO', $admin->id, '2026-10-18 09:00:00');
        $this->gasto($ap, 'Gasto18B', 50, 'ACTIVO', $admin->id, '2026-10-18 14:00:00');
        $this->gasto($ap, 'Gasto19A', 200, 'ACTIVO', $admin->id, '2026-10-19 09:00:00');
        $this->gasto($ap, 'Gasto19B', 300, 'ACTIVO', $admin->id, '2026-10-19 14:00:00');
        $this->gasto($ap, 'Gasto20A', 150, 'ACTIVO', $admin->id, '2026-10-20 09:00:00');

        $r = $this->actingAs($admin)->get(route('gastos.index', [
            'desde' => '2026-10-18',
            'hasta' => '2026-10-19',
        ]));

        $r->assertStatus(200)
            ->assertSee('Gasto18A')
            ->assertSee('Gasto18B')
            ->assertSee('Gasto19A')
            ->assertSee('Gasto19B')
            ->assertDontSee('Gasto20A')
            ->assertSee('C$ 650.00')
            ->assertSee('Gastos del período 2026-10-18 → 2026-10-19');
    }

    public function test_el_ultimo_dia_se_incluye_completo_incluso_ultimo_minuto(): void
    {
        $admin = $this->usuario('ADMIN');
        $ap = $this->apertura();
        $inicioUltimoDia = '2026-10-18 00:00:00';
        $ultimoMinuto = '2026-10-18 23:59:59';
        $primerMinutoSiguiente = '2026-10-19 00:00:00';

        $this->gasto($ap, 'GastoInicioUltimoDia', 100, 'ACTIVO', $admin->id, $inicioUltimoDia);
        $this->gasto($ap, 'GastoUltimoMinuto', 50, 'ACTIVO', $admin->id, $ultimoMinuto);
        $this->gasto($ap, 'GastoPrimerMinutoSiguiente', 999, 'ACTIVO', $admin->id, $primerMinutoSiguiente);

        $r = $this->actingAs($admin)->get(route('gastos.index', [
            'desde' => '2026-10-18',
            'hasta' => '2026-10-18',
        ]));

        $r->assertStatus(200)
            ->assertSee('GastoInicioUltimoDia')
            ->assertSee('GastoUltimoMinuto')
            ->assertDontSee('GastoPrimerMinutoSiguiente')
            ->assertSee('C$ 150.00')
            ->assertSee('Gastos del período 2026-10-18 → 2026-10-18');
    }

    public function test_rango_invertido_no_calcula_nada_y_muestra_el_motivo(): void
    {
        $admin = $this->usuario('ADMIN');
        $ap = $this->apertura();
        $this->gasto($ap, 'Gasto18', 100, 'ACTIVO', $admin->id, '2026-10-18 09:00:00');
        $this->gasto($ap, 'Gasto19', 200, 'ACTIVO', $admin->id, '2026-10-19 09:00:00');

        $r = $this->actingAs($admin)->get(route('gastos.index', [
            'desde' => '2026-10-19',
            'hasta' => '2026-10-18',
        ]));

        $r->assertStatus(200)
            ->assertSee('La fecha "Desde" no puede ser posterior a la fecha "Hasta"')
            ->assertDontSee('Gasto18')
            ->assertDontSee('Gasto19')
            ->assertDontSee('C$ 100.00')
            ->assertDontSee('C$ 200.00')
            ->assertSee('No hay gastos en el período');
    }

    public function test_fecha_invalida_hace_que_el_listado_no_se_calcule(): void
    {
        $admin = $this->usuario('ADMIN');
        $ap = $this->apertura();
        $this->gasto($ap, 'GastoValido', 100, 'ACTIVO', $admin->id, '2026-10-18 09:00:00');

        $r = $this->actingAs($admin)->get(route('gastos.index', [
            'desde' => '18/10/2026',
            'hasta' => '2026-10-18',
        ]));

        $r->assertStatus(200)
            ->assertSee('Las fechas indicadas no son válidas.')
            ->assertDontSee('GastoValido')
            ->assertDontSee('C$ 100.00')
            ->assertSee('No hay gastos en el período');
    }

    public function test_fechas_y_filtro_de_estado_se_combinan(): void
    {
        $admin = $this->usuario('ADMIN');
        $ap = $this->apertura();
        $this->gasto($ap, 'ActivoSeleccion', 100, 'ACTIVO', $admin->id, '2026-10-18 09:00:00');
        $this->gasto($ap, 'AnuladoSeleccion', 200, 'ANULADO', $admin->id, '2026-10-18 09:00:00');

        $r = $this->actingAs($admin)->get(route('gastos.index', [
            'desde' => '2026-10-18',
            'hasta' => '2026-10-18',
            'estado' => 'anulados',
        ]));

        $r->assertStatus(200)
            ->assertSee('AnuladoSeleccion')
            ->assertDontSee('ActivoSeleccion')
            ->assertSee('C$ 200.00')
            ->assertDontSee('C$ 100.00')
            ->assertSee('SUBTOTAL ANULADOS')
            ->assertSee('Gastos del período 2026-10-18 → 2026-10-18');
    }

    public function test_paginacion_conserva_las_fechas_y_muestra_todos_los_totales_sobre_el_rango_completo(): void
    {
        $admin = $this->usuario('ADMIN');

        // 55 activos del mismo día -> página 1 (50) y página 2 (5).
        $ap = $this->apertura();
        for ($i = 1; $i <= 55; $i++) {
            $this->gasto($ap, "ActivoFecha{$i}", 100, 'ACTIVO', $admin->id, '2026-10-18 09:00:00');
        }

        $primera = $this->actingAs($admin)->get(route('gastos.index', [
            'desde' => '2026-10-18',
            'hasta' => '2026-10-18',
        ]));

        $primera->assertStatus(200)
            ->assertSee('Mostrando 1–50 de 55 gasto(s)')
            ->assertSee('C$ 5,500.00')  // total sobre todas las filas
            ->assertSee('Gastos del período 2026-10-18 → 2026-10-18');

        $this->assertStringContainsString('desde=2026-10-18', $primera->viewData('paginaGastos')->nextPageUrl());
        $this->assertStringContainsString('hasta=2026-10-18', $primera->viewData('paginaGastos')->nextPageUrl());

        $segunda = $this->actingAs($admin)
            ->get(route('gastos.index', [
                'desde' => '2026-10-18',
                'hasta' => '2026-10-18',
                'pagina' => 2,
            ]));

        $segunda
            ->assertStatus(200)
            ->assertSee('Mostrando 51–55 de 55 gasto(s)')
            ->assertSee('C$ 5,500.00')
            ->assertSee('Gastos del período 2026-10-18 → 2026-10-18');

        $contenido = $segunda->viewData('paginaGastos')->pluck('concepto')->toArray();
        $this->assertContains('ActivoFecha51', $contenido);
        $this->assertContains('ActivoFecha55', $contenido);
    }

    public function test_limpiar_filtros_vuelve_al_turno_activo(): void
    {
        $admin = $this->usuario('ADMIN');
        $ap = $this->apertura();
        $this->gasto($ap, 'GastoTurno18', 100, 'ACTIVO', $admin->id, '2026-10-18 09:00:00');

        $r = $this->actingAs($admin)->get(route('gastos.index', [
            'desde' => '2026-10-18',
            'hasta' => '2026-10-18',
        ]));
        $r->assertStatus(200)->assertSee('Gastos del período 2026-10-18 → 2026-10-18');

        $sinFiltro = $this->actingAs($admin)->get(route('gastos.index'));
        $sinFiltro->assertStatus(200)
            ->assertSee('Gastos Registrados en este Turno')
            ->assertSee('GastoTurno18');
    }

    public function test_el_filtro_de_fechas_respeta_los_permisos_existentes(): void
    {
        $admin = $this->usuario('ADMIN');
        $cajero = $this->usuario('CAJERO');
        $ap = $this->apertura();
        $this->gasto($ap, 'GastoAdmin', 100, 'ACTIVO', $admin->id, '2026-10-18 09:00:00');

        // ADMIN y CAJERO pueden ver con fechas.
        $this->actingAs($admin)->get(route('gastos.index', ['desde' => '2026-10-18', 'hasta' => '2026-10-18']))->assertStatus(200);
        $this->actingAs($cajero)->get(route('gastos.index', ['desde' => '2026-10-18', 'hasta' => '2026-10-18']))->assertStatus(200);

        // MESERO sigue sin permiso (403) aunque envíe fechas.
        $this->actingAs($this->usuario('MESERO'))
            ->get(route('gastos.index', ['desde' => '2026-10-18', 'hasta' => '2026-10-18']))
            ->assertStatus(403);
    }

    /* ------------------------------------------------------------------
     | 7. Los tres botones de impresión (independientes del filtro)
     | ------------------------------------------------------------------ */

    public function test_pantalla_tiene_tres_botones_de_impresion(): void
    {
        $usuario = $this->usuario('CAJERO');
        $aperturaId = $this->apertura('ABIERTA', $usuario->id);
        $this->gasto($aperturaId, 'GastoParaBotones', 100, 'ACTIVO', $usuario->id);

        $respuesta = $this->actingAs($usuario)->get(route('gastos.index'));

        $respuesta->assertStatus(200);
        $respuesta->assertSee(route('gastos.ticket', ['estado' => 'todos']), false);
        $respuesta->assertSee(route('gastos.ticket', ['estado' => 'activos']), false);
        $respuesta->assertSee(route('gastos.ticket', ['estado' => 'anulados']), false);
    }

    public function test_botones_son_independientes_del_filtro_de_pantalla(): void
    {
        $usuario = $this->usuario('CAJERO');
        $aperturaId = $this->apertura('ABIERTA', $usuario->id);
        $this->gasto($aperturaId, 'ActivoBoton', 100, 'ACTIVO', $usuario->id);
        $this->gasto($aperturaId, 'AnuladoBoton', 200, 'ANULADO', $usuario->id);

        // Aunque la pantalla esté filtrada en "anulados", el botón de activos
        // sigue imprimiendo solo activos, y viceversa.
        $this->actingAs($usuario)->get(route('gastos.index', ['estado' => 'anulados']))->assertStatus(200);

        $activos = $this->actingAs($usuario)->get(route('gastos.ticket', ['estado' => 'activos']));
        $activos->assertStatus(200);
        $activos->assertSee('ActivoBoton');
        $activos->assertDontSee('AnuladoBoton');

        $anulados = $this->actingAs($usuario)->get(route('gastos.ticket', ['estado' => 'anulados']));
        $anulados->assertStatus(200);
        $anulados->assertSee('AnuladoBoton');
        $anulados->assertDontSee('ActivoBoton');
    }

    public function test_imprimir_todos_muestra_secciones_y_subtotales_separados(): void
    {
        $usuario = $this->usuario('CAJERO');
        $aperturaId = $this->apertura('ABIERTA', $usuario->id);
        $this->gasto($aperturaId, 'ActivoSeccion', 100, 'ACTIVO', $usuario->id);
        $this->gasto($aperturaId, 'AnuladoSeccion', 999, 'ANULADO', $usuario->id);

        $respuesta = $this->actingAs($usuario)->get(route('gastos.ticket', ['estado' => 'todos']));

        $respuesta->assertStatus(200);
        $respuesta->assertSee('GASTOS ACTIVOS');
        $respuesta->assertSee('GASTOS ANULADOS');
        $respuesta->assertSee('TOTAL EGRESOS (activos)');
        $respuesta->assertSee('SUBTOTAL ANULADOS');
        $respuesta->assertSee('C$ 100.00');
        $respuesta->assertSee('C$ 999.00');
        $respuesta->assertDontSee('1,099.00');
    }

    public function test_imprimir_anulados_sin_registros_muestra_mensaje(): void
    {
        $usuario = $this->usuario('CAJERO');
        $aperturaId = $this->apertura('ABIERTA', $usuario->id);
        $this->gasto($aperturaId, 'SoloActivo', 100, 'ACTIVO', $usuario->id);

        $respuesta = $this->actingAs($usuario)->get(route('gastos.ticket', ['estado' => 'anulados']));

        $respuesta->assertStatus(200);
        $respuesta->assertSee('No se registran salidas de dinero anuladas');
        $respuesta->assertDontSee('SoloActivo');
    }
}
