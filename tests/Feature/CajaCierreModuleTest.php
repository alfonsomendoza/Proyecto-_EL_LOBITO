<?php

namespace Tests\Feature;

use App\Models\CajaApertura;
use App\Models\CajaCierre;
use App\Models\User;
use App\Services\CajaCierreService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/*
 * Pruebas aisladas del módulo de Cierre de Caja (arqueo del turno).
 *
 * IMPORTANTE:
 * - Corren en SQLite :memory: (configurado en phpunit.xml).
 * - El esquema se construye a mano porque las tablas reales viven en MySQL;
 *   así ninguna prueba escribe en la base "sistemadecaja".
 * - setUp() falla a propósito si la conexión no es SQLite en memoria.
 *
 * Reglas comprobadas:
 * - ADMIN, GERENTE y CAJERO: ver el panel, cerrar e imprimir.
 * - ADMIN y GERENTE: anular. CAJERO y MESERO: 403.
 * - MESERO: sin acceso al módulo.
 * - Fórmula: total_esperado = monto_inicial + ventas_efectivo + otros - egresos - retiros.
 * - Tarjetas/transferencias se reportan aparte y NO suman al efectivo esperado.
 * - total_contado = conteo_local + (dólares x tasa); diferencia y estado coherentes.
 * - No se puede cerrar sin caja abierta ni dos veces.
 * - Anulación lógica: estado ANULADO y la apertura vuelve a ABIERTA.
 */
class CajaCierreModuleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Candado de seguridad: nunca apuntar a la base MySQL real.
        $this->assertSame('sqlite', DB::connection()->getDriverName(), 'Las pruebas deben ejecutarse en SQLite.');
        $this->assertSame(':memory:', (string) config('database.connections.sqlite.database'), 'La base de pruebas debe ser SQLite en memoria.');

        $this->crearEsquema();
    }

    private function crearEsquema(): void
    {
        foreach (['caja_cierre', 'caja_gastos', 'caja_apertura', 'cuenta_detalles', 'cuentas', 'venta_detalles', 'ventas', 'mesas', 'usuarios', 'roles'] as $tabla) {
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

        Schema::create('mesas', function (Blueprint $t) {
            $t->increments('id');
            $t->string('numero', 20)->nullable();
            $t->integer('capacidad')->nullable();
            $t->string('estado', 20)->default('LIBRE');
        });

        Schema::create('ventas', function (Blueprint $t) {
            $t->increments('id');
            $t->unsignedBigInteger('mesa_id')->nullable();
            $t->integer('apertura_id')->nullable();
            $t->string('descripcion')->nullable();
            $t->decimal('monto', 12, 2)->default(0);
            $t->string('estado', 20)->default('ACTIVA');
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

        // Detalle de la venta (consumo). Refleja el esquema real usado por el POS.
        Schema::create('venta_detalles', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->integer('venta_id');
            $t->integer('producto_id');
            $t->integer('cantidad');
            $t->decimal('precio_unitario', 10, 2);
            $t->decimal('subtotal', 10, 2);
            $t->integer('comanda_id')->nullable();
            $t->integer('cuenta_id')->nullable();
            $t->string('estado', 255)->default('ACTIVO');
            $t->text('notas')->nullable();
            $t->string('estado_preparacion', 20)->nullable();
            $t->timestamps();
        });

        // Asignación de unidades de una venta a una cuenta (con borrado lógico).
        Schema::create('cuenta_detalles', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('cuenta_id');
            $t->integer('venta_id');
            $t->unsignedBigInteger('venta_detalle_id');
            $t->unsignedInteger('cantidad');
            $t->decimal('subtotal', 10, 2);
            $t->boolean('eliminado')->default(false);
            $t->integer('eliminado_por')->nullable();
            $t->timestamp('eliminado_en')->nullable();
            $t->timestamps();
        });

        // Refleja el esquema real de caja_apertura (incluye creado_en).
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

        // Refleja el esquema real de caja_cierre.
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

    private function apertura(string $estado = 'ABIERTA', ?string $creadoEn = null, float $montoInicial = 1000, ?int $usuarioId = null): int
    {
        return DB::table('caja_apertura')->insertGetId([
            'fecha' => now()->subHour(),
            'cajero' => 'Cajero Test',
            'turno' => 'Tarde',
            'caja_id' => 1,
            'monto_inicial' => $montoInicial,
            'conteo_json' => null,
            'estado' => $estado,
            'creado_en' => $creadoEn ?? now()->subHour(),
            'usuario_id' => $usuarioId,
        ]);
    }

    private function cuenta(float $total, ?string $metodo, ?string $banco = null, ?string $updatedAt = null, ?int $aperturaId = null, string $estado = 'PAGADA', string $nombre = 'Cuenta'): int
    {
        $momento = $updatedAt ?? now()->subMinutes(30);

        return DB::table('cuentas')->insertGetId([
            'venta_id' => 1,
            'nombre' => $nombre,
            'total' => $total,
            'estado' => $estado,
            'apertura_id' => $aperturaId,
            'metodo_pago' => $metodo,
            'banco' => $banco,
            'monto_recibido' => $total,
            'cambio' => 0,
            'usuario_cobro' => null,
            'created_at' => $momento,
            'updated_at' => $momento,
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

    private function conteoLocalCero(): array
    {
        $conteo = [];
        foreach (CajaCierreService::DENOMINACIONES_LOCAL as $den) {
            $conteo[(string) $den] = 0;
        }

        return $conteo;
    }

    /* ------------------------------------------------------------------
     | Constructores de mesas/ventas/cuentas con consumo (bloqueo de cierre)
     | ------------------------------------------------------------------ */

    private function mesa(string $numero = '1', string $estado = 'OCUPADA'): int
    {
        return DB::table('mesas')->insertGetId([
            'numero' => $numero,
            'capacidad' => 4,
            'estado' => $estado,
        ]);
    }

    private function venta(int $mesaId, string $estado = 'ACTIVA'): int
    {
        return DB::table('ventas')->insertGetId([
            'mesa_id' => $mesaId,
            'apertura_id' => null,
            'descripcion' => 'Venta de prueba',
            'monto' => 0,
            'estado' => $estado,
        ]);
    }

    private function cuentaDeVenta(int $ventaId, float $total, string $estado = 'ABIERTA', string $nombre = 'Cuenta'): int
    {
        return DB::table('cuentas')->insertGetId([
            'venta_id' => $ventaId,
            'nombre' => $nombre,
            'total' => $total,
            'estado' => $estado,
            'apertura_id' => null,
            'metodo_pago' => null,
            'banco' => null,
            'monto_recibido' => null,
            'cambio' => null,
            'usuario_cobro' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function detalle(int $ventaId, int $cantidad, string $estado = 'ACTIVO'): int
    {
        return DB::table('venta_detalles')->insertGetId([
            'venta_id' => $ventaId,
            'producto_id' => 1,
            'cantidad' => $cantidad,
            'precio_unitario' => 100,
            'subtotal' => $cantidad * 100,
            'comanda_id' => null,
            'cuenta_id' => null,
            'estado' => $estado,
            'notas' => null,
            'estado_preparacion' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function asignacion(int $cuentaId, int $ventaId, int $detalleId, int $cantidad, bool $eliminado = false): int
    {
        return DB::table('cuenta_detalles')->insertGetId([
            'cuenta_id' => $cuentaId,
            'venta_id' => $ventaId,
            'venta_detalle_id' => $detalleId,
            'cantidad' => $cantidad,
            'subtotal' => $cantidad * 100,
            'eliminado' => $eliminado,
            'eliminado_por' => null,
            'eliminado_en' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Intenta cerrar la caja como lo haría el navegador (POST JSON).
     */
    private function intentarCierre()
    {
        return $this->postJson(route('caja.cierre.registrar'), [
            'tasa_cambio' => 36.5,
            'conteo_local' => $this->conteoLocalCero(),
        ]);
    }

    /* ------------------------------------------------------------------
     | 1. Acceso
     | ------------------------------------------------------------------ */

    public function test_admin_gerente_y_cajero_pueden_ver_el_panel(): void
    {
        foreach (['ADMIN', 'GERENTE', 'CAJERO'] as $rol) {
            $this->actingAs($this->usuario($rol))
                ->get(route('caja.cierre'))
                ->assertStatus(200);
        }
    }

    public function test_mesero_no_puede_ver_el_panel(): void
    {
        $this->actingAs($this->usuario('MESERO'))
            ->get(route('caja.cierre'))
            ->assertStatus(403);
    }

    public function test_no_autenticado_es_redirigido_al_login(): void
    {
        $this->get(route('caja.cierre'))->assertRedirect(route('login'));
    }

    public function test_apertura_rechaza_montos_con_formato_invalido(): void
    {
        $admin = $this->usuario('ADMIN');

        $invalidos = [
            'e',
            '0e',
            '0e13212312132121',
            '1e3',
            '10abc',
            '25aasasdasdasdasasd',
            '--10',
            '-10',
            '10.999',
            '10000000000',
        ];

        foreach ($invalidos as $valor) {
            $this->actingAs($admin)
                ->postJson(route('caja.abrir'), ['monto_inicial' => $valor])
                ->assertStatus(422);
        }

        $this->assertDatabaseCount('caja_apertura', 0);

        $this->actingAs($admin)
            ->postJson(route('caja.abrir'), ['monto_inicial' => '10.50'])
            ->assertStatus(200)
            ->assertJson(['exito' => true]);

        $this->assertDatabaseHas('caja_apertura', ['usuario_id' => $admin->id, 'estado' => 'ABIERTA']);
    }

    public function test_mesero_no_puede_cerrar_caja(): void
    {
        $this->apertura('ABIERTA');

        $this->actingAs($this->usuario('MESERO'))
            ->post(route('caja.cierre.registrar'), [
                'tasa_cambio' => 36.5,
                'conteo_local' => $this->conteoLocalCero(),
            ])
            ->assertStatus(403)
            ->assertJson(['exito' => false]);

        $this->assertDatabaseCount('caja_cierre', 0);
    }

    public function test_mesero_no_puede_imprimir_ticket(): void
    {
        $this->actingAs($this->usuario('MESERO'))
            ->get(route('caja.cierre.ticket'))
            ->assertStatus(403);
    }

    /* ------------------------------------------------------------------
     | 2. Fórmula del arqueo
     | ------------------------------------------------------------------ */

    public function test_la_formula_calcula_esperado_solo_con_efectivo(): void
    {
        $admin = $this->usuario('ADMIN');
        $aperturaId = $this->apertura('ABIERTA', now()->subHour()->format('Y-m-d H:i:s'), 1000, $admin->id);

        // Efectivo del turno: 870 + 500 = 1370
        $this->cuenta(870, 'EFECTIVO');
        $this->cuenta(500, 'EFECTIVO');
        // Tarjeta y transferencia: NO deben sumar al efectivo esperado.
        $this->cuenta(1000, 'TARJETA', 'BAC');
        $this->cuenta(300, 'TRANSFERENCIA');
        // Efectivo FUERA del turno (antes de la apertura): debe excluirse.
        $this->cuenta(9999, 'EFECTIVO', null, now()->subDays(2)->format('Y-m-d H:i:s'));

        // Gastos: uno activo (200) y uno anulado (999) que no cuenta.
        $this->gasto($aperturaId, 200, 'ACTIVO');
        $this->gasto($aperturaId, 999, 'ANULADO');

        // Esperado = 1000 + 1370 - 200 = 2170
        $conteo = $this->conteoLocalCero();
        $conteo['1000'] = 2; // 2000
        $conteo['100'] = 1;  // 100
        $conteo['50'] = 1;   // 50
        $conteo['20'] = 1;   // 20  => 2170

        $this->actingAs($admin)
            ->postJson(route('caja.cierre.registrar'), [
                'tasa_cambio' => 36.5,
                'conteo_local' => $conteo,
            ])
            ->assertStatus(200)
            ->assertJson(['exito' => true, 'estado' => 'CUADRA']);

        $cierre = CajaCierre::latest('id')->first();
        $this->assertEqualsWithDelta(1370.0, (float) $cierre->ventas_efectivo, 0.001, 'Solo el efectivo suma.');
        $this->assertEqualsWithDelta(200.0, (float) $cierre->egresos, 0.001, 'Solo los gastos activos suman.');
        $this->assertEqualsWithDelta(2170.0, (float) $cierre->total_esperado, 0.001);
        $this->assertEqualsWithDelta(2170.0, (float) $cierre->total_contado, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $cierre->diferencia, 0.001);
        $this->assertSame('CUADRA', $cierre->estado);

        // La apertura queda cerrada.
        $this->assertSame('CERRADA', CajaApertura::find($aperturaId)->estado);
    }

    public function test_descuadre_cuando_falta_efectivo(): void
    {
        $admin = $this->usuario('ADMIN');
        $aperturaId = $this->apertura('ABIERTA', now()->subHour()->format('Y-m-d H:i:s'), 1000, $admin->id);
        $this->cuenta(500, 'EFECTIVO');

        // Esperado = 1000 + 500 = 1500; contamos solo 1400 => falta 100.
        $conteo = $this->conteoLocalCero();
        $conteo['1000'] = 1;
        $conteo['500'] = 0;
        $conteo['200'] = 2; // 1400

        $this->actingAs($admin)
            ->postJson(route('caja.cierre.registrar'), ['tasa_cambio' => 36.5, 'conteo_local' => $conteo])
            ->assertStatus(200)
            ->assertJson(['exito' => true, 'estado' => 'DESCUADRE']);

        $cierre = CajaCierre::latest('id')->first();
        $this->assertEqualsWithDelta(-100.0, (float) $cierre->diferencia, 0.001);
        $this->assertSame('DESCUADRE', $cierre->estado);
    }

    public function test_moneda_extranjera_se_convierte_con_la_tasa(): void
    {
        $admin = $this->usuario('ADMIN');
        $this->apertura('ABIERTA', now()->subHour()->format('Y-m-d H:i:s'), 1000, $admin->id);

        // 10 billetes de $10 = 100 USD; a 36.5 => 3650 C$.
        $this->actingAs($admin)
            ->postJson(route('caja.cierre.registrar'), [
                'tasa_cambio' => 36.5,
                'conteo_local' => $this->conteoLocalCero(),
                'conteo_dolar' => ['10' => 10],
            ])
            ->assertStatus(200)
            ->assertJson(['exito' => true]);

        $cierre = CajaCierre::latest('id')->first();
        $this->assertEqualsWithDelta(100.0, (float) $cierre->monto_extranjero, 0.001);
        $this->assertEqualsWithDelta(3650.0, (float) $cierre->total_contado, 0.001);
        // Esperado = 1000 (sin ventas ni gastos) => sobra 2650, sigue CUADRA.
        $this->assertEqualsWithDelta(1000.0, (float) $cierre->total_esperado, 0.001);
        $this->assertSame('CUADRA', $cierre->estado);
    }

    public function test_otros_ingresos_y_retiros_afectan_el_esperado(): void
    {
        $admin = $this->usuario('ADMIN');
        $this->apertura('ABIERTA', now()->subHour()->format('Y-m-d H:i:s'), 1000, $admin->id);

        // Esperado = 1000 + 0 + 500 (otros) - 0 - 200 (retiros) = 1300.
        $conteo = $this->conteoLocalCero();
        $conteo['1000'] = 1;
        $conteo['200'] = 1;
        $conteo['100'] = 1; // 1300

        $this->actingAs($admin)
            ->postJson(route('caja.cierre.registrar'), [
                'tasa_cambio' => 36.5,
                'otros_ingresos' => 500,
                'retiros' => 200,
                'conteo_local' => $conteo,
            ])
            ->assertStatus(200);

        $cierre = CajaCierre::latest('id')->first();
        $this->assertEqualsWithDelta(1300.0, (float) $cierre->total_esperado, 0.001);
        $this->assertEqualsWithDelta(500.0, (float) $cierre->otros_ingresos, 0.001);
        $this->assertEqualsWithDelta(200.0, (float) $cierre->retiros, 0.001);
    }

    public function test_dolares_como_sobrante_cordobizado(): void
    {
        // Regresión del bug del PHP puro: los dólares NO se contaban como
        // sobrante. Aquí deben convertirse e integrarse al total contado.
        $admin = $this->usuario('ADMIN');
        $this->apertura('ABIERTA', now()->subHour()->format('Y-m-d H:i:s'), 0, $admin->id);
        $this->cuenta(1000, 'EFECTIVO');

        $this->actingAs($admin)
            ->postJson(route('caja.cierre.registrar'), [
                'tasa_cambio' => 36.5,
                'conteo_local' => $this->conteoLocalCero(),
                'conteo_dolar' => ['20' => 1], // $20 => 730 C$
            ])
            ->assertStatus(200);

        $cierre = CajaCierre::latest('id')->first();
        $this->assertEqualsWithDelta(730.0, (float) $cierre->total_contado, 0.001);
        // Esperado 1000, contado 730 => falta 270 => DESCUADRE.
        $this->assertSame('DESCUADRE', $cierre->estado);
        $this->assertEqualsWithDelta(-270.0, (float) $cierre->diferencia, 0.001);
    }

    public function test_tarjetas_se_agrupan_por_banco(): void
    {
        $aperturaId = $this->apertura('ABIERTA', now()->subHour()->format('Y-m-d H:i:s'));
        $this->cuenta(1000, 'TARJETA', 'BAC');
        $this->cuenta(500, 'TARJETA', 'BAC');
        $this->cuenta(300, 'TARJETA', 'BANPRO');
        $this->cuenta(200, 'TRANSFERENCIA');
        $this->cuenta(400, 'EFECTIVO');

        $resumen = app(CajaCierreService::class)->resumenVentas(CajaApertura::find($aperturaId));

        $this->assertEqualsWithDelta(400.0, $resumen['efectivo'], 0.001);
        $this->assertEqualsWithDelta(1800.0, $resumen['tarjeta'], 0.001);
        $this->assertEqualsWithDelta(200.0, $resumen['transferencia'], 0.001);
        $this->assertEqualsWithDelta(1500.0, $resumen['tarjetas_por_banco']['BAC'], 0.001);
        $this->assertEqualsWithDelta(300.0, $resumen['tarjetas_por_banco']['BANPRO'], 0.001);
    }

    /* ------------------------------------------------------------------
     | 3. Atribución al turno
     | ------------------------------------------------------------------ */

    public function test_cuenta_con_apertura_id_tiene_prioridad_sobre_la_ventana(): void
    {
        $aperturaId = $this->apertura('ABIERTA', now()->subHour()->format('Y-m-d H:i:s'));

        // Fuera de la ventana temporal, pero con apertura_id explícito:
        // pertenece al turno y debe contarse.
        $this->cuenta(700, 'EFECTIVO', null, now()->subDays(5)->format('Y-m-d H:i:s'), $aperturaId);

        $resumen = app(CajaCierreService::class)->resumenVentas(CajaApertura::find($aperturaId));
        $this->assertEqualsWithDelta(700.0, $resumen['efectivo'], 0.001);
    }

    public function test_cuenta_pagada_fuera_de_la_ventana_se_excluye(): void
    {
        $aperturaId = $this->apertura('ABIERTA', now()->subHour()->format('Y-m-d H:i:s'));

        // Sin apertura_id y con fecha anterior al turno: no pertenece.
        $this->cuenta(999, 'EFECTIVO', null, now()->subDays(5)->format('Y-m-d H:i:s'));
        $this->cuenta(100, 'EFECTIVO');

        $resumen = app(CajaCierreService::class)->resumenVentas(CajaApertura::find($aperturaId));
        $this->assertEqualsWithDelta(100.0, $resumen['efectivo'], 0.001);
    }

    public function test_cuentas_no_pagadas_no_se_consideran(): void
    {
        $aperturaId = $this->apertura('ABIERTA', now()->subHour()->format('Y-m-d H:i:s'));
        $this->cuenta(500, 'EFECTIVO', null, null, null, 'ABIERTA');
        $this->cuenta(300, 'EFECTIVO', null, null, null, 'CANCELADA');

        $resumen = app(CajaCierreService::class)->resumenVentas(CajaApertura::find($aperturaId));
        $this->assertEqualsWithDelta(0.0, $resumen['efectivo'], 0.001);
    }

    /* ------------------------------------------------------------------
     | 4. Reglas de cierre
     | ------------------------------------------------------------------ */

    public function test_script_global_no_registra_envio_duplicado_de_apertura_y_cierre(): void
    {
        // El envío de estos dos formularios lo maneja cada vista con su propio
        // `fetch`. Si script.js volviera a registrar `manejarEnvio`, un solo
        // clic dispararía DOS peticiones HTTP.
        $script = file_get_contents(public_path('script.js'));

        $this->assertStringNotContainsString("manejarEnvio('formApertura')", $script);
        $this->assertStringNotContainsString("manejarEnvio('formCierre')", $script);

        // Las vistas conservan su manejador propio (validaciones y mensajes).
        $this->assertStringContainsString("getElementById('formApertura')", file_get_contents(resource_path('views/caja/apertura.blade.php')));
        $this->assertStringContainsString("getElementById('formCierre')", file_get_contents(resource_path('views/caja/cierre.blade.php')));
    }

    public function test_no_se_puede_cerrar_sin_caja_abierta(): void
    {
        $this->actingAs($this->usuario('CAJERO'))
            ->postJson(route('caja.cierre.registrar'), ['tasa_cambio' => 36.5, 'conteo_local' => $this->conteoLocalCero()])
            ->assertStatus(409)
            ->assertJson(['exito' => false]);

        $this->assertDatabaseCount('caja_cierre', 0);
    }

    public function test_no_se_puede_cerrar_dos_veces(): void
    {
        $admin = $this->usuario('ADMIN');
        $this->apertura('ABIERTA', now()->subHour()->format('Y-m-d H:i:s'), 1000, $admin->id);

        $this->actingAs($admin)
            ->postJson(route('caja.cierre.registrar'), ['tasa_cambio' => 36.5, 'conteo_local' => $this->conteoLocalCero()])
            ->assertStatus(200);

        // Segundo intento: ya no hay apertura ABIERTA.
        $this->actingAs($admin)
            ->postJson(route('caja.cierre.registrar'), ['tasa_cambio' => 36.5, 'conteo_local' => $this->conteoLocalCero()])
            ->assertStatus(409);

        $this->assertDatabaseCount('caja_cierre', 1);
    }

    public function test_tasa_de_cambio_invalida_es_rechazada(): void
    {
        $admin = $this->usuario('ADMIN');
        $this->apertura('ABIERTA', now()->subHour()->format('Y-m-d H:i:s'), 1000, $admin->id);

        $this->actingAs($admin)
            ->postJson(route('caja.cierre.registrar'), ['tasa_cambio' => 0, 'conteo_local' => $this->conteoLocalCero()])
            ->assertStatus(422);

        $this->assertDatabaseCount('caja_cierre', 0);
    }

    public function test_campos_numericos_con_formato_invalido_son_rechazados(): void
    {
        $admin = $this->usuario('ADMIN');
        $this->apertura('ABIERTA', now()->subHour()->format('Y-m-d H:i:s'), 1000, $admin->id);

        $conteo = $this->conteoLocalCero();

        $casos = [
            ['tasa_cambio' => 'e'],
            ['tasa_cambio' => '0e'],
            ['tasa_cambio' => '0e13212312132121'],
            ['tasa_cambio' => '1e3'],
            ['tasa_cambio' => '10abc'],
            ['tasa_cambio' => '-36.5'],
            ['tasa_cambio' => '36.50001'],
            ['tasa_cambio' => '1000000'],
            ['tasa_cambio' => '36.5', 'otros_ingresos' => 'e'],
            ['tasa_cambio' => '36.5', 'otros_ingresos' => '1e3'],
            ['tasa_cambio' => '36.5', 'otros_ingresos' => '10.999'],
            ['tasa_cambio' => '36.5', 'otros_ingresos' => '10000000000'],
            ['tasa_cambio' => '36.5', 'retiros' => '10abc'],
            ['tasa_cambio' => '36.5', 'retiros' => '-5'],
            ['tasa_cambio' => '36.5', 'retiros' => '5.999'],
            ['tasa_cambio' => '36.5', 'retiros' => '10000000000'],
            ['tasa_cambio' => '36.5', 'conteo_local' => array_merge($conteo, ['1000' => 'e'])],
            ['tasa_cambio' => '36.5', 'conteo_local' => array_merge($conteo, ['1000' => '1e3'])],
            ['tasa_cambio' => '36.5', 'conteo_local' => array_merge($conteo, ['1000' => '1.5'])],
            ['tasa_cambio' => '36.5', 'conteo_local' => array_merge($conteo, ['1000' => '10000000'])],
            ['tasa_cambio' => '36.5', 'conteo_dolar' => array_merge($conteo, ['100' => '0e123'])],
            ['tasa_cambio' => '36.5', 'conteo_dolar' => array_merge($conteo, ['100' => '10000000'])],
        ];

        foreach ($casos as $caso) {
            $this->actingAs($admin)
                ->postJson(route('caja.cierre.registrar'), $caso)
                ->assertStatus(422);
        }

        $this->assertDatabaseCount('caja_cierre', 0);
    }

    public function test_denominaciones_invalidas_no_se_contabilizan(): void
    {
        $admin = $this->usuario('ADMIN');
        $this->apertura('ABIERTA', now()->subHour()->format('Y-m-d H:i:s'), 1000, $admin->id);

        // La denominación 7 no existe: debe ignorarse por completo.
        $conteo = $this->conteoLocalCero();
        $conteo['7'] = 1000;

        $this->actingAs($admin)
            ->postJson(route('caja.cierre.registrar'), ['tasa_cambio' => 36.5, 'conteo_local' => $conteo])
            ->assertStatus(200);

        $cierre = CajaCierre::latest('id')->first();
        $this->assertEqualsWithDelta(0.0, (float) $cierre->total_contado, 0.001);
        $this->assertArrayNotHasKey('7', $cierre->conteo_json['local']);
    }

    public function test_el_cierre_guarda_el_conteo_y_la_observacion(): void
    {
        $admin = $this->usuario('ADMIN');
        $this->apertura('ABIERTA', now()->subHour()->format('Y-m-d H:i:s'), 0, $admin->id);

        $conteo = $this->conteoLocalCero();
        $conteo['0.5'] = 3; // 1.50

        $this->actingAs($admin)
            ->postJson(route('caja.cierre.registrar'), [
                'tasa_cambio' => 36.5,
                'conteo_local' => $conteo,
                'observaciones' => 'Faltó vuelto por error de digitación',
            ])
            ->assertStatus(200);

        $cierre = CajaCierre::latest('id')->first();
        $this->assertSame(3, $cierre->conteo_json['local']['0.5']);
        $this->assertEqualsWithDelta(1.5, (float) $cierre->total_contado, 0.001);
        $this->assertSame('Faltó vuelto por error de digitación', $cierre->observaciones);
        $this->assertSame($admin->id, $cierre->usuario_id);
    }

    /* ------------------------------------------------------------------
     | 4b. Bloqueo estricto: mesas con consumos pendientes de cobro
     | ------------------------------------------------------------------ */

    public function test_una_mesa_con_cuenta_pendiente_impide_cerrar_la_caja(): void
    {
        $admin = $this->usuario('ADMIN');
        $this->apertura('ABIERTA', now()->subHour()->format('Y-m-d H:i:s'), 1000, $admin->id);

        $mesaId = $this->mesa('1');
        $ventaId = $this->venta($mesaId);
        $this->cuentaDeVenta($ventaId, 250, 'ABIERTA', 'Cuenta principal');

        $this->actingAs($admin)
            ->intentarCierre()
            ->assertStatus(409)
            ->assertJson(['exito' => false, 'mesas_pendientes' => 1])
            ->assertJsonPath('error', 'No se puede cerrar la caja. Hay 1 mesa ocupada con consumos pendientes de cobro. Cobra o resuelve las cuentas pendientes antes de intentar el cierre.');

        $this->assertDatabaseCount('caja_cierre', 0);
    }

    public function test_varias_subcuentas_de_la_misma_mesa_cuentan_como_una_sola(): void
    {
        $admin = $this->usuario('ADMIN');
        $this->apertura('ABIERTA', now()->subHour()->format('Y-m-d H:i:s'), 1000, $admin->id);

        $mesaId = $this->mesa('1');
        $ventaId = $this->venta($mesaId);
        $this->cuentaDeVenta($ventaId, 100, 'ABIERTA', 'Principal');
        $this->cuentaDeVenta($ventaId, 100, 'ABIERTA', 'Subcuenta A');
        $this->cuentaDeVenta($ventaId, 100, 'ABIERTA', 'Subcuenta B');

        $this->actingAs($admin)
            ->intentarCierre()
            ->assertStatus(409)
            ->assertJson(['exito' => false, 'mesas_pendientes' => 1]);
    }

    public function test_dos_mesas_pendientes_bloquean_e_informan_el_numero_correcto(): void
    {
        $admin = $this->usuario('ADMIN');
        $this->apertura('ABIERTA', now()->subHour()->format('Y-m-d H:i:s'), 1000, $admin->id);

        foreach (['1', '2'] as $numero) {
            $mesaId = $this->mesa($numero);
            $ventaId = $this->venta($mesaId);
            $this->cuentaDeVenta($ventaId, 150, 'ABIERTA');
        }

        // Mesa marcada LIBRE por error pero con consumo: también cuenta, porque
        // la regla no se basa solo en el estado visual de la mesa.
        $mesaLibre = $this->mesa('3', 'LIBRE');
        $ventaLibre = $this->venta($mesaLibre);
        $this->cuentaDeVenta($ventaLibre, 80, 'ABIERTA');

        $this->actingAs($admin)
            ->intentarCierre()
            ->assertStatus(409)
            ->assertJsonPath('mesas_pendientes', 3)
            ->assertJsonPath('error', 'No se puede cerrar la caja. Hay 3 mesas ocupadas con consumos pendientes de cobro. Cobra o resuelve las cuentas pendientes antes de intentar el cierre.');

        $this->assertDatabaseCount('caja_cierre', 0);
    }

    public function test_la_caja_sin_consumos_pendientes_cierra_normalmente(): void
    {
        $admin = $this->usuario('ADMIN');
        $this->apertura('ABIERTA', now()->subHour()->format('Y-m-d H:i:s'), 1000, $admin->id);

        // Mesa ya cobrada: la venta está PAGADA.
        $mesaPagada = $this->mesa('1', 'LIBRE');
        $ventaPagada = $this->venta($mesaPagada, 'PAGADA');
        $cuentaPagada = $this->cuentaDeVenta($ventaPagada, 200, 'PAGADA');
        $detallePagado = $this->detalle($ventaPagada, 2);
        $this->asignacion($cuentaPagada, $ventaPagada, $detallePagado, 2);

        // Mesa abierta pero sin consumo (solo una cuenta en cero).
        $mesaVacia = $this->mesa('2', 'OCUPADA');
        $ventaVacia = $this->venta($mesaVacia);
        $this->cuentaDeVenta($ventaVacia, 0, 'ABIERTA');

        $this->actingAs($admin)
            ->intentarCierre()
            ->assertStatus(200)
            ->assertJson(['exito' => true]);

        $this->assertDatabaseCount('caja_cierre', 1);
        $this->assertDatabaseHas('caja_apertura', ['estado' => 'CERRADA']);
    }

    public function test_una_cuenta_pagada_no_bloquea_el_cierre_por_si_sola(): void
    {
        $admin = $this->usuario('ADMIN');
        $this->apertura('ABIERTA', now()->subHour()->format('Y-m-d H:i:s'), 1000, $admin->id);

        // Venta todavía ACTIVA (dato histórico) pero cuyo consumo ya está
        // cubierto por una cuenta PAGADA: no debe bloquear.
        $mesaId = $this->mesa('1', 'LIBRE');
        $ventaId = $this->venta($mesaId);
        $cuentaPagada = $this->cuentaDeVenta($ventaId, 300, 'PAGADA');
        $detalleId = $this->detalle($ventaId, 3);
        $this->asignacion($cuentaPagada, $ventaId, $detalleId, 3);

        $this->actingAs($admin)
            ->intentarCierre()
            ->assertStatus(200)
            ->assertJson(['exito' => true]);
    }

    public function test_una_cuenta_principal_en_cero_no_oculta_subcuentas_pendientes(): void
    {
        $admin = $this->usuario('ADMIN');
        $this->apertura('ABIERTA', now()->subHour()->format('Y-m-d H:i:s'), 1000, $admin->id);

        $mesaId = $this->mesa('1');
        $ventaId = $this->venta($mesaId);
        // La principal está en cero, pero la subcuenta tiene consumo pendiente.
        $this->cuentaDeVenta($ventaId, 0, 'ABIERTA', 'Principal');
        $subcuenta = $this->cuentaDeVenta($ventaId, 200, 'ABIERTA', 'Subcuenta');
        $detalleId = $this->detalle($ventaId, 2);
        $this->asignacion($subcuenta, $ventaId, $detalleId, 2);

        $this->actingAs($admin)
            ->intentarCierre()
            ->assertStatus(409)
            ->assertJson(['exito' => false, 'mesas_pendientes' => 1]);
    }

    public function test_un_detalle_sin_cuenta_vigente_bloquea_el_cierre(): void
    {
        $admin = $this->usuario('ADMIN');
        $this->apertura('ABIERTA', now()->subHour()->format('Y-m-d H:i:s'), 1000, $admin->id);

        $mesaId = $this->mesa('1');
        $ventaId = $this->venta($mesaId);
        // Cuenta cancelada: al cancelarla se borran sus asignaciones, pero el
        // consumo sigue ACTIVO y sin cuenta válida que lo cobre.
        $this->cuentaDeVenta($ventaId, 0, 'CANCELADA', 'Cancelada');
        $this->detalle($ventaId, 2);

        $this->actingAs($admin)
            ->intentarCierre()
            ->assertStatus(409)
            ->assertJson(['exito' => false, 'mesas_pendientes' => 1]);
    }

    public function test_una_peticion_http_directa_tampoco_elude_el_bloqueo(): void
    {
        $admin = $this->usuario('ADMIN');
        $this->apertura('ABIERTA', now()->subHour()->format('Y-m-d H:i:s'), 1000, $admin->id);

        $mesaId = $this->mesa('1');
        $ventaId = $this->venta($mesaId);
        $this->cuentaDeVenta($ventaId, 120, 'ABIERTA');

        // POST hecho "a mano", sin pasar por la interfaz.
        $this->actingAs($admin)->post(
            route('caja.cierre.registrar'),
            ['tasa_cambio' => 36.5, 'conteo_local' => $this->conteoLocalCero()],
            ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest']
        )
            ->assertStatus(409)
            ->assertJson(['exito' => false, 'mesas_pendientes' => 1]);

        $this->assertDatabaseCount('caja_cierre', 0);
    }

    public function test_el_rechazo_no_deja_cambios_parciales(): void
    {
        $admin = $this->usuario('ADMIN');
        $aperturaId = $this->apertura('ABIERTA', now()->subHour()->format('Y-m-d H:i:s'), 1000, $admin->id);

        $mesaId = $this->mesa('1', 'OCUPADA');
        $ventaId = $this->venta($mesaId);
        $cuentaId = $this->cuentaDeVenta($ventaId, 400, 'ABIERTA');

        $antesApertura = DB::table('caja_apertura')->where('id', $aperturaId)->first();
        $antesMesa = DB::table('mesas')->where('id', $mesaId)->first();
        $antesVenta = DB::table('ventas')->where('id', $ventaId)->first();
        $antesCuenta = DB::table('cuentas')->where('id', $cuentaId)->first();

        $this->actingAs($admin)->intentarCierre()->assertStatus(409);

        $this->assertDatabaseCount('caja_cierre', 0);
        $this->assertEquals($antesApertura, DB::table('caja_apertura')->where('id', $aperturaId)->first());
        $this->assertEquals($antesMesa, DB::table('mesas')->where('id', $mesaId)->first());
        $this->assertEquals($antesVenta, DB::table('ventas')->where('id', $ventaId)->first());
        $this->assertEquals($antesCuenta, DB::table('cuentas')->where('id', $cuentaId)->first());
    }

    public function test_los_historicos_pagados_no_provocan_falsos_bloqueos(): void
    {
        $admin = $this->usuario('ADMIN');
        $this->apertura('ABIERTA', now()->subHour()->format('Y-m-d H:i:s'), 1000, $admin->id);

        // Venta PAGADA con cuentas residuales (datos viejos).
        $mesa1 = $this->mesa('1', 'LIBRE');
        $venta1 = $this->venta($mesa1, 'PAGADA');
        $this->cuentaDeVenta($venta1, 500, 'ABIERTA');
        $this->cuentaDeVenta($venta1, 0, 'CANCELADA');

        // Venta CANCELADA con consumo.
        $mesa2 = $this->mesa('2', 'LIBRE');
        $venta2 = $this->venta($mesa2, 'CANCELADA');
        $this->detalle($venta2, 3);
        $this->cuentaDeVenta($venta2, 300, 'ABIERTA');

        // Venta sin mesa (mostrador): no es una mesa, no bloquea el cierre.
        $ventaSinMesa = DB::table('ventas')->insertGetId([
            'mesa_id' => null,
            'apertura_id' => null,
            'descripcion' => 'Mostrador',
            'monto' => 0,
            'estado' => 'ACTIVA',
        ]);
        $this->cuentaDeVenta($ventaSinMesa, 150, 'ABIERTA');

        $this->actingAs($admin)
            ->intentarCierre()
            ->assertStatus(200)
            ->assertJson(['exito' => true]);

        $this->assertDatabaseCount('caja_cierre', 1);
    }

    public function test_el_panel_avisa_cuantas_mesas_estan_pendientes(): void
    {
        $admin = $this->usuario('ADMIN');
        $this->apertura('ABIERTA', now()->subHour()->format('Y-m-d H:i:s'), 1000, $admin->id);

        $mesaId = $this->mesa('1');
        $ventaId = $this->venta($mesaId);
        $this->cuentaDeVenta($ventaId, 90, 'ABIERTA');

        $this->actingAs($admin)->get(route('caja.cierre'))
            ->assertStatus(200)
            ->assertSee('No se puede cerrar la caja.')
            ->assertSee('mesa ocupada con consumos pendientes de cobro');
    }

    /* ------------------------------------------------------------------
     | 5. Anulación del cierre
     | ------------------------------------------------------------------ */

    public function test_admin_y_gerente_pueden_anular_un_cierre(): void
    {
        foreach (['ADMIN', 'GERENTE'] as $rol) {
            // Aislamos cada rol: al anular el cierre anterior la apertura queda
            // ABIERTA, y una segunda caja abierta impediría (con razón) reabrir.
            DB::table('caja_cierre')->delete();
            DB::table('caja_apertura')->delete();

            $usuario = $this->usuario($rol);
            $aperturaId = $this->apertura('ABIERTA', now()->subHour()->format('Y-m-d H:i:s'), 1000, $usuario->id);

            $this->actingAs($usuario)
                ->postJson(route('caja.cierre.registrar'), ['tasa_cambio' => 36.5, 'conteo_local' => $this->conteoLocalCero()])
                ->assertStatus(200);

            $cierreId = (int) CajaCierre::latest('id')->first()->id;

            $this->actingAs($usuario)
                ->post(route('caja.cierre.anular', $cierreId), ['motivo' => 'Cierre registrado por error'])
                ->assertStatus(200)
                ->assertJson(['exito' => true]);

            $this->assertSame('ANULADO', CajaCierre::find($cierreId)->estado);
            // Al anular el único cierre, la apertura se reabre.
            $this->assertSame('ABIERTA', CajaApertura::find($aperturaId)->estado);
        }
    }

    public function test_cajero_y_mesero_no_pueden_anular_un_cierre(): void
    {
        $admin = $this->usuario('ADMIN');
        $this->apertura('ABIERTA', now()->subHour()->format('Y-m-d H:i:s'), 1000, $admin->id);
        $this->actingAs($admin)->postJson(route('caja.cierre.registrar'), ['tasa_cambio' => 36.5, 'conteo_local' => $this->conteoLocalCero()]);
        $cierreId = (int) CajaCierre::latest('id')->first()->id;

        foreach (['CAJERO', 'MESERO'] as $rol) {
            $this->actingAs($this->usuario($rol))
                ->post(route('caja.cierre.anular', $cierreId))
                ->assertStatus(403);
        }

        // El cierre sigue vigente (no se anuló), sin importar su estado de cuadre.
        $this->assertNotSame('ANULADO', CajaCierre::find($cierreId)->estado);
    }

    public function test_anular_un_cierre_inexistente_devuelve_404(): void
    {
        $this->actingAs($this->usuario('ADMIN'))
            ->post(route('caja.cierre.anular', 9999), ['motivo' => 'Prueba de cierre inexistente'])
            ->assertStatus(404)
            ->assertJson(['exito' => false]);
    }

    public function test_no_se_puede_anular_un_cierre_sin_motivo(): void
    {
        $admin = $this->usuario('ADMIN');
        $aperturaId = $this->apertura('ABIERTA', now()->subHour()->format('Y-m-d H:i:s'), 1000, $admin->id);

        $this->actingAs($admin)->postJson(route('caja.cierre.registrar'), [
            'tasa_cambio' => 36.5,
            'conteo_local' => $this->conteoLocalCero(),
        ])->assertStatus(200);

        $cierreId = (int) CajaCierre::latest('id')->first()->id;

        // Sin motivo (o solo espacios) el backend responde 422 y NO anula.
        foreach (['', '   '] as $motivoInvalido) {
            $this->actingAs($admin)
                ->post(route('caja.cierre.anular', $cierreId), ['motivo' => $motivoInvalido])
                ->assertStatus(422)
                ->assertJson(['exito' => false]);
        }

        $this->assertNotSame('ANULADO', CajaCierre::find($cierreId)->estado);
        $this->assertSame('CERRADA', CajaApertura::find($aperturaId)->estado);
    }

    public function test_anular_con_motivo_valido_conserva_el_motivo_en_la_respuesta(): void
    {
        $admin = $this->usuario('ADMIN');
        $this->apertura('ABIERTA', now()->subHour()->format('Y-m-d H:i:s'), 1000, $admin->id);

        $this->actingAs($admin)->postJson(route('caja.cierre.registrar'), [
            'tasa_cambio' => 36.5,
            'conteo_local' => $this->conteoLocalCero(),
        ])->assertStatus(200);

        $cierreId = (int) CajaCierre::latest('id')->first()->id;

        $this->actingAs($admin)
            ->post(route('caja.cierre.anular', $cierreId), ['motivo' => '  Turno duplicado  '])
            ->assertStatus(200)
            ->assertJson(['exito' => true]);

        $this->assertSame('ANULADO', CajaCierre::find($cierreId)->estado);
    }

    public function test_no_se_puede_anular_dos_veces_el_mismo_cierre(): void
    {
        $admin = $this->usuario('ADMIN');
        $this->apertura('ABIERTA', now()->subHour()->format('Y-m-d H:i:s'), 1000, $admin->id);
        $this->actingAs($admin)->postJson(route('caja.cierre.registrar'), ['tasa_cambio' => 36.5, 'conteo_local' => $this->conteoLocalCero()]);
        $cierreId = (int) CajaCierre::latest('id')->first()->id;

        $this->actingAs($admin)->post(route('caja.cierre.anular', $cierreId), ['motivo' => 'Primera anulación'])->assertStatus(200);

        $this->actingAs($admin)
            ->post(route('caja.cierre.anular', $cierreId), ['motivo' => 'Segundo intento'])
            ->assertStatus(409)
            ->assertJson(['exito' => false]);
    }

    /* ------------------------------------------------------------------
     | 6. Ticket
     | ------------------------------------------------------------------ */

    public function test_ticket_muestra_totales_y_estado(): void
    {
        $admin = $this->usuario('ADMIN');
        $this->apertura('ABIERTA', now()->subHour()->format('Y-m-d H:i:s'), 1000, $admin->id);
        $this->cuenta(500, 'EFECTIVO');

        // Se asigna por clave (no array_merge: reindexaría las claves numéricas).
        $conteo = $this->conteoLocalCero();
        $conteo['1000'] = 1;
        $conteo['500'] = 1; // 1500 => cuadra con el esperado.

        $this->actingAs($admin)->postJson(route('caja.cierre.registrar'), [
            'tasa_cambio' => 36.5,
            'conteo_local' => $conteo,
        ])->assertStatus(200);

        $cierreId = (int) CajaCierre::latest('id')->first()->id;

        $respuesta = $this->actingAs($admin)->get(route('caja.cierre.ticket', ['cierre_id' => $cierreId]));

        $respuesta->assertStatus(200);
        $respuesta->assertSee('CIERRE DE CAJA');
        $respuesta->assertSee('CUADRA');
        $respuesta->assertSee('C$ 1,500.00');
    }

    public function test_ticket_sin_id_devuelve_el_ultimo_cierre(): void
    {
        $admin = $this->usuario('ADMIN');
        $this->apertura('ABIERTA', now()->subHour()->format('Y-m-d H:i:s'), 1000, $admin->id);
        $this->actingAs($admin)->postJson(route('caja.cierre.registrar'), ['tasa_cambio' => 36.5, 'conteo_local' => $this->conteoLocalCero()]);

        $this->actingAs($admin)
            ->get(route('caja.cierre.ticket'))
            ->assertStatus(200)
            ->assertSee('CIERRE DE CAJA');
    }

    public function test_ticket_inexistente_devuelve_404(): void
    {
        $this->actingAs($this->usuario('ADMIN'))
            ->get(route('caja.cierre.ticket', ['cierre_id' => 9999]))
            ->assertStatus(404);
    }
}
