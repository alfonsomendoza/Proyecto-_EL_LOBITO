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
        $this->crearVenta($mesa, 'PAGADA', 450.00);
        $this->crearVenta($this->crearMesa('6'), 'ACTIVA', 700.00); // no pagada

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
        $this->crearVenta($this->crearMesa('21'), 'PAGADA', 111.11, '2026-10-18 09:00:00');
        $this->crearVenta($this->crearMesa('22'), 'PAGADA', 222.22, '2026-10-19 09:00:00');
        $this->crearVenta($this->crearMesa('23'), 'PAGADA', 333.33, '2026-10-20 09:00:00');

        $respuesta = $this->actingAs($this->usuario('ADMIN'))
            ->get(route('ventas.index', ['desde' => '2026-10-19', 'hasta' => '2026-10-19']));

        $respuesta->assertStatus(200)
            ->assertSee('222.22')
            ->assertDontSee('111.11')
            ->assertDontSee('333.33');
    }

    public function test_filtro_rango_de_varios_dias_incluye_ambos_extremos(): void
    {
        $this->crearVenta($this->crearMesa('31'), 'PAGADA', 411.11, '2026-10-17 12:00:00');
        $this->crearVenta($this->crearMesa('32'), 'PAGADA', 422.22, '2026-10-18 12:00:00');
        $this->crearVenta($this->crearMesa('33'), 'PAGADA', 433.33, '2026-10-19 12:00:00');
        $this->crearVenta($this->crearMesa('34'), 'PAGADA', 444.44, '2026-10-21 12:00:00');

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
        $this->crearVenta($this->crearMesa('41'), 'PAGADA', 555.55, '2026-10-01 12:00:00');
        $this->crearVenta($this->crearMesa('42'), 'PAGADA', 666.66, '2026-10-31 12:00:00');

        $respuesta = $this->actingAs($this->usuario('ADMIN'))
            ->get(route('ventas.index', ['desde' => '2026-10-10', 'hasta' => '2026-10-20']));

        $respuesta->assertStatus(200)
            ->assertDontSee('555.55')
            ->assertDontSee('666.66')
            ->assertSee('No hay ventas cobradas en el período');
    }

    public function test_incluye_una_venta_del_ultimo_dia_aunque_tenga_hora_tardia(): void
    {
        $this->crearVenta($this->crearMesa('51'), 'PAGADA', 777.77, '2026-10-20 23:59:59');

        $this->actingAs($this->usuario('ADMIN'))
            ->get(route('ventas.index', ['desde' => '2026-10-20', 'hasta' => '2026-10-20']))
            ->assertStatus(200)
            ->assertSee('777.77');
    }

    public function test_totales_y_cantidad_coinciden_con_las_ventas_filtradas(): void
    {
        $this->crearVenta($this->crearMesa('61'), 'PAGADA', 1000.00, '2026-10-18 10:00:00');
        $this->crearVenta($this->crearMesa('62'), 'PAGADA', 500.50, '2026-10-19 10:00:00');
        // Fuera del rango: no debe sumar.
        $this->crearVenta($this->crearMesa('63'), 'PAGADA', 9999.99, '2026-10-25 10:00:00');

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
        $this->crearVenta($this->crearMesa('91'), 'PAGADA', 321.12, '2026-10-18 10:00:00');

        $this->actingAs($this->usuario('ADMIN'))
            ->get(route('ventas.index'))
            ->assertStatus(200)
            ->assertSee('Ventas cobradas')
            ->assertSee('321.12');
    }
}
