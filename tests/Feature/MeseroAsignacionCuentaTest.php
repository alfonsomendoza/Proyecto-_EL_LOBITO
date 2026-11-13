<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\CreaEsquemaCompleto;
use Tests\TestCase;

class MeseroAsignacionCuentaTest extends TestCase
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
            'nombre' => $rolNombre.' Test',
            'username' => uniqid(strtolower($rolNombre)),
            'password_hash' => bcrypt('secret'),
            'rol_id' => $rolId,
            'activo' => 1,
        ]));
    }

    private function cuentaBase(): array
    {
        $mesaId = DB::table('mesas')->insertGetId(['numero' => 'M'.uniqid(), 'capacidad' => 4, 'estado' => 'OCUPADA']);
        $ventaId = DB::table('ventas')->insertGetId([
            'mesa_id' => $mesaId, 'descripcion' => 't', 'monto' => 0,
            'estado' => 'ACTIVA', 'usuario_id' => 1, 'fecha' => now(),
        ]);
        $cuentaId = DB::table('cuentas')->insertGetId([
            'venta_id' => $ventaId, 'nombre' => 'Principal', 'total' => 0,
            'estado' => 'ABIERTA', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['cuenta' => $cuentaId, 'venta' => $ventaId];
    }

    public function test_admin_asigna_mesero_a_una_cuenta_y_se_registra_historial(): void
    {
        $admin = $this->usuario('ADMIN');
        $mesero = $this->usuario('MESERO');
        ['cuenta' => $cuentaId, 'venta' => $ventaId] = $this->cuentaBase();

        $this->actingAs($admin)
            ->postJson(route('cuentas.mesero.asignar', ['cuentaId' => $cuentaId]), ['mesero_id' => $mesero->id])
            ->assertStatus(200)
            ->assertJson(['exito' => true]);

        $this->assertSame($mesero->id, (int) DB::table('cuentas')->where('id', $cuentaId)->value('mesero_responsable_id'));
        $this->assertDatabaseHas('mesa_asignaciones', ['venta_id' => $ventaId, 'mesero_id' => $mesero->id]);
    }

    public function test_asignar_el_mismo_mesero_no_duplica_registros(): void
    {
        $admin = $this->usuario('ADMIN');
        $mesero = $this->usuario('MESERO');
        ['cuenta' => $cuentaId] = $this->cuentaBase();

        $this->actingAs($admin)
            ->postJson(route('cuentas.mesero.asignar', ['cuentaId' => $cuentaId]), ['mesero_id' => $mesero->id])
            ->assertStatus(200);

        $this->actingAs($admin)
            ->postJson(route('cuentas.mesero.asignar', ['cuentaId' => $cuentaId]), ['mesero_id' => $mesero->id])
            ->assertStatus(200)
            ->assertJson(['sin_cambios' => true]);

        $this->assertSame(1, DB::table('mesa_asignaciones')->where('mesero_id', $mesero->id)->count());
    }

    public function test_mesero_y_cocinero_no_pueden_asignar_encargado(): void
    {
        $meseroUser = $this->usuario('MESERO');
        $cocinero = $this->usuario('COCINERO');
        $meseroDestino = $this->usuario('MESERO');
        ['cuenta' => $cuentaId] = $this->cuentaBase();

        $this->actingAs($meseroUser)
            ->postJson(route('cuentas.mesero.asignar', ['cuentaId' => $cuentaId]), ['mesero_id' => $meseroDestino->id])
            ->assertStatus(403);

        $this->actingAs($cocinero)
            ->postJson(route('cuentas.mesero.asignar', ['cuentaId' => $cuentaId]), ['mesero_id' => $meseroDestino->id])
            ->assertStatus(403);
    }

    public function test_sin_columnas_de_mesero_devuelve_500_claro(): void
    {
        $admin = $this->usuario('ADMIN');
        ['cuenta' => $cuentaId] = $this->cuentaBase();

        // Simular el estado de producción antes de la migración.
        \Illuminate\Support\Facades\Schema::table('cuentas', function ($table) {
            $table->dropColumn('mesero_responsable_id');
        });

        \Illuminate\Support\Facades\Schema::table('ventas', function ($table) {
            $table->dropColumn('mesero_responsable_id');
        });

        $respuesta = $this->actingAs($admin)
            ->postJson(route('cuentas.mesero.asignar', ['cuentaId' => $cuentaId]), ['mesero_id' => $this->usuario('MESERO')->id]);

        $this->assertSame(500, $respuesta->getStatusCode());
        $this->assertStringContainsString('2026_11_12_000001_mesero_responsable_propinas', $respuesta->getContent());
    }

    public function test_mesero_inactivo_no_es_asignable(): void
    {
        $admin = $this->usuario('ADMIN');
        $mesero = $this->usuario('MESERO');
        ['cuenta' => $cuentaId] = $this->cuentaBase();
        DB::table('usuarios')->where('id', $mesero->id)->update(['activo' => 0]);

        $this->actingAs($admin)
            ->postJson(route('cuentas.mesero.asignar', ['cuentaId' => $cuentaId]), ['mesero_id' => $mesero->id])
            ->assertStatus(422);
    }
}
