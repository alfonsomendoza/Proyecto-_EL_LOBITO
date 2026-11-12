<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\CreaEsquemaCompleto;
use Tests\TestCase;

/*
 * LÍNEAS DE PAGO DEL COBRO (pagos mixtos)
 * =======================================
 *
 * Regresión del cobro con VARIAS líneas de pago. El problema original era de
 * interfaz (el botón «+ Agregar línea de pago» había perdido su listener en un
 * refactor de pos.blade.php), pero el servidor también debe garantizar:
 *
 *  - Registrar TODAS las líneas enviadas (efectivo, tarjeta, dólares, etc.).
 *  - Calcular en el servidor los córdobas de cada línea (nunca el navegador).
 *  - Sumar bien el total, el importe recibido y el cambio.
 *  - Rechazar métodos/importes inválidos sin registrar pagos parciales.
 *  - No duplicar pagos al reintentar (clave de operación idempotente).
 *  - Dejar cuenta, venta y mesa en el estado correcto solo si el cobro cubre
 *    el total.
 *
 * IMPORTANTE: corren en SQLite :memory: (phpunit.xml); nunca tocan MySQL.
 */
class PagosLineasCobroTest extends TestCase
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

    /**
     * Mesa OCUPADA + venta ACTIVA + una cuenta ABIERTA con el consumo indicado.
     * El consumo se guarda en cuenta_detalles (fuente de verdad del cobro).
     */
    private function montarCuenta(User $usuario, float $consumo = 500): array
    {
        $mesaId = DB::table('mesas')->insertGetId([
            'numero' => 'Mesa 3',
            'capacidad' => 4,
            'estado' => 'OCUPADA',
        ]);

        $ventaId = DB::table('ventas')->insertGetId([
            'mesa_id' => $mesaId,
            'descripcion' => 'Mesa 3',
            'monto' => $consumo,
            'estado' => 'ACTIVA',
            'usuario_id' => $usuario->id,
            'fecha' => now(),
        ]);

        $productoId = DB::table('productos')->insertGetId([
            'nombre' => 'Plato de prueba',
            'precio' => $consumo,
            'requiere_cocina' => 0,
            'controla_stock' => 0,
            'activo' => 1,
        ]);

        $detalleId = DB::table('venta_detalles')->insertGetId([
            'venta_id' => $ventaId,
            'producto_id' => $productoId,
            'cantidad' => 1,
            'precio_unitario' => $consumo,
            'subtotal' => $consumo,
            'estado' => 'ACTIVO',
        ]);

        $cuentaId = DB::table('cuentas')->insertGetId([
            'venta_id' => $ventaId,
            'nombre' => 'Cuenta principal',
            'total' => $consumo,
            'estado' => 'ABIERTA',
        ]);

        DB::table('cuenta_detalles')->insert([
            'cuenta_id' => $cuentaId,
            'venta_id' => $ventaId,
            'venta_detalle_id' => $detalleId,
            'cantidad' => 1,
            'subtotal' => $consumo,
            'eliminado' => false,
        ]);

        return ['mesa' => $mesaId, 'venta' => $ventaId, 'cuenta' => $cuentaId];
    }

    private function linea(string $metodo, $monto, array $extra = []): array
    {
        return array_merge([
            'clave_operacion' => (string) Str::uuid(),
            'metodo_pago' => $metodo,
            'monto' => $monto,
        ], $extra);
    }

    private function cobrar(User $usuario, int $cuentaId, array $pagos, array $extra = [])
    {
        return $this->actingAs($usuario)->postJson(route('pos.cuentas.pagar', $cuentaId), array_merge([
            'pagos' => $pagos,
        ], $extra));
    }

    /* ------------------------------------------------------------------
     | Cobro correcto con varias líneas
     * ------------------------------------------------------------------ */

    public function test_registra_varias_lineas_y_calcula_total_recibido_y_cambio(): void
    {
        $cajero = $this->usuario('CAJERO');
        $escena = $this->montarCuenta($cajero, 500);

        // 300 efectivo + 250 tarjeta = 550; el total es 500 => cambio 50.
        $respuesta = $this->cobrar($cajero, $escena['cuenta'], [
            $this->linea('EFECTIVO_CORDOBA', 300),
            $this->linea('TARJETA_DEBITO', 250, ['banco' => 'Banpro']),
        ]);

        $respuesta->assertStatus(200)
            ->assertJsonPath('exito', true)
            ->assertJsonPath('pagos', 2);

        $this->assertEquals(50.0, (float) $respuesta->json('cambio'));

        // Cada línea quedó registrada por separado, con su importe en córdobas.
        $pagos = DB::table('pagos_cuenta')->orderBy('id')->get();
        $this->assertCount(2, $pagos);
        $this->assertSame('EFECTIVO_CORDOBA', $pagos[0]->metodo_pago);
        $this->assertEquals(300.0, (float) $pagos[0]->monto);
        $this->assertEquals(300.0, (float) $pagos[0]->monto_cordobas);
        $this->assertSame('TARJETA_DEBITO', $pagos[1]->metodo_pago);
        $this->assertSame('Banpro', $pagos[1]->banco);
        $this->assertEquals(250.0, (float) $pagos[1]->monto_cordobas);

        // La cuenta resume el cobro como MIXTO y conserva lo realmente recibido.
        $cuenta = DB::table('cuentas')->find($escena['cuenta']);
        $this->assertSame('PAGADA', $cuenta->estado);
        $this->assertSame('MIXTO', $cuenta->metodo_pago);
        $this->assertEquals(550.0, (float) $cuenta->monto_recibido);
        $this->assertEquals(50.0, (float) $cuenta->cambio);

        // Al ser la única cuenta pendiente, la venta se cierra y la mesa queda libre.
        $this->assertSame('PAGADA', DB::table('ventas')->find($escena['venta'])->estado);
        $this->assertSame('LIBRE', DB::table('mesas')->find($escena['mesa'])->estado);

        // El cobro queda atribuido a la apertura activa real (no a NULL).
        $aperturaActivaId = (int) DB::table('caja_apertura')->where('estado', 'ABIERTA')->value('id');
        $this->assertSame($aperturaActivaId, (int) DB::table('cuentas')->find($escena['cuenta'])->apertura_id);
        $this->assertSame($aperturaActivaId, (int) DB::table('ventas')->find($escena['venta'])->apertura_id);

        // La línea de pago queda vinculada a la factura emitida.
        $facturaId = (int) DB::table('facturas')->where('cuenta_id', $escena['cuenta'])->value('id');
        $this->assertSame($facturaId, (int) DB::table('pagos_cuenta')->first()->factura_id);
        $this->assertSame('ACTIVO', DB::table('pagos_cuenta')->first()->estado);
    }

    public function test_linea_en_dolares_convierte_a_cordobas_en_el_servidor(): void
    {
        $cajero = $this->usuario('CAJERO');
        $escena = $this->montarCuenta($cajero, 400);

        // 10 USD × 36.5 = 365 C$ + 35 C$ en efectivo = 400 C$.
        $respuesta = $this->cobrar($cajero, $escena['cuenta'], [
            $this->linea('EFECTIVO_USD', 10, ['moneda' => 'USD', 'tasa_cambio' => 36.5]),
            $this->linea('EFECTIVO_CORDOBA', 35),
        ]);

        $respuesta->assertStatus(200)->assertJsonPath('exito', true);

        $pagoUsd = DB::table('pagos_cuenta')->where('metodo_pago', 'EFECTIVO_USD')->sole();
        $this->assertSame('USD', $pagoUsd->moneda);
        $this->assertEquals(10.0, (float) $pagoUsd->monto);
        $this->assertEquals(36.5, (float) $pagoUsd->tasa_cambio);
        $this->assertEquals(365.0, (float) $pagoUsd->monto_cordobas);

        $this->assertEquals(
            0.0,
            (float) DB::table('cuentas')->find($escena['cuenta'])->cambio,
            'Con los pagos exactos el cambio debe ser cero.'
        );
    }

    /* ------------------------------------------------------------------
     | Validaciones: nada se registra si una línea es inválida
     * ------------------------------------------------------------------ */

    public function test_metodo_de_pago_invalido_no_registra_ningun_pago(): void
    {
        $cajero = $this->usuario('CAJERO');
        $escena = $this->montarCuenta($cajero, 300);

        $this->cobrar($cajero, $escena['cuenta'], [
            $this->linea('BITCOIN', 300),
        ])->assertStatus(422)->assertJsonValidationErrors('pagos.0.metodo_pago');

        $this->assertSame(0, DB::table('pagos_cuenta')->count());
        $this->assertSame(0, DB::table('facturas')->count());
        $this->assertSame('ABIERTA', DB::table('cuentas')->find($escena['cuenta'])->estado);
    }

    public function test_importes_invalidos_no_registran_ningun_pago(): void
    {
        $cajero = $this->usuario('CAJERO');
        $escena = $this->montarCuenta($cajero, 300);

        // Cero y más de dos decimales son rechazados por NumeroEstricto.
        foreach ([0, 0.0, '10.999', '1e3'] as $montoInvalido) {
            $this->cobrar($cajero, $escena['cuenta'], [
                $this->linea('EFECTIVO_CORDOBA', $montoInvalido),
            ])->assertStatus(422)->assertJsonValidationErrors('pagos.0.monto');
        }

        $this->assertSame(0, DB::table('pagos_cuenta')->count());
        $this->assertSame(0, DB::table('facturas')->count());
        $this->assertSame('ABIERTA', DB::table('cuentas')->find($escena['cuenta'])->estado);
    }

    public function test_tarjeta_no_puede_superar_el_total_a_cobrar(): void
    {
        $cajero = $this->usuario('CAJERO');
        $escena = $this->montarCuenta($cajero, 100);

        // El excedente solo se admite en efectivo (para devolver cambio).
        $this->cobrar($cajero, $escena['cuenta'], [
            $this->linea('TARJETA_CREDITO', 150, ['banco' => 'BAC']),
        ])->assertStatus(422)
            ->assertJsonPath('exito', false);

        $this->assertSame(0, DB::table('pagos_cuenta')->count());
        $this->assertSame(0, DB::table('facturas')->count());
        $this->assertSame('ABIERTA', DB::table('cuentas')->find($escena['cuenta'])->estado);
    }

    public function test_pagos_insuficientes_no_marcan_la_cuenta_como_pagada(): void
    {
        $cajero = $this->usuario('CAJERO');
        $escena = $this->montarCuenta($cajero, 200);

        $this->cobrar($cajero, $escena['cuenta'], [
            $this->linea('EFECTIVO_CORDOBA', 150),
        ])->assertStatus(422)->assertJsonPath('exito', false);

        $this->assertSame(0, DB::table('pagos_cuenta')->count());
        $this->assertSame(0, DB::table('facturas')->count());
        $this->assertSame('ABIERTA', DB::table('cuentas')->find($escena['cuenta'])->estado);
        $this->assertSame('ACTIVA', DB::table('ventas')->find($escena['venta'])->estado);
        $this->assertSame('OCUPADA', DB::table('mesas')->find($escena['mesa'])->estado);
    }

    /* ------------------------------------------------------------------
     | Idempotencia: reintentos no duplican pagos
     * ------------------------------------------------------------------ */

    public function test_reintentar_el_mismo_cobro_no_duplica_pagos_ni_facturas(): void
    {
        $cajero = $this->usuario('CAJERO');
        $escena = $this->montarCuenta($cajero, 300);

        $pago = $this->linea('EFECTIVO_CORDOBA', 300);

        $this->cobrar($cajero, $escena['cuenta'], [$pago])
            ->assertStatus(200)
            ->assertJsonPath('exito', true);

        // Reenvío exacto (doble clic / mala conexión): la cuenta ya no está ABIERTA.
        $this->cobrar($cajero, $escena['cuenta'], [$pago])
            ->assertStatus(422)
            ->assertJsonPath('exito', false);

        $this->assertSame(1, DB::table('pagos_cuenta')->count());
        $this->assertSame(1, DB::table('facturas')->count());
    }

    public function test_clave_de_operacion_ya_registrada_se_rechaza_sin_duplicar(): void
    {
        $cajero = $this->usuario('CAJERO');
        $escena = $this->montarCuenta($cajero, 300);

        // Simula una línea ya guardada con la misma clave (p. ej. otro intento
        // que sí llegó a insertar) manteniendo la cuenta ABIERTA.
        DB::table('pagos_cuenta')->insert([
            'clave_operacion' => 'clave-repetida',
            'cuenta_id' => $escena['cuenta'],
            'venta_id' => $escena['venta'],
            'metodo_pago' => 'EFECTIVO_CORDOBA',
            'moneda' => 'NIO',
            'monto' => 1,
            'monto_cordobas' => 1,
            'usuario_id' => $cajero->id,
            'creado_en' => now(),
        ]);

        $this->cobrar($cajero, $escena['cuenta'], [
            $this->linea('EFECTIVO_CORDOBA', 300, ['clave_operacion' => 'clave-repetida']),
        ])->assertStatus(422)->assertJsonPath('exito', false);

        $this->assertSame(1, DB::table('pagos_cuenta')->count());
        $this->assertSame(0, DB::table('facturas')->count());
        $this->assertSame('ABIERTA', DB::table('cuentas')->find($escena['cuenta'])->estado);
    }

    /* ------------------------------------------------------------------
     | Permisos y regresión de la interfaz
     * ------------------------------------------------------------------ */

    public function test_mesero_no_puede_cobrar(): void
    {
        $mesero = $this->usuario('MESERO');
        $escena = $this->montarCuenta($mesero, 100);

        $this->cobrar($mesero, $escena['cuenta'], [
            $this->linea('EFECTIVO_CORDOBA', 100),
        ])->assertStatus(403)->assertJsonPath('exito', false);

        $this->assertSame(0, DB::table('pagos_cuenta')->count());
        $this->assertSame('ABIERTA', DB::table('cuentas')->find($escena['cuenta'])->estado);
    }

    public function test_el_pos_conserva_el_listener_de_agregar_linea_de_pago(): void
    {
        $vista = file_get_contents(resource_path('views/ventas/pos.blade.php'));

        // El botón debe existir y tener un listener en didOpen; su ausencia fue
        // la causa de que no se pudieran agregar líneas de pago.
        $this->assertStringContainsString('id="agregar-linea"', $vista);
        $this->assertStringContainsString("getElementById('agregar-linea')", $vista);
        $this->assertStringContainsString("addEventListener('click'", $vista);

        // Regresión SweetAlert2 v11: Swal.close() no devuelve promesa.
        $this->assertStringNotContainsString('Swal.close().then', $vista);
    }

    /* ------------------------------------------------------------------
     | DIVISIÓN DE PAGOS: automática (partes iguales) y manual
     * ------------------------------------------------------------------ */

    private function cobrarDividido(User $usuario, int $cuentaId, array $pagos, string $modo, array $extra = [])
    {
        return $this->cobrar($usuario, $cuentaId, $pagos, array_merge([
            'modo_division' => $modo,
        ], $extra));
    }

    public function test_division_automatica_en_dos_partes_iguales(): void
    {
        $cajero = $this->usuario('CAJERO');
        $escena = $this->montarCuenta($cajero, 500);

        $respuesta = $this->cobrarDividido($cajero, $escena['cuenta'], [
            $this->linea('EFECTIVO_CORDOBA', 250),
            $this->linea('TARJETA_DEBITO', 250, ['banco' => 'Banpro']),
        ], 'AUTOMATICA');

        $respuesta->assertStatus(200)
            ->assertJsonPath('exito', true)
            ->assertJsonPath('pagos', 2)
            ->assertJsonPath('modo_division', 'AUTOMATICA');

        $this->assertEquals(0.0, (float) $respuesta->json('cambio'));

        $pagos = DB::table('pagos_cuenta')->orderBy('id')->get();
        $this->assertCount(2, $pagos);
        $this->assertEquals(250.0, (float) $pagos[0]->monto_cordobas);
        $this->assertEquals(250.0, (float) $pagos[1]->monto_cordobas);

        $cuenta = DB::table('cuentas')->find($escena['cuenta']);
        $this->assertSame('PAGADA', $cuenta->estado);
        $this->assertEquals(500.0, (float) $cuenta->monto_recibido);
        $this->assertEquals(0.0, (float) $cuenta->cambio);
    }

    public function test_division_automatica_en_tres_partes_resuelve_centavos(): void
    {
        $cajero = $this->usuario('CAJERO');
        $escena = $this->montarCuenta($cajero, 100);

        // 100.00 / 3 = 33.33 + 33.33 + 33.34: la suma es exacta.
        $respuesta = $this->cobrarDividido($cajero, $escena['cuenta'], [
            $this->linea('EFECTIVO_CORDOBA', 33.33),
            $this->linea('TARJETA_DEBITO', 33.33, ['banco' => 'Banpro']),
            $this->linea('TARJETA_CREDITO', 33.34, ['banco' => 'BAC']),
        ], 'AUTOMATICA');

        $respuesta->assertStatus(200)
            ->assertJsonPath('exito', true)
            ->assertJsonPath('pagos', 3);

        $pagos = DB::table('pagos_cuenta')->orderBy('id')->get();
        $this->assertCount(3, $pagos);

        $centavos = $pagos->map(fn ($pago) => (int) round((float) $pago->monto_cordobas * 100))->all();
        $this->assertLessThanOrEqual(1, max($centavos) - min($centavos));
        $this->assertSame(10000, array_sum($centavos));
        $this->assertEquals(0.0, (float) DB::table('cuentas')->find($escena['cuenta'])->cambio);
    }

    public function test_division_automatica_en_cuatro_partes_resuelve_centavos(): void
    {
        $cajero = $this->usuario('CAJERO');
        $escena = $this->montarCuenta($cajero, 100.01);

        // 100.01 / 4 = 25.00 + 25.00 + 25.00 + 25.01.
        $respuesta = $this->cobrarDividido($cajero, $escena['cuenta'], [
            $this->linea('EFECTIVO_CORDOBA', 25.00),
            $this->linea('TARJETA_DEBITO', 25.00, ['banco' => 'Banpro']),
            $this->linea('TARJETA_CREDITO', 25.00, ['banco' => 'BAC']),
            $this->linea('TRANSFERENCIA', 25.01, ['banco' => 'Lafise', 'numero_transferencia' => '123456']),
        ], 'AUTOMATICA');

        $respuesta->assertStatus(200)
            ->assertJsonPath('exito', true)
            ->assertJsonPath('pagos', 4);

        $pagos = DB::table('pagos_cuenta')->orderBy('id')->get();
        $this->assertCount(4, $pagos);

        $centavos = $pagos->map(fn ($pago) => (int) round((float) $pago->monto_cordobas * 100))->all();
        $this->assertLessThanOrEqual(1, max($centavos) - min($centavos));
        $this->assertSame(10001, array_sum($centavos));
        $this->assertEquals(0.0, (float) DB::table('cuentas')->find($escena['cuenta'])->cambio);
    }

    public function test_division_manual_distribuye_importes_por_metodo(): void
    {
        $cajero = $this->usuario('CAJERO');
        $escena = $this->montarCuenta($cajero, 500);

        $respuesta = $this->cobrarDividido($cajero, $escena['cuenta'], [
            $this->linea('EFECTIVO_CORDOBA', 200),
            $this->linea('TARJETA_DEBITO', 300, ['banco' => 'Banpro']),
        ], 'MANUAL');

        $respuesta->assertStatus(200)
            ->assertJsonPath('exito', true)
            ->assertJsonPath('pagos', 2)
            ->assertJsonPath('modo_division', 'MANUAL');

        $this->assertEquals(500.0, (float) $respuesta->json('totales.total'));

        $pagos = DB::table('pagos_cuenta')->orderBy('id')->get();
        $this->assertCount(2, $pagos);
        $this->assertSame('EFECTIVO_CORDOBA', $pagos[0]->metodo_pago);
        $this->assertEquals(200.0, (float) $pagos[0]->monto_cordobas);
        $this->assertSame('TARJETA_DEBITO', $pagos[1]->metodo_pago);
        $this->assertSame('Banpro', $pagos[1]->banco);
        $this->assertEquals(300.0, (float) $pagos[1]->monto_cordobas);

        $cuenta = DB::table('cuentas')->find($escena['cuenta']);
        $this->assertSame('PAGADA', $cuenta->estado);
        $this->assertSame('MIXTO', $cuenta->metodo_pago);
        $this->assertEquals(500.0, (float) $cuenta->monto_recibido);

        // El modo de división queda en el detalle de la factura.
        $factura = DB::table('facturas')->where('cuenta_id', $escena['cuenta'])->sole();
        $detalle = json_decode((string) $factura->detalle_consumo, true);
        $this->assertSame('MANUAL', $detalle['ajustes']['modo_division']);
        $this->assertCount(2, $detalle['ajustes']['pagos']);
        $this->assertEquals(500.0, (float) $factura->total);
    }

    public function test_division_manual_rechaza_suma_insuficiente_sin_registrar_pagos(): void
    {
        $cajero = $this->usuario('CAJERO');
        $escena = $this->montarCuenta($cajero, 500);

        $respuesta = $this->cobrarDividido($cajero, $escena['cuenta'], [
            $this->linea('EFECTIVO_CORDOBA', 200),
            $this->linea('TARJETA_DEBITO', 250, ['banco' => 'Banpro']),
        ], 'MANUAL');

        $respuesta->assertStatus(422)->assertJsonPath('exito', false);
        $this->assertStringContainsString('no cubre', $respuesta->json('error'));

        $this->assertSame(0, DB::table('pagos_cuenta')->count());
        $this->assertSame(0, DB::table('facturas')->count());
        $this->assertSame('ABIERTA', DB::table('cuentas')->find($escena['cuenta'])->estado);
    }

    public function test_division_manual_rechaza_exceso_sin_registrar_pagos(): void
    {
        $cajero = $this->usuario('CAJERO');
        $escena = $this->montarCuenta($cajero, 500);

        $respuesta = $this->cobrarDividido($cajero, $escena['cuenta'], [
            $this->linea('EFECTIVO_CORDOBA', 300),
            $this->linea('TARJETA_DEBITO', 250, ['banco' => 'Banpro']),
        ], 'MANUAL');

        $respuesta->assertStatus(422)->assertJsonPath('exito', false);
        $this->assertStringContainsString('sobran', $respuesta->json('error'));

        $this->assertSame(0, DB::table('pagos_cuenta')->count());
        $this->assertSame(0, DB::table('facturas')->count());
        $this->assertSame('ABIERTA', DB::table('cuentas')->find($escena['cuenta'])->estado);
    }

    public function test_division_automatica_rechaza_partes_desiguales(): void
    {
        $cajero = $this->usuario('CAJERO');
        $escena = $this->montarCuenta($cajero, 500);

        $respuesta = $this->cobrarDividido($cajero, $escena['cuenta'], [
            $this->linea('EFECTIVO_CORDOBA', 100),
            $this->linea('TARJETA_DEBITO', 400, ['banco' => 'Banpro']),
        ], 'AUTOMATICA');

        $respuesta->assertStatus(422)->assertJsonPath('exito', false);
        $this->assertStringContainsString('partes iguales', $respuesta->json('error'));

        $this->assertSame(0, DB::table('pagos_cuenta')->count());
        $this->assertSame(0, DB::table('facturas')->count());
    }

    public function test_division_requiere_al_menos_dos_formas_de_pago(): void
    {
        $cajero = $this->usuario('CAJERO');
        $escena = $this->montarCuenta($cajero, 500);

        $respuesta = $this->cobrarDividido($cajero, $escena['cuenta'], [
            $this->linea('EFECTIVO_CORDOBA', 500),
        ], 'MANUAL');

        $respuesta->assertStatus(422)->assertJsonPath('exito', false);
        $this->assertStringContainsString('al menos dos', $respuesta->json('error'));
        $this->assertSame(0, DB::table('pagos_cuenta')->count());
    }

    public function test_division_rechaza_claves_de_operacion_repetidas(): void
    {
        $cajero = $this->usuario('CAJERO');
        $escena = $this->montarCuenta($cajero, 500);

        $clave = (string) Str::uuid();

        $respuesta = $this->cobrarDividido($cajero, $escena['cuenta'], [
            $this->linea('EFECTIVO_CORDOBA', 250, ['clave_operacion' => $clave]),
            $this->linea('TARJETA_DEBITO', 250, ['clave_operacion' => $clave, 'banco' => 'Banpro']),
        ], 'MANUAL');

        $respuesta->assertStatus(422)->assertJsonPath('exito', false);
        $this->assertStringContainsString('repetidas', $respuesta->json('error'));
        $this->assertSame(0, DB::table('pagos_cuenta')->count());
    }

    public function test_division_rechaza_importes_invalidos(): void
    {
        $cajero = $this->usuario('CAJERO');
        $escena = $this->montarCuenta($cajero, 500);

        $this->cobrarDividido($cajero, $escena['cuenta'], [
            $this->linea('EFECTIVO_CORDOBA', 0),
            $this->linea('TARJETA_DEBITO', 500, ['banco' => 'Banpro']),
        ], 'MANUAL')->assertStatus(422)->assertJsonValidationErrors('pagos.0.monto');

        $this->cobrarDividido($cajero, $escena['cuenta'], [
            $this->linea('EFECTIVO_CORDOBA', -50),
            $this->linea('TARJETA_DEBITO', 550, ['banco' => 'Banpro']),
        ], 'MANUAL')->assertStatus(422)->assertJsonValidationErrors('pagos.0.monto');

        $this->assertSame(0, DB::table('pagos_cuenta')->count());
        $this->assertSame(0, DB::table('facturas')->count());
        $this->assertSame('ABIERTA', DB::table('cuentas')->find($escena['cuenta'])->estado);
    }

    public function test_division_manual_conserva_descuento_y_propina(): void
    {
        $cajero = $this->usuario('CAJERO');
        $escena = $this->montarCuenta($cajero, 500);

        // Consumo 500 − descuento 50 + propina 25 = 475 a cobrar.
        $respuesta = $this->cobrarDividido($cajero, $escena['cuenta'], [
            $this->linea('EFECTIVO_CORDOBA', 200),
            $this->linea('TARJETA_DEBITO', 275, ['banco' => 'Banpro']),
        ], 'MANUAL', [
            'descuento' => '50.00',
            'descuento_activo' => '1',
            'descuento_motivo' => 'Descuento autorizado',
            'propina' => '25.00',
            'propina_activa' => '1',
        ]);

        $respuesta->assertStatus(200)
            ->assertJsonPath('exito', true)
            ->assertJsonPath('pagos', 2);

        $this->assertEquals(475.0, (float) $respuesta->json('totales.total'));
        $this->assertEquals(50.0, (float) $respuesta->json('totales.descuento'));
        $this->assertEquals(25.0, (float) $respuesta->json('totales.propina'));
        $this->assertEquals(0.0, (float) $respuesta->json('cambio'));

        $pagos = DB::table('pagos_cuenta')->get();
        $this->assertCount(2, $pagos);
        $this->assertEquals(475.0, (float) $pagos->sum('monto_cordobas'));

        $factura = DB::table('facturas')->where('cuenta_id', $escena['cuenta'])->sole();
        $this->assertEquals(475.0, (float) $factura->total);

        $detalle = json_decode((string) $factura->detalle_consumo, true);
        $this->assertSame('MANUAL', $detalle['ajustes']['modo_division']);
        $this->assertEquals(50.0, (float) $detalle['ajustes']['descuento']);
        $this->assertEquals(25.0, (float) $detalle['ajustes']['propina']);
    }

    public function test_el_pos_expone_la_division_de_pagos_con_sus_listeners(): void
    {
        $vista = file_get_contents(resource_path('views/ventas/pos.blade.php'));

        // Controles de la división automática y manual.
        $this->assertStringContainsString('id="modo-division"', $vista);
        $this->assertStringContainsString('id="division-cantidad"', $vista);
        $this->assertStringContainsString('id="division-agregar-fila"', $vista);
        $this->assertStringContainsString('id="division-aplicar"', $vista);
        $this->assertStringContainsString('id="division-resumen"', $vista);

        // Los listeners se enlazan en didOpen y el resumen se refresca al
        // cambiar el total; sin esto la división no sería operable.
        $this->assertStringContainsString("getElementById('modo-division')", $vista);
        $this->assertStringContainsString("getElementById('division-aplicar')", $vista);
        $this->assertStringContainsString('refrescarDivision(t)', $vista);

        // La división se envía al backend junto con las líneas de pago.
        $this->assertStringContainsString("form.append('modo_division'", $vista);
    }

    public function test_cobro_rechaza_si_no_hay_caja_abierta(): void
    {
        $cajero = $this->usuario('CAJERO');
        $escena = $this->montarCuenta($cajero, 200);

        DB::table('caja_apertura')->update(['estado' => 'CERRADA']);

        $this->cobrar($cajero, $escena['cuenta'], [
            $this->linea('EFECTIVO_CORDOBA', 200),
        ])->assertStatus(422)
            ->assertJsonPath('exito', false);

        $this->assertSame(0, DB::table('pagos_cuenta')->count());
        $this->assertSame(0, DB::table('facturas')->count());
        $this->assertSame('ABIERTA', DB::table('cuentas')->find($escena['cuenta'])->estado);
    }
}
