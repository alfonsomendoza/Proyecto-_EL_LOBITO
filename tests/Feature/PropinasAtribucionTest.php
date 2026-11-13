<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Tests\Feature\Concerns\CreaEsquemaCompleto;
use Tests\TestCase;

/*
 * ATRIBUCIÓN DE PROPINAS DESPUÉS DEL CIERRE DE MESA / FACTURA EMITIDA
 * ==================================================================
 *
 * Verifica el flujo de atribución de propinas cobradas:
 *
 *  - Una propina puede quedar PENDIENTE aunque la mesa esté cerrada y la
 *    factura emitida; se atribuye manualmente al MESERO que atendió.
 *  - Antes del cierre de la caja: ADMIN, GERENTE y CAJERO pueden atribuir.
 *  - Después del cierre de la apertura: SOLO ADMIN/GERENTE, con motivo y
 *    auditoría distinguida.
 *  - Motivo OBLIGATORIO siempre; auditoría SIEMPRE (usuario, fecha,
 *    atribución anterior y nueva, motivo).
 *  - Factura, consumo, pagos e importe de propina NUNCA cambian.
 *  - La atribución mueve el importe de «pendiente» al mesero (lo leen el
 *    reporte individual y Operaciones desde el mismo origen).
 *  - Las aperturas no se mezclan: cada propina va con la apertura real de su
 *    cuenta (`cuentas.apertura_id`).
 *
 * Corren en SQLite :memory: (phpunit.xml); nunca tocan MySQL ni "sistemadecaja".
 */
class PropinasAtribucionTest extends TestCase
{
    use CreaEsquemaCompleto;

    protected function setUp(): void
    {
        parent::setUp();

        // Candado de seguridad: nunca apuntar a la base MySQL real.
        $this->assertSame('sqlite', DB::connection()->getDriverName(), 'Las pruebas deben ejecutarse en SQLite.');
        $this->assertSame(':memory:', (string) config('database.connections.sqlite.database'), 'La base de pruebas debe ser SQLite en memoria.');

        $this->crearEsquemaCompleto();

        // Auditoría de seguridad y cierres de caja: no forman parte del
        // esquema base de los tests; aquí se necesitan.
        Schema::dropIfExists('auditoria_seguridad');
        Schema::create('auditoria_seguridad', function (Blueprint $t) {
            $t->bigInteger('id')->autoIncrement();
            $t->integer('usuario_id')->nullable();
            $t->string('evento', 100);
            $t->string('ip', 45)->nullable();
            $t->text('user_agent')->nullable();
            $t->text('motivo')->nullable();
            $t->text('detalles')->nullable();
            $t->timestamp('creado_en')->nullable();
        });

        Schema::dropIfExists('caja_cierre');
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
    }

    /* ------------------------------------------------------------------
     | Utilidades
     | ------------------------------------------------------------------ */

    private function usuario(string $rolNombre): User
    {
        $rolId = DB::table('roles')->insertGetId(['nombre' => $rolNombre]);

        return User::findOrFail(DB::table('usuarios')->insertGetId([
            'nombre' => $rolNombre.' Test',
            'username' => strtolower($rolNombre).'_'.uniqid(),
            'password_hash' => bcrypt('secret'),
            'rol_id' => $rolId,
            'activo' => 1,
        ]));
    }

