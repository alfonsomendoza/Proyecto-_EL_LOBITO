<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\CreaEsquemaCompleto;
use Tests\TestCase;

/*
 * Traslado INDIVIDUAL de consumo (POST /pos/cuentas/{id}/trasladar-consumo):
 * estados de la mesa destino, coherencia de ambas mesas, idempotencia,
 * rechazos sin cambios parciales y permisos validados en backend.
 *
 * Regresión principal: una mesa LIBRE con venta ACTIVA propia (POS abierto
 * sin pedido, o venta vaciada por un traslado anterior) era rechazada de
 * forma PERMANENTE con «La mesa destino cambió de estado. Vuelve a
 * intentarlo.», porque el guardia de concurrencia comparaba la existencia
 * de una venta en vez del estado real de la mesa. Ahora esa mesa se
 * acepta y la cuenta se une a su venta existente; los cambios de estado
 * reales siguen rechazándose con el mismo mensaje.
 *
 * SQLite :memory: (phpunit.xml). Nunca toca "sistemadecaja".
 */
class TrasladoConsumoDestinoTest extends TestCase
{
    use CreaEsquemaCompleto;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', (string) config('database.connections.sqlite.database'));

        $this->crearEsquemaCompleto();

        $this->admin = $this->usuario('ADMIN');
    }

    private function usuario(string $rolNombre): User
    {
        $rolId = DB::table('roles')->insertGetId(['nombre' => $rolNombre]);
        $usuarioId = DB::table('usuarios')->insertGetId([
            'nombre' => $rolNombre.' Test',
            'username' => strtolower($rolNombre).'_'.uniqid(),
            'password_hash' => bcrypt('secret'),
            'rol_id' => $rolId,
        ]);

        return User::findOrFail($usuarioId);
    }

    /**
     * Mesa OCUPADA con venta ACTIVA, una cuenta ABIERTA y UNA línea de
     * consumo (producto de barra: el traslado no depende de cocina).
     *
     * @return array<string,int>
     */
    private function mesaConConsumo(string $numero, string $producto, float $precio, int $cantidad, string $nombreCuenta): array
    {
        $mesa = DB::table('mesas')->insertGetId(['numero' => $numero, 'capacidad' => 4, 'estado' => 'OCUPADA']);
        $subtotal = round($precio * $cantidad, 2);

        $venta = DB::table('ventas')->insertGetId([
            'mesa_id' => $mesa, 'monto' => $subtotal, 'estado' => 'ACTIVA', 'fecha' => now(),
        ]);

        $prod = DB::table('productos')->insertGetId([
            'nombre' => $producto, 'precio' => $precio, 'requiere_cocina' => 0, 'activo' => true,
        ]);

        $vd = DB::table('venta_detalles')->insertGetId([
            'venta_id' => $venta, 'producto_id' => $prod, 'cantidad' => $cantidad,
            'precio_unitario' => $precio, 'subtotal' => $subtotal, 'comanda_id' => null,
            'estado' => 'ACTIVO', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $cuenta = DB::table('cuentas')->insertGetId([
            'venta_id' => $venta, 'nombre' => $nombreCuenta, 'total' => $subtotal,
            'estado' => 'ABIERTA', 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('cuenta_detalles')->insert([
            'cuenta_id' => $cuenta, 'venta_id' => $venta, 'venta_detalle_id' => $vd,
            'cantidad' => $cantidad, 'subtotal' => $subtotal,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['mesa' => $mesa, 'venta' => $venta, 'producto' => $prod, 'vd' => $vd, 'cuenta' => $cuenta];
    }

    private function trasladar(int $cuentaId, int $mesaDestino, ?string $clave = null, ?User $usuario = null)
    {
        $datos = ['mesa_destino_id' => $mesaDestino];

        if ($clave !== null) {
            $datos['clave_operacion'] = $clave;
        }

        return $this->actingAs($usuario ?? $this->admin)
            ->postJson('/pos/cuentas/'.$cuentaId.'/trasladar-consumo', $datos);
    }

    /* ------------------------------------------------------------------
     | A. Traslado individual: éxito y coherencia de estados
     | ------------------------------------------------------------------ */

    public function test_traslado_valido_cambia_la_mesa_sin_mensaje_de_error(): void
    {
        $origen = $this->mesaConConsumo('1', 'Plato fuerte', 100, 2, 'Ana');
        $destino = DB::table('mesas')->insertGetId(['numero' => '2', 'capacidad' => 4, 'estado' => 'LIBRE']);

        $resp = $this->trasladar($origen['cuenta'], $destino, 'clave-valida');
        $resp->assertStatus(200)->assertJson(['exito' => true]);
        // El mensaje de error NO aparece cuando el traslado es válido.
        $this->assertArrayNotHasKey('error', $resp->json());

        // Destino: venta creada, líneas asociadas a esa venta y mesa OCUPADA.
        $ventaDestino = DB::table('ventas')->where('mesa_id', $destino)->first();
        $this->assertNotNull($ventaDestino, 'Debe crearse la venta de la mesa destino.');
        $this->assertSame('OCUPADA', DB::table('mesas')->where('id', $destino)->value('estado'));

        $lineaDestino = DB::table('venta_detalles')->where('venta_id', $ventaDestino->id)->first();
        $this->assertNotNull($lineaDestino);
        $this->assertSame($origen['producto'], (int) $lineaDestino->producto_id);
        $this->assertSame(2, (int) $lineaDestino->cantidad);
        $this->assertSame($origen['vd'], (int) $lineaDestino->trasladado_desde_id);

        // La cuenta destino existe en la venta destino y tiene el consumo.
        $cuentaDestino = DB::table('cuentas')->where('venta_id', $ventaDestino->id)->where('nombre', 'Ana')->first();
        $this->assertNotNull($cuentaDestino);
        $this->assertSame(200.0, (float) $cuentaDestino->total);
        $this->assertSame('ABIERTA', $cuentaDestino->estado);

        // Cuenta origen vacía: CANCELADA (se oculta, no se borra).
        $this->assertSame('CANCELADA', DB::table('cuentas')->where('id', $origen['cuenta'])->value('estado'));

        // Origen sin consumo: queda LIBRE y su venta sigue ACTIVA con monto 0.
        $this->assertSame('LIBRE', DB::table('mesas')->where('id', $origen['mesa'])->value('estado'));
        $this->assertSame('ACTIVA', DB::table('ventas')->where('id', $origen['venta'])->value('estado'));
        $this->assertSame(0.0, (float) DB::table('ventas')->where('id', $origen['venta'])->value('monto'));

        // Trazabilidad del movimiento.
        $this->assertSame(1, DB::table('traslados_cuenta')->count());
        $this->assertSame(1, DB::table('traslado_detalles')->count());
    }

    /** El origen que aún conserva consumo NO se libera. */
    public function test_el_origen_consumo_restante_sigue_ocupado(): void
    {
        $origen = $this->mesaConConsumo('1', 'Plato', 60, 2, 'Ana');

        // Otra cuenta de la misma mesa conserva el plato 1 unidad.
        $cuentaBerta = DB::table('cuentas')->insertGetId([
            'venta_id' => $origen['venta'], 'nombre' => 'Berta', 'total' => 60,
            'estado' => 'ABIERTA', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('cuenta_detalles')->insert([
            'cuenta_id' => $cuentaBerta, 'venta_id' => $origen['venta'], 'venta_detalle_id' => $origen['vd'],
            'cantidad' => 1, 'subtotal' => 60, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('venta_detalles')->where('id', $origen['vd'])->update(['cantidad' => 3, 'subtotal' => 180.0]);
        DB::table('cuentas')->where('id', $origen['cuenta'])->update(['total' => 120.0]);
        DB::table('ventas')->where('id', $origen['venta'])->update(['monto' => 180.0]);

        $destino = DB::table('mesas')->insertGetId(['numero' => '2', 'capacidad' => 4, 'estado' => 'LIBRE']);

        $this->trasladar($origen['cuenta'], $destino, 'clave-parcial')->assertStatus(200);

        // Ana se fue con sus 2 unidades; a Berta le queda 1 => mesa OCUPADA.
        $this->assertSame('OCUPADA', DB::table('mesas')->where('id', $origen['mesa'])->value('estado'));
        $this->assertSame(1, (int) DB::table('venta_detalles')->where('id', $origen['vd'])->value('cantidad'));
        $this->assertSame('ABIERTA', DB::table('cuentas')->where('id', $cuentaBerta)->value('estado'));
        $this->assertSame(60.0, (float) DB::table('ventas')->where('id', $origen['venta'])->value('monto'));
    }

    public function test_mesa_destino_ocupada_con_venta_activa_recibe_la_cuenta_en_su_venta(): void
    {
        $origen = $this->mesaConConsumo('1', 'Plato', 50, 1, 'Ana');
        $destino = $this->mesaConConsumo('2', 'Ensalada', 40, 2, 'Luis'); // ya OCUPADA con venta

        $this->trasladar($origen['cuenta'], $destino['mesa'], 'clave-ocupada')
            ->assertStatus(200)
            ->assertJson(['exito' => true]);

        // No se crea otra venta: la cuenta viaja a la venta que ya tenía
        // la mesa destino y ambas conviven en ella.
        $this->assertSame(2, DB::table('ventas')->count());
        $this->assertSame(1, DB::table('cuentas')->where('venta_id', $destino['venta'])->where('nombre', 'Ana')->count());
        $this->assertSame(2, DB::table('venta_detalles')->where('venta_id', $destino['venta'])->count());
        // 80 de la ensalada que ya tenía + 50 del plato trasladado.
        $this->assertSame(130.0, (float) DB::table('ventas')->where('id', $destino['venta'])->value('monto'));
        $this->assertSame('OCUPADA', DB::table('mesas')->where('id', $destino['mesa'])->value('estado'));
    }

    /* ------------------------------------------------------------------
     | A. Regresión: mesa LIBRE con venta ACTIVA propia
     | ------------------------------------------------------------------ */

    /**
     * Caso 1: alguien abrió el POS en la mesa destino sin hacer un pedido
     * (venta ACTIVA, mesa LIBRE). Antes del arreglo esto devolvía
     * «La mesa destino cambió de estado. Vuelve a intentarlo.» siempre.
     */
    public function test_mesa_libre_con_venta_activa_por_pos_se_reutiliza_y_no_rechaza(): void
    {
        $origen = $this->mesaConConsumo('1', 'Plato', 50, 1, 'Ana');
        $destino = DB::table('mesas')->insertGetId(['numero' => '2', 'capacidad' => 4, 'estado' => 'LIBRE']);

        // El POS crea la venta vacía y la mesa sigue LIBRE.
        $this->actingAs($this->admin)->get('/pos/'.$destino)->assertStatus(200);
        $ventaPos = (int) DB::table('ventas')->where('mesa_id', $destino)->value('id');
        $this->assertGreaterThan(0, $ventaPos, 'El POS debe haber creado la venta de la mesa.');
        $this->assertSame('LIBRE', DB::table('mesas')->where('id', $destino)->value('estado'));

        $resp = $this->trasladar($origen['cuenta'], $destino, 'clave-pos');
        $this->assertSame(200, $resp->status(), 'Regresión no resuelta: '.(string) $resp->json('error'));
        $resp->assertJson(['exito' => true]);

        // NO se creó una segunda venta para la mesa destino.
        $this->assertSame(1, DB::table('ventas')->where('mesa_id', $destino)->count());
        $this->assertSame(2, DB::table('ventas')->count());

        // La cuenta trasladada quedó en LA venta que ya tenía la mesa.
        $this->assertSame(1, DB::table('cuentas')->where('venta_id', $ventaPos)->where('nombre', 'Ana')->count());
        $this->assertSame(1, DB::table('venta_detalles')->where('venta_id', $ventaPos)->count());
        $this->assertSame('OCUPADA', DB::table('mesas')->where('id', $destino)->value('estado'));
    }

    /**
     * Caso 2 (el más habitual): la mesa 1 quedó LIBRE con su venta ACTIVA
     * tras vaciarse por un traslado; recibir otra cuenta debe funcionar.
     */
    public function test_mesa_libre_con_venta_vaciada_por_otro_traslado_admite_entrar(): void
    {
        $mesa1 = $this->mesaConConsumo('1', 'Plato', 100, 2, 'Ana');
        $mesa3 = $this->mesaConConsumo('3', 'Postre', 60, 1, 'Berta');
        $mesa2 = DB::table('mesas')->insertGetId(['numero' => '2', 'capacidad' => 4, 'estado' => 'LIBRE']);

        // 1) Ana se va a la mesa 2: la mesa 1 queda LIBRE con venta ACTIVA vacía.
        $this->trasladar($mesa1['cuenta'], $mesa2, 'clave-ana')->assertStatus(200)->assertJson(['exito' => true]);
        $this->assertSame('LIBRE', DB::table('mesas')->where('id', $mesa1['mesa'])->value('estado'));
        $this->assertSame('ACTIVA', DB::table('ventas')->where('id', $mesa1['venta'])->value('estado'));

        $ventasAntes = DB::table('ventas')->count(); // 3

        // 2) Berta entra a la mesa 1: antes se rechazaba con el mensaje de
        //    «mesa destino cambió de estado» de forma permanente.
        $resp = $this->trasladar($mesa3['cuenta'], $mesa1['mesa'], 'clave-berta');
        $this->assertSame(200, $resp->status(), 'Regresión no resuelta: '.(string) $resp->json('error'));
        $resp->assertJson(['exito' => true]);

        // No se creó una cuarta venta: Berta entró a la venta de la mesa 1.
        $this->assertSame($ventasAntes, DB::table('ventas')->count());
        $this->assertSame('OCUPADA', DB::table('mesas')->where('id', $mesa1['mesa'])->value('estado'));

        $enMesa1 = DB::table('venta_detalles')
            ->where('venta_id', $mesa1['venta'])
            ->where('cantidad', '>', 0)
            ->get();
        $this->assertCount(1, $enMesa1, 'Solo el consumo de Berta queda activo en la mesa 1.');
        $this->assertSame($mesa3['producto'], (int) $enMesa1[0]->producto_id);
        $this->assertSame(60.0, (float) DB::table('ventas')->where('id', $mesa1['venta'])->value('monto'));

        $this->assertSame(1, DB::table('cuentas')->where('venta_id', $mesa1['venta'])->where('nombre', 'Berta')->count());
        $this->assertSame('CANCELADA', DB::table('cuentas')->where('id', $mesa3['cuenta'])->value('estado'));
    }

    /* ------------------------------------------------------------------
     | A. Rechazos válidos (sin cambios parciales)
     | ------------------------------------------------------------------ */

    public function test_mesa_destino_en_cobro_se_rechaza_sin_cambios(): void
    {
        $origen = $this->mesaConConsumo('1', 'Plato', 100, 2, 'Ana');
        $destino = DB::table('mesas')->insertGetId(['numero' => '2', 'capacidad' => 4, 'estado' => 'COBRANDO']);

        $resp = $this->trasladar($origen['cuenta'], $destino, 'clave-cobro');
        $resp->assertStatus(422)->assertJson(['exito' => false]);
        $this->assertStringContainsString('no admite traslados', (string) $resp->json('error'));

        // Nada cambió.
        $this->assertSame(1, DB::table('ventas')->count());
        $this->assertSame(1, DB::table('venta_detalles')->count());
        $this->assertSame(2, (int) DB::table('venta_detalles')->where('id', $origen['vd'])->value('cantidad'));
        $this->assertSame('OCUPADA', DB::table('mesas')->where('id', $origen['mesa'])->value('estado'));
        $this->assertSame('COBRANDO', DB::table('mesas')->where('id', $destino)->value('estado'));
        $this->assertSame('ABIERTA', DB::table('cuentas')->where('id', $origen['cuenta'])->value('estado'));
        $this->assertSame(200.0, (float) DB::table('cuentas')->where('id', $origen['cuenta'])->value('total'));
        $this->assertSame(0, DB::table('traslados_cuenta')->count());
        $this->assertSame(0, (int) DB::table('cuenta_detalles')->where('cuenta_id', $origen['cuenta'])->value('eliminado'));
    }

    public function test_mesa_destino_ocupada_sin_venta_activa_se_rechaza_sin_cambios(): void
    {
        $origen = $this->mesaConConsumo('1', 'Plato', 100, 1, 'Ana');
        // Estado incoherente de destino: OCUPADA pero sin venta activa.
        $destino = DB::table('mesas')->insertGetId(['numero' => '2', 'capacidad' => 4, 'estado' => 'OCUPADA']);

        $resp = $this->trasladar($origen['cuenta'], $destino, 'clave-incoherente');
        $resp->assertStatus(422)->assertJson(['exito' => false]);
        $this->assertStringContainsString('ocupada sin una venta activa', (string) $resp->json('error'));

        $this->assertSame(1, DB::table('ventas')->count());
        $this->assertSame('OCUPADA', DB::table('mesas')->where('id', $origen['mesa'])->value('estado'));
        $this->assertSame('ABIERTA', DB::table('cuentas')->where('id', $origen['cuenta'])->value('estado'));
        $this->assertSame(0, DB::table('traslados_cuenta')->count());
    }

    /* ------------------------------------------------------------------
     | C. Idempotencia y peticiones repetidas
     | ------------------------------------------------------------------ */

    public function test_repetir_la_misma_clave_no_duplica_el_traslado(): void
    {
        $origen = $this->mesaConConsumo('1', 'Plato', 80, 1, 'Ana');
        $destino = DB::table('mesas')->insertGetId(['numero' => '2', 'capacidad' => 4, 'estado' => 'LIBRE']);

        $primera = $this->trasladar($origen['cuenta'], $destino, 'clave-x');
        $primera->assertStatus(200)->assertJson(['exito' => true, 'idempotente' => false]);

        $ventasTrasPrimera = DB::table('ventas')->count();
        $detallesTrasPrimera = DB::table('venta_detalles')->count();
        $cuentasTrasPrimera = DB::table('cuentas')->count();

        // Misma clave (reenvío de la misma petición): no repite el movimiento.
        $segunda = $this->trasladar($origen['cuenta'], $destino, 'clave-x');
        $segunda->assertStatus(200)->assertJson(['exito' => true, 'idempotente' => true]);

        $this->assertSame(1, DB::table('traslados_cuenta')->count());
        $this->assertSame($ventasTrasPrimera, DB::table('ventas')->count());
        $this->assertSame($detallesTrasPrimera, DB::table('venta_detalles')->count());
        $this->assertSame($cuentasTrasPrimera, DB::table('cuentas')->count());
    }

    public function test_una_segunda_peticion_con_otra_clave_no_duplica_el_traslado(): void
    {
        $origen = $this->mesaConConsumo('1', 'Plato', 80, 1, 'Ana');
        $destino = DB::table('mesas')->insertGetId(['numero' => '2', 'capacidad' => 4, 'estado' => 'LIBRE']);

        $this->trasladar($origen['cuenta'], $destino, 'clave-1')->assertStatus(200);

        $ventas = DB::table('ventas')->count();
        $detalles = DB::table('venta_detalles')->count();
        $cuentas = DB::table('cuentas')->count();

        // Segundo clic con clave distinta: la cuenta origen ya quedó vacía
        // y CANCELADA, así que se rechaza sin tocar nada.
        $segunda = $this->trasladar($origen['cuenta'], $destino, 'clave-2');
        $segunda->assertStatus(422)->assertJson(['exito' => false]);
        $this->assertStringContainsString('ABIERTAS', (string) $segunda->json('error'));

        $this->assertSame($ventas, DB::table('ventas')->count());
        $this->assertSame($detalles, DB::table('venta_detalles')->count());
        $this->assertSame($cuentas, DB::table('cuentas')->count());
        $this->assertSame(1, DB::table('traslados_cuenta')->count());
    }

    /* ------------------------------------------------------------------
     | C. Permisos en backend
     | ------------------------------------------------------------------ */

    public function test_los_permisos_del_traslado_individual_siguen_aplicandose_en_backend(): void
    {
        foreach (['ADMIN', 'GERENTE', 'CAJERO'] as $rol) {
            $usuario = $this->usuario($rol);
            $origen = $this->mesaConConsumo('A'.$rol, 'Plato '.$rol, 10, 1, 'Cuenta '.$rol);
            $destino = DB::table('mesas')->insertGetId(['numero' => 'D'.$rol, 'capacidad' => 4, 'estado' => 'LIBRE']);

            $this->trasladar($origen['cuenta'], $destino, 'clave-'.$rol, $usuario)
                ->assertStatus(200)
                ->assertJson(['exito' => true]);
        }

        foreach (['MESERO', 'COCINERO'] as $rol) {
            $usuario = $this->usuario($rol);
            $origen = $this->mesaConConsumo('B'.$rol, 'Plato '.$rol, 10, 1, 'Cuenta '.$rol);
            $destino = DB::table('mesas')->insertGetId(['numero' => 'E'.$rol, 'capacidad' => 4, 'estado' => 'LIBRE']);
            $ventasAntes = DB::table('ventas')->count();

            $this->trasladar($origen['cuenta'], $destino, 'clave-'.$rol, $usuario)
                ->assertStatus(403)
                ->assertJson(['exito' => false]);

            // Sin cambios: la venta del destino ni siquiera se creó.
            $this->assertSame($ventasAntes, DB::table('ventas')->count());
            $this->assertSame('LIBRE', DB::table('mesas')->where('id', $destino)->value('estado'));
            $this->assertSame('ABIERTA', DB::table('cuentas')->where('id', $origen['cuenta'])->value('estado'));
        }
    }
}
