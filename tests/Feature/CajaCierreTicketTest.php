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
 * Ticket de Cierre de Caja — detalle de ventas y desglose de tarjetas.
 *
 * Verifica que:
 *  - Cada cuenta cobrada aparece con la MESA a la que pertenece, el importe,
 *    el tipo de cuenta (principal o subcuenta) y la forma de pago.
 *  - Los cobros con líneas de pago (mixtos) muestran sus componentes.
 *  - Débito y crédito se agrupan por separado y el total por tipo está
 *    expuesto en los datos del ticket.
 *  - El efectivo esperado NO incluye tarjetas/transferencias ni duplica
 *    importes; sí incluye la parte de efectivo de los cobros mixtos.
 *  - Anulación / cancelación y cambio sobre pago en efectivo: lo mismo que
 *    en el cobro, solo el efectivo retenido cuenta.
 *
 * IMPORTANTE: corren en SQLite :memory: (phpunit.xml); nunca tocan MySQL.
 */
class CajaCierreTicketTest extends TestCase
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
        foreach (['pagos_cuenta', 'caja_cierre', 'caja_gastos', 'caja_apertura', 'caja_bloqueo', 'venta_detalles', 'cuenta_detalles', 'cuentas', 'ventas', 'mesas', 'usuarios', 'roles'] as $tabla) {
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
            $t->timestamp('fecha')->nullable();
            $t->unsignedBigInteger('usuario_id')->nullable();
        });

        Schema::create('venta_detalles', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('venta_id');
            $t->integer('producto_id')->nullable();
            $t->integer('cantidad')->default(1);
            $t->decimal('precio_unitario', 12, 2)->default(0);
            $t->decimal('subtotal', 12, 2)->default(0);
            $t->integer('comanda_id')->nullable();
            $t->integer('cuenta_id')->nullable();
            $t->string('estado', 255)->default('ACTIVO');
            $t->timestamps();
        });

        Schema::create('cuenta_detalles', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('cuenta_id');
            $t->integer('venta_id');
            $t->integer('venta_detalle_id');
            $t->integer('cantidad')->default(1);
            $t->decimal('subtotal', 12, 2)->default(0);
            $t->boolean('eliminado')->default(false);
            $t->timestamps();
        });

        Schema::create('cuentas', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('venta_id');
            $t->integer('cuenta_origen_id')->nullable();
            $t->string('nombre', 255)->default('Cuenta Principal');
            $t->decimal('total', 12, 2)->default(0);
            $t->string('estado', 255)->default('ABIERTA');
            $t->integer('apertura_id')->nullable();
            $t->string('metodo_pago', 30)->nullable();
            $t->string('banco', 30)->nullable();
            $t->decimal('monto_recibido', 12, 2)->nullable();
            $t->decimal('cambio', 12, 2)->nullable();
            $t->unsignedBigInteger('usuario_cobro')->nullable();
            $t->timestamps();
        });

        Schema::create('pagos_cuenta', function (Blueprint $t) {
            $t->increments('id');
            $t->string('clave_operacion', 64);
            $t->integer('cuenta_id');
            $t->integer('venta_id')->nullable();
            $t->string('metodo_pago', 30);
            $t->string('banco', 30)->nullable();
            $t->string('moneda', 10)->default('NIO');
            $t->decimal('monto', 12, 2)->default(0);
            $t->decimal('tasa_cambio', 10, 4)->default(1);
            $t->decimal('monto_cordobas', 12, 2)->default(0);
            $t->integer('usuario_id')->nullable();
            $t->timestamp('creado_en')->nullable();
            $t->integer('factura_id')->nullable();
            $t->string('estado', 20)->default('ACTIVO');
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
            $t->integer('usuario_id')->nullable();
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

    private function apertura(float $montoInicial = 1000, string $estado = 'ABIERTA'): int
    {
        return DB::table('caja_apertura')->insertGetId([
            'fecha' => now()->subHour(),
            'cajero' => 'Cajero Test',
            'turno' => 'Tarde',
            'caja_id' => 1,
            'monto_inicial' => $montoInicial,
            'conteo_json' => null,
            'estado' => $estado,
            'creado_en' => now()->subHour(),
        ]);
    }

    private function mesa(string $numero): int
    {
        return DB::table('mesas')->insertGetId([
            'numero' => $numero,
            'capacidad' => 4,
            'estado' => 'OCUPADA',
        ]);
    }

    private function venta(int $mesaId): int
    {
        return DB::table('ventas')->insertGetId([
            'mesa_id' => $mesaId,
            'descripcion' => 'Mesa '.$mesaId,
            'monto' => 0,
            'estado' => 'PAGADA',
            'fecha' => now()->subMinutes(30),
        ]);
    }

    /** @return array{venta_id: int, cuenta_id: int} */
    private function crearCuentaCobro(int $mesaId, string $nombre, float $consumo, ?string $metodo, float $cambio = 0, ?string $estadoCuenta = 'PAGADA', bool $subcuenta = false, ?int $aperturaId = null): array
    {
        $ventaId = $this->venta($mesaId);

        $cuentaId = DB::table('cuentas')->insertGetId([
            'venta_id' => $ventaId,
            'cuenta_origen_id' => $subcuenta ? 999 : null,
            'nombre' => $nombre,
            'total' => $consumo,
            'estado' => $estadoCuenta,
            'metodo_pago' => $metodo,
            'monto_recibido' => $consumo + $cambio,
            'cambio' => $cambio,
            'apertura_id' => $aperturaId,
            'created_at' => now()->subMinutes(30),
            'updated_at' => now()->subMinutes(30),
        ]);

        return ['venta_id' => $ventaId, 'cuenta_id' => $cuentaId];
    }

    /** @param array<int, array{metodo: string, monto: float, banco?: ?string}> $lineas */
    private function crearPago(int $ventaId, int $cuentaId, array $lineas): void
    {
        foreach ($lineas as $linea) {
            DB::table('pagos_cuenta')->insert([
                'clave_operacion' => (string) \Illuminate\Support\Str::uuid(),
                'cuenta_id' => $cuentaId,
                'venta_id' => $ventaId,
                'metodo_pago' => $linea['metodo'],
                'banco' => $linea['banco'] ?? null,
                'moneda' => 'NIO',
                'monto' => $linea['monto'],
                'tasa_cambio' => 1,
                'monto_cordobas' => $linea['monto'],
                'estado' => 'ACTIVO',
                'creado_en' => now()->subMinutes(30),
            ]);
        }
    }

    private function gasto(int $aperturaId, float $monto, string $estado = 'ACTIVO'): void
    {
        DB::table('caja_gastos')->insert([
            'apertura_id' => $aperturaId,
            'concepto' => 'Gasto test',
            'monto' => $monto,
            'fecha' => now(),
            'estado' => $estado,
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
     | Casos requeridos por el ticket
     | ------------------------------------------------------------------ */

    public function test_mesa_y_cuenca_aparecen_en_el_ticket(): void
    {
        $admin = $this->usuario('ADMIN');
        $aperturaId = $this->apertura(1000);

        ['cuenta_id' => $cId] = $this->crearCuentaCobro($this->mesa('17'), 'Cuenta Principal', 300, 'EFECTIVO', 0, 'PAGADA', false, $aperturaId);

        $this->actingAs($admin)
            ->postJson(route('caja.cierre.registrar'), ['tasa_cambio' => 36.5, 'conteo_local' => $this->conteoLocalCero()])
            ->assertStatus(200)
            ->assertJsonPath('exito', true);

        $ticket = $this->actingAs($admin)->get(route('caja.cierre.ticket'))->assertStatus(200)->getContent();

        $this->assertStringContainsString('Mesa 17', $ticket);
        $this->assertStringContainsString('Cuenta Principal', $ticket);
        $this->assertStringContainsString('Efectivo', $ticket);
        $this->assertStringContainsString('300.00', $ticket);
    }

    public function test_varias_cuentas_de_la_misma_mesa_figura_cada_una_con_la_mesa(): void
    {
        $admin = $this->usuario('ADMIN');
        $aperturaId = $this->apertura(1000);
        $mesaId = $this->mesa('21');

        $this->crearCuentaCobro($mesaId, 'Cuenta Principal', 150, 'EFECTIVO', 0, 'PAGADA', false, $aperturaId);
        $this->crearCuentaCobro($mesaId, 'Familia Pérez', 80, 'EFECTIVO', 0, 'PAGADA', true, $aperturaId);

        $this->actingAs($admin)
            ->postJson(route('caja.cierre.registrar'), ['tasa_cambio' => 36.5, 'conteo_local' => $this->conteoLocalCero()])
            ->assertStatus(200);

        $ticket = $this->actingAs($admin)->get(route('caja.cierre.ticket'))->assertStatus(200)->getContent();

        $this->assertStringContainsString('Mesa 21', $ticket);
        $this->assertStringContainsString('Familia Pérez', $ticket);
        $this->assertStringContainsString('Subcuenta', $ticket);
        // Marca que son subcuentas: dos veces la mesa 21
        $this->assertSame(2, substr_count($ticket, 'Mesa 21'));
        // Efectivo esperado: 1000 + 150 + 80 = 1230
        $this->assertEqualsWithDelta(1230.0, (float) CajaCierre::latest('id')->first()->total_esperado, 0.001);
    }

    public function test_dos_mesas_con_consumos_figura_cada_una_con_su_mesa(): void
    {
        $admin = $this->usuario('ADMIN');
        $aperturaId = $this->apertura(1000);

        $this->crearCuentaCobro($this->mesa('5'), 'Mesa 5', 200, 'EFECTIVO', 0, 'PAGADA', false, $aperturaId);
        $this->crearCuentaCobro($this->mesa('9'), 'Mesa 9', 300, 'EFECTIVO', 0, 'PAGADA', false, $aperturaId);

        $this->actingAs($admin)
            ->postJson(route('caja.cierre.registrar'), ['tasa_cambio' => 36.5, 'conteo_local' => $this->conteoLocalCero()])
            ->assertStatus(200);

        $ticket = $this->actingAs($admin)->get(route('caja.cierre.ticket'))->assertStatus(200)->getContent();

        $this->assertStringContainsString('Mesa 5', $ticket);
        $this->assertStringContainsString('Mesa 9', $ticket);
        $this->assertStringContainsString('200.00', $ticket);
        $this->assertStringContainsString('300.00', $ticket);
    }

    public function test_mixto_efectivo_tarjeta_y_transferencia(): void
    {
        $admin = $this->usuario('ADMIN');
        $aperturaId = $this->apertura(1000);

        // Cobro mixto: 60 efectivo + 40 transferencia/legal
        ['venta_id' => $vId, 'cuenta_id' => $cId] = $this->crearCuentaCobro($this->mesa('15'), 'Mixto A', 100, 'MIXTO', 0, 'PAGADA', false, $aperturaId);
        $this->crearPago($vId, $cId, [
            ['metodo' => 'EFECTIVO_CORDOBA', 'monto' => 60],
            ['metodo' => 'TRANSFERENCIA', 'monto' => 40, 'banco' => 'Banpro'],
        ]);

        // Segundo cobro mixto: efectivo + debito BAC
        ['venta_id' => $v2, 'cuenta_id' => $c2] = $this->crearCuentaCobro($this->mesa('15'), 'Mixto B', 300, 'MIXTO', 50, 'PAGADA', false, $aperturaId);
        $this->crearPago($v2, $c2, [
            ['metodo' => 'EFECTIVO_CORDOBA', 'monto' => 120],
            ['metodo' => 'TARJETA_DEBITO', 'monto' => 180, 'banco' => 'BAC'],
        ]);

        $this->actingAs($admin)
            ->postJson(route('caja.cierre.registrar'), ['tasa_cambio' => 36.5, 'conteo_local' => $this->conteoLocalCero()])
            ->assertStatus(200);

        $resumen = app(CajaCierreService::class)->resumenVentas(CajaApertura::find($aperturaId));

        // Efectivo: 60 + (120 - 50 cambio) = 130
        $this->assertEqualsWithDelta(130.0, $resumen['efectivo'], 0.001);
        $this->assertEqualsWithDelta(180.0, $resumen['tarjeta_debito'], 0.001);
        $this->assertEqualsWithDelta(0.0, $resumen['tarjeta_credito'], 0.001);
        $this->assertEqualsWithDelta(180.0, $resumen['tarjeta'], 0.001);
        $this->assertEqualsWithDelta(40.0, $resumen['transferencia'], 0.001);

        $ticket = $this->actingAs($admin)->get(route('caja.cierre.ticket'))->assertStatus(200)->getContent();

        $this->assertStringContainsString('Efectivo C$', $ticket);
        $this->assertStringContainsString('Transferencia', $ticket);
        $this->assertStringContainsString('Débito', $ticket);
        $this->assertStringContainsString('BAC', $ticket);
        $this->assertStringContainsString('Mesa 15', $ticket);

        // El esperado = 1000 + 130 (efectivo) = 1130; tarjetas/transf. excluídas
        $this->assertEqualsWithDelta(1130.0, (float) CajaCierre::latest('id')->first()->total_esperado, 0.001);
    }

    public function test_tarjeta_debito_y_tarjeta_credito_se_agrupan_por_separado(): void
    {
        $admin = $this->usuario('ADMIN');
        $aperturaId = $this->apertura(1000);

        // Débito 200 BAC, Crédito 300 BAC, Transferencia 50, Efectivo 100
        ['venta_id' => $vId, 'cuenta_id' => $cId] = $this->crearCuentaCobro($this->mesa('33'), 'Combo', 850, 'MIXTO', 0, 'PAGADA', false, $aperturaId);
        $this->crearPago($vId, $cId, [
            ['metodo' => 'EFECTIVO_CORDOBA', 'monto' => 100],
            ['metodo' => 'TARJETA_DEBITO', 'monto' => 200, 'banco' => 'BAC'],
            ['metodo' => 'TARJETA_CREDITO', 'monto' => 300, 'banco' => 'BAC'],
            ['metodo' => 'TRANSFERENCIA', 'monto' => 50, 'banco' => 'Lafise'],
        ]);

        $this->actingAs($admin)
            ->postJson(route('caja.cierre.registrar'), ['tasa_cambio' => 36.5, 'conteo_local' => $this->conteoLocalCero()])
            ->assertStatus(200);

        $resumen = app(CajaCierreService::class)->resumenVentas(CajaApertura::find($aperturaId));

        $this->assertEqualsWithDelta(100.0, $resumen['efectivo'], 0.001);
        $this->assertEqualsWithDelta(200.0, $resumen['tarjeta_debito'], 0.001);
        $this->assertEqualsWithDelta(300.0, $resumen['tarjeta_credito'], 0.001);
        $this->assertEqualsWithDelta(500.0, $resumen['tarjeta'], 0.001);

        // Regresión: efectivo esperado excluye tarjetas/transferencias
        $this->assertEqualsWithDelta(1100.0, (float) CajaCierre::latest('id')->first()->total_esperado, 0.001);

        // Datos cortos por banco: débito y crédito no se confunden
        $this->assertEqualsWithDelta(200.0 + 300.0, $resumen['tarjetas_por_banco']['BAC'], 0.001);

        $ticket = $this->actingAs($admin)->get(route('caja.cierre.ticket'))->assertStatus(200)->getContent();
        $this->assertStringContainsString('Tarjeta Débito', $ticket);
        $this->assertStringContainsString('Tarjeta Crédito', $ticket);
        $this->assertStringContainsString('BAC', $ticket);
        // Total tarjetas visible y 500.00
        $this->assertStringContainsString('500.00', $ticket);
    }

    public function test_cierre_con_gastos_y_retiros_no_duplica_totales(): void
    {
        $admin = $this->usuario('ADMIN');
        $aperturaId = $this->apertura(1000);

        $this->crearCuentaCobro($this->mesa('41'), 'Venta caja chica', 200, 'EFECTIVO', 0, 'PAGADA', false, $aperturaId);
        $this->gasto($aperturaId, 150, 'ACTIVO');
        $this->gasto($aperturaId, 999, 'ANULADO'); // no suma

        // Esperado = 1000 + 200 - 150 = 1050.
        $this->actingAs($admin)
            ->postJson(route('caja.cierre.registrar'), [
                'tasa_cambio' => 36.5,
                'otros_ingresos' => 0,
                'retiros' => 80,
                'conteo_local' => $this->conteoLocalCero(),
            ])
            ->assertStatus(200);

        $cierre = CajaCierre::latest('id')->first();
        $this->assertEqualsWithDelta(200.0, (float) $cierre->ventas_efectivo, 0.001);
        $this->assertEqualsWithDelta(150.0, (float) $cierre->egresos, 0.001);
        $this->assertEqualsWithDelta(80.0, (float) $cierre->retiros, 0.001);
        $this->assertEqualsWithDelta(970.0, (float) $cierre->total_esperado, 0.001); // 1000 + 200 - 150 - 80
    }

    public function test_cierre_ventas_efectivo_tarjeta_transferencia_solo_suma_efectivo(): void
    {
        $admin = $this->usuario('ADMIN');
        $aperturaId = $this->apertura(1000);

        $this->crearCuentaCobro($this->mesa('51'), 'Grupo pago', 400, 'EFECTIVO', 0, 'PAGADA', false, $aperturaId);
        ['venta_id' => $v, 'cuenta_id' => $c] = $this->crearCuentaCobro($this->mesa('51'), 'Grupo tarjeta abonada', 500, 'MIXTO', 0, 'PAGADA', false, $aperturaId);
        $this->crearPago($v, $c, [
            ['metodo' => 'TARJETA_CREDITO', 'monto' => 500, 'banco' => 'BAC'],
        ]);

        $this->actingAs($admin)
            ->postJson(route('caja.cierre.registrar'), ['tasa_cambio' => 36.5, 'conteo_local' => $this->conteoLocalCero()])
            ->assertStatus(200);

        $resumen = app(CajaCierreService::class)->resumenVentas(CajaApertura::find($aperturaId));

        // Efectivo: solo la primera cuenta (400). La tarjeta va aparte.
        $this->assertEqualsWithDelta(400.0, $resumen['efectivo'], 0.001);
        $this->assertEqualsWithDelta(500.0, $resumen['tarjeta'], 0.001);
        $this->assertEqualsWithDelta(500.0, $resumen['tarjeta_credito'], 0.001);

        // Esperado = 1000 + 400 = 1400
        $this->assertEqualsWithDelta(1400.0, (float) CajaCierre::latest('id')->first()->total_esperado, 0.001);

        $ticket = $this->actingAs($admin)->get(route('caja.cierre.ticket'))->assertStatus(200)->getContent();
        $this->assertStringContainsString('Grupo pago', $ticket);
        $this->assertStringContainsString('Grupo tarjeta abonada', $ticket);
        $this->assertStringContainsString('400.00', $ticket);
        $this->assertStringContainsString('500.00', $ticket);
    }
}