    /**
     * Crea una mesa + venta PAGADA + cuenta PAGADA con propina (pendiente o
     * con responsable) + factura EMITIDA.
     *
     * @param  array<int, array{propina: float, fecha: string, apertura_id: int, mesero_responsable_id?: ?int, mesa_estado?: string, meseros_en_comandas?: array<int>, nombre?: string}>  $cuentas
     */
    private function montarCuentasCobradas(array $cuentas, int $usuarioId): array
    {
        $ids = [];

        foreach ($cuentas as $indice => $datos) {
            $mesaId = DB::table('mesas')->insertGetId([
                'numero' => 'Mesa Propina '.uniqid(),
                'capacidad' => 4,
                'estado' => $datos['mesa_estado'] ?? 'LIBRE',
            ]);

            $ventaId = DB::table('ventas')->insertGetId([
                'mesa_id' => $mesaId,
                'descripcion' => 'Venta propina',
                'monto' => 100,
                'estado' => 'PAGADA',
                'usuario_id' => $usuarioId,
                'apertura_id' => $datos['apertura_id'],
                'fecha' => $datos['fecha'],
            ]);

            $productoId = DB::table('productos')->insertGetId([
                'nombre' => 'Producto propina '.uniqid(),
                'precio' => 100,
                'requiere_cocina' => 0,
                'controla_stock' => 0,
                'activo' => 1,
            ]);

            $detalleId = DB::table('venta_detalles')->insertGetId([
                'venta_id' => $ventaId,
                'producto_id' => $productoId,
                'cantidad' => 1,
                'precio_unitario' => 100,
                'subtotal' => 100,
                'estado' => 'ACTIVO',
            ]);

            foreach ($datos['meseros_en_comandas'] ?? [] as $meseroComandaId) {
                $comandaId = DB::table('comandas')->insertGetId([
                    'venta_id' => $ventaId,
                    'mesa_id' => $mesaId,
                    'numero_ticket' => ($indice + 1),
                    'usuario_id' => $meseroComandaId,
                    'estado' => 'PAGADA',
                    'total' => 100,
                    'creado_en' => $datos['fecha'],
                ]);

                DB::table('comanda_detalles')->insert([
                    'comanda_id' => $comandaId,
                    'producto_id' => $productoId,
                    'cantidad' => 1,
                    'precio_unitario' => 100,
                    'subtotal' => 100,
                    'estado' => 'ACTIVO',
                ]);
            }

            $cuentaId = DB::table('cuentas')->insertGetId([
                'venta_id' => $ventaId,
                'nombre' => $datos['nombre'] ?? ('Cuenta propina '.$indice),
                'total' => 100,
                'estado' => 'PAGADA',
                'usuario_cobro' => $usuarioId,
                'apertura_id' => $datos['apertura_id'],
                'descuento' => 0,
                'propina' => $datos['propina'],
                'mesero_responsable_id' => $datos['mesero_responsable_id'] ?? null,
                'created_at' => $datos['fecha'],
                'updated_at' => $datos['fecha'],
            ]);

            DB::table('cuenta_detalles')->insert([
                'cuenta_id' => $cuentaId,
                'venta_id' => $ventaId,
                'venta_detalle_id' => $detalleId,
                'cantidad' => 1,
                'subtotal' => 100,
                'eliminado' => false,
            ]);

            DB::table('facturas')->insert([
                'numero_factura' => 'FAC-PROP-'.str_pad((string) ($indice + 1), 4, '0', STR_PAD_LEFT),
                'venta_id' => $ventaId,
                'cuenta_id' => $cuentaId,
                'mesa' => 'Mesa',
                'detalle_consumo' => '{}',
                'total' => 100,
                'fecha_emision' => $datos['fecha'],
                'usuario_id' => $usuarioId,
                'estado' => 'EMITIDA',
            ]);

            $ids[] = $cuentaId;
        }

        return $ids;
    }

    private function atribuir(User $usuario, int $cuentaId, array $payload)
    {
        return $this->actingAs($usuario)->postJson(route('cuentas.propina.atribuir', $cuentaId), $payload);
    }

    /* ------------------------------------------------------------------
     | Antes del cierre: ADMIN, GERENTE y CAJERO pueden atribuir
     | ------------------------------------------------------------------ */

    public function test_la_cuenta_cobrada_con_factura_emitida_se_atribuye_antes_del_cierre(): void
    {
        $cajero = $this->usuario('CAJERO');
        $mesero = $this->usuario('MESERO');
        [$cuentaId] = $this->montarCuentasCobradas([
            ['propina' => 81.00, 'fecha' => '2026-10-19 16:34:00', 'apertura_id' => 1],
        ], $cajero->id);

        $respuesta = $this->atribuir($cajero, $cuentaId, [
            'mesero_id' => $mesero->id,
            'motivo' => 'Atendió la mesa durante todo el turno',
        ]);

        $respuesta->assertStatus(200)->assertJsonPath('exito', true);
        $this->assertSame((int) $mesero->id, (int) DB::table('cuentas')->find($cuentaId)->mesero_responsable_id);
        $this->assertSame(1, DB::table('auditoria_seguridad')->where('evento', 'propina_atribuida')->count());
    }

