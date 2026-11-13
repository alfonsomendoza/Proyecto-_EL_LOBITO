<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\CreaEsquemaCompleto;
use Tests\TestCase;

/*
 * BLOQUEO DE COBRO POR COCINA
 * ===========================
 *
 * Regla de negocio verificada aquí:
 *
 *   No se puede cobrar una cuenta mientras exista ALGÚN producto válido de
 *   sus comandas que Cocina no haya marcado como TERMINADO (listo = LISTO en
 *   `venta_detalles.estado_preparacion`).
 *
 *  - Bloquea con productos PENDIENTE o EN_PREPARACION (y cualquier estado
 *    distinto de LISTO).
 *  - Permite cobrar cuando todos los productos de cocina están en LISTO.
 *  - Productos anulados/eliminados o comandas anuladas/canceladas NO
 *    bloquean (son productos legítimamente fuera del flujo).
 *  - La validación ocurre en el backend dentro de la transacción del cobro:
 *    una petición directa recibe 422 + la lista de productos bloqueantes.
 *  - ADMIN y GERENTE tampoco pueden saltarse la regla (sin excepción).
 *  - La interfaz (POS) recibe los mismos datos (`paso` COCINA_PENDIENTES y
 *    el 409/422 con `productos_pendientes`).
 *
 * Corren en SQLite :memory: (phpunit.xml); nunca tocan MySQL ni "sistemadecaja".
 */
class CocinaCobroBlockTest extends TestCase
{
    use CreaEsquemaCompleto;

    protected function setUp(): void
    {
        parent::setUp();

        // Candado de seguridad: nunca apuntar a la base MySQL real.
        $this->assertSame('sqlite', DB::connection()->getDriverName(), 'Las pruebas deben ejecutarse en SQLite.');
        $this->assertSame(':memory:', (string) config('database.connections.sqlite.database'), 'La base de pruebas debe ser SQLite en memoria.');

        $this->crearEsquemaCompleto();
        $this->configurar('cocina_digital_habilitada', true);
    }

    /* ------------------------------------------------------------------
     | Utilidades
     | ------------------------------------------------------------------ */

