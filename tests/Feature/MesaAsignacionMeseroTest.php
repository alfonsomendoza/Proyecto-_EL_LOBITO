<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\CreaEsquemaCompleto;
use Tests\TestCase;

class MesaAsignacionMeseroTest extends TestCase
{
    use CreaEsquemaCompleto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', (string) config('database.connections.sqlite.database'));

        $this->crearEsquemaCompleto();
    }

    private function rol(string $nombre): int
    {
        return DB::table('roles')->insertGetId(['nombre' => $nombre]);
    }

    private function usuario(string $rolNombre): User
    {
        $rolId = $this->rol($rolNombre);

        return User::findOrFail(DB::table('usuarios')->insertGetId([
            'nombre' => $rolNombre.' Test',
            'username' => strtolower($rolNombre).'_'.uniqid(),
            'password_hash' => bcrypt('secret'),
            'rol_id' => $rolId,
            'activo' => 1,
        ]));
    }

    public function test_abrir_mesa_asigna_mesero_y_guarda_snapshot_en_cuenta(): void
    {
        $admin = $this->usuario('ADMIN');
        $mesero = $this->usuario('MESERO');
        $mesaId = DB::table('mesas')->insertGetId(['numero' => 'M1', 'capacidad' => 4, 'estado' => 'LIBRE']);

        $this->actingAs($admin)
            ->postJson('/mesas/api', [
                'accion' => 'abrir_mesa',
                'mesa_id' => $mesaId,
                'mesero_id' => $mesero->id,
            ])
            ->assertStatus(200)
            ->assertJson(['exito' => true]);

        $venta = DB::table('ventas')->where('mesa_id', $mesaId)->value('id');
        $this->assertSame($mesero->id, (int) DB::table('ventas')->where('id', $venta)->value('mesero_responsable_id'));
        $this->assertSame($mesero->id, (int) DB::table('cuentas')->where('venta_id', $venta)->value('mesero_responsable_id'));
        $this->assertSame($mesero->id, (int) DB::table('mesa_asignaciones')->where('venta_id', $venta)->value('mesero_id'));

        $this->assertDatabaseHas('mesa_asignaciones', ['venta_id' => $venta, 'mesero_id' => $mesero->id, 'motivo' => 'APERTURA']);
    }

    public function test_cambiar_encargado_no_altera_cuentas_ya_creadas(): void
    {
        $admin = $this->usuario('ADMIN');
        $mesero1 = $this->usuario('MESERO');
        $mesero2 = $this->usuario('MESERO');
        $mesaId = DB::table('mesas')->insertGetId(['numero' => 'M2', 'capacidad' => 4, 'estado' => 'LIBRE']);

        // Abrir con mesero1.
        $this->actingAs($admin)
            ->postJson('/mesas/api', ['accion' => 'abrir_mesa', 'mesa_id' => $mesaId, 'mesero_id' => $mesero1->id])
            ->assertStatus(200);

        $ventaId = (int) DB::table('ventas')->where('mesa_id', $mesaId)->value('id');
        $cuentaAntes = (int) DB::table('cuentas')->where('venta_id', $ventaId)->value('mesero_responsable_id');

        // Reasignar a mesero2.
        $this->actingAs($admin)
            ->postJson(route('mesas.asignar-mesero', $mesaId), ['mesero_id' => $mesero2->id])
            ->assertStatus(200)
            ->assertJson(['exito' => true]);

        $this->assertSame($mesero2->id, (int) DB::table('ventas')->where('id', $ventaId)->value('mesero_responsable_id'));
        $this->assertSame($mesero1->id, (int) DB::table('cuentas')->where('venta_id', $ventaId)->value('mesero_responsable_id'), 'Las cuentas previas conservan el responsable original.');
        $this->assertDatabaseHas('mesa_asignaciones', ['venta_id' => $ventaId, 'mesero_id' => $mesero2->id, 'motivo' => 'REASIGNACION']);
    }

    public function test_mesero_no_puede_reasignar_encargado(): void
    {
        $mesero = $this->usuario('MESERO');
        $mesaId = DB::table('mesas')->insertGetId(['numero' => 'M3', 'capacidad' => 4, 'estado' => 'LIBRE']);

        $this->actingAs($mesero)
            ->postJson(route('mesas.asignar-mesero', $mesaId), ['mesero_id' => $mesero->id])
            ->assertStatus(403);
    }
}