    public function test_mesero_y_cocina_no_pueden_atribuir(): void
    {
        $meseroSin = $this->usuario('MESERO');
        $mesero = $this->usuario('MESERO');
        [$cuentaId] = $this->montarCuentasCobradas([
            ['propina' => 10.00, 'fecha' => '2026-10-19 16:34:00', 'apertura_id' => 1],
        ], $meseroSin->id);

        $this->atribuir($meseroSin, $cuentaId, [
            'mesero_id' => $mesero->id,
            'motivo' => 'Intento desde MESERO',
        ])->assertStatus(403);

        $this->assertNull(DB::table('cuentas')->find($cuentaId)->mesero_responsable_id);
    }

    /* ------------------------------------------------------------------
     | Después del cierre: solo ADMIN/GERENTE, con motivo y auditoría
     | ------------------------------------------------------------------ */

    public function test_post_cierre_cajero_no_puede_corregir(): void
    {
        $cajero = $this->usuario('CAJERO');
        $admin = $this->usuario('ADMIN');
        $mesero = $this->usuario('MESERO');
        [$cuentaId] = $this->montarCuentasCobradas([
            ['propina' => 50.00, 'fecha' => '2026-10-19 10:00:00', 'apertura_id' => 2],
        ], $cajero->id);

        // La apertura 2 ya tiene cierre registrado.
        DB::table('caja_apertura')->insert([
            'id' => 2, 'fecha' => now(), 'cajero' => 'TEST', 'turno' => '2', 'caja_id' => 1,
            'monto_inicial' => 0, 'estado' => 'CERRADA', 'usuario_id' => $admin->id,
        ]);
        DB::table('caja_cierre')->insert([
            'apertura_id' => 2, 'fecha' => now(), 'estado' => 'CUADRA', 'usuario_id' => $admin->id,
        ]);

        $respuesta = $this->atribuir($cajero, $cuentaId, [
            'mesero_id' => $mesero->id,
            'motivo' => 'Corrección después del cierre',
        ]);

        $respuesta->assertStatus(403)->assertJsonPath('exito', false);
        $this->assertNull(DB::table('cuentas')->find($cuentaId)->mesero_responsable_id);
        $this->assertSame(0, DB::table('auditoria_seguridad')->count());
    }

    public function test_post_cierre_admin_o_gerente_corrigen_con_auditoria(): void
    {
        $gerente = $this->usuario('GERENTE');
        $admin = $this->usuario('ADMIN');
        $mesero = $this->usuario('MESERO');
        $otroMesero = $this->usuario('MESERO');

        [$cuentaAdmin, $cuentaGerente] = $this->montarCuentasCobradas([
            ['propina' => 20.00, 'fecha' => '2026-10-19 10:00:00', 'apertura_id' => 2,
             'mesero_responsable_id' => $otroMesero->id],
            ['propina' => 30.00, 'fecha' => '2026-10-19 11:00:00', 'apertura_id' => 2,
             'mesero_responsable_id' => null],
        ], $admin->id);

        DB::table('caja_apertura')->insert([
            'id' => 2, 'fecha' => now(), 'cajero' => 'TEST', 'turno' => '2', 'caja_id' => 1,
            'monto_inicial' => 0, 'estado' => 'CERRADA', 'usuario_id' => $admin->id,
        ]);
        DB::table('caja_cierre')->insert([
            'apertura_id' => 2, 'fecha' => now(), 'estado' => 'CUADRA', 'usuario_id' => $admin->id,
        ]);

        // ADMIN corrige una atribución anterior (post-cierre).
        $r1 = $this->atribuir($admin, $cuentaAdmin, [
            'mesero_id' => $mesero->id,
            'motivo' => 'Era el mesero equivocado en la cuenta de la mesa 12',
        ]);
        $r1->assertStatus(200)->assertJsonPath('exito', true)->assertJsonPath('correccion_postcierre', true);

        // GERENTE atribuye una pendiente (nunca atribuida) post-cierre.
        $r2 = $this->atribuir($gerente, $cuentaGerente, [
            'mesero_id' => $mesero->id,
            'motivo' => 'Mesa 13 la atendió el mesero Y, quedó mal atribuida',
        ]);
        $r2->assertStatus(200)->assertJsonPath('exito', true)->assertJsonPath('correccion_postcierre', true);

        $this->assertSame(2, DB::table('auditoria_seguridad')->where('evento', 'propina_correccion_postcierre')->count());

        $auditoria = DB::table('auditoria_seguridad')
            ->where('evento', 'propina_correccion_postcierre')
            ->orderBy('id')
            ->get();

        $detalles1 = json_decode($auditoria[0]->detalles, true);
        $this->assertSame($otroMesero->id, $detalles1['mesero_anterior_id']);
        $this->assertSame($mesero->id, $detalles1['mesero_nuevo_id']);
        $this->assertTrue($detalles1['cierre_previo']);
        $this->assertSame(2, (int) $detalles1['apertura_id']);
        $this->assertNotEmpty($auditoria[0]->motivo);
        $this->assertSame($admin->id, (int) $auditoria[0]->usuario_id);
    }

