<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\CreaEsquemaCompleto;
use Tests\TestCase;

/*
 * Pruebas de IMPRESIÓN de la prefactura (VentaController::prefactura).
 *
 * IMPORTANTE:
 * - Corren en SQLite :memory: (phpunit.xml); nunca tocan la base real.
 * - El esquema se construye con el trait compartido CreaEsquemaCompleto.
 *
 * Reglas comprobadas:
 * - La prefactura NO muestra las leyendas retiradas
 *   («PREFACTURA — NO ES FACTURA FISCAL DEFINITIVA» y
 *   «Documento de consulta; no valida el pago.»).
 * - Conserva identificación (mesa, cuenta), productos, cantidades,
 *   importes y el total por cobrar.
 * - COCINERO recibe 403 (no ve información financiera).
 * - La ruta es de SOLO LECTURA: no cambia el estado de la cuenta.
 */
class PrefacturaImpresionTest extends TestCase
{
    use CreaEsquemaCompleto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('sqlite', DB::connection()->getDriverName(), 'Las pruebas deben ejecutarse en SQLite.');
        $this->assertSame(':memory:', (string) config('database.connections.sqlite.database'), 'La base de pruebas debe ser SQLite en memoria.');

        $this->crearEsquemaCompleto();
    }

    /* ------------------------------------------------------------------
     | Utilidades
     * ------------------------------------------------------------------ */

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

    /** Mesa con venta activa, una cuenta y consumo de C$ 200 (2 × C$ 100). */
    private function montarMesa(User $usuario): array
    {
        $mesaId = DB::table('mesas')->insertGetId([
            'numero' => 'Mesa 7',
            'capacidad' => 4,
            'estado' => 'OCUPADA',
        ]);

        $ventaId = DB::table('ventas')->insertGetId([
            'mesa_id' => $mesaId,
            'descripcion' => 'Mesa 7',
            'monto' => 200,
            'estado' => 'ACTIVA',
            'usuario_id' => $usuario->id,
            'fecha' => now(),
        ]);

        $productoId = DB::table('productos')->insertGetId([
            'nombre' => 'Gallo Pinto',
            'precio' => 100,
            'requiere_cocina' => 0,
            'controla_stock' => 0,
            'activo' => 1,
        ]);

        $detalleId = DB::table('venta_detalles')->insertGetId([
            'venta_id' => $ventaId,
            'producto_id' => $productoId,
            'cantidad' => 2,
            'precio_unitario' => 100,
            'subtotal' => 200,
            'estado' => 'ACTIVO',
        ]);

        $cuentaId = DB::table('cuentas')->insertGetId([
            'venta_id' => $ventaId,
            'nombre' => 'Cuenta 1',
            'total' => 200,
            'estado' => 'ABIERTA',
        ]);

        DB::table('cuenta_detalles')->insert([
            'cuenta_id' => $cuentaId,
            'venta_id' => $ventaId,
            'venta_detalle_id' => $detalleId,
            'cantidad' => 2,
            'subtotal' => 200,
            'eliminado' => false,
        ]);

        return ['mesa' => $mesaId, 'venta' => $ventaId, 'cuenta' => $cuentaId];
    }

    /* ------------------------------------------------------------------
     | Contenido impreso
     * ------------------------------------------------------------------ */

    public function test_prefactura_no_incluye_las_leyendas_retiradas(): void
    {
        $cajero = $this->usuario('CAJERO');
        $escena = $this->montarMesa($cajero);

        $respuesta = $this->actingAs($cajero)
            ->get(route('pos.cuentas.prefactura', ['cuentaId' => $escena['cuenta']]));

        $respuesta->assertStatus(200);

        // Las dos leyendas retiradas NO deben aparecer en la impresión.
        $this->assertStringNotContainsString(
            'PREFACTURA — NO ES FACTURA FISCAL DEFINITIVA',
            $respuesta->getContent()
        );
        $this->assertStringNotContainsString(
            'Documento de consulta; no valida el pago.',
            $respuesta->getContent()
        );
    }

    public function test_prefactura_conserva_datos_productos_e_importes(): void
    {
        $cajero = $this->usuario('CAJERO');
        $escena = $this->montarMesa($cajero);

        $this->actingAs($cajero)
            ->get(route('pos.cuentas.prefactura', ['cuentaId' => $escena['cuenta']]))
            ->assertStatus(200)
            // Identificación de la cuenta.
            ->assertSee('PREFACTURA')
            ->assertSee('Mesa 7')
            ->assertSee('Cuenta 1')
            // Producto, cantidad e importes.
            ->assertSee('Gallo Pinto')
            ->assertSee('200.00');
    }

    public function test_prefactura_no_modifica_la_cuenta_ni_la_venta(): void
    {
        $cajero = $this->usuario('CAJERO');
        $escena = $this->montarMesa($cajero);

        $this->actingAs($cajero)
            ->get(route('pos.cuentas.prefactura', ['cuentaId' => $escena['cuenta']]))
            ->assertStatus(200);

        // SOLO LECTURA: nada de lo impreso cambia estados ni registra pagos.
        $this->assertSame('ABIERTA', DB::table('cuentas')->find($escena['cuenta'])->estado);
        $this->assertSame('ACTIVA', DB::table('ventas')->find($escena['venta'])->estado);
        $this->assertSame('OCUPADA', DB::table('mesas')->find($escena['mesa'])->estado);
        $this->assertSame(0, DB::table('pagos_cuenta')->count());
        $this->assertSame(0, DB::table('facturas')->count());
    }

    /* ------------------------------------------------------------------
     | Permisos
     * ------------------------------------------------------------------ */

    public function test_cocinero_no_puede_imprimir_la_prefactura(): void
    {
        $cocinero = $this->usuario('COCINERO');
        $cajero = $this->usuario('CAJERO');
        $escena = $this->montarMesa($cajero);

        $this->actingAs($cocinero)
            ->get(route('pos.cuentas.prefactura', ['cuentaId' => $escena['cuenta']]))
            ->assertStatus(403);
    }
}
