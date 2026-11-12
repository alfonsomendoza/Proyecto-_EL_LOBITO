<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\CreaEsquemaCompleto;
use Tests\TestCase;

class CajaBloqueoGlobalTest extends TestCase
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
            'username' => strtolower($rolNombre).'_'.uniqid(),
            'password_hash' => bcrypt('secret'),
            'rol_id' => $rolId,
        ]));
    }

    private function cerrarCaja(): void
    {
        DB::table('caja_apertura')->update(['estado' => 'CERRADA']);
    }

    public function test_caja_cerrada_bloquea_operaciones_mutantes_para_todos_los_roles(): void
    {
        $this->cerrarCaja();

        foreach (['ADMIN', 'GERENTE', 'CAJERO', 'MESERO', 'COCINERO'] as $rol) {
            $usuario = $this->usuario($rol);

            $this->actingAs($usuario)
                ->postJson('/mesas/api', ['accion' => 'abrir_mesa', 'mesa_id' => 1])
                ->assertStatus(422)
                ->assertJson(['exito' => false]);
        }
    }

    public function test_caja_cerrada_permite_acceder_a_la_pantalla_de_apertura(): void
    {
        $this->cerrarCaja();

        foreach (['ADMIN', 'GERENTE', 'CAJERO'] as $rol) {
            $this->actingAs($this->usuario($rol))
                ->get('/caja/apertura')
                ->assertStatus(200);
        }
    }

    public function test_caja_abierta_permite_operaciones_segun_rol(): void
    {
        // caja ya abierta por el esquema; un CAJERO puede crear una venta.
        $mesaId = DB::table('mesas')->insertGetId(['numero' => 'M1', 'capacidad' => 4, 'estado' => 'LIBRE']);

        $this->actingAs($this->usuario('CAJERO'))
            ->postJson('/mesas/api', ['accion' => 'abrir_mesa', 'mesa_id' => $mesaId])
            ->assertStatus(200)
            ->assertJson(['exito' => true]);
    }

    public function test_apertura_valida_habilita_operaciones(): void
    {
        $this->cerrarCaja();

        $this->actingAs($this->usuario('CAJERO'))
            ->postJson('/caja/apertura', ['monto_inicial' => 100])
            ->assertStatus(200)
            ->assertJson(['exito' => true]);

        $mesaId = DB::table('mesas')->insertGetId(['numero' => 'M2', 'capacidad' => 4, 'estado' => 'LIBRE']);

        $this->actingAs($this->usuario('CAJERO'))
            ->postJson('/mesas/api', ['accion' => 'abrir_mesa', 'mesa_id' => $mesaId])
            ->assertStatus(200)
            ->assertJson(['exito' => true]);
    }

    public function test_caja_cerrada_permite_administracion_y_seguridad_segun_permisos(): void
    {
        $this->cerrarCaja();

        // ADMIN puede gestionar usuarios/roles con caja cerrada.
        $admin = $this->usuario('ADMIN');
        $this->actingAs($admin)->get('/usuarios')->assertStatus(200);
        $this->actingAs($admin)->get('/roles')->assertStatus(200);
        $this->actingAs($admin)->get('/configuracion')->assertStatus(200);

        // CAJERO/MESERO/COCINERO no reciben privilegios adicionales.
        foreach (['CAJERO', 'MESERO', 'COCINERO'] as $rol) {
            $u = $this->usuario($rol);
            $this->actingAs($u)->get('/usuarios')->assertRedirect(route('inicio'));
            $this->actingAs($u)->get('/roles')->assertRedirect(route('inicio'));
        }

        // Operaciones comerciales siguen bloqueadas.
        $mesaId = DB::table('mesas')->insertGetId(['numero' => 'M3', 'capacidad' => 4, 'estado' => 'LIBRE']);
        $this->actingAs($admin)
            ->postJson('/mesas/api', ['accion' => 'abrir_mesa', 'mesa_id' => $mesaId])
            ->assertStatus(422)
            ->assertJson(['exito' => false]);
    }
}