    /* ------------------------------------------------------------------
     | Separación entre aperturas: nunca se mezclan en el cierre
     | ------------------------------------------------------------------ */

    public function test_las_aperturas_no_se_mezclan(): void
    {
        $cajero = $this->usuario('CAJERO');
        $admin = $this->usuario('ADMIN');
        $mesero = $this->usuario('MESERO');

        [$cuentaApertura1, $cuentaApertura2] = $this->montarCuentasCobradas([
            ['propina' => 15.00, 'fecha' => '2026-10-19 09:00:00', 'apertura_id' => 1],
            ['propina' => 25.00, 'fecha' => '2026-10-19 15:00:00', 'apertura_id' => 2],
        ], $cajero->id);

        // Apertura 2 cerrada; apertura 1 sigue ABIERTA (seed id=1).
        DB::table('caja_apertura')->insert([
            'id' => 2, 'fecha' => now(), 'cajero' => 'TEST', 'turno' => '2', 'caja_id' => 1,
            'monto_inicial' => 0, 'estado' => 'CERRADA', 'usuario_id' => $admin->id,
        ]);
        DB::table('caja_cierre')->insert([
            'apertura_id' => 2, 'fecha' => now(), 'estado' => 'CUADRA', 'usuario_id' => $admin->id,
        ]);

        // CAJERO atribuye en la apertura ABIERTA (permitido)...
        $this->atribuir($cajero, $cuentaApertura1, [
            'mesero_id' => $mesero->id,
            'motivo' => 'Apertura correcta del turno',
        ])->assertStatus(200);

        // ...pero NO puede corregir la propina de la apertura cerrada.
        $this->atribuir($cajero, $cuentaApertura2, [
            'mesero_id' => $mesero->id,
            'motivo' => 'Intento de mezclar aperturas',
        ])->assertStatus(403);

        $this->assertSame((int) $mesero->id, (int) DB::table('cuentas')->find($cuentaApertura1)->mesero_responsable_id);
        $this->assertNull(DB::table('cuentas')->find($cuentaApertura2)->mesero_responsable_id);
    }

    /* ------------------------------------------------------------------
     | Motivo obligatorio e idempotencia
     | ------------------------------------------------------------------ */

    public function test_el_motivo_es_obligatorio(): void
    {
        $cajero = $this->usuario('CAJERO');
        $mesero = $this->usuario('MESERO');
        [$cuentaId] = $this->montarCuentasCobradas([
            ['propina' => 10.00, 'fecha' => '2026-10-19 10:00:00', 'apertura_id' => 1],
        ], $cajero->id);

        $this->atribuir($cajero, $cuentaId, ['mesero_id' => $mesero->id])
            ->assertStatus(422);

        $this->atribuir($cajero, $cuentaId, ['mesero_id' => $mesero->id, 'motivo' => 'ab'])
            ->assertStatus(422);

        $this->assertNull(DB::table('cuentas')->find($cuentaId)->mesero_responsable_id);
        $this->assertSame(0, DB::table('auditoria_seguridad')->count());
    }

