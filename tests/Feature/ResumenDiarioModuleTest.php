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

        DB::table('caja_cierre')->insert([
            'apertura_id' => $ids['a'],
            'fecha' => now(),
            'cajero' => 'Ana',
            'turno' => 'Tarde',
            'caja_id' => 1,
            'monto_apertura' => 1000,
            'ventas_efectivo' => 400,
            'otros_ingresos' => 200,
            'egresos' => 0,
            'retiros' => 100,
            'total_esperado' => 1500,
            'tasa_cambio' => 36.5,
            'monto_extranjero' => 0,
            'total_contado' => 1500,
            'diferencia' => 0,
            'estado' => 'CUADRA',
            'conteo_json' => null,
            'observaciones' => null,
            'creado_en' => now(),
            'usuario_id' => null,
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
            ->assertSee('C$ 1,900.00'); // Fondo 1500 + efectivo 400 = 1900.
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
}
