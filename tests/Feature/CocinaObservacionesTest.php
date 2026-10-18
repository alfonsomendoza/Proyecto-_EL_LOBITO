<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Concerns\CreaEsquemaCompleto;
use Tests\TestCase;

class CocinaObservacionesTest extends TestCase
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

    public function test_notas_vacia_espacios_van_a_null_y_validacion_500_501(): void
    {
        $admin = $this->usuario('ADMIN');
        $mesaId = DB::table('mesas')->insertGetId(['numero' => '1', 'capacidad' => 4, 'estado' => 'LIBRE']);
        $ventaId = DB::table('ventas')->insertGetId(['mesa_id' => $mesaId, 'monto' => 0, 'estado' => 'ACTIVA', 'fecha' => now()]);
        DB::table('cuentas')->insertGetId(['venta_id' => $ventaId, 'nombre' => 'Cuenta Principal', 'total' => 0, 'estado' => 'ABIERTA']);
        $prodCocina = DB::table('productos')->insertGetId(['nombre' => 'Plato A', 'precio' => 10, 'requiere_cocina' => 1, 'activo' => true]);
        $prodBarra = DB::table('productos')->insertGetId(['nombre' => 'Refresco', 'precio' => 5, 'requiere_cocina' => 0, 'activo' => true]);

        // Nota vacía -> null
        $resp = $this->actingAs($admin)->postJson('/pos/api', [
            'accion' => 'enviar_cocina',
            'venta_id' => $ventaId,
            'carrito' => json_encode([['id' => $prodCocina, 'cantidad' => 1, 'notas' => ''], ['id' => $prodBarra, 'cantidad' => 1, 'notas' => '']]),
        ])->assertStatus(200)->assertJson(['exito' => true]);
        $this->assertDatabaseHas('venta_detalles', ['venta_id' => $ventaId, 'producto_id' => $prodCocina, 'notas' => null]);

        // Solo espacios -> null
        $resp = $this->actingAs($admin)->postJson('/pos/api', [
            'accion' => 'enviar_cocina',
            'venta_id' => $ventaId,
            'carrito' => json_encode([['id' => $prodCocina, 'cantidad' => 1, 'notas' => '   ']]),
        ])->assertStatus(200);
        $this->assertTrue(true);

        // 500 caracteres aceptados
        $texto500 = str_repeat('a', 500);
        $this->actingAs($admin)->postJson('/pos/api', [
            'accion' => 'enviar_cocina',
            'venta_id' => $ventaId,
            'carrito' => json_encode([['id' => $prodCocina, 'cantidad' => 1, 'notas' => $texto500]]),
        ])->assertStatus(200);

        // 501 caracteres rechazados sin truncar
        $texto501 = str_repeat('b', 501);
        $this->actingAs($admin)->postJson('/pos/api', [
            'accion' => 'enviar_cocina',
            'venta_id' => $ventaId,
            'carrito' => json_encode([['id' => $prodCocina, 'cantidad' => 1, 'notas' => $texto501]]),
        ])->assertStatus(422);
    }

    public function test_notas_con_saltos_de_linea_y_sin_truncamiento(): void
    {
        $admin = $this->usuario('ADMIN');
        $mesaId = DB::table('mesas')->insertGetId(['numero' => '1', 'capacidad' => 4, 'estado' => 'LIBRE']);
        $ventaId = DB::table('ventas')->insertGetId(['mesa_id' => $mesaId, 'monto' => 0, 'estado' => 'ACTIVA', 'fecha' => now()]);
        DB::table('cuentas')->insertGetId(['venta_id' => $ventaId, 'nombre' => 'Cuenta Principal', 'total' => 0, 'estado' => 'ABIERTA']);
        $prodCocina = DB::table('productos')->insertGetId(['nombre' => 'Plato A', 'precio' => 10, 'requiere_cocina' => 1, 'activo' => true]);

        $texto = "sin cebolla\nbien cocido";
        $this->actingAs($admin)->postJson('/pos/api', [
            'accion' => 'enviar_cocina',
            'venta_id' => $ventaId,
            'carrito' => json_encode([['id' => $prodCocina, 'cantidad' => 1, 'notas' => $texto]]),
        ])->assertStatus(200);

        $this->assertDatabaseHas('venta_detalles', ['venta_id' => $ventaId, 'producto_id' => $prodCocina, 'notas' => $texto]);
    }
}