    public function test_el_mismo_mesero_no_registra_cambios_ni_auditoria(): void
    {
        $cajero = $this->usuario('CAJERO');
        $mesero = $this->usuario('MESERO');
        [$cuentaId] = $this->montarCuentasCobradas([
            ['propina' => 10.00, 'fecha' => '2026-10-19 10:00:00', 'apertura_id' => 1,
             'mesero_responsable_id' => $mesero->id],
        ], $cajero->id);

        $respuesta = $this->atribuir($cajero, $cuentaId, [
            'mesero_id' => $mesero->id,
            'motivo' => 'Ya estaba atribuida',
        ]);

        $respuesta->assertStatus(200)->assertJsonPath('sin_cambios', true);
        $this->assertSame(0, DB::table('auditoria_seguridad')->count());
    }

    /* ------------------------------------------------------------------
     | El mesero debe ser un MESERO activo
     | ------------------------------------------------------------------ */

    public function test_el_destino_debe_ser_un_mesero_activo(): void
    {
        $cajero = $this->usuario('CAJERO');
        $cajeroDestino = $this->usuario('CAJERO');
        $adminDestino = $this->usuario('ADMIN');
        $meseroInactivo = $this->usuario('MESERO');
        DB::table('usuarios')->where('id', $meseroInactivo->id)->update(['activo' => 0]);

        [$cuentaId] = $this->montarCuentasCobradas([
            ['propina' => 10.00, 'fecha' => '2026-10-19 10:00:00', 'apertura_id' => 1],
        ], $cajero->id);

        // CAJERO como destino: rechazado (solo MESERO).
        $this->atribuir($cajero, $cuentaId, ['mesero_id' => $cajeroDestino->id, 'motivo' => 'Intento con cajero'])
            ->assertStatus(422);

        // ADMIN como destino: rechazado.
        $this->atribuir($cajero, $cuentaId, ['mesero_id' => $adminDestino->id, 'motivo' => 'Intento con admin'])
            ->assertStatus(422);

        // MESERO inactivo: rechazado.
        $this->atribuir($cajero, $cuentaId, ['mesero_id' => $meseroInactivo->id, 'motivo' => 'Mesero apagado'])
            ->assertStatus(422);

        // ID inexistente: rechazado.
        $this->atribuir($cajero, $cuentaId, ['mesero_id' => 999999, 'motivo' => 'Fantasma'])
            ->assertStatus(422);

        $this->assertNull(DB::table('cuentas')->find($cuentaId)->mesero_responsable_id);
    }

    /* ------------------------------------------------------------------
     | Factura, consumo, pagos y propina quedan intactos
     | ------------------------------------------------------------------ */

    public function test_factura_consumo_pagos_y_propina_quedan_intactos(): void
    {
        $cajero = $this->usuario('CAJERO');
        $mesero = $this->usuario('MESERO');
        [$cuentaId] = $this->montarCuentasCobradas([
            ['propina' => 33.33, 'fecha' => '2026-10-19 10:00:00', 'apertura_id' => 1],
        ], $cajero->id);

        $antes = [
            'cuenta' => DB::table('cuentas')->find($cuentaId),
            'factura' => DB::table('facturas')->where('cuenta_id', $cuentaId)->first(),
            'pagos' => DB::table('pagos_cuenta')->where('cuenta_id', $cuentaId)->count(),
            'consumo' => (float) DB::table('cuenta_detalles')->where('cuenta_id', $cuentaId)->where('eliminado', false)->sum('subtotal'),
        ];

        $this->atribuir($cajero, $cuentaId, [
            'mesero_id' => $mesero->id,
            'motivo' => 'Auditoría de no alteración',
        ])->assertStatus(200);

        $cuenta = DB::table('cuentas')->find($cuentaId);
        $factura = DB::table('facturas')->where('cuenta_id', $cuentaId)->first();

        $this->assertEquals((float) $antes['cuenta']->propina, (float) $cuenta->propina);
        $this->assertEquals((float) $antes['cuenta']->total, (float) $cuenta->total);
        $this->assertSame($antes['cuenta']->estado, $cuenta->estado);
        $this->assertSame((float) $antes['factura']->total, (float) $factura->total);
        $this->assertSame($antes['factura']->estado, $factura->estado);
        $this->assertSame($antes['factura']->fecha_emision, $factura->fecha_emision);
        $this->assertSame($antes['pagos'], DB::table('pagos_cuenta')->where('cuenta_id', $cuentaId)->count());
        $this->assertEquals((float) $antes['consumo'], (float) DB::table('cuenta_detalles')->where('cuenta_id', $cuentaId)->where('eliminado', false)->sum('subtotal'));
    }

