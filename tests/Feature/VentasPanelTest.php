<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\CreaEsquemaCompleto;
use Tests\TestCase;

/*
 * Módulo de Ventas (`ventas.index`): cuentas pendientes de cobro e historial.
 *
 * - La autorización está en el backend (VentaController::panelVentas):
 *   ADMIN, GERENTE y CAJERO. MESERO y COCINERO reciben 403, aunque el
 *   enlace estuviera oculto o se manipulase la petición.
 * - El cobro NO se reimplementa: el botón "Cobrar" abre el POS acotado a esa
 *   cuenta (pos.index?cuenta=), reutilizando VentaController::pagarCuenta.
 *
 * IMPORTANTE:
 * - Corren en SQLite :memory: (configurado en phpunit.xml).
 * - El esquema se construye a mano porque las migraciones reales usan SQL de
 *   MySQL. Ninguna prueba toca "sistemadecaja".
 */
class VentasPanelTest extends TestCase
{
    use CreaEsquemaCompleto;

    protected function setUp(): void
    {
        parent::setUp();

        // Candado de seguridad: nunca apuntar a la base MySQL real.
        $this->assertSame('sqlite', DB::connection()->getDriverName(), 'Las pruebas deben ejecutarse en SQLite.');
        $this->assertSame(':memory:', (string) config('database.connections.sqlite.database'), 'La base de pruebas debe ser SQLite en memoria.');

        $this->crearEsquemaCompleto();
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

    private function crearMesa(string $numero): int
    {
        return DB::table('mesas')->insertGetId([
            'numero' => $numero,
            'capacidad' => 4,
            'estado' => 'OCUPADA',
        ]);
    }

    private function crearVenta(int $mesaId, string $estado, float $monto = 0, ?string $fecha = null): int
    {
        return DB::table('ventas')->insertGetId([
            'mesa_id' => $mesaId,
            'monto' => $monto,
            'estado' => $estado,
            'fecha' => $fecha ?? now(),
        ]);
    }

    /** Cuenta PAGADA de una venta + factura (ANULADA o EMITIDA). */
    private function cobroPara(int $venta, float $facturaTotal, array $ajustes = [], string $estadoFactura = 'EMITIDA', ?string $numero = null, $fecha = null): int
    {
        $emitido = $fecha ?? now();

        $cuentaId = DB::table('cuentas')->insertGetId([
            'venta_id' => $venta,
            'nombre' => 'Cuenta ' . uniqid(),
            'total' => $ajustes['consumo'] ?? $facturaTotal,
            'estado' => 'PAGADA',
            'metodo_pago' => 'EFECTIVO',
            'usuario_cobro' => $this->usuario('CAJERO')->id,
            'descuento' => $ajustes['descuento'] ?? 0,
            'propina' => $ajustes['propina'] ?? 0,
            'updated_at' => $emitido,
        ]);

        DB::table('facturas')->insert([
            'numero_factura' => $numero ?? ('FAC-' . str_pad((string) random_int(1, 999999), 4, '0', STR_PAD_LEFT)),
            'venta_id' => $venta,
            'cuenta_id' => $cuentaId,
            'total' => $facturaTotal,
            'estado' => $estadoFactura,
            'fecha_emision' => $emitido,
            'usuario_id' => $this->usuario('ADMIN')->id,
            'detalle_consumo' => json_encode(['nota' => 'test', 'ajustes' => $ajustes], JSON_UNESCAPED_UNICODE),
        ]);

        return $cuentaId;
    }

    private function crearCuenta(int $ventaId, string $nombre, string $estado = 'ABIERTA'): int
    {
        return DB::table('cuentas')->insertGetId([
            'venta_id' => $ventaId,
            'nombre' => $nombre,
            'total' => 0,
            'estado' => $estado,
        ]);
    }

    /** Simula consumo asignado a una cuenta (cuenta_detalles). */
    private function asignarConsumo(int $cuentaId, int $ventaId, float $subtotal, bool $eliminado = false): void
    {
        DB::table('cuenta_detalles')->insert([
            'cuenta_id' => $cuentaId,
            'venta_id' => $ventaId,
            'venta_detalle_id' => 0,
            'cantidad' => 1,
            'subtotal' => $subtotal,
            'eliminado' => $eliminado,
        ]);
    }

    /* ------------------------------------------------------------------
     | 1. Autorización del módulo financiero
     | ------------------------------------------------------------------ */

    public function test_admin_gerente_y_cajero_pueden_ver_el_modulo(): void
    {
        foreach (['ADMIN', 'GERENTE', 'CAJERO'] as $rol) {
            $this->actingAs($this->usuario($rol))
                ->get(route('ventas.index'))
                ->assertStatus(200);
        }
    }

    public function test_mesero_y_cocinero_reciben_403_en_el_modulo(): void
    {
        foreach (['MESERO', 'COCINERO'] as $rol) {
            $this->actingAs($this->usuario($rol))
                ->get(route('ventas.index'))
                ->assertStatus(403);
        }
    }

    public function test_el_modulo_exige_autenticacion(): void
    {
        $this->get(route('ventas.index'))->assertRedirect(route('login'));
    }

    /* ------------------------------------------------------------------
     | 2. Cuentas pendientes de cobro
     | ------------------------------------------------------------------ */

    public function test_lista_las_cuentas_pendientes_con_su_importe_real(): void
    {
        $mesa = $this->crearMesa('7');
        $venta = $this->crearVenta($mesa, 'ACTIVA');
        $cuenta = $this->crearCuenta($venta, 'Principal');
        $this->asignarConsumo($cuenta, $venta, 150.00);

        $this->actingAs($this->usuario('ADMIN'))
            ->get(route('ventas.index'))
            ->assertStatus(200)
            ->assertSee('Mesa 7')
            ->assertSee('150.00');
    }

    public function test_ignora_lineas_de_consumo_ya_anuladas(): void
    {
        $mesa = $this->crearMesa('4');
        $venta = $this->crearVenta($mesa, 'ACTIVA');
        $cuenta = $this->crearCuenta($venta, 'Principal');
        $this->asignarConsumo($cuenta, $venta, 120.00);
        $this->asignarConsumo($cuenta, $venta, 80.00, true); // anulada

        $this->actingAs($this->usuario('CAJERO'))
            ->get(route('ventas.index'))
            ->assertStatus(200)
            ->assertSee('120.00')
            ->assertDontSee('200.00');
    }

    public function test_no_lista_como_pendiente_una_cuenta_vacia(): void
    {
        $mesa = $this->crearMesa('3');
        $venta = $this->crearVenta($mesa, 'ACTIVA');
        $this->crearCuenta($venta, 'Principal'); // sin consumo asignado

        $html = $this->actingAs($this->usuario('ADMIN'))
            ->get(route('ventas.index'))
            ->assertStatus(200)
            ->getContent();

        $this->assertStringNotContainsString('Mesa 3', $html, 'Una cuenta sin consumo no es algo por cobrar.');
    }

    public function test_no_lista_como_pendiente_una_cuenta_ya_pagada(): void
    {
        $mesa = $this->crearMesa('9');
        $venta = $this->crearVenta($mesa, 'ACTIVA');
        $cuenta = $this->crearCuenta($venta, 'Principal', 'PAGADA');
        $this->asignarConsumo($cuenta, $venta, 200.00);

        $this->actingAs($this->usuario('CAJERO'))
            ->get(route('ventas.index'))
            ->assertStatus(200)
            ->assertDontSee('200.00');
    }

    public function test_no_ofrece_cobrar_una_cuenta_de_una_venta_ya_pagada(): void
    {
        // Defensa: aunque una cuenta hubiera quedado ABIERTA dentro de una
        // venta ya PAGADA, no debe ofrecerse de nuevo para cobro.
        $mesa = $this->crearMesa('11');
        $venta = $this->crearVenta($mesa, 'PAGADA', 999.00);
        $this->cobroPara($venta, 999.00, [
            'consumo' => 900.00, 'descuento' => 0.00, 'base' => 900.00,
            'propina' => 99.00, 'iva' => 0.00, 'total_factura' => 999.00, 'neto' => 999.00,
        ], 'EMITIDA', 'FAC-1111');
        $cuenta = $this->crearCuenta($venta, 'Principal');
        $this->asignarConsumo($cuenta, $venta, 300.00);

        $this->actingAs($this->usuario('ADMIN'))
            ->get(route('ventas.index'))
            ->assertStatus(200)
            ->assertDontSee('300.00')
            ->assertSee('999.00');
    }

    /* ------------------------------------------------------------------
     | 3. Historial de ventas cobradas
     | ------------------------------------------------------------------ */

    public function test_muestra_el_historial_de_ventas_pagadas(): void
    {
        $mesa = $this->crearMesa('5');
        $venta = $this->crearVenta($mesa, 'PAGADA', 450.00);
        $this->cobroPara($venta, 450.00, ['consumo' => 400.00, 'descuento' => 0, 'base' => 400.00, 'propina' => 50.00, 'iva' => 0, 'total_factura' => 450.00, 'neto' => 450.00]);
        $this->crearVenta($this->crearMesa('6'), 'ACTIVA', 700.00); // no pagada, sin cobro

        $this->actingAs($this->usuario('ADMIN'))
            ->get(route('ventas.index'))
            ->assertStatus(200)
            ->assertSee('Mesa 5')
            ->assertSee('450.00')
            ->assertDontSee('700.00');
    }

    /* ------------------------------------------------------------------
     | 4. Reutilización del cobro existente (sin duplicar lógica)
     | ------------------------------------------------------------------ */

    public function test_el_boton_cobrar_abre_el_pos_acotado_a_esa_cuenta(): void
    {
        $mesa = $this->crearMesa('8');
        $venta = $this->crearVenta($mesa, 'ACTIVA');
        $cuenta = $this->crearCuenta($venta, 'Principal');
        $this->asignarConsumo($cuenta, $venta, 80.00);

        $html = $this->actingAs($this->usuario('CAJERO'))
            ->get(route('ventas.index'))
            ->assertStatus(200)
            ->getContent();

        $this->assertStringContainsString(
            route('pos.index', $mesa).'?cuenta='.$cuenta,
            $html,
            'El cobro se reutiliza abriendo el POS acotado a la cuenta.'
        );
    }

    public function test_el_boton_cobrar_abre_el_pos_acotado_a_esa_cuenta_admin(): void
    {
        $mesa = $this->crearMesa('12');
        $venta = $this->crearVenta($mesa, 'ACTIVA');
        $cuenta = $this->crearCuenta($venta, 'Principal');
        $this->asignarConsumo($cuenta, $venta, 60.00);

        $html = $this->actingAs($this->usuario('ADMIN'))
            ->get(route('ventas.index'))
            ->assertStatus(200)
            ->getContent();

        $this->assertStringContainsString(route('pos.index', $mesa).'?cuenta='.$cuenta, $html);
    }

    /**
     * Regresión de la cuenta 46: el POS usa `numeroEstricto` y
     * `numeroPorcentaje` dentro de sus scripts inline (prefactura). Esas
     * funciones viven en `public/script.js`, por lo que el layout debe
     * cargar el archivo ANTES del contenido de la vista. Si vuelve a
     * colocarse al final del body, los scripts inline fallan con
     * "Uncaught ReferenceError: numeroEstricto is not defined".
     */
    public function test_el_layout_carga_script_js_antes_del_script_del_pos(): void
    {
        $mesa = $this->crearMesa('13');
        $venta = $this->crearVenta($mesa, 'ACTIVA');
        $cuenta = $this->crearCuenta($venta, 'Principal');
        $this->asignarConsumo($cuenta, $venta, 40.00);

        $html = $this->actingAs($this->usuario('ADMIN'))
            ->get(route('pos.index', $mesa).'?cuenta='.$cuenta)
            ->assertStatus(200)
            ->getContent();

        $posScript = strpos($html, 'function abrirPrefacturaModalCuenta');
        $cargaScript = strpos($html, 'script.js?v=');

        $this->assertNotFalse($cargaScript, 'El layout debe cargar public/script.js.');
        $this->assertNotFalse($posScript, 'El POS debe definir abrirPrefacturaModalCuenta.');
        $this->assertLessThan(
            $posScript,
            $cargaScript,
            'script.js debe cargarse antes del script inline que usa numeroEstricto/numeroPorcentaje.'
        );
    }

    /* ------------------------------------------------------------------
     | 5. Filtro Desde/Hasta del historial de ventas cobradas
     | ------------------------------------------------------------------ */

    public function test_filtro_una_fecha_especifica_muestra_solo_ese_dia(): void
    {
        $this->cobroPara($this->crearVenta($this->crearMesa('21'), 'PAGADA', 111.11, '2026-10-18 09:00:00'), 111.11, [
            'consumo' => 100.00, 'propina' => 11.11, 'total_factura' => 111.11, 'neto' => 111.11,
        ], 'EMITIDA', 'FAC-2101', '2026-10-18 09:00:00');
        $this->cobroPara($this->crearVenta($this->crearMesa('22'), 'PAGADA', 222.22, '2026-10-19 09:00:00'), 222.22, [
            'consumo' => 200.00, 'propina' => 22.22, 'total_factura' => 222.22, 'neto' => 222.22,
        ], 'EMITIDA', 'FAC-2102', '2026-10-19 09:00:00');
        $this->cobroPara($this->crearVenta($this->crearMesa('23'), 'PAGADA', 333.33, '2026-10-20 09:00:00'), 333.33, [
            'consumo' => 300.00, 'propina' => 33.33, 'total_factura' => 333.33, 'neto' => 333.33,
        ], 'EMITIDA', 'FAC-2103', '2026-10-20 09:00:00');

        $respuesta = $this->actingAs($this->usuario('ADMIN'))
            ->get(route('ventas.index', ['desde' => '2026-10-19', 'hasta' => '2026-10-19']));

        $respuesta->assertStatus(200)
            ->assertSee('222.22')
            ->assertDontSee('111.11')
            ->assertDontSee('333.33');
    }

    public function test_filtro_rango_de_varios_dias_incluye_ambos_extremos(): void
    {
        $this->cobroPara($this->crearVenta($this->crearMesa('31'), 'PAGADA', 411.11, '2026-10-17 12:00:00'), 411.11, [
            'consumo' => 400.00, 'propina' => 11.11, 'total_factura' => 411.11, 'neto' => 411.11,
        ], 'EMITIDA', 'FAC-3101', '2026-10-17 12:00:00');
        $this->cobroPara($this->crearVenta($this->crearMesa('32'), 'PAGADA', 422.22, '2026-10-18 12:00:00'), 422.22, [
            'consumo' => 400.00, 'propina' => 22.22, 'total_factura' => 422.22, 'neto' => 422.22,
        ], 'EMITIDA', 'FAC-3102', '2026-10-18 12:00:00');
        $this->cobroPara($this->crearVenta($this->crearMesa('33'), 'PAGADA', 433.33, '2026-10-19 12:00:00'), 433.33, [
            'consumo' => 400.00, 'propina' => 33.33, 'total_factura' => 433.33, 'neto' => 433.33,
        ], 'EMITIDA', 'FAC-3103', '2026-10-19 12:00:00');
        $this->cobroPara($this->crearVenta($this->crearMesa('34'), 'PAGADA', 444.44, '2026-10-21 12:00:00'), 444.44, [
            'consumo' => 400.00, 'propina' => 44.44, 'total_factura' => 444.44, 'neto' => 444.44,
        ], 'EMITIDA', 'FAC-3104', '2026-10-21 12:00:00');

        $respuesta = $this->actingAs($this->usuario('ADMIN'))
            ->get(route('ventas.index', ['desde' => '2026-10-18', 'hasta' => '2026-10-19']));

        $respuesta->assertStatus(200)
            ->assertSee('422.22')
            ->assertSee('433.33')
            ->assertDontSee('411.11')
            ->assertDontSee('444.44');
    }

    public function test_filtro_excluye_ventas_fuera_del_rango(): void
    {
        // Una venta ANTERIOR y otra POSTERIOR al período no deben salir.
        $this->cobroPara($this->crearVenta($this->crearMesa('41'), 'PAGADA', 555.55, '2026-10-01 12:00:00'), 555.55, [
            'consumo' => 500.00, 'propina' => 55.55, 'total_factura' => 555.55, 'neto' => 555.55,
        ], 'EMITIDA', 'FAC-4101', '2026-10-01 12:00:00');
        $this->cobroPara($this->crearVenta($this->crearMesa('42'), 'PAGADA', 666.66, '2026-10-31 12:00:00'), 666.66, [
            'consumo' => 600.00, 'propina' => 66.66, 'total_factura' => 666.66, 'neto' => 666.66,
        ], 'EMITIDA', 'FAC-4102', '2026-10-31 12:00:00');

        $respuesta = $this->actingAs($this->usuario('ADMIN'))
            ->get(route('ventas.index', ['desde' => '2026-10-10', 'hasta' => '2026-10-20']));

        $respuesta->assertStatus(200)
            ->assertDontSee('555.55')
            ->assertDontSee('666.66')
            ->assertSee('No hay ventas cobradas en el período');
    }

    public function test_incluye_una_venta_del_ultimo_dia_aunque_tenga_hora_tardia(): void
    {
        $this->cobroPara($this->crearVenta($this->crearMesa('51'), 'PAGADA', 777.77, '2026-10-20 23:59:59'), 777.77, [
            'consumo' => 700.00, 'propina' => 77.77, 'total_factura' => 777.77, 'neto' => 777.77,
        ], 'EMITIDA', 'FAC-5101', '2026-10-20 23:59:59');

        $this->actingAs($this->usuario('ADMIN'))
            ->get(route('ventas.index', ['desde' => '2026-10-20', 'hasta' => '2026-10-20']))
            ->assertStatus(200)
            ->assertSee('777.77');
    }

    public function test_totales_y_cantidad_coinciden_con_las_ventas_filtradas(): void
    {
        $this->cobroPara($this->crearVenta($this->crearMesa('61'), 'PAGADA', 1000.00, '2026-10-18 10:00:00'), 1000.00, [
            'consumo' => 900.00, 'propina' => 100.00, 'total_factura' => 1000.00, 'neto' => 1000.00,
        ], 'EMITIDA', 'FAC-6101', '2026-10-18 10:00:00');
        $this->cobroPara($this->crearVenta($this->crearMesa('62'), 'PAGADA', 500.50, '2026-10-19 10:00:00'), 500.50, [
            'consumo' => 450.00, 'propina' => 50.50, 'total_factura' => 500.50, 'neto' => 500.50,
        ], 'EMITIDA', 'FAC-6102', '2026-10-19 10:00:00');
        // Fuera del rango: no debe sumar.
        $this->cobroPara($this->crearVenta($this->crearMesa('63'), 'PAGADA', 9999.99, '2026-10-25 10:00:00'), 9999.99, [
            'consumo' => 9000.00, 'propina' => 999.99, 'total_factura' => 9999.99, 'neto' => 9999.99,
        ], 'EMITIDA', 'FAC-6103', '2026-10-25 10:00:00');

        $respuesta = $this->actingAs($this->usuario('ADMIN'))
            ->get(route('ventas.index', ['desde' => '2026-10-18', 'hasta' => '2026-10-19']));

        $respuesta->assertStatus(200)
            ->assertSee('1,500.50')     // total del rango (con separador de miles)
            ->assertSee('1,000.00')
            ->assertSee('500.50')
            ->assertDontSee('9,999.99');
    }

    public function test_rango_sin_ventas_muestra_estado_vacio_y_cero(): void
    {
        $this->crearVenta($this->crearMesa('71'), 'PAGADA', 100.00, '2026-10-18 10:00:00');

        $respuesta = $this->actingAs($this->usuario('ADMIN'))
            ->get(route('ventas.index', ['desde' => '2026-11-01', 'hasta' => '2026-11-30']));

        $respuesta->assertStatus(200)
            ->assertSee('No hay ventas cobradas en el período')
            ->assertSee('0.00');
    }

    public function test_desde_posterior_a_hasta_muestra_error(): void
    {
        $this->crearVenta($this->crearMesa('81'), 'PAGADA', 123.45, '2026-10-19 10:00:00');

        $respuesta = $this->actingAs($this->usuario('ADMIN'))
            ->get(route('ventas.index', ['desde' => '2026-10-20', 'hasta' => '2026-10-19']));

        $respuesta->assertStatus(200)
            ->assertSee('no puede ser posterior')
            ->assertDontSee('123.45');
    }

    public function test_fechas_invalidas_muestran_error(): void
    {
        $this->actingAs($this->usuario('ADMIN'))
            ->get(route('ventas.index', ['desde' => '2026-13-40', 'hasta' => '2026-10-19']))
            ->assertStatus(200)
            ->assertSee('no son válidas');

        $this->actingAs($this->usuario('CAJERO'))
            ->get(route('ventas.index', ['desde' => 'texto', 'hasta' => '2026-10-19']))
            ->assertStatus(200)
            ->assertSee('no son válidas');
    }

    public function test_el_formulario_conserva_los_filtros_enviados(): void
    {
        $respuesta = $this->actingAs($this->usuario('ADMIN'))
            ->get(route('ventas.index', ['desde' => '2026-10-18', 'hasta' => '2026-10-20']));

        $respuesta->assertStatus(200)
            ->assertSee('value="2026-10-18"', false)
            ->assertSee('value="2026-10-20"', false)
            ->assertSee('name="desde"', false)
            ->assertSee('name="hasta"', false)
            ->assertSee('Limpiar filtros');
    }

    public function test_los_permisos_siguen_igual_con_filtro(): void
    {
        $this->actingAs($this->usuario('MESERO'))
            ->get(route('ventas.index', ['desde' => '2026-10-18', 'hasta' => '2026-10-20']))
            ->assertStatus(403);

        $this->actingAs($this->usuario('COCINERO'))
            ->get(route('ventas.index', ['desde' => '2026-10-18', 'hasta' => '2026-10-20']))
            ->assertStatus(403);

        // ADMIN/GERENTE/CAJERO sí acceden con filtro.
        foreach (['ADMIN', 'GERENTE', 'CAJERO'] as $rol) {
            $this->actingAs($this->usuario($rol))
                ->get(route('ventas.index', ['desde' => '2026-10-18', 'hasta' => '2026-10-20']))
                ->assertStatus(200);
        }
    }

    public function test_sin_filtro_mantiene_el_comportamiento_previo(): void
    {
        // Sin parámetros no se aplica rango: se listan las cobradas recientes.
        $venta = $this->crearVenta($this->crearMesa('91'), 'PAGADA', 321.12, '2026-10-18 10:00:00');
        $this->cobroPara($venta, 321.12, [
            'consumo' => 290.00, 'propina' => 31.12, 'total_factura' => 321.12, 'neto' => 321.12,
        ], 'EMITIDA', 'FAC-9101', '2026-10-18 10:00:00');

        $this->actingAs($this->usuario('ADMIN'))
            ->get(route('ventas.index'))
            ->assertStatus(200)
            ->assertSee('Ventas cobradas')
            ->assertSee('321.12');
    }

    public function test_ventas_cobradas_hoy_con_fecha_negocio_anterior_aparecen(): void
    {
        $mesa = $this->crearMesa('901');
        $venta = $this->crearVenta($mesa, 'PAGADA', 495.00, '2026-11-09 16:54:00');

        // Cuenta PAGADA hoy (cobro hoy) pese a venta con fecha de negocio antigua.
        DB::table('cuentas')->insert([
            'venta_id' => $venta,
            'nombre' => 'Cuenta principal',
            'total' => 495.00,
            'estado' => 'PAGADA',
            'metodo_pago' => 'EFECTIVO',
            'usuario_cobro' => $this->usuario('ADMIN')->id,
            'updated_at' => now(),
        ]);
        $cuentaId = (int) DB::table('cuentas')->where('venta_id', $venta)->value('id');
        DB::table('facturas')->insert([
            'numero_factura' => 'FAC-4951',
            'venta_id' => $venta,
            'cuenta_id' => $cuentaId,
            'total' => 495.00,
            'estado' => 'EMITIDA',
            'fecha_emision' => now(),
            'usuario_id' => 1,
            'detalle_consumo' => json_encode(['ajustes' => ['consumo' => 450.00, 'descuento' => 0, 'base' => 450.00, 'propina' => 45.00, 'iva' => 0, 'total_factura' => 495.00, 'neto' => 495.00]]),
        ]);

        $this->actingAs($this->usuario('ADMIN'))
            ->get(route('ventas.index', ['desde' => now()->format('Y-m-d'), 'hasta' => now()->format('Y-m-d')]))
            ->assertStatus(200)
            ->assertSee('495.00');
    }

    public function test_muestra_facturas_anuladas_marcadas_y_no_duplica_total(): void
    {
        $mesa = $this->crearMesa('8');
        $venta = $this->crearVenta($mesa, 'PAGADA', 550.00);
        $cuenta = $this->crearCuenta($venta, 'Principal', 'PAGADA');

        DB::table('facturas')->insert([
            'numero_factura' => 'FAC-0900',
            'venta_id' => $venta,
            'cuenta_id' => $cuenta,
            'total' => 550.00,
            'estado' => 'ANULADA',
            'fecha_emision' => now(),
        ]);
        DB::table('facturas')->insert([
            'numero_factura' => 'FAC-0901',
            'venta_id' => $venta,
            'cuenta_id' => $cuenta,
            'total' => 550.00,
            'estado' => 'EMITIDA',
            'fecha_emision' => now(),
        ]);

        $html = $this->actingAs($this->usuario('ADMIN'))
            ->get(route('ventas.index'))
            ->assertStatus(200)
            ->getContent();

        $this->assertStringContainsString('FAC-0900', $html);
        $this->assertStringContainsString('ANULADA', $html);
        $this->assertStringContainsString('FAC-0901', $html);
        $this->assertStringContainsString('550.00', $html);
    }

    /* ------------------------------------------------------------------
     | Desglose del cobro: consumo, descuento, propina, impuestos
     | ------------------------------------------------------------------ */

    public function test_historial_muestra_consumo_propina_iva_y_total_global(): void
    {
        $venta = $this->crearVenta($this->crearMesa('71'), 'PAGADA', 999.00); // monto ≠ global
        $this->cobroPara($venta, 562.50, [
            'consumo' => 450.00,
            'descuento' => 0.00,
            'base' => 450.00,
            'propina' => 45.00,
            'iva' => 67.50,
            'retencion_dgi' => 0.00,
            'retencion_alcaldia' => 0.00,
            'total_retenciones' => 0.00,
            'total_factura' => 562.50,
            'neto' => 562.50,
        ], 'EMITIDA', 'FAC-7777');

        $html = $this->actingAs($this->usuario('ADMIN'))
            ->get(route('ventas.index'))
            ->assertStatus(200)
            ->getContent();

        $this->assertStringContainsString('FAC-7777', $html);
        $this->assertStringContainsString('450.00', $html);   // consumo
        $this->assertStringContainsString('45.00', $html);    // propina
        $this->assertStringContainsString('67.50', $html);    // IVA
        $this->assertStringContainsString('562.50', $html);   // total global real
        $this->assertStringContainsString('consumo C$', $html);
        // ventas.monto (999.00) NO debe mostrarse como el total
        $this->assertStringNotContainsString('999.00', $html);
    }

    public function test_total_global_no_suma_facturas_anuladas(): void
    {
        $venta = $this->crearVenta($this->crearMesa('72'), 'PAGADA', 600.00);
        // Recobro: la ANULADA queda tachada, no suma; la EMITIDA es la vigente.
        $this->cobroPara($venta, 600.00, [
            'consumo' => 500.00, 'descuento' => 0.00, 'base' => 500.00,
            'propina' => 100.00, 'iva' => 0.00, 'total_factura' => 600.00, 'neto' => 600.00,
        ], 'ANULADA', 'FAC-7001');
        $this->cobroPara($venta, 300.00, [
            'consumo' => 250.00, 'descuento' => 0.00, 'base' => 250.00,
            'propina' => 50.00, 'iva' => 0.00, 'total_factura' => 300.00, 'neto' => 300.00,
        ], 'EMITIDA', 'FAC-7002');

        $html = $this->actingAs($this->usuario('ADMIN'))
            ->get(route('ventas.index'))
            ->assertStatus(200)
            ->getContent();

        $this->assertStringContainsString('FAC-7001', $html);
        $this->assertStringContainsString('ANULADA', $html);
        $this->assertStringContainsString('FAC-7002', $html);
        $this->assertStringContainsString('300.00', $html);   // saldo vigente
        $this->assertStringNotContainsString('C$ 600.00</td>', $html); // la ANULADA no se suma en el pie
    }

    public function test_conceptos_desactivados_se_omiten_del_desglose(): void
    {
        $venta = $this->crearVenta($this->crearMesa('73'), 'PAGADA', 200.00);
        $this->cobroPara($venta, 450.00, [
            'consumo' => 400.00, 'descuento' => 0.00, 'base' => 400.00,
            'propina' => 50.00, 'iva' => 0.00, 'retencion_dgi' => 0.00, 'retencion_alcaldia' => 0.00,
            'total_retenciones' => 0.00, 'total_factura' => 450.00, 'neto' => 450.00,
        ], 'EMITIDA', 'FAC-7003');

        $html = $this->actingAs($this->usuario('ADMIN'))
            ->get(route('ventas.index'))
            ->assertStatus(200)
            ->getContent();

        // Con IVA y retenciones desactivadas, NO se imprimen en el desglose.
        $this->assertStringNotContainsString('IVA C$', $html);
        $this->assertStringNotContainsString('ret. DGI C$', $html);
        $this->assertStringNotContainsString('ret. alcaldía C$', $html);
        // Y el global sí refleja la propina: 400 + 50 = 450
        $this->assertStringContainsString('450.00', $html);
    }

    public function test_retenciones_se_muestran_y_se_descuentan_del_total_global(): void
    {
        $venta = $this->crearVenta($this->crearMesa('74'), 'PAGADA', 600.00);
        $this->cobroPara($venta, 702.00, [
            'consumo' => 600.00, 'descuento' => 0.00, 'base' => 600.00,
            'propina' => 30.00, 'iva' => 90.00,
            'retencion_dgi' => 12.00, 'retencion_alcaldia' => 6.00,
            'total_retenciones' => 18.00, 'total_factura' => 720.00, 'neto' => 702.00,
        ], 'EMITIDA', 'FAC-7004');

        $html = $this->actingAs($this->usuario('ADMIN'))
            ->get(route('ventas.index'))
            ->assertStatus(200)
            ->getContent();

        $this->assertStringContainsString('90.00', $html);   // IVA
        $this->assertStringContainsString('ret. DGI C$ 12.00', $html);
        $this->assertStringContainsString('ret. alcaldía C$ 6.00', $html);
        $this->assertStringContainsString('702.00', $html);  // neto real tras retenciones
        $this->assertStringNotContainsString('720.00', $html); // total_factura no cobrado
    }

    public function test_venta_pagada_sin_factura_vigente_muestra_guion_y_no_inventa_total(): void
    {
        $venta = $this->crearVenta($this->crearMesa('75'), 'PAGADA', 150.00);

        $html = $this->actingAs($this->usuario('ADMIN'))
            ->get(route('ventas.index'))
            ->assertStatus(200)
            ->getContent();

        $this->assertStringNotContainsString('C$ 150.00', $html); // ventas.monto NO se usa como total
        $this->assertStringNotContainsString('> 150.00 <', $html);
    }

    public function test_varios_cobros_en_la_misma_venta_se_suman_sin_duplicarse(): void
    {
        $venta = $this->crearVenta($this->crearMesa('76'), 'PAGADA', 333.33);
        $this->cobroPara($venta, 111.11, [
            'consumo' => 100.00, 'descuento' => 0.00, 'base' => 100.00,
            'propina' => 11.11, 'iva' => 0.00, 'total_factura' => 111.11, 'neto' => 111.11,
        ], 'EMITIDA', 'FAC-7100');
        $this->cobroPara($venta, 222.22, [
            'consumo' => 200.00, 'descuento' => 0.00, 'base' => 200.00,
            'propina' => 22.22, 'iva' => 0.00, 'total_factura' => 222.22, 'neto' => 222.22,
        ], 'EMITIDA', 'FAC-7101');

        $html = $this->actingAs($this->usuario('ADMIN'))
            ->get(route('ventas.index'))
            ->assertStatus(200)
            ->getContent();

        $this->assertStringContainsString('FAC-7100', $html);
        $this->assertStringContainsString('FAC-7101', $html);
        $this->assertStringContainsString('333.33', $html);   // 111.11 + 222.22
    }
}
