<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\CreaEsquemaCompleto;
use Tests\TestCase;

class MesaMeseroCierreTest extends TestCase
{
    use CreaEsquemaCompleto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', (string) config('database.connections.sqlite.database'));
        $this->crearEsquemaCompleto();
    }

    private function usuario(string $rolNombre): User
    {
        $rolId = DB::table('roles')->insertGetId(['nombre' => $rolNombre]);

        return User::findOrFail(DB::table('usuarios')->insertGetId([
            'nombre' => $rolNombre.' Test '.uniqid(),
            'username' => strtolower($rolNombre).'_'.uniqid(),
            'password_hash' => bcrypt('secret'),
            'rol_id' => $rolId,
            'activo' => 1,
        ]));
    }

    private function montarCuenta(int $usuarioId, int $meseroId): array
    {
        $mesaId = DB::table('mesas')->insertGetId(['numero' => 'M'.uniqid(), 'capacidad' => 4, 'estado' => 'LIBRE']);
        $ventaId = DB::table('ventas')->insertGetId([
            'mesa_id' => $mesaId,
            'descripcion' => 'Venta de prueba',
            'monto' => 0,
            'estado' => 'ACTIVA',
            'usuario_id' => $usuarioId,
            'fecha' => now(),
            'mesero_responsable_id' => $meseroId,
        ]);
        $productoId = DB::table('productos')->insertGetId([
            'nombre' => 'Plato', 'precio' => 100, 'requiere_cocina' => 0, 'controla_stock' => 0, 'activo' => 1,
        ]);
        $detalleId = DB::table('venta_detalles')->insertGetId([
            'venta_id' => $ventaId, 'producto_id' => $productoId,
            'cantidad' => 1, 'precio_unitario' => 100, 'subtotal' => 100, 'estado' => 'ACTIVO',
        ]);
        $cuentaId = DB::table('cuentas')->insertGetId([
            'venta_id' => $ventaId, 'nombre' => 'Cuenta principal', 'total' => 100,
            'estado' => 'PAGADA', 'apertura_id' => 1, 'metodo_pago' => 'EFECTIVO',
            'usuario_cobro' => $usuarioId, 'propina' => 50, 'created_at' => now(), 'updated_at' => now(),
            'mesero_responsable_id' => $meseroId,
        ]);
        DB::table('cuenta_detalles')->insert([
            'cuenta_id' => $cuentaId, 'venta_id' => $ventaId,
            'venta_detalle_id' => $detalleId, 'cantidad' => 1, 'subtotal' => 100, 'eliminado' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('facturas')->insert([
            'numero_factura' => 'FAC-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
            'venta_id' => $ventaId, 'cuenta_id' => $cuentaId, 'mesa' => 'Mesa',
            'detalle_consumo' => '{}', 'total' => 150,
            'fecha_emision' => now(), 'usuario_id' => $usuarioId, 'estado' => 'EMITIDA',
        ]);

        return ['mesa' => $mesaId, 'venta' => $ventaId, 'cuenta' => $cuentaId];
    }

    public function test_un_mesero_desactivado_conserva_sus_propinas_historicas(): void
    {
        $admin = $this->usuario('ADMIN');
        $mesero = $this->usuario('MESERO');
        $this->montarCuenta($admin->id, $mesero->id);

        // Desactivar el mesero: NO debe perder sus propinas históricas.
        DB::table('usuarios')->where('id', $mesero->id)->update(['activo' => 0]);

        $servicio = app(\App\Services\ResumenPropinasService::class);
        $resumen = $servicio->resumenDelDia(now()->format('Y-m-d'));

        $this->assertSame(1, $resumen['cantidad']);
        $this->assertSame('ATRIBUIDA', $resumen['detalle'][0]['atribucion']);
        $this->assertSame($mesero->id, (int) $resumen['detalle'][0]['mesero_atribuido_id']);
        $this->assertEquals(50.0, (float) $resumen['atribuido']);
    }

    public function test_reapertura_con_venta_activa_actualiza_responsable_y_conserva_snapshot(): void
    {
        $admin = $this->usuario('ADMIN');
        $mesero1 = $this->usuario('MESERO');
        $mesero2 = $this->usuario('MESERO');
        $mesaId = DB::table('mesas')->insertGetId(['numero' => 'M'.uniqid(), 'capacidad' => 4, 'estado' => 'LIBRE']);

        $this->actingAs($admin)->postJson('/mesas/api', [
            'accion' => 'abrir_mesa', 'mesa_id' => $mesaId, 'mesero_id' => $mesero1->id,
        ])->assertStatus(200);

        $ventaId = (int) DB::table('ventas')->where('mesa_id', $mesaId)->value('id');
        $cuentaAntes = (int) DB::table('cuentas')->where('venta_id', $ventaId)->value('mesero_responsable_id');

        // Reapertura eligiendo otro encargado: debe registrarse la reasignación.
        $this->actingAs($admin)->postJson('/mesas/api', [
            'accion' => 'abrir_mesa', 'mesa_id' => $mesaId, 'mesero_id' => $mesero2->id,
        ])->assertStatus(200);

        $this->assertSame($mesero2->id, (int) DB::table('ventas')->where('id', $ventaId)->value('mesero_responsable_id'));
        $this->assertSame($cuentaAntes, (int) DB::table('cuentas')->where('venta_id', $ventaId)->value('mesero_responsable_id'));
        $this->assertDatabaseHas('mesa_asignaciones', ['venta_id' => $ventaId, 'mesero_id' => $mesero2->id, 'motivo' => 'REASIGNACION']);
    }

    public function test_mesero_que_abre_mesa_y_se_pasa_a_si_mismo_queda_asignado(): void
    {
        $mesero = $this->usuario('MESERO');
        $mesaId = DB::table('mesas')->insertGetId(['numero' => 'M'.uniqid(), 'capacidad' => 4, 'estado' => 'LIBRE']);

        $this->actingAs($mesero)->postJson('/mesas/api', [
            'accion' => 'abrir_mesa', 'mesa_id' => $mesaId,
        ])->assertStatus(200);

        $venta = DB::table('ventas')->where('mesa_id', $mesaId)->first();
        $this->assertSame($mesero->id, (int) $venta->mesero_responsable_id);
        $miCuenta = DB::table('cuentas')->where('venta_id', $venta->id)->first();
        $this->assertSame($mesero->id, (int) $miCuenta->mesero_responsable_id);
    }

    public function test_mesero_no_puede_elegir_otro_encargado_al_abrir_mesa(): void
    {
        $mesero = $this->usuario('MESERO');
        $otroMesero = $this->usuario('MESERO');
        $mesaId = DB::table('mesas')->insertGetId(['numero' => 'M'.uniqid(), 'capacidad' => 4, 'estado' => 'LIBRE']);

        $this->actingAs($mesero)->postJson('/mesas/api', [
            'accion' => 'abrir_mesa', 'mesa_id' => $mesaId, 'mesero_id' => $otroMesero->id,
        ])->assertStatus(403);
    }

    public function test_panel_de_mesa_muestra_meseros_activos_en_el_selector(): void
    {
        $admin = $this->usuario('ADMIN');
        $gerente = $this->usuario('GERENTE');
        $cajero = $this->usuario('CAJERO');
        $mesero1 = $this->usuario('MESERO');
        $mesero2 = $this->usuario('MESERO');
        $cocMode = $this->usuario('COCINERO');

        // Un mesero inactivo debe quedar EXCLUIDO.
        $meseroInactivo = $this->usuario('MESERO');
        DB::table('usuarios')->where('id', $meseroInactivo->id)->update(['activo' => 0]);

        $mesaId = DB::table('mesas')->insertGetId(['numero' => 'M'.uniqid(), 'capacidad' => 4, 'estado' => 'OCUPADA']);
        $ventaId = DB::table('ventas')->insertGetId([
            'mesa_id' => $mesaId,
            'descripcion' => 'test',
            'monto' => 0,
            'estado' => 'ACTIVA',
            'usuario_id' => $admin->id,
            'fecha' => now(),
        ]);

        DB::table('cuentas')->insertGetId([
            'venta_id' => $ventaId, 'nombre' => 'Principal', 'total' => 0,
            'estado' => 'ABIERTA', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $marta = 'Marta PruebaPanel';
        $pepe = 'Pepe PruebaPanel';

        DB::table('usuarios')->where('id', $mesero1->id)->update(['nombre' => $marta]);
        DB::table('usuarios')->where('id', $mesero2->id)->update(['nombre' => $pepe]);

        foreach ([$admin, $gerente, $cajero] as $actor) {
            $this->actingAs($actor)->get(route('mesas.cuentas', ['mesaId' => $mesaId]))
                ->assertStatus(200)
                ->assertSee($marta)
                ->assertSee($pepe);
        }

        // El mesero INACTIVO NO aparece.
        $inactivoNombre = $meseroInactivo->nombre;
        $this->actingAs($admin)->get(route('mesas.cuentas', ['mesaId' => $mesaId]))
            ->assertDontSee($inactivoNombre);

        // COCINERO no aparece ni los demás roles tampoco.
        $this->actingAs($admin)->get(route('mesas.cuentas', ['mesaId' => $mesaId]))
            ->assertDontSee('Admin Test')
            ->assertDontSee('GERENTE Test')
            ->assertDontSee('CAJERO Test')
            ->assertDontSee('Cocinero');
    }

    public function test_panel_mesa_abierta_muestra_selector_de_mesero_para_admin(): void
    {
        $admin = $this->usuario('ADMIN');
        $mesero = $this->usuario('MESERO');
        $mesaId = DB::table('mesas')->insertGetId(['numero' => 'M'.uniqid(), 'capacidad' => 4, 'estado' => 'OCUPADA']);
        $ventaId = DB::table('ventas')->insertGetId([
            'mesa_id' => $mesaId,
            'descripcion' => 'test',
            'monto' => 0,
            'estado' => 'ACTIVA',
            'usuario_id' => $admin->id,
            'fecha' => now(),
        ]);
        $cuentaId = DB::table('cuentas')->insertGetId([
            'venta_id' => $ventaId, 'nombre' => 'Principal', 'total' => 0,
            'estado' => 'ABIERTA', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($admin)->get(route('mesas.cuentas', ['mesaId' => $mesaId]))
            ->assertStatus(200)
            ->assertSee('sel-mesero-encargado')
            ->assertSee('btn-asignar-mesero');
    }

    public function test_mesero_no_tiene_permiso_asignar_en_panel(): void
    {
        $admin = $this->usuario('ADMIN');
        $mesero = $this->usuario('MESERO');
        $mesaId = DB::table('mesas')->insertGetId(['numero' => 'M'.uniqid(), 'capacidad' => 4, 'estado' => 'OCUPADA']);
        $ventaId = DB::table('ventas')->insertGetId([
            'mesa_id' => $mesaId,
            'descripcion' => 'test',
            'monto' => 0,
            'estado' => 'ACTIVA',
            'usuario_id' => $admin->id,
            'fecha' => now(),
        ]);
        $cuentaId = DB::table('cuentas')->insertGetId([
            'venta_id' => $ventaId, 'nombre' => 'Principal', 'total' => 0,
            'estado' => 'ABIERTA', 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Un mesero ve el panel pero SIN selector de administración.
        $this->actingAs($mesero)->get(route('mesas.cuentas', ['mesaId' => $mesaId]))
            ->assertStatus(200)
            ->assertDontSee('sel-mesero-encargado');

        // Y tampoco puede llamar la ruta de asignación.
        $this->actingAs($mesero)->postJson(route('cuentas.mesero.asignar', ['cuentaId' => $cuentaId]), ['mesero_id' => $mesero->id])
            ->assertStatus(403);
    }

    public function test_cocinero_no_puede_abrir_mesa(): void
    {
        $cocinero = $this->usuario('COCINERO');
        $mesaId = DB::table('mesas')->insertGetId(['numero' => 'M'.uniqid(), 'capacidad' => 4, 'estado' => 'LIBRE']);

        $this->actingAs($cocinero)->postJson('/mesas/api', [
            'accion' => 'abrir_mesa', 'mesa_id' => $mesaId, 'mesero_id' => $cocinero->id,
        ])->assertStatus(403);
    }
}