    /* ------------------------------------------------------------------
     | Persistencia tras la mesa cerrada y factura emitida
     | ------------------------------------------------------------------ */

    public function test_mesa_cerrada_y_factura_emitida_conserva_pendiente_y_atribuible(): void
    {
        $cajero = $this->usuario('CAJERO');
        $mesero = $this->usuario('MESERO');
        [$cuentaId] = $this->montarCuentasCobradas([
            ['propina' => 42.00, 'fecha' => '2026-10-19 18:20:00', 'apertura_id' => 1, 'mesa_estado' => 'LIBRE'],
        ], $cajero->id);

        // La propina sigue registrada aunque la mesa esté LIBRE y la factura EMITIDA.
        $this->assertSame('PAGADA', DB::table('cuentas')->find($cuentaId)->estado);
        $this->assertSame('EMITIDA', DB::table('facturas')->where('cuenta_id', $cuentaId)->value('estado'));
        $this->assertNull(DB::table('cuentas')->find($cuentaId)->mesero_responsable_id);

        // Y se puede resolver antes del cierre de caja.
        $this->atribuir($cajero, $cuentaId, [
            'mesero_id' => $mesero->id,
            'motivo' => 'Atendió la mesa antes de que cerrara',
        ])->assertStatus(200)->assertJsonPath('exito', true);
    }

    /* ------------------------------------------------------------------
     | Varios meseros y varias cuentas en la misma apertura
     | ------------------------------------------------------------------ */

    public function test_varias_cuentas_se_atribuyen_a_sus_meseros_reales(): void
    {
        $cajero = $this->usuario('CAJERO');
        $mesero1 = $this->usuario('MESERO');
        $mesero2 = $this->usuario('MESERO');

        [$cuenta1, $cuenta2] = $this->montarCuentasCobradas([
            ['propina' => 10.00, 'fecha' => '2026-10-19 10:00:00', 'apertura_id' => 1, 'nombre' => 'Cuenta 1'],
            ['propina' => 20.00, 'fecha' => '2026-10-19 11:00:00', 'apertura_id' => 1, 'nombre' => 'Cuenta 2'],
        ], $cajero->id);

        $this->atribuir($cajero, $cuenta1, ['mesero_id' => $mesero1->id, 'motivo' => 'Mesa 1 del mesero uno'])
            ->assertStatus(200);
        $this->atribuir($cajero, $cuenta2, ['mesero_id' => $mesero2->id, 'motivo' => 'Mesa 2 del mesero dos'])
            ->assertStatus(200);

        $this->assertSame((int) $mesero1->id, (int) DB::table('cuentas')->find($cuenta1)->mesero_responsable_id);
        $this->assertSame((int) $mesero2->id, (int) DB::table('cuentas')->find($cuenta2)->mesero_responsable_id);

        // Resumen: 30.00 atribuido, 0 pendiente, desglose por mesero exacto.
        $resumen = (new \App\Services\ResumenPropinasService())->resumenDelRango('2026-10-19', '2026-10-19');
        $this->assertEqualsWithDelta(30.00, (float) $resumen['atribuido'], 0.001);
        $this->assertEqualsWithDelta(0.00, (float) $resumen['pendiente'], 0.001);
        $this->assertEqualsWithDelta(30.00, (float) $resumen['total'], 0.001);

        $meseros = collect($resumen['resumen_meseros'])->keyBy('mesero_id');
        $this->assertEqualsWithDelta(10.00, (float) $meseros[$mesero1->id]['propinas'], 0.001);
        $this->assertEqualsWithDelta(20.00, (float) $meseros[$mesero2->id]['propinas'], 0.001);
    }

    /* ------------------------------------------------------------------
     | Mesero no inequívoco: permanece pendiente hasta atribución manual
     | ------------------------------------------------------------------ */

