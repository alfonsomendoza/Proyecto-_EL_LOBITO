<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\CreaEsquemaCompleto;
use Tests\TestCase;

class AnularDetalleCocinaTest extends TestCase
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

    public function test_no_se_puede_anular_si_esta_en_preparacion_o_listo_cuando_cocina_habilitada(): void
    {
        $admin = $this->usuario('ADMIN');
        // Habilitar cocina
        $this->actingAs($admin)->postJson('/configuracion/api', ['modulo' => 'cocina', 'habilitado' => true])->assertStatus(200);

        $mesaId = DB::table('mesas')->insertGetId(['numero' => '1', 'capacidad' => 4, 'estado' => 'LIBRE']);
        $ventaId = DB::table('ventas')->insertGetId(['mesa_id' => $mesaId, 'monto' => 0, 'estado' => 'ACTIVA', 'fecha' => now()]);
        $cuentaId = DB::table('cuentas')->insertGetId(['venta_id' => $ventaId, 'nombre' => 'Cuenta Principal', 'total' => 0, 'estado' => 'ABIERTA']);
        $prodCocina = DB::table('productos')->insertGetId(['nombre' => 'Plato', 'precio' => 10, 'requiere_cocina' => 1, 'activo' => true]);

        // Crear pedido y obtener detalle
        $this->actingAs($admin)->postJson('/pos/api', [
            'accion' => 'enviar_cocina',
            'venta_id' => $ventaId,
            'carrito' => json_encode([['id' => $prodCocina, 'cantidad' => 2, 'notas' => null]]),
        ])->assertStatus(200);

        $detalleId = DB::table('venta_detalles')->where('venta_id', $ventaId)->value('id');

        // Marcar EN_PREPARACION
        $cocinero = $this->usuario('COCINERO');
        $this->actingAs($cocinero)->postJson("/cocina/api/detalles/{$detalleId}/estado", ['estado' => 'EN_PREPARACION'])->assertStatus(200);

        // Intentar anular
        $this->actingAs($admin)->postJson("/pos/detalle/{$detalleId}/anular", ['cantidad' => 1, 'motivo' => 'prueba motivo'])->assertStatus(422);

        // Marcar LISTO
        $this->actingAs($cocinero)->postJson("/cocina/api/detalles/{$detalleId}/estado", ['estado' => 'LISTO'])->assertStatus(200);
        $this->actingAs($admin)->postJson("/pos/detalle/{$detalleId}/anular", ['cantidad' => 1, 'motivo' => 'prueba motivo'])->assertStatus(422);
    }

    public function test_puede_anular_si_pendiente_cuando_cocina_habilitada(): void
    {
        $admin = $this->usuario('ADMIN');
        $this->actingAs($admin)->postJson('/configuracion/api', ['modulo' => 'cocina', 'habilitado' => true])->assertStatus(200);

        $mesaId = DB::table('mesas')->insertGetId(['numero' => '1', 'capacidad' => 4, 'estado' => 'LIBRE']);
        $ventaId = DB::table('ventas')->insertGetId(['mesa_id' => $mesaId, 'monto' => 0, 'estado' => 'ACTIVA', 'fecha' => now()]);
        $cuentaId = DB::table('cuentas')->insertGetId(['venta_id' => $ventaId, 'nombre' => 'Cuenta Principal', 'total' => 0, 'estado' => 'ABIERTA']);
        $prodCocina = DB::table('productos')->insertGetId(['nombre' => 'Plato', 'precio' => 10, 'requiere_cocina' => 1, 'activo' => true]);

        $this->actingAs($admin)->postJson('/pos/api', [
            'accion' => 'enviar_cocina',
            'venta_id' => $ventaId,
            'carrito' => json_encode([['id' => $prodCocina, 'cantidad' => 2, 'notas' => null]]),
        ])->assertStatus(200);

        $detalleId = DB::table('venta_detalles')->where('venta_id', $ventaId)->value('id');
        // PENDIENTE -> puede anular (dependiendo de reglas comerciales existentes; al menos backend no bloquea por cocina)
        $this->actingAs($admin)->postJson("/pos/detalle/{$detalleId}/anular", ['cantidad' => 1, 'motivo' => 'prueba motivo'])->assertStatus(200);
    }
}
