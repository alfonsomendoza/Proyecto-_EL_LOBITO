<?php

namespace Tests\Feature;

use App\Models\CajaApertura;
use App\Models\User;
use App\Services\CajaCierreService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\CreaEsquemaCompleto;
use Tests\TestCase;

/*
 * ANULACIÓN CONTROLADA DE FACTURAS
 * ================================
 *
 * Reglas que se prueban (todas se validan en el backend):
 *  - Solo ADMIN y GERENTE.
 *  - Solo el MISMO DÍA calendario de emisión (America/Managua).
 *  - Observación obligatoria (>= 5 caracteres).
 *  - La factura perteneciente a un cierre FINALIZADO se bloquea.
 *  - La factura NO se borra: queda ANULADA con número, importe, fecha,
 *    cajero y pagos originales intactos.
 *  - Los pagos originales se conservan y se marcan REVERSADOS (sin borrar
 *    filas), con trazabilidad a factura, pago, usuario y anulación.
 *  - La reversión es LÓGICA: la devolución física del dinero queda PENDIENTE;
 *    no se inventa ningún movimiento de caja ni gasto.
 *  - La cuenta original se reconstruye (ABIERTA), con productos y comandas
 *    recuperados sin duplicación, y la venta vuelve a ACTIVA.
 *  - La mesa vuelve a OCUPADA y solo se libera tras el nuevo cobro.
 *  - Se puede recobrar y emitir una nueva factura.
 *  - Se bloquea con 422 si otra venta activa ocupa la mesa o si la cuenta
 *    ya no está PAGADA.
 *
 * IMPORTANTE: SQLite :memory: (phpunit.xml); nunca toca MySQL.
 */
class AnulacionFacturaTest extends TestCase
{
    use CreaEsquemaCompleto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('sqlite', DB::connection()->getDriverName(), 'Las pruebas deben ejecutarse en SQLite.');
        $this->assertSame(':memory:', (string) config('database.connections.sqlite.database'), 'La base de pruebas debe ser SQLite en memoria.');