    public function test_varios_meseros_sin_atribuir_permanece_pendiente_y_se_atribuye_manualmente(): void
    {
        $cajero = $this->usuario('CAJERO');
        $mesero1 = $this->usuario('MESERO');
        $mesero2 = $this->usuario('MESERO');

        // DOS meseros participaron en la misma venta: sin atribución manual
        // la propina queda PENDIENTE (nunca se reparte ni se inventa).
        [$cuentaId] = $this->montarCuentasCobradas([
            ['propina' => 60.00, 'fecha' => '2026-10-19 12:00:00', 'apertura_id' => 1,
             'meseros_en_comandas' => [$mesero1->id, $mesero2->id]],
        ], $cajero->id);

        $servicio = new \App\Services\ResumenPropinasService();
        $antes = $servicio->resumenDelRango('2026-10-19', '2026-10-19');
        $this->assertEqualsWithDelta(60.00, (float) $antes['pendiente'], 0.001);
        $this->assertEqualsWithDelta(0.00, (float) $antes['atribuido'], 0.001);

        // Atribución manual: la propina va completa al mesero realmente responsable.
        $this->atribuir($cajero, $cuentaId, [
            'mesero_id' => $mesero2->id,
            'motivo' => 'Aunque enviaron 2 comandas, quien atendió fue el mesero dos',
        ])->assertStatus(200);

        $despues = $servicio->resumenDelRango('2026-10-19', '2026-10-19');
        $this->assertEqualsWithDelta(0.00, (float) $despues['pendiente'], 0.001);
        $this->assertEqualsWithDelta(60.00, (float) $despues['atribuido'], 0.001);
        $fila = collect($despues['resumen_meseros'])->firstWhere('mesero_id', $mesero2->id);
        $this->assertEqualsWithDelta(60.00, (float) $fila['propinas'], 0.001);
    }

    /* ------------------------------------------------------------------
     | Auditoría completa de la atribución
     | ------------------------------------------------------------------ */

    public function test_la_auditoria_registra_anterior_nuevo_motivo_y_usuario(): void
    {
        $admin = $this->usuario('ADMIN');
        $meseroAntes = $this->usuario('MESERO');
        $meseroDespues = $this->usuario('MESERO');
        [$cuentaId] = $this->montarCuentasCobradas([
            ['propina' => 55.00, 'fecha' => '2026-10-19 10:00:00', 'apertura_id' => 1,
             'mesero_responsable_id' => $meseroAntes->id],
        ], $admin->id);

        $this->atribuir($admin, $cuentaId, [
            'mesero_id' => $meseroDespues->id,
            'motivo' => 'Corrección: atendí y documenté al mesero real',
        ])->assertStatus(200);

        $auditoria = DB::table('auditoria_seguridad')->sole();
        $this->assertSame('propina_atribuida', $auditoria->evento);
        $this->assertSame($admin->id, (int) $auditoria->usuario_id);
        $this->assertSame('Corrección: atendí y documenté al mesero real', $auditoria->motivo);
        $this->assertNotNull($auditoria->creado_en);

        $detalles = json_decode($auditoria->detalles, true);
        $this->assertSame($cuentaId, (int) $detalles['cuenta_id']);
        $this->assertSame($meseroAntes->id, (int) $detalles['mesero_anterior_id']);
        $this->assertSame($meseroDespues->id, (int) $detalles['mesero_nuevo_id']);
        $this->assertEqualsWithDelta(55.00, (float) $detalles['propina'], 0.001);
        $this->assertSame(1, (int) $detalles['apertura_id']);
        $this->assertFalse((bool) $detalles['cierre_previo']);
    }

    /* ------------------------------------------------------------------
     | Una propina en cierre oculto NO atribuye pero tampoco se borra
     | ------------------------------------------------------------------ */

    public function test_la_atribucion_descuenta_de_pendientes(): void
    {
        $cajero = $this->usuario('CAJERO');
        $mesero = $this->usuario('MESERO');
        [$cuentaId] = $this->montarCuentasCobradas([
            ['propina' => 91.00, 'fecha' => '2026-10-19 10:00:00', 'apertura_id' => 1],
        ], $cajero->id);

        $servicio = new \App\Services\ResumenPropinasService();
        $antes = $servicio->resumenDelRango('2026-10-19', '2026-10-19');
        $this->assertEqualsWithDelta(91.00, (float) $antes['pendiente'], 0.001);
        $this->assertSame(1, (int) $antes['cantidad_pendientes']);

        $this->atribuir($cajero, $cuentaId, [
            'mesero_id' => $mesero->id,
            'motivo' => 'Pendiente atribuida antes del cierre',
        ])->assertStatus(200);

        $despues = $servicio->resumenDelRango('2026-10-19', '2026-10-19');
        $this->assertEqualsWithDelta(0.00, (float) $despues['pendiente'], 0.001);
        $this->assertSame(0, (int) $despues['cantidad_pendientes']);
        $this->assertEqualsWithDelta(91.00, (float) $despues['atribuido'], 0.001);

        // Operaciones toma el turno por apertura desde el MISMO servicio:
        // la apertura 1 la lee con el filtro apertura y ve la propina atribuida.
        $apertura = $servicio->resumenDelRango('2026-10-19', '2026-10-19', ['apertura' => 1]);
        $this->assertEqualsWithDelta(91.00, (float) $apertura['atribuido'], 0.001);
    }

