<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Concerns\CreaEsquemaCompleto;
use Tests\TestCase;

class ConfiguracionModuleTest extends TestCase
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
        $usuarioId = DB::table('usuarios')->insertGetId([
            'nombre' => $rolNombre . ' Test',
            'username' => strtolower($rolNombre) . '_' . uniqid(),
            'password_hash' => bcrypt('secret'),
            'rol_id' => $rolId,
        ]);
        return User::findOrFail($usuarioId);
    }

    public function test_solo_admin_puede_ver_configuracion_y_cambiar_interruptores(): void
    {
        $admin = $this->usuario('ADMIN');
        $gerente = $this->usuario('GERENTE');
        $cajero = $this->usuario('CAJERO');
        $cocinero = $this->usuario('COCINERO');

        $this->actingAs($gerente)->get('/configuracion')->assertStatus(403);
        $this->actingAs($cajero)->get('/configuracion')->assertStatus(403);
        $this->actingAs($cocinero)->get('/configuracion')->assertStatus(403);

        $this->actingAs($admin)->get('/configuracion')->assertStatus(200);
        $this->actingAs($admin)->postJson('/configuracion/api', ['modulo' => 'cocina', 'habilitado' => true])->assertStatus(200)->assertJson(['exito' => true, 'modulo' => 'cocina', 'habilitado' => true]);
        $this->actingAs($admin)->postJson('/configuracion/api', ['modulo' => 'inventario', 'habilitado' => false])->assertStatus(200)->assertJson(['exito' => true, 'modulo' => 'inventario', 'habilitado' => false]);

        $this->actingAs($gerente)->postJson('/configuracion/api', ['modulo' => 'cocina', 'habilitado' => false])->assertStatus(403);
    }

    public function test_cocina_deshabilitada_por_defecto_pero_puede_habilitarse(): void
    {
        $admin = $this->usuario('ADMIN');
        $this->actingAs($admin)->get('/configuracion')->assertStatus(200);
    }

    public function test_inventario_deshabilitado_bloquea_pantallas_administrativas_sin_romper_ventas(): void
    {
        $admin = $this->usuario('ADMIN');
        // Deshabilitar inventario
        $this->actingAs($admin)->postJson('/configuracion/api', ['modulo' => 'inventario', 'habilitado' => false])->assertStatus(200)->assertJson(['exito' => true]);

        $gerente = $this->usuario('GERENTE');
        // Gestión bloqueada
        $this->actingAs($gerente)->get('/inventario')->assertStatus(403);
        $this->actingAs($gerente)->get('/inventario/historial')->assertStatus(403);
    }
}