        $this->crearEsquemaCompleto();
        $this->crearTablaCierreCaja();
        $this->crearTablaCajaGastos();
    }

    private function crearTablaCajaGastos(): void
    {
        Schema::dropIfExists('caja_gastos');
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

    private function crearTablaCierreCaja(): void
    {
        Schema::dropIfExists('caja_cierre');

        Schema::create('caja_cierre', function (Blueprint $t) {
            $t->integer('id')->autoIncrement();
            $t->integer('apertura_id');
            $t->dateTime('fecha');
            $t->string('cajero', 100);
            $t->string('turno', 50)->nullable();
            $t->integer('caja_id');
            $t->decimal('monto_apertura', 12, 2);
            $t->decimal('ventas_efectivo', 12, 2)->default(0);
            $t->decimal('otros_ingresos', 12, 2)->default(0);
            $t->decimal('egresos', 12, 2)->default(0);
            $t->decimal('retiros', 12, 2)->default(0);
            $t->decimal('total_esperado', 12, 2);
            $t->decimal('tasa_cambio', 10, 4)->default(1);
            $t->decimal('monto_extranjero', 12, 2)->default(0);
            $t->decimal('total_contado', 12, 2);
            $t->decimal('diferencia', 12, 2);
            $t->string('estado', 20);
            $t->json('conteo_json')->nullable();
            $t->text('observaciones')->nullable();
            $t->timestamp('creado_en')->nullable();
            $t->integer('usuario_id')->nullable();
        });
    }

    private function usuario(string $rolNombre): User
    {
        $rolId = DB::table('roles')->insertGetId(['nombre' => $rolNombre]);

        return User::findOrFail(DB::table('usuarios')->insertGetId([
            'nombre' => $rolNombre.' Test',
            'username' => strtolower($rolNombre).'_'.uniqid(),
            'password_hash' => bcrypt('secret'),
            'rol_id' => $rolId,
        ]));
    }

    /**
     * Escena completa de un cobro ya terminado, con consumo, comanda y pago.
     */
    private function escena(User $cajero, array $opciones = []): array
    {
        $fechaEmision = $opciones['fecha_emision'] ?? now();
        $estadoFactura = $opciones['estado_factura'] ?? 'EMITIDA';
        $estadoVenta = $opciones['estado_venta'] ?? 'PAGADA';
        $estadoMesa = $opciones['estado_mesa'] ?? 'LIBRE';
        $total = $opciones['total'] ?? 500.0;
        $aperturaId = array_key_exists('apertura_id', $opciones) ? $opciones['apertura_id'] : 1;
        $lineasPago = $opciones['lineas_pago'] ?? [['metodo_pago' => 'EFECTIVO_CORDOBA', 'monto' => $total]];

        $mesaId = DB::table('mesas')->insertGetId([
            'numero' => 'Mesa '.Str::random(3),
            'capacidad' => 4,
            'estado' => $estadoMesa,
        ]);

        $ventaId = DB::table('ventas')->insertGetId([
            'mesa_id' => $mesaId,
            'apertura_id' => $aperturaId,
            'descripcion' => 'Venta de prueba',
            'monto' => $total,
            'metodo_pago' => 'EFECTIVO_CORDOBA',
            'monto_recibido' => $total,
            'cambio' => 0,
            'estado' => $estadoVenta,
            'usuario_id' => $cajero->id,
            'fecha' => $fechaEmision,
        ]);

        $productoId = DB::table('productos')->insertGetId([
            'nombre' => 'Plato de prueba',
            'precio' => $total,
            'requiere_cocina' => 0,
            'controla_stock' => 0,
            'activo' => 1,
        ]);

        $detalleId = DB::table('venta_detalles')->insertGetId([
            'venta_id' => $ventaId,
            'producto_id' => $productoId,
            'cantidad' => 1,
            'precio_unitario' => $total,
            'subtotal' => $total,
            'estado' => 'ACTIVO',
            'comanda_id' => null,
        ]);

        $comandaId = DB::table('comandas')->insertGetId([
            'mesa_id' => $mesaId,
            'venta_id' => $ventaId,
            'numero_ticket' => 1,
            'usuario_id' => $cajero->id,
            'estado' => 'ABIERTA',
            'total' => $total,
            'creado_en' => $fechaEmision,
        ]);

        $cuentaId = DB::table('cuentas')->insertGetId([
            'venta_id' => $ventaId,
            'apertura_id' => $aperturaId,
            'nombre' => 'Cuenta principal',
            'total' => $total,
            'estado' => 'PAGADA',
            'metodo_pago' => 'EFECTIVO_CORDOBA',
            'monto_recibido' => $total,
            'cambio' => 0,
            'usuario_cobro' => $cajero->id,
            'created_at' => $fechaEmision,
            'updated_at' => $fechaEmision,
        ]);

        DB::table('cuenta_detalles')->insert([
            'cuenta_id' => $cuentaId,
            'venta_id' => $ventaId,
            'venta_detalle_id' => $detalleId,
            'cantidad' => 1,
            'subtotal' => $total,
            'eliminado' => false,
            'created_at' => $fechaEmision,
            'updated_at' => $fechaEmision,
        ]);

        $pagoIds = [];

        foreach ($lineasPago as $linea) {
            $monto = (float) ($linea['monto'] ?? $total);
            $pagoIds[] = DB::table('pagos_cuenta')->insertGetId([
                'clave_operacion' => $linea['clave_operacion'] ?? (string) Str::uuid(),
                'cuenta_id' => $cuentaId,
                'venta_id' => $ventaId,
                'metodo_pago' => $linea['metodo_pago'],
                'banco' => $linea['banco'] ?? null,
                'moneda' => $linea['moneda'] ?? 'NIO',
                'monto' => $monto,
                'tasa_cambio' => $linea['tasa_cambio'] ?? null,
                'monto_cordobas' => $monto,
                'usuario_id' => $cajero->id,
                'creado_en' => $fechaEmision,
                'estado' => 'ACTIVO',
            ]);
        }

        $facturaId = DB::table('facturas')->insertGetId([
            'numero_factura' => 'FAC-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
            'venta_id' => $ventaId,
            'cuenta_id' => $cuentaId,
            'mesa' => 'Mesa de prueba',
            'total' => $total,
            'fecha_emision' => $fechaEmision,
            'usuario_id' => $cajero->id,
            'estado' => $estadoFactura,
        ]);

        if (Schema::hasColumn('pagos_cuenta', 'factura_id')) {
            DB::table('pagos_cuenta')->whereIn('id', $pagoIds)->update(['factura_id' => $facturaId]);
        }

        return [
            'mesa' => $mesaId,
            'venta' => $ventaId,
            'cuenta' => $cuentaId,
            'producto' => $productoId,
            'detalle' => $detalleId,
            'comanda' => $comandaId,
            'pago' => $pagoIds[0],
            'pagos' => $pagoIds,
            'factura' => $facturaId,
        ];
    }

    private function anular(User $usuario, int $facturaId, array $datos = [])
    {
        return $this->actingAs($usuario)->postJson(
            route('facturas.anular', ['factura' => $facturaId]),
            array_merge(['motivo' => 'Error de cobro en el método de pago.'], $datos)
        );
    }

    /* ------------------------------------------------------------------
     | Permisos
     * ------------------------------------------------------------------ */
    public function test_solo_admin_y_gerente_pueden_anular(): void
    {
        $admin = $this->usuario('ADMIN');
        $gerente = $this->usuario('GERENTE');
        $cajero = $this->usuario('CAJERO');
        $mesero = $this->usuario('MESERO');

        $escenaAdmin = $this->escena($admin);
        $escenaGerente = $this->escena($gerente);

        $this->anular($cajero, $escenaAdmin['factura'])
            ->assertStatus(403)
            ->assertJsonPath('exito', false);

        $this->anular($mesero, $escenaAdmin['factura'])
            ->assertStatus(403)
            ->assertJsonPath('exito', false);

        $this->assertSame('EMITIDA', DB::table('facturas')->find($escenaAdmin['factura'])->estado);

        $this->anular($admin, $escenaAdmin['factura'])
            ->assertStatus(200)
            ->assertJsonPath('exito', true);

        $this->anular($gerente, $escenaGerente['factura'])
            ->assertStatus(200)
            ->assertJsonPath('exito', true);
    }

    /* ------------------------------------------------------------------
     | Ventana temporal y observación
     * ------------------------------------------------------------------ */
    public function test_anula_una_factura_emitida_el_mismo_dia(): void
    {
        $admin = $this->usuario('ADMIN');
        $escena = $this->escena($admin, ['fecha_emision' => now()->setTime(8, 30)]);

        $this->anular($admin, $escena['factura'])
            ->assertStatus(200)
            ->assertJsonPath('exito', true)
            ->assertJsonPath('factura_id', $escena['factura']);

        $this->assertSame('ANULADA', DB::table('facturas')->find($escena['factura'])->estado);
    }

    public function test_no_anula_una_factura_emitida_otro_dia(): void
    {
        $admin = $this->usuario('ADMIN');
        $escena = $this->escena($admin, ['fecha_emision' => now()->subDay()->setTime(12, 0)]);

        $respuesta = $this->anular($admin, $escena['factura'])
            ->assertStatus(422)
            ->assertJsonPath('exito', false);

        $this->assertStringContainsString('mismo día', $respuesta->json('error'));
        $this->assertSame('EMITIDA', DB::table('facturas')->find($escena['factura'])->estado);
        $this->assertSame('PAGADA', DB::table('cuentas')->find($escena['cuenta'])->estado);
    }

    public function test_exige_observacion_obligatoria(): void
    {
        $admin = $this->usuario('ADMIN');
        $escena = $this->escena($admin);

        $this->actingAs($admin)
            ->postJson(route('facturas.anular', ['factura' => $escena['factura']]), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('motivo');

        $this->anular($admin, $escena['factura'], ['motivo' => 'x'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('motivo');

        $this->assertSame('EMITIDA', DB::table('facturas')->find($escena['factura'])->estado);
        $this->assertSame(0, DB::table('anulaciones_caja_auditoria')->count());
    }

    /* ------------------------------------------------------------------
     | Estados y validaciones
     * ------------------------------------------------------------------ */
    public function test_no_anula_si_la_venta_no_permite_reconstruccion(): void
    {
        $admin = $this->usuario('ADMIN');
        $escena = $this->escena($admin, ['estado_venta' => 'CANCELADA', 'estado_mesa' => 'LIBRE']);

        $respuesta = $this->anular($admin, $escena['factura'])
            ->assertStatus(422)
            ->assertJsonPath('exito', false);

        $this->assertSame('EMITIDA', DB::table('facturas')->find($escena['factura'])->estado);
    }

    public function test_no_anula_si_la_cuenta_ya_no_esta_pagada(): void
    {
        $admin = $this->usuario('ADMIN');
        $escena = $this->escena($admin);

        DB::table('cuentas')->where('id', $escena['cuenta'])->update(['estado' => 'ABIERTA']);

        $respuesta = $this->anular($admin, $escena['factura'])
            ->assertStatus(422)
            ->assertJsonPath('exito', false);

        $this->assertStringContainsString('no está PAGADA', $respuesta->json('error'));
        $this->assertSame('EMITIDA', DB::table('facturas')->find($escena['factura'])->estado);
    }

    public function test_no_anula_si_la_factura_no_tiene_cuenta(): void
    {
        $admin = $this->usuario('ADMIN');
        $escena = $this->escena($admin);

        DB::table('facturas')->where('id', $escena['factura'])->update(['cuenta_id' => null]);

        $respuesta = $this->anular($admin, $escena['factura'])
            ->assertStatus(422)
            ->assertJsonPath('exito', false);

        $this->assertStringContainsString('cuenta asociada', $respuesta->json('error'));
        $this->assertSame('EMITIDA', DB::table('facturas')->find($escena['factura'])->estado);
    }

    public function test_no_anula_si_otra_venta_activa_ocupa_la_mesa(): void
    {
        $admin = $this->usuario('ADMIN');
        $escena = $this->escena($admin, ['estado_mesa' => 'OCUPADA']);

        DB::table('ventas')->insert([
            'mesa_id' => $escena['mesa'],
            'apertura_id' => 1,
            'descripcion' => 'Otra venta activa',
            'monto' => 0,
            'estado' => 'ACTIVA',
            'usuario_id' => $admin->id,
            'fecha' => now(),
        ]);

        $respuesta = $this->anular($admin, $escena['factura'])
            ->assertStatus(422)
            ->assertJsonPath('exito', false);

        $this->assertStringContainsString('ocupada por otra venta activa', $respuesta->json('error'));
        $this->assertSame('EMITIDA', DB::table('facturas')->find($escena['factura'])->estado);
        $this->assertSame('OCUPADA', DB::table('mesas')->find($escena['mesa'])->estado);
    }

    public function test_no_anula_dos_veces_la_misma_factura(): void
    {
        $admin = $this->usuario('ADMIN');
        $escena = $this->escena($admin);

        $this->anular($admin, $escena['factura'])->assertStatus(200);

        $respuesta = $this->anular($admin, $escena['factura'])
            ->assertStatus(422)
            ->assertJsonPath('exito', false);

        $this->assertStringContainsString('ya está anulada', $respuesta->json('error'));
        $this->assertSame('ANULADA', DB::table('facturas')->find($escena['factura'])->estado);
        $this->assertSame(1, DB::table('anulaciones_caja_auditoria')->count());
    }

    /* ------------------------------------------------------------------
     | Cierres de caja
     * ------------------------------------------------------------------ */
    public function test_bloquea_la_anulacion_si_el_cierre_de_caja_ya_finalizo(): void
    {
        $admin = $this->usuario('ADMIN');
        $escena = $this->escena($admin, ['total' => 500]);

        $cierreId = DB::table('caja_cierre')->insertGetId([
            'apertura_id' => 1,
            'fecha' => now(),
            'cajero' => 'ADMIN Test',
            'turno' => '1',
            'caja_id' => 1,
            'monto_apertura' => 0,
            'total_esperado' => 500,
            'total_contado' => 500,
            'diferencia' => 0,
            'estado' => 'CERRADO',
            'creado_en' => now(),
            'usuario_id' => $admin->id,
        ]);

        $cierreAntes = DB::table('caja_cierre')->find($cierreId);

        $respuesta = $this->anular($admin, $escena['factura'])
            ->assertStatus(422)
            ->assertJsonPath('exito', false);

        $this->assertStringContainsString('cierre de caja', $respuesta->json('error'));

        $cierreDespues = DB::table('caja_cierre')->find($cierreId);
        $this->assertSame($cierreAntes->estado, $cierreDespues->estado);
        $this->assertEquals((float) $cierreAntes->total_contado, (float) $cierreDespues->total_contado);

        $this->assertSame('EMITIDA', DB::table('facturas')->find($escena['factura'])->estado);
        $this->assertSame('PAGADA', DB::table('cuentas')->find($escena['cuenta'])->estado);
        $this->assertSame(0, DB::table('anulaciones_caja_auditoria')->count());
    }

    public function test_permite_anular_si_el_cierre_esta_anulado(): void
    {
        $admin = $this->usuario('ADMIN');
        $escena = $this->escena($admin);

        DB::table('caja_cierre')->insert([
            'apertura_id' => 1,
            'fecha' => now(),
            'cajero' => 'ADMIN Test',
            'turno' => '1',
            'caja_id' => 1,
            'monto_apertura' => 0,
            'total_esperado' => 500,
            'total_contado' => 500,
            'diferencia' => 0,
            'estado' => 'ANULADO',
            'creado_en' => now(),
            'usuario_id' => $admin->id,
        ]);

        $this->anular($admin, $escena['factura'])
            ->assertStatus(200)
            ->assertJsonPath('exito', true);

        $this->assertSame('ANULADA', DB::table('facturas')->find($escena['factura'])->estado);
    }

    public function test_no_bloquea_por_un_cierre_antiguo_sin_vinculo_temporal(): void
    {
        $admin = $this->usuario('ADMIN');

        // Cierre finalizado para una apertura ANTIGUA (turno ya cerrado).
        DB::table('caja_apertura')->insert([
            'id' => 2,
            'fecha' => now()->subDays(2),
            'cajero' => 'Cajero Antiguo',
            'turno' => '1',
            'caja_id' => 1,
            'monto_inicial' => 0,
            'estado' => 'CERRADA',
            'usuario_id' => $admin->id,
        ]);

        DB::table('caja_cierre')->insert([
            'apertura_id' => 2,
            'fecha' => now()->subDays(2),
            'cajero' => 'Cajero Antiguo',
            'turno' => '1',
            'caja_id' => 1,
            'monto_apertura' => 0,
            'total_esperado' => 500,
            'total_contado' => 500,
            'diferencia' => 0,
            'estado' => 'CUADRA',
            'creado_en' => now()->subDays(2),
            'usuario_id' => $admin->id,
        ]);

        // La factura actual se emite HOY, sin apertura_id directo. Antes del
        // fix, el fallback temporal sin límite superior la absorbía en el
        // turno #1 (o #2) y la bloqueaba. Ahora no hay vínculo fiable.
        $escena = $this->escena($admin, ['apertura_id' => null]);

        // Forzar la cuenta/venta sin apertura y su updated_at en el presente.
        DB::table('cuentas')->where('id', $escena['cuenta'])->update([
            'apertura_id' => null,
            'updated_at' => now(),
        ]);
        DB::table('ventas')->where('id', $escena['venta'])->update([
            'apertura_id' => null,
        ]);

        $this->anular($admin, $escena['factura'])
            ->assertStatus(200)
            ->assertJsonPath('exito', true);

        $this->assertSame('ANULADA', DB::table('facturas')->find($escena['factura'])->estado);
    }

    /* ------------------------------------------------------------------
     | Conservación y reversión
     * ------------------------------------------------------------------ */
    public function test_conserva_numero_importe_fecha_cajero_detalle_y_pagos(): void
    {
        $admin = $this->usuario('ADMIN');
        $escena = $this->escena($admin, ['total' => 875.50]);

        $facturaAntes = DB::table('facturas')->find($escena['factura']);
        $pagoAntes = DB::table('pagos_cuenta')->find($escena['pago']);

        $this->anular($admin, $escena['factura'], ['motivo' => 'Se cobró el método equivocado.'])
            ->assertStatus(200)
            ->assertJsonPath('exito', true);

        $facturaDespues = DB::table('facturas')->find($escena['factura']);

        $this->assertSame('ANULADA', $facturaDespues->estado);
        $this->assertSame($facturaAntes->numero_factura, $facturaDespues->numero_factura);
        $this->assertEquals((float) $facturaAntes->total, (float) $facturaDespues->total);
        $this->assertSame((string) $facturaAntes->fecha_emision, (string) $facturaDespues->fecha_emision);
        $this->assertSame((int) $facturaAntes->usuario_id, (int) $facturaDespues->usuario_id);

        $this->assertSame(1, DB::table('pagos_cuenta')->count());
        $pagoDespues = DB::table('pagos_cuenta')->find($escena['pago']);
        $this->assertSame($pagoAntes->clave_operacion, $pagoDespues->clave_operacion);
        $this->assertEquals((float) $pagoAntes->monto_cordobas, (float) $pagoDespues->monto_cordobas);
        $this->assertSame('REVERSADO', $pagoDespues->estado);
        $this->assertSame('PENDIENTE', $pagoDespues->devolucion_estado);

        // La cuenta se reconstruye y la venta vuelve a operar.
        $this->assertSame('ABIERTA', DB::table('cuentas')->find($escena['cuenta'])->estado);
        $this->assertSame('ACTIVA', DB::table('ventas')->find($escena['venta'])->estado);
        $this->assertSame('OCUPADA', DB::table('mesas')->find($escena['mesa'])->estado);
    }

    public function test_revierte_todos_los_metodos_de_pago_sin_borrarlos(): void
    {
        $admin = $this->usuario('ADMIN');
        $escena = $this->escena($admin, [
            'total' => 1000.0,
            'lineas_pago' => [
                ['metodo_pago' => 'EFECTIVO_CORDOBA', 'monto' => 400.0],
                ['metodo_pago' => 'TARJETA_CREDITO', 'monto' => 350.0, 'banco' => 'BAC'],
                ['metodo_pago' => 'TRANSFERENCIA', 'monto' => 250.0, 'banco' => 'Banpro'],
            ],
        ]);

        $this->assertCount(3, $escena['pagos']);

        $this->anular($admin, $escena['factura'])
            ->assertStatus(200)
            ->assertJsonPath('pagos_reversados', 3);

        $this->assertSame(3, DB::table('pagos_cuenta')->count());

        foreach (DB::table('pagos_cuenta')->get() as $pago) {
            $this->assertSame('REVERSADO', $pago->estado);
            $this->assertSame('PENDIENTE', $pago->devolucion_estado);
            $this->assertNotNull($pago->reversado_en);
            $this->assertSame($admin->id, (int) $pago->reversado_por);
            $this->assertNotNull($pago->anulacion_id);
        }

        // Ningún gasto ficticio ni movimiento de caja se inventó.
        $this->assertSame(0, DB::table('caja_gastos')->count());
    }

    public function test_registra_auditoria_con_usuario_rol_motivo_y_pagos(): void
    {
        $gerente = $this->usuario('GERENTE');
        $escena = $this->escena($gerente, ['total' => 640]);

        $this->anular($gerente, $escena['factura'], ['motivo' => 'Cobro duplicado del cliente.'])
            ->assertStatus(200);

        $auditoria = DB::table('anulaciones_caja_auditoria')
            ->where('tipo', 'FACTURA_ANULADA')
            ->where('registro_original_id', $escena['factura'])
            ->sole();

        $this->assertSame($gerente->id, (int) $auditoria->usuario_id);
        $this->assertSame('GERENTE', $auditoria->rol);
        $this->assertSame('Cobro duplicado del cliente.', $auditoria->motivo);
        $this->assertSame('EMITIDA', $auditoria->estado_anterior);
        $this->assertSame('ANULADA', $auditoria->estado_resultante);
        $this->assertSame(1, (int) $auditoria->apertura_id);
        $this->assertNotNull($auditoria->fecha_anulacion);

        $referencia = json_decode((string) $auditoria->referencia_json, true);
        $this->assertSame($escena['factura'], $referencia['factura_id']);
        $this->assertSame($escena['venta'], $referencia['venta_id']);
        $this->assertSame($escena['cuenta'], $referencia['cuenta_id']);
        $this->assertCount(1, $referencia['pagos']);
        $this->assertEquals(640.0, (float) $referencia['pagos'][0]['monto_cordobas']);
        $this->assertContains($escena['pago'], $referencia['pagos_reversados']);
        $this->assertSame('PENDIENTE', $referencia['reversion_fisica']);
    }

    public function test_recupera_productos_y_comandas_sin_duplicarlos(): void
    {
        $admin = $this->usuario('ADMIN');
        $escena = $this->escena($admin);

        $productosAntes = DB::table('venta_detalles')->where('venta_id', $escena['venta'])->count();
        $comandasAntes = DB::table('comandas')->where('venta_id', $escena['venta'])->count();
        $asignacionesAntes = DB::table('cuenta_detalles')->where('cuenta_id', $escena['cuenta'])->count();

        $this->anular($admin, $escena['factura'])->assertStatus(200);

        $this->assertSame($productosAntes, DB::table('venta_detalles')->where('venta_id', $escena['venta'])->count());
        $this->assertSame($comandasAntes, DB::table('comandas')->where('venta_id', $escena['venta'])->count());
        $this->assertSame($asignacionesAntes, DB::table('cuenta_detalles')->where('cuenta_id', $escena['cuenta'])->count());

        // La cuenta sigue con su consumo; se puede agregar más.
        $this->assertSame('ABIERTA', DB::table('cuentas')->find($escena['cuenta'])->estado);
    }

    /* ------------------------------------------------------------------
     | Flujo completo: anular -> recobrar -> nueva factura
     * ------------------------------------------------------------------ */
    public function test_flujo_completo_anulacion_reversion_recobro_nueva_factura(): void
    {
        $admin = $this->usuario('ADMIN');
        $cajero = $this->usuario('CAJERO');
        $escena = $this->escena($admin);

        // Anular como ADMIN.
        $this->anular($admin, $escena['factura'])
            ->assertStatus(200)
            ->assertJsonPath('exito', true);

        $this->assertSame('ANULADA', DB::table('facturas')->find($escena['factura'])->estado);
        $this->assertSame('ABIERTA', DB::table('cuentas')->find($escena['cuenta'])->estado);
        $this->assertSame('ACTIVA', DB::table('ventas')->find($escena['venta'])->estado);
        $this->assertSame('OCUPADA', DB::table('mesas')->find($escena['mesa'])->estado);

        $pagoOriginal = DB::table('pagos_cuenta')->find($escena['pago']);
        $this->assertSame('REVERSADO', $pagoOriginal->estado);

        // Recobrar como CAJERO (nueva línea de pago).
        $respuesta = $this->actingAs($cajero)->postJson(
            route('pos.cuentas.pagar', $escena['cuenta']),
            [
                'pagos' => [[
                    'clave_operacion' => (string) Str::uuid(),
                    'metodo_pago' => 'TARJETA_DEBITO',
                    'monto' => 500,
                    'banco' => 'Banpro',
                ]],
            ]
        );

        $respuesta->assertStatus(200)->assertJsonPath('exito', true);

        $this->assertSame('PAGADA', DB::table('cuentas')->find($escena['cuenta'])->estado);
        $this->assertSame('PAGADA', DB::table('ventas')->find($escena['venta'])->estado);
        $this->assertSame('LIBRE', DB::table('mesas')->find($escena['mesa'])->estado);

        $facturas = DB::table('facturas')->where('cuenta_id', $escena['cuenta'])->orderBy('id')->get();
        $this->assertCount(2, $facturas);
        $this->assertSame('ANULADA', $facturas[0]->estado);
        $this->assertSame('EMITIDA', $facturas[1]->estado);

        $nuevosPagos = DB::table('pagos_cuenta')
            ->where('cuenta_id', $escena['cuenta'])
            ->where('estado', 'ACTIVO')
            ->get();
        $this->assertCount(1, $nuevosPagos);
        $this->assertSame('TARJETA_DEBITO', $nuevosPagos->first()->metodo_pago);
        $this->assertSame((int) $facturas[1]->id, (int) $nuevosPagos->first()->factura_id);

        // El pago original sigue revertido.
        $this->assertSame('REVERSADO', DB::table('pagos_cuenta')->find($escena['pago'])->estado);

        // El cierre del turno cuenta el cobro UNA sola vez.
        $apertura = CajaApertura::find(1);
        $servicio = app(CajaCierreService::class);
        $resumen = $servicio->resumenVentas($apertura);
        $this->assertEquals(500.0, (float) $resumen['total']);
        $this->assertSame(1, $resumen['cantidad']);
    }
}
