<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ResumenDiarioService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/*
 * Pruebas aisladas del módulo "Operaciones — Resumen del Día".
 *
 * IMPORTANTE:
 * - Corren en SQLite :memory: (configurado en phpunit.xml).
 * - El esquema se construye a mano porque las tablas reales viven en MySQL;
 *   así ninguna prueba escribe en la base "sistemadecaja".
 * - setUp() falla a propósito si la conexión no es SQLite en memoria.
 *
 * Reglas comprobadas:
 * - ADMIN y GERENTE consultan e imprimen; CAJERO y MESERO: 403.
 * - Varios turnos del mismo día: las ventanas NO se solapan (no se cuenta dos
 *   veces una venta).
 * - Clasificación de cobros igual que el cierre (efectivo/tarjeta/transferencia).
 * - Gastos activos suman; anulados no.
 * - Efectivo esperado = fondo + efectivo + otros ingresos − gastos activos −
 *   retiros (los cambios NO se descuentan: eran un doble descuento del PHP puro).
 * - Filtro por cajero y por fecha.
 * - Es solo lectura: consultar/imprimir no modifica datos.
 */
class ResumenDiarioModuleTest extends TestCase
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
        foreach (['caja_cierre', 'caja_gastos', 'caja_apertura', 'cuentas', 'ventas', 'usuarios', 'roles'] as $tabla) {
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

        Schema::create('ventas', function (Blueprint $t) {
            $t->increments('id');
            $t->unsignedBigInteger('mesa_id')->nullable();
            $t->integer('apertura_id')->nullable();
            $t->string('descripcion')->nullable();
            $t->decimal('monto', 12, 2)->default(0);
            $t->string('estado', 20)->default('ACTIVA');
            $t->dateTime('fecha')->nullable();
        });

        Schema::create('cuentas', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->integer('venta_id')->nullable();
            $t->string('nombre', 255)->default('Cuenta');
            $t->decimal('total', 10, 2)->default(0);
            $t->string('estado', 255)->default('ABIERTA');
            $t->integer('apertura_id')->nullable();
            $t->string('metodo_pago', 30)->nullable();
            $t->string('banco', 30)->nullable();
            $t->decimal('monto_recibido', 12, 2)->nullable();
            $t->decimal('cambio', 12, 2)->nullable();
            $t->unsignedBigInteger('usuario_cobro')->nullable();
            $t->timestamps();
        });

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

        Schema::create('caja_gastos', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('apertura_id');
            $t->string('concepto', 255);
            $t->decimal('monto', 12, 2);
            $t->dateTime('fecha')->nullable();
            $t->string('estado', 20)->default('ACTIVO');
            $t->integer('usuario_id')->nullable();
        });

        Schema::create('caja_cierre', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('apertura_id');
            $t->dateTime('fecha')->nullable();
            $t->string('cajero', 100)->nullable();
            $t->string('turno', 50)->nullable();
            $t->integer('caja_id')->default(1);
            $t->decimal('monto_apertura', 12, 2)->default(0);
            $t->decimal('ventas_efectivo', 12, 2)->default(0);
            $t->decimal('otros_ingresos', 12, 2)->default(0);
            $t->decimal('egresos', 12, 2)->default(0);
            $t->decimal('retiros', 12, 2)->default(0);
            $t->decimal('total_esperado', 12, 2)->default(0);
            $t->decimal('tasa_cambio', 10, 4)->default(1);
            $t->decimal('monto_extranjero', 12, 2)->default(0);
            $t->decimal('total_contado', 12, 2)->default(0);
            $t->decimal('diferencia', 12, 2)->default(0);
            $t->string('estado', 20)->default('CUADRA');
            $t->text('conteo_json')->nullable();
            $t->text('observaciones')->nullable();
            $t->timestamp('creado_en')->nullable();
            $t->integer('usuario_id')->nullable();
        });
    }

    /* ------------------------------------------------------------------
     | Constructores de datos
     | ------------------------------------------------------------------ */

    private function usuario(string $rolNombre): User
    {
        $rolId = DB::table('roles')->insertGetId(['nombre' => $rolNombre]);

        $usuarioId = DB::table('usuarios')->insertGetId([
            'nombre' => $rolNombre.' Test',
            'username' => strtolower($rolNombre).'_test',
            'password_hash' => bcrypt('secret'),
            'rol_id' => $rolId,
        ]);

        return User::findOrFail($usuarioId);
    }

    /**
     * Inserta un turno con fecha (día de negocio) y creado_en explícitos.
     */
    private function apertura(string $fecha, string $creadoEn, float $montoInicial, string $cajero = 'Cajero', string $estado = 'ABIERTA'): int
    {
        return DB::table('caja_apertura')->insertGetId([
            'fecha' => $fecha,
            'cajero' => $cajero,
            'turno' => 'Tarde',
            'caja_id' => 1,
            'monto_inicial' => $montoInicial,
            'conteo_json' => null,
            'estado' => $estado,
            'creado_en' => $creadoEn,
            'usuario_id' => null,
        ]);
    }

    private function cuenta(float $total, ?string $metodo, ?string $banco, string $updatedAt, ?int $aperturaId = null, ?float $cambio = 0.0): int
    {
        return DB::table('cuentas')->insertGetId([
            'venta_id' => 1,
            'nombre' => 'Cuenta',
            'total' => $total,
            'estado' => 'PAGADA',
            'apertura_id' => $aperturaId,
            'metodo_pago' => $metodo,
            'banco' => $banco,
            'monto_recibido' => $total,
            'cambio' => $cambio,
            'usuario_cobro' => null,
            'created_at' => $updatedAt,
            'updated_at' => $updatedAt,
        ]);
    }

    private function ventaCancelada(float $monto, string $fecha): int
    {
        return DB::table('ventas')->insertGetId([
            'descripcion' => 'Consumo cancelado',
            'monto' => $monto,
            'estado' => 'CANCELADA',
            'fecha' => $fecha,
        ]);
    }

    private function cierre(int $aperturaId, string $estado = 'CUADRA', ?float $diferencia = 0.0): int
    {
        return DB::table('caja_cierre')->insertGetId([
            'apertura_id' => $aperturaId,
            'fecha' => now(),
            'cajero' => DB::table('caja_apertura')->where('id', $aperturaId)->value('cajero'),
            'turno' => 'Tarde',
            'caja_id' => 1,
            'monto_apertura' => DB::table('caja_apertura')->where('id', $aperturaId)->value('monto_inicial'),
            'ventas_efectivo' => 0,
            'otros_ingresos' => 0,
            'egresos' => 0,
            'retiros' => 0,
            'total_esperado' => 0,
            'tasa_cambio' => 1,
            'monto_extranjero' => 0,
            'total_contado' => 0,
            'diferencia' => $diferencia,
            'estado' => $estado,
            'conteo_json' => null,
            'observaciones' => null,
            'creado_en' => now(),
            'usuario_id' => null,
        ]);
    }

    private function gasto(int $aperturaId, float $monto, string $estado = 'ACTIVO'): int
    {
        return DB::table('caja_gastos')->insertGetId([
            'apertura_id' => $aperturaId,
            'concepto' => 'Gasto '.$estado,
            'monto' => $monto,
            'fecha' => now(),
            'estado' => $estado,
            'usuario_id' => null,
        ]);
    }

    /**
     * Dos turnos HOY + uno de mañana (centinela para acotar la ventana del
     * último turno de hoy sin depender de la hora exacta de ejecución).
     *
     * @return array{a: int, b: int}
     */
    private function dosTurnosHoy(): array
    {
        $hoy = now()->format('Y-m-d');
        $manana = now()->addDay()->format('Y-m-d');

        $a = $this->apertura($hoy.' 08:00:00', now()->subHours(6)->format('Y-m-d H:i:s'), 1000, 'Ana');
        $b = $this->apertura($hoy.' 12:00:00', now()->subHours(2)->format('Y-m-d H:i:s'), 500, 'Beto');
        $this->cierre($a);
        $this->cierre($b);
        // Frontera: no pertenece al día consultado.
        $this->apertura($manana.' 08:00:00', now()->addDay()->format('Y-m-d H:i:s'), 0, 'Ana');

        return ['a' => $a, 'b' => $b];
    }

    private function fechaHoy(): string
    {
        return now()->format('Y-m-d');
    }

    /* ------------------------------------------------------------------
     | 1. Acceso
     | ------------------------------------------------------------------ */

    public function test_admin_y_gerente_ven_el_resumen(): void
    {
        foreach (['ADMIN', 'GERENTE'] as $rol) {
            $this->actingAs($this->usuario($rol))
                ->get(route('reportes.resumen'))
                ->assertStatus(200);
        }
    }

    public function test_cajero_y_mesero_no_ven_el_resumen(): void
    {
        foreach (['CAJERO', 'MESERO'] as $rol) {
            $this->actingAs($this->usuario($rol))
                ->get(route('reportes.resumen'))
                ->assertStatus(403);
        }
    }

    public function test_cajero_y_mesero_no_imprimen_el_resumen(): void
    {
        foreach (['CAJERO', 'MESERO'] as $rol) {
            $this->actingAs($this->usuario($rol))
                ->get(route('reportes.resumen.ticket'))
                ->assertStatus(403);
        }
    }

    public function test_no_autenticado_es_redirigido_al_login(): void
    {
        $this->get(route('reportes.resumen'))->assertRedirect(route('login'));
    }

    /* ------------------------------------------------------------------
     | 2. Varios turnos sin doble conteo
     | ------------------------------------------------------------------ */

    public function test_suma_varios_turnos_sin_contar_dos_veces(): void
    {
        $ids = $this->dosTurnosHoy();

        // Turno A (08:00–12:00): efectivo 400 + tarjeta BAC 1000.
        $this->cuenta(400, 'EFECTIVO', null, now()->subHours(4)->format('Y-m-d H:i:s'));
        $this->cuenta(1000, 'TARJETA', 'BAC', now()->subHours(3)->format('Y-m-d H:i:s'));
        // Turno B (12:00–mañana): efectivo 250 + transferencia 300.
        $this->cuenta(250, 'EFECTIVO', null, now()->subHour()->format('Y-m-d H:i:s'));
        $this->cuenta(300, 'TRANSFERENCIA', null, now()->subMinutes(30)->format('Y-m-d H:i:s'));

        $this->gasto($ids['a'], 100, 'ACTIVO');
        $this->gasto($ids['b'], 50, 'ACTIVO');
        $this->gasto($ids['a'], 999, 'ANULADO');

        $r = app(ResumenDiarioService::class)->resumenDelDia($this->fechaHoy());

        $this->assertTrue($r['exito']);
        $this->assertSame(2, $r['cantidad_turnos']);
        $this->assertEqualsWithDelta(1500.0, $r['total_monto_apertura'], 0.001, 'Fondo: 1000 + 500.');
        $this->assertEqualsWithDelta(650.0, $r['efectivo'], 0.001, 'Efectivo: 400 + 250.');
        $this->assertEqualsWithDelta(1000.0, $r['tarjeta'], 0.001);
        $this->assertEqualsWithDelta(300.0, $r['transferencia'], 0.001);
        $this->assertEqualsWithDelta(1950.0, $r['total_ventas'], 0.001, 'Total ventas = 400+1000+250+300.');
        $this->assertEqualsWithDelta(150.0, $r['gastos_activos'], 0.001);
        $this->assertEqualsWithDelta(999.0, $r['gastos_anulados'], 0.001);
        $this->assertSame(1, $r['cantidad_gastos_anulados']);
        $this->assertEqualsWithDelta(1000.0, $r['tarjetas_por_banco']['BAC'], 0.001);

        // Efectivo esperado = 1500 + 650 - 150 = 2000 (sin restar cambios).
        $this->assertEqualsWithDelta(2000.0, $r['efectivo_esperado'], 0.001);

        // Cada venta se cuenta UNA sola vez (no se solapan las ventanas).
        $totalCuentas = 0;
        foreach ($r['turnos'] as $detalle) {
            $totalCuentas += $detalle['ventas']['cantidad'];
        }
        $this->assertSame(4, $totalCuentas);
    }

    public function test_no_resta_los_cambios_entregados(): void
    {
        $this->dosTurnosHoy();

        // Venta de 1000 con cambio 150: el neto en caja es 1000, no 850.
        $this->cuenta(1000, 'EFECTIVO', null, now()->subHours(4)->format('Y-m-d H:i:s'), null, 150.0);

        $r = app(ResumenDiarioService::class)->resumenDelDia($this->fechaHoy());

        $this->assertEqualsWithDelta(1000.0, $r['efectivo'], 0.001);
        $this->assertEqualsWithDelta(150.0, $r['cambios_entregados'], 0.001, 'Los cambios se muestran como dato informativo.');
        // Esperado = 1500 (fondo) + 1000 (efectivo) = 2500; NO se resta el cambio.
        $this->assertEqualsWithDelta(2500.0, $r['efectivo_esperado'], 0.001);
    }

    public function test_ventas_anuladas_se_informan_sin_afectar_las_ventas(): void
    {
        $ids = $this->dosTurnosHoy();
        $this->cuenta(400, 'EFECTIVO', null, now()->subHours(4)->format('Y-m-d H:i:s'));
        // Venta cancelada dentro de la ventana del turno A.
        $this->ventaCancelada(300, now()->subHours(4)->format('Y-m-d H:i:s'));

        $r = app(ResumenDiarioService::class)->resumenDelDia($this->fechaHoy());

        $this->assertEqualsWithDelta(300.0, $r['ventas_anuladas'], 0.001);
        $this->assertSame(1, $r['cantidad_ventas_anuladas']);
        // Las anuladas NO se suman a las ventas reales.
        $this->assertEqualsWithDelta(400.0, $r['total_ventas'], 0.001);
        $this->assertNotEmpty($ids);
    }

    public function test_filtro_por_cajero(): void
    {
        $this->dosTurnosHoy();

        // 400 en el turno de Ana (08:00–12:00).
        $this->cuenta(400, 'EFECTIVO', null, now()->subHours(4)->format('Y-m-d H:i:s'));
        // 250 en el turno de Beto.
        $this->cuenta(250, 'EFECTIVO', null, now()->subHour()->format('Y-m-d H:i:s'));

        $r = app(ResumenDiarioService::class)->resumenDelDia($this->fechaHoy(), 'Ana');

        $this->assertTrue($r['exito']);
        $this->assertSame(1, $r['cantidad_turnos']);
        $this->assertSame('Ana', $r['cajero']);
        $this->assertEqualsWithDelta(1000.0, $r['total_monto_apertura'], 0.001, 'Solo el fondo de Ana.');
        $this->assertEqualsWithDelta(400.0, $r['efectivo'], 0.001, 'Solo las ventas de Ana.');
    }

    public function test_otros_ingresos_y_retiros_del_cierre_afectan_el_esperado(): void
    {
        $ids = $this->dosTurnosHoy();
        $this->cuenta(400, 'EFECTIVO', null, now()->subHours(4)->format('Y-m-d H:i:s'));

        DB::table('caja_cierre')->where('apertura_id', $ids['a'])->update([
            'otros_ingresos' => 200,
            'retiros' => 100,
            'ventas_efectivo' => 400,
            'total_esperado' => 1500,
            'total_contado' => 1500,
        ]);

        $r = app(ResumenDiarioService::class)->resumenDelDia($this->fechaHoy());

        $this->assertEqualsWithDelta(200.0, $r['otros_ingresos'], 0.001);
        $this->assertEqualsWithDelta(100.0, $r['retiros'], 0.001);
        // Esperado = 1500 (fondo) + 400 (efectivo) + 200 (otros) - 0 - 100 (retiros).
        $this->assertEqualsWithDelta(2000.0, $r['efectivo_esperado'], 0.001);
    }

    /* ------------------------------------------------------------------
     | 3. Validación de parámetros / casos límite
     | ------------------------------------------------------------------ */

    public function test_fecha_sin_turnos_devuelve_mensaje_sin_error(): void
    {
        $this->dosTurnosHoy();

        $r = app(ResumenDiarioService::class)->resumenDelDia('2000-01-01');

        $this->assertFalse($r['exito']);
        $this->assertArrayHasKey('error', $r);
    }

    public function test_fecha_invalida_no_lanza_error(): void
    {
        $r = app(ResumenDiarioService::class)->resumenDelDia('31/13/2026');

        $this->assertFalse($r['exito']);
    }

    public function test_fecha_invalida_en_la_url_no_rompe_la_pantalla(): void
    {
        $this->actingAs($this->usuario('ADMIN'))
            ->get(route('reportes.resumen', ['fecha' => 'no-es-fecha']))
            ->assertStatus(200);
    }

    /* ------------------------------------------------------------------
     | 3b. Cajas: quién abrió/cerró, fechas, totales y diferencia
     | ------------------------------------------------------------------ */

    public function test_muestra_quien_abrio_y_quien_cerro_la_caja_y_la_diferencia(): void
    {
        $admin = $this->usuario('ADMIN');
        $cajero = $this->usuario('CAJERO');
        $hoy = $this->fechaHoy();

        $aperturaId = DB::table('caja_apertura')->insertGetId([
            'fecha' => $hoy.' 08:00:00',
            'cajero' => 'Texto heredado de apertura',
            'turno' => 'Mañana',
            'caja_id' => 1,
            'monto_inicial' => 500,
            'conteo_json' => null,
            'estado' => 'CERRADA',
            'creado_en' => now()->subHours(6)->format('Y-m-d H:i:s'),
            'usuario_id' => $admin->id,
        ]);

        // Venta de 300 en efectivo dentro del turno.
        $this->cuenta(300, 'EFECTIVO', null, now()->subHours(4)->format('Y-m-d H:i:s'), $aperturaId);

        DB::table('caja_cierre')->insert([
            'apertura_id' => $aperturaId,
            'fecha' => now()->subHour()->format('Y-m-d H:i:s'),
            'cajero' => 'Texto heredado de cierre',
            'turno' => 'Mañana',
            'caja_id' => 1,
            'monto_apertura' => 500,
            'ventas_efectivo' => 300,
            'otros_ingresos' => 0,
            'egresos' => 0,
            'retiros' => 0,
            'total_esperado' => 800,
            'tasa_cambio' => 36.5,
            'monto_extranjero' => 0,
            'total_contado' => 780,
            'diferencia' => -20,
            'estado' => 'DESCUADRE',
            'conteo_json' => null,
            'observaciones' => null,
            'creado_en' => now(),
            'usuario_id' => $cajero->id,
        ]);

        $r = app(ResumenDiarioService::class)->resumenDelDia($hoy);

        $this->assertTrue($r['exito']);
        $this->assertSame('ADMIN Test', $r['turnos'][0]['abrio'], 'Se prefiere caja_apertura.usuario_id, no el texto cajero.');
        $this->assertSame('CAJERO Test', $r['turnos'][0]['cerro'], 'Se prefiere caja_cierre.usuario_id.');
        $this->assertNotNull($r['turnos'][0]['fecha_apertura']);
        $this->assertNotNull($r['turnos'][0]['fecha_cierre']);
        $this->assertEqualsWithDelta(300.0, $r['turnos'][0]['total_ventas'], 0.001);
        $this->assertEqualsWithDelta(-20.0, $r['turnos'][0]['diferencia'], 0.001);
        $this->assertSame(1, $r['cantidad_cierres']);
        $this->assertEqualsWithDelta(-20.0, $r['diferencia_caja'], 0.001);
        // El esquema mínimo de esta prueba no tiene propinas: no se inventan.
        $this->assertFalse($r['propinas']['disponible']);

        // Y la interfaz muestra los nombres y la diferencia.
        $this->actingAs($admin)
            ->get(route('reportes.resumen', ['fecha' => $hoy]))
            ->assertStatus(200)
            ->assertSee('ADMIN Test')
            ->assertSee('CAJERO Test')
            ->assertSee('TOTAL VENTAS');
    }

    /* ------------------------------------------------------------------
     | 4. Ticket y solo lectura
     | ------------------------------------------------------------------ */

    public function test_el_ticket_imprime_el_esperado(): void
    {
        $this->dosTurnosHoy();
        $this->cuenta(400, 'EFECTIVO', null, now()->subHours(4)->format('Y-m-d H:i:s'));

        $this->actingAs($this->usuario('ADMIN'))
            ->get(route('reportes.resumen.ticket', ['fecha' => $this->fechaHoy(), 'cajero' => 'TODOS']))
            ->assertStatus(200)
            ->assertSee('RESUMEN DEL DÍA')
            ->assertSee('C$ 1,400.00') // Fondo 1000 + efectivo 400 = 1400 (cierre de Ana).
            ->assertSee('C$ 500.00');  // Fondo 500 (cierre de Beto).
    }

    public function test_el_ticket_sin_datos_devuelve_404(): void
    {
        $this->dosTurnosHoy();

        $this->actingAs($this->usuario('ADMIN'))
            ->get(route('reportes.resumen.ticket', ['fecha' => '2000-01-01']))
            ->assertStatus(404);
    }

    public function test_consultar_e_imprimir_no_modifican_datos(): void
    {
        $ids = $this->dosTurnosHoy();
        $this->cuenta(400, 'EFECTIVO', null, now()->subHours(4)->format('Y-m-d H:i:s'));
        $this->gasto($ids['a'], 100, 'ACTIVO');

        $antes = [
            'cuentas' => DB::table('cuentas')->count(),
            'ventas' => DB::table('ventas')->count(),
            'gastos' => DB::table('caja_gastos')->count(),
            'cierres' => DB::table('caja_cierre')->count(),
            'aperturas' => DB::table('caja_apertura')->count(),
            'suma_cuentas' => (float) DB::table('cuentas')->sum('total'),
        ];

        $admin = $this->usuario('ADMIN');
        $this->actingAs($admin)->get(route('reportes.resumen', ['fecha' => $this->fechaHoy()]))->assertStatus(200);
        $this->actingAs($admin)->get(route('reportes.resumen.ticket', ['fecha' => $this->fechaHoy()]))->assertStatus(200);

        $despues = [
            'cuentas' => DB::table('cuentas')->count(),
            'ventas' => DB::table('ventas')->count(),
            'gastos' => DB::table('caja_gastos')->count(),
            'cierres' => DB::table('caja_cierre')->count(),
            'aperturas' => DB::table('caja_apertura')->count(),
            'suma_cuentas' => (float) DB::table('cuentas')->sum('total'),
        ];

        $this->assertSame($antes, $despues, 'Consultar/imprimir no debe modificar datos.');
    }

    public function test_caja_abierta_sin_cierre_muestra_resumen_inmediato(): void
    {
        $aperturaId = $this->apertura($this->fechaHoy().' 08:00:00', now()->subHours(6)->format('Y-m-d H:i:s'), 200, 'Ana');
        $this->cuenta(300, 'EFECTIVO', null, now()->subHours(4)->format('Y-m-d H:i:s'), $aperturaId);

        $r = app(ResumenDiarioService::class)->resumenDelDia($this->fechaHoy());

        $this->assertTrue($r['exito']);
        $this->assertSame(1, $r['cantidad_turnos']);

        $turno = $r['turnos'][0];
        $this->assertSame('ABIERTA', $turno['estado']);
        $this->assertSame('ABIERTA', $turno['estado_caja']);
        $this->assertTrue($turno['cierre'] === null || $turno['cierre']->id > 0);
        $this->assertNull($turno['diferencia']);
        $this->assertEqualsWithDelta(300.0, $turno['total_ventas'], 0.001);

        $html = $this->actingAs($this->usuario('ADMIN'))
            ->get(route('reportes.resumen', ['fecha' => $this->fechaHoy()]))
            ->assertStatus(200)
            ->getContent();

        $this->assertStringContainsString('CAJA ABIERTA', $html);
        $this->assertStringNotContainsString('No se encontraron aperturas', $html);
    }

    public function test_caja_abierta_sin_ventas_muestra_movimientos_cero(): void
    {
        $this->apertura($this->fechaHoy().' 09:00:00', now()->subHours(6)->format('Y-m-d H:i:s'), 150, 'Ana');

        $r = app(ResumenDiarioService::class)->resumenDelDia($this->fechaHoy());

        $this->assertTrue($r['exito']);
        $this->assertSame(1, $r['cantidad_turnos']);
        $this->assertEqualsWithDelta(0.0, $r['turnos'][0]['total_ventas'], 0.001);
        $this->assertEqualsWithDelta(150.0, (float) ($r['aperturas'][0]->monto_inicial ?? 0), 0.001, 'El fondo debe aparecer en el detalle.');
    }

    public function test_caja_abierta_con_gastos_y_retiros_aparece_y_suma_bien(): void
    {
        $aperturaId = $this->apertura($this->fechaHoy().' 08:00:00', now()->subHours(6)->format('Y-m-d H:i:s'), 500, 'Ana');
        $this->cuenta(1000, 'EFECTIVO', null, now()->subHours(4)->format('Y-m-d H:i:s'), $aperturaId);
        $this->gasto($aperturaId, 200, 'ACTIVO');

        // Retiro registrado en la "ventana" del turno se modela como retiro del cierre.
        $cierreId = DB::table('caja_cierre')->insertGetId([
            'apertura_id' => $aperturaId,
            'fecha' => now(),
            'cajero' => 'Ana',
            'turno' => 'Tarde',
            'caja_id' => 1,
            'monto_apertura' => 500,
            'ventas_efectivo' => 1000,
            'otros_ingresos' => 0,
            'egresos' => 200,
            'retiros' => 100,
            'total_esperado' => 1200,
            'tasa_cambio' => 1,
            'monto_extranjero' => 0,
            'total_contado' => 1200,
            'diferencia' => 0,
            'estado' => 'CUADRA',
            'conteo_json' => null,
            'observaciones' => null,
            'creado_en' => now(),
            'usuario_id' => null,
        ]);

        // Si existe cierre, el resumen es definitivo. El retiro es del cierre.
        $r = app(ResumenDiarioService::class)->resumenDelDia($this->fechaHoy());

        $this->assertTrue($r['exito']);
        $this->assertSame(1, $r['cantidad_turnos']);
        $this->assertEqualsWithDelta(200.0, $r['turnos'][0]['gastos_activos'], 0.001);
        $this->assertEqualsWithDelta(100.0, $r['turnos'][0]['retiros'], 0.001);
    }

    public function test_periodo_con_caja_abierta_y_una_cerrada_muestra_ambas(): void
    {
        $aperturaAbierta = $this->apertura($this->fechaHoy().' 08:00:00', now()->subHours(6)->format('Y-m-d H:i:s'), 300, 'Ana');
        $aperturaCerrada = $this->apertura($this->fechaHoy().' 12:00:00', now()->subHours(3)->format('Y-m-d H:i:s'), 400, 'Ana');
        $this->cuenta(300, 'EFECTIVO', null, now()->subHours(5)->format('Y-m-d H:i:s'), $aperturaAbierta);
        $this->cuenta(200, 'EFECTIVO', null, now()->subHours(2)->format('Y-m-d H:i:s'), $aperturaCerrada);
        $this->cierre($aperturaCerrada);

        $r = app(ResumenDiarioService::class)->resumenDelDia($this->fechaHoy());

        $this->assertTrue($r['exito']);
        $this->assertSame(2, $r['cantidad_turnos']);
        $this->assertSame(1, $r['turnos_abiertos']);
        $this->assertSame(1, $r['cantidad_cierres']);

        $html = $this->actingAs($this->usuario('ADMIN'))
            ->get(route('reportes.resumen', ['fecha' => $this->fechaHoy()]))
            ->assertStatus(200)
            ->getContent();

        $this->assertStringContainsString('CAJA ABIERTA', $html);
        $this->assertStringContainsString('Cierre', $html);
    }

    public function test_tres_aperturas_en_un_dia_cada_una_aparece_y_no_se_duplica(): void
    {
        $a1 = $this->apertura($this->fechaHoy().' 07:00:00', now()->subHours(7)->format('Y-m-d H:i:s'), 100, 'Ana');
        $a2 = $this->apertura($this->fechaHoy().' 10:00:00', now()->subHours(5)->format('Y-m-d H:i:s'), 200, 'Ana');
        $a3 = $this->apertura($this->fechaHoy().' 15:00:00', now()->subHours(2)->format('Y-m-d H:i:s'), 300, 'Ana');
        $this->cuenta(100, 'EFECTIVO', null, now()->subHours(6)->format('Y-m-d H:i:s'), $a1);
        $this->cuenta(200, 'EFECTIVO', null, now()->subHours(4)->format('Y-m-d H:i:s'), $a2);
        $this->cuenta(300, 'EFECTIVO', null, now()->subHours(1)->format('Y-m-d H:i:s'), $a3);
        $this->cierre($a1);
        $this->cierre($a2);
        $this->cierre($a3);

        $r = app(ResumenDiarioService::class)->resumenDelDia($this->fechaHoy());

        $this->assertSame(3, $r['cantidad_turnos']);
        $this->assertSame(3, $r['cantidad_cierres']);
        $this->assertSame(0, $r['turnos_abiertos']);
        $this->assertEqualsWithDelta(600.0, (float) $r['total_ventas'], 0.001);
        $this->assertEqualsWithDelta(600.0, (float) $r['efectivo'], 0.001);
    }

    public function test_periodo_sin_cierres_pero_con_aperturas_muestra_aperturas_no_mensaje_de_error(): void
    {
        $this->apertura($this->fechaHoy().' 08:00:00', now()->subHours(6)->format('Y-m-d H:i:s'), 200, 'Ana');

        $html = $this->actingAs($this->usuario('ADMIN'))
            ->get(route('reportes.resumen', ['fecha' => $this->fechaHoy()]))
            ->assertStatus(200)
            ->getContent();

        $this->assertStringContainsString('CAJA ABIERTA', $html);
        $this->assertStringNotContainsString('No se encontraron aperturas', $html);
        $this->assertStringNotContainsString('No se encontraron cierres', $html);
    }

    public function test_filtro_por_cajero_con_caja_abierta(): void
    {
        $this->apertura($this->fechaHoy().' 08:00:00', now()->subHours(6)->format('Y-m-d H:i:s'), 300, 'Ana');
        $this->apertura($this->fechaHoy().' 10:00:00', now()->subHours(4)->format('Y-m-d H:i:s'), 500, 'Beto');

        $r = app(ResumenDiarioService::class)->resumenDelDia($this->fechaHoy(), 'Ana');

        $this->assertTrue($r['exito']);
        $this->assertSame(1, $r['cantidad_turnos']);
        $this->assertSame('Ana', $r['cajero']);
    }

    public function test_al_cerrar_la_misma_caja_conserva_operaciones_sin_duplicar(): void
    {
        $aperturaId = $this->apertura($this->fechaHoy().' 08:00:00', now()->subHours(6)->format('Y-m-d H:i:s'), 300, 'Ana');
        $this->cuenta(400, 'EFECTIVO', null, now()->subHours(4)->format('Y-m-d H:i:s'), $aperturaId);

        // Antes de cerrar: CAJA ABIERTA con 400 de ventas.
        $previa = app(ResumenDiarioService::class)->resumenDelDia($this->fechaHoy());
        $this->assertSame(1, $previa['cantidad_turnos']);
        $this->assertEqualsWithDelta(400.0, $previa['turnos'][0]['total_ventas'], 0.001);

        // Cerrar la MISMA apertura.
        $this->cierre($aperturaId);

        // Después de cerrar: aparece de nuevo el mismo turno, mismos 400.
        $definitiva = app(ResumenDiarioService::class)->resumenDelDia($this->fechaHoy());
        $this->assertSame(1, $definitiva['cantidad_turnos']);
        $this->assertEqualsWithDelta(400.0, $definitiva['turnos'][0]['total_ventas'], 0.001);
        $this->assertEqualsWithDelta(400.0, $definitiva['efectivo'], 0.001);
        $this->assertSame(1, $definitiva['cantidad_cierres']);
    }

    public function test_propinas_se_informan_con_pendiente(): void
    {
        $aperturaId = $this->apertura($this->fechaHoy().' 08:00:00', now()->subHours(6)->format('Y-m-d H:i:s'), 200, 'Ana');

        $r = app(ResumenDiarioService::class)->resumenDelDia($this->fechaHoy());

        $this->assertTrue($r['exito']);
        $this->assertArrayHasKey('propinas', $r);
        $this->assertIsArray($r['propinas']);
    }

    public function test_pantalla_y_ticket_coinciden_en_datos(): void
    {
        $aperturaId = $this->apertura($this->fechaHoy().' 08:00:00', now()->subHours(6)->format('Y-m-d H:i:s'), 200, 'Ana');
        $this->cuenta(500, 'EFECTIVO', null, now()->subHours(4)->format('Y-m-d H:i:s'), $aperturaId);

        $admin = $this->usuario('ADMIN');
        $htmlPantalla = $this->actingAs($admin)
            ->get(route('reportes.resumen', ['fecha' => $this->fechaHoy()]))
            ->assertStatus(200)
            ->getContent();

        $htmlTicket = $this->actingAs($admin)
            ->get(route('reportes.resumen.ticket', ['fecha' => $this->fechaHoy()]))
            ->assertStatus(200)
            ->getContent();

        $this->assertStringContainsString('500.00', $htmlPantalla);
        $this->assertStringContainsString('500.00', $htmlTicket);
        $this->assertStringContainsString('Ana', $htmlPantalla);
        $this->assertStringContainsString('Ana', $htmlTicket);
    }
}