    private function configurar(string $clave, bool $valor): void
    {
        DB::table('configuraciones')->updateOrInsert(
            ['clave' => $clave],
            ['valor' => $valor ? '1' : '0', 'created_at' => now(), 'updated_at' => now()]
        );
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

    /**
     * Mesa OCUPADA + venta ACTIVA + comanda + UNA cuenta ABIERTA con un
     * producto de cocina (o barra si $requiereCocina = false).
     *
     * @param  array{requiere_cocina?: bool, estado_preparacion?: ?string, estado_linea?: string, estado_comanda?: string, detalle_eliminado?: bool, cantidad?: int}  $opciones
     */
    private function montarCuentaCocina(string $rolCobrador, array $opciones = []): array
    {
        $requiereCocina = $opciones['requiere_cocina'] ?? true;
        $preparacion = array_key_exists('estado_preparacion', $opciones)
            ? $opciones['estado_preparacion']
            : ($requiereCocina ? 'PENDIENTE' : null);
        $estadoLinea = $opciones['estado_linea'] ?? 'ACTIVO';
        $estadoComanda = $opciones['estado_comanda'] ?? 'ABIERTA';
        $detalleEliminado = (bool) ($opciones['detalle_eliminado'] ?? false);
        $cantidad = (int) ($opciones['cantidad'] ?? 2);

        $cobrador = $this->usuario($rolCobrador);

        $mesaId = DB::table('mesas')->insertGetId([
            'numero' => 'Mesa Cocina '.uniqid(),
            'capacidad' => 4,
            'estado' => 'OCUPADA',
        ]);

        $ventaId = DB::table('ventas')->insertGetId([
            'mesa_id' => $mesaId,
            'descripcion' => 'Mesa con cocina',
            'monto' => $cantidad * 100,
            'estado' => 'ACTIVA',
            'usuario_id' => $cobrador->id,
            'fecha' => now(),
        ]);

        $productoId = DB::table('productos')->insertGetId([
            'nombre' => 'Arrachera de prueba',
            'precio' => 100,
            'requiere_cocina' => $requiereCocina,
            'controla_stock' => 0,
            'activo' => 1,
        ]);

        $cuentaId = DB::table('cuentas')->insertGetId([
            'venta_id' => $ventaId,
            'nombre' => 'Cuenta principal',
            'total' => $cantidad * 100,
            'estado' => 'ABIERTA',
        ]);

        $comandaId = null;
        if ($requiereCocina) {
            $comandaId = DB::table('comandas')->insertGetId([
                'venta_id' => $ventaId,
                'mesa_id' => $mesaId,
                'numero_ticket' => 1,
                'usuario_id' => $cobrador->id,
                'estado' => $estadoComanda,
                'total' => $cantidad * 100,
                'creado_en' => now(),
            ]);
        }

        $detalleId = DB::table('venta_detalles')->insertGetId([
            'venta_id' => $ventaId,
            'producto_id' => $productoId,
            'cantidad' => $cantidad,
            'precio_unitario' => 100,
            'subtotal' => $cantidad * 100,
            'comanda_id' => $comandaId,
            'cuenta_id' => $cuentaId,
            'estado' => $estadoLinea,
            'estado_preparacion' => $preparacion,
        ]);

        DB::table('cuenta_detalles')->insert([
            'cuenta_id' => $cuentaId,
            'venta_id' => $ventaId,
            'venta_detalle_id' => $detalleId,
            'cantidad' => $cantidad,
            'subtotal' => $cantidad * 100,
            'eliminado' => $detalleEliminado,
        ]);

        return [
            'mesa' => $mesaId,
            'venta' => $ventaId,
            'cuenta' => $cuentaId,
            'detalle' => $detalleId,
            'comanda' => $comandaId,
            'producto_nombre' => 'Arrachera de prueba',
            'cobrador' => $cobrador,
        ];
    }

    private function intentarCobro(User $usuario, int $cuentaId)
    {
        return $this->actingAs($usuario)->postJson(route('pos.cuentas.pagar', $cuentaId), [
            'pagos' => [[
                'clave_operacion' => (string) Str::uuid(),
                'metodo_pago' => 'EFECTIVO_CORDOBA',
                'monto' => 200,
            ]],
        ]);
    }

    /* ------------------------------------------------------------------
     | Bloqueo: PENDIENTE / EN_PREPARACION / cualquier estado != LISTO
     | ------------------------------------------------------------------ */

    public function test_cobro_bloqueado_con_producto_pendiente(): void
    {
        $escena = $this->montarCuentaCocina('CAJERO', ['estado_preparacion' => 'PENDIENTE']);

        $respuesta = $this->intentarCobro($escena['cobrador'], $escena['cuenta']);

        $respuesta->assertStatus(422)
            ->assertJsonPath('exito', false)
            ->assertJsonPath('productos_pendientes.0.producto', 'Arrachera de prueba')
            ->assertJsonPath('productos_pendientes.0.cantidad', 2)
            ->assertJsonPath('productos_pendientes.0.estado', 'PENDIENTE');

        $this->assertStringContainsString('Arrachera de prueba', $respuesta->json('error'));
        $this->assertStringContainsString('TERMINADO', $respuesta->json('error'));
        $this->assertSame('ABIERTA', DB::table('cuentas')->find($escena['cuenta'])->estado);
        $this->assertSame(0, DB::table('pagos_cuenta')->count());
        $this->assertSame(0, DB::table('facturas')->count());
    }

    public function test_cobro_bloqueado_con_producto_en_preparacion(): void
    {
        $escena = $this->montarCuentaCocina('CAJERO', ['estado_preparacion' => 'EN_PREPARACION']);

        $respuesta = $this->intentarCobro($escena['cobrador'], $escena['cuenta']);

        $respuesta->assertStatus(422)
            ->assertJsonPath('exito', false)
            ->assertJsonPath('productos_pendientes.0.estado', 'EN_PREPARACION');

        $this->assertSame('ABIERTA', DB::table('cuentas')->find($escena['cuenta'])->estado);
        $this->assertSame(0, DB::table('pagos_cuenta')->count());
    }

    public function test_cobro_permitido_cuando_todos_los_productos_estan_terminados(): void
    {
        $escena = $this->montarCuentaCocina('CAJERO', ['estado_preparacion' => 'LISTO']);

        $respuesta = $this->intentarCobro($escena['cobrador'], $escena['cuenta']);

        $respuesta->assertStatus(200)->assertJsonPath('exito', true);
        $this->assertSame('PAGADA', DB::table('cuentas')->find($escena['cuenta'])->estado);
        $this->assertSame(1, DB::table('facturas')->count());
    }

    /* ------------------------------------------------------------------
     | Productos legítimamente fuera del flujo NO bloquean
     | ------------------------------------------------------------------ */

    public function test_producto_anulado_no_bloquea_el_cobro(): void
    {
        // La línea ya está ANULADA (anularDetalle): ya no es producción válida.
        $escena = $this->montarCuentaCocina('CAJERO', [
            'estado_preparacion' => 'PENDIENTE',
            'estado_linea' => 'ANULADO',
        ]);

        $this->intentarCobro($escena['cobrador'], $escena['cuenta'])
            ->assertStatus(200)
            ->assertJsonPath('exito', true);
    }

    public function test_detalle_eliminado_no_bloquea_el_cobro(): void
    {
        // El detalle de cuenta fue eliminado (borrado lógico): no hay unidades pendientes.
        $escena = $this->montarCuentaCocina('CAJERO', [
            'estado_preparacion' => 'PENDIENTE',
            'detalle_eliminado' => true,
        ]);

        // Sin consumo no se puede cobrar, pero por NO TENER CONSUMO, no por cocina:
        $respuesta = $this->intentarCobro($escena['cobrador'], $escena['cuenta']);
        $respuesta->assertStatus(422);
        $this->assertStringNotContainsString('TERMINADO', $respuesta->json('error'));
    }

    public function test_comanda_anulada_no_bloquea_el_cobro(): void
    {
        // La comanda fue anulada: sus productos ya no se sirven.
        $escena = $this->montarCuentaCocina('CAJERO', [
            'estado_preparacion' => 'PENDIENTE',
            'estado_comanda' => 'ANULADA',
        ]);

        $this->intentarCobro($escena['cobrador'], $escena['cuenta'])
            ->assertStatus(200)
            ->assertJsonPath('exito', true);
    }

    public function test_producto_de_barra_no_bloquea(): void
    {
        $escena = $this->montarCuentaCocina('CAJERO', [
            'requiere_cocina' => false,
            'estado_preparacion' => null,
        ]);

        $this->intentarCobro($escena['cobrador'], $escena['cuenta'])
            ->assertStatus(200)
            ->assertJsonPath('exito', true);
    }

    /* ------------------------------------------------------------------
     | Backend: sin omitir por rol (ADMIN / GERENTE tampoco pueden)
     | ------------------------------------------------------------------ */

    public function test_admin_no_puede_omitir_el_bloqueo(): void
    {
        $escena = $this->montarCuentaCocina('ADMIN', ['estado_preparacion' => 'EN_PREPARACION']);

        $respuesta = $this->intentarCobro($escena['cobrador'], $escena['cuenta']);

        $respuesta->assertStatus(422)->assertJsonPath('exito', false);
        $this->assertSame('ABIERTA', DB::table('cuentas')->find($escena['cuenta'])->estado);
    }

    public function test_gerente_no_puede_omitir_el_bloqueo(): void
    {
        $escena = $this->montarCuentaCocina('GERENTE', ['estado_preparacion' => 'PENDIENTE']);

        $respuesta = $this->intentarCobro($escena['cobrador'], $escena['cuenta']);

        $respuesta->assertStatus(422)->assertJsonPath('exito', false);
        $this->assertSame('ABIERTA', DB::table('cuentas')->find($escena['cuenta'])->estado);
    }

    /* ------------------------------------------------------------------
     | Flujo completo: PENDIENTE -> cocinando -> terminado -> cobrar
     | ------------------------------------------------------------------ */

    public function test_e2e_cocina_transiciona_y_el_cobro_se_habilita(): void
    {
        $escena = $this->montarCuentaCocina('CAJERO', ['estado_preparacion' => 'PENDIENTE']);
        $cocinero = $this->usuario('COCINERO');

        // 1. Cocina acepta la comanda: PENDIENTE -> EN_PREPARACION
        $this->actingAs($cocinero)
            ->postJson(route('cocina.api.estado', $escena['detalle']), ['estado' => 'EN_PREPARACION'])
            ->assertStatus(200)
            ->assertJsonPath('exito', true);

        $this->intentarCobro($escena['cobrador'], $escena['cuenta'])
            ->assertStatus(422)
            ->assertJsonPath('productos_pendientes.0.estado', 'EN_PREPARACION');

        // 2. Cocina entrega el plato: EN_PREPARACION -> LISTO
        $this->actingAs($cocinero)
            ->postJson(route('cocina.api.estado', $escena['detalle']), ['estado' => 'LISTO'])
            ->assertStatus(200)
            ->assertJsonPath('exito', true);

        // 3. Ahora sí se puede cobrar
        $this->intentarCobro($escena['cobrador'], $escena['cuenta'])
            ->assertStatus(200)
            ->assertJsonPath('exito', true);
    }

    /* ------------------------------------------------------------------
     | Interfaz: el POS recibe los productos bloqueantes
     | ------------------------------------------------------------------ */

    public function test_el_pos_expone_los_productos_que_bloquean(): void
    {
        $escena = $this->montarCuentaCocina('CAJERO', ['estado_preparacion' => 'PENDIENTE']);

        $html = $this->actingAs($escena['cobrador'])
            ->get(route('pos.index', ['id' => $escena['mesa']]) . '?cuenta=' . $escena['cuenta'])
            ->assertStatus(200)
            ->getContent();

        // El mapa COCINA_PENDIENTES y la lista están embebidos en el POS:
        $this->assertStringContainsString('COCINA_PENDIENTES', $html);
        $this->assertStringContainsString('Arrachera de prueba', $html);
        $this->assertStringContainsString('aviso-cocina', $html);
        $this->assertStringContainsString('cocinaPendienteDe', $html);
    }

    public function test_el_pos_no_reporta_pendientes_cuando_todo_termino(): void
    {
        $escena = $this->montarCuentaCocina('CAJERO', ['estado_preparacion' => 'LISTO']);

        $html = $this->actingAs($escena['cobrador'])
            ->get(route('pos.index', ['id' => $escena['mesa']]) . '?cuenta=' . $escena['cuenta'])
            ->assertStatus(200)
            ->getContent();

        $this->assertStringContainsString('COCINA_PENDIENTES', $html);
        $this->assertStringNotContainsString('"estado":"PENDIENTE"', $html);
        $this->assertStringNotContainsString('"estado":"EN_PREPARACION"', $html);
    }
}