    /* ------------------------------------------------------------------
     | La cuenta debe estar PAGADA y con propina > 0
     | ------------------------------------------------------------------ */

    public function test_cuenta_abierta_o_sin_propina_no_se_atribuye(): void
    {
        $cajero = $this->usuario('CAJERO');
        $mesero = $this->usuario('MESERO');

        $mesaId = DB::table('mesas')->insertGetId(['numero' => 'Mesa XYZ', 'capacidad' => 4, 'estado' => 'OCUPADA']);
        $ventaId = DB::table('ventas')->insertGetId([
            'mesa_id' => $mesaId, 'descripcion' => 'V', 'monto' => 100,
            'estado' => 'ACTIVA', 'usuario_id' => $cajero->id, 'fecha' => now(),
        ]);
        $cuentaAbiertaId = DB::table('cuentas')->insertGetId([
            'venta_id' => $ventaId, 'nombre' => 'Abierta', 'total' => 100, 'estado' => 'ABIERTA',
            'apertura_id' => 1, 'propina' => 10,
        ]);

        [$cuentaSinPropinaId] = $this->montarCuentasCobradas([
            ['propina' => 0.00, 'fecha' => '2026-10-19 10:00:00', 'apertura_id' => 1],
        ], $cajero->id);

        $this->atribuir($cajero, $cuentaAbiertaId, ['mesero_id' => $mesero->id, 'motivo' => 'No está cobrada'])
            ->assertStatus(422);

        $this->atribuir($cajero, $cuentaSinPropinaId, ['mesero_id' => $mesero->id, 'motivo' => 'Sin propina'])
            ->assertStatus(422);
    }

    /* ------------------------------------------------------------------
     | Permisos de las vistas: CAJERO solo ve pendientes; MESERO ninguno
     | ------------------------------------------------------------------ */

    public function test_la_vista_pendientes_es_para_admin_gerente_y_cajero(): void
    {
        $admin = $this->usuario('ADMIN');
        $gerente = $this->usuario('GERENTE');
        $cajero = $this->usuario('CAJERO');
        $mesero = $this->usuario('MESERO');

        [$cuentaId] = $this->montarCuentasCobradas([
            ['propina' => 99.00, 'fecha' => '2026-10-19 10:00:00', 'apertura_id' => 1, 'nombre' => 'Cuenta visible'],
        ], $admin->id);

        foreach ([[$admin, 200], [$gerente, 200], [$cajero, 200]] as [$usuario, $esperado]) {
            $respuesta = $this->actingAs($usuario)->get(route('reportes.propinas.pendientes'));
            $respuesta->assertStatus($esperado);
            if ($esperado === 200) {
                $respuesta->assertSee('FAC-PROP-0001')->assertSee('Cuenta visible');
                // La pantalla mínima NO muestra los KPIs del reporte financiero.
                $respuesta->assertDontSee('TOTAL PROPINAS');
            }
        }

        $this->actingAs($mesero)->get(route('reportes.propinas.pendientes'))->assertStatus(403);

        // El reporte financiero completo sigue vedado a CAJERO.
        $this->actingAs($cajero)->get(route('reportes.propinas'))->assertStatus(403);

        // El panel de pendientes también vive dentro del reporte (ADMIN/GERENTE).
        $html = $this->actingAs($admin)->get(route('reportes.propinas', ['desde' => '2026-10-19', 'hasta' => '2026-10-19']))->getContent();
        $this->assertStringContainsString('Pendientes de atribución', $html);
        $this->assertStringContainsString('Cuenta visible', $html);
    }
}
