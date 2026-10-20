<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\CreaEsquemaCompleto;
use Tests\TestCase;

/*
 * FACTURA vs PREFACTURA Y SUBCUENTAS INDEPENDIENTES
 * ================================================
 *
 * Estas pruebas reproducen los dos problemas corregidos:
 *
 *  1. La factura impresa/guardada debe mostrar los MISMOS conceptos e
 *     importes que la prefactura (consumo, descuento, base, propina y total),
 *     además de los pagos, el importe recibido y el cambio del cobro.
 *
 *  2. Cada subcuenta es un cobro independiente: su prefactura, factura,
 *     pagos, cambio e historial no pueden mezclarse con los de otra cuenta
 *     de la misma mesa.
 *
 * IMPORTANTE:
 * - Corren en SQLite :memory: (phpunit.xml); nunca tocan la base real.
 * - El esquema se construye con el trait compartido (columnas reales).
 */
class FacturaPrefacturaSubcuentasTest extends TestCase
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
     | ------------------------------------------------------------------ */

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
     * Monta una mesa con una venta ACTIVA y dos subcuentas independientes.
     *
     * Cuenta A: 3 × 100 = C$ 300 (Plato A)
     * Cuenta B: 2 × 100 = C$ 200 (Plato B)
     *
     * Cada línea de venta tiene su propia asignación en cuenta_detalles:
     * esa es la fuente de verdad del cobro, la prefactura y la factura.
     */
    private function montarDosSubcuentas(User $usuario): array
    {
        $mesaId = DB::table('mesas')->insertGetId([
            'numero' => 'Mesa 1',
            'capacidad' => 4,
            'estado' => 'OCUPADA',
        ]);

        $ventaId = DB::table('ventas')->insertGetId([
            'mesa_id' => $mesaId,
            'descripcion' => 'Mesa 1',
            'monto' => 500,
            'estado' => 'ACTIVA',
            'usuario_id' => $usuario->id,
            'fecha' => now(),
        ]);

        $productoA = DB::table('productos')->insertGetId([
            'nombre' => 'Plato A',
            'precio' => 100,
            'requiere_cocina' => 0,
            'controla_stock' => 0,
            'activo' => 1,
        ]);

        $productoB = DB::table('productos')->insertGetId([
            'nombre' => 'Plato B',
            'precio' => 100,
            'requiere_cocina' => 0,
            'controla_stock' => 0,
            'activo' => 1,
        ]);

        $detalleA = DB::table('venta_detalles')->insertGetId([
            'venta_id' => $ventaId,
            'producto_id' => $productoA,
            'cantidad' => 3,
            'precio_unitario' => 100,
            'subtotal' => 300,
            'estado' => 'ACTIVO',
        ]);

        $detalleB = DB::table('venta_detalles')->insertGetId([
            'venta_id' => $ventaId,
            'producto_id' => $productoB,
            'cantidad' => 2,
            'precio_unitario' => 100,
            'subtotal' => 200,
            'estado' => 'ACTIVO',
        ]);

        $cuentaA = DB::table('cuentas')->insertGetId([
            'venta_id' => $ventaId,
            'nombre' => 'Ana',
            'total' => 300,
            'estado' => 'ABIERTA',
        ]);

        $cuentaB = DB::table('cuentas')->insertGetId([
            'venta_id' => $ventaId,
            'nombre' => 'Luis',
            'total' => 200,
            'estado' => 'ABIERTA',
        ]);

        DB::table('cuenta_detalles')->insert([
            'cuenta_id' => $cuentaA,
            'venta_id' => $ventaId,
            'venta_detalle_id' => $detalleA,
            'cantidad' => 3,
            'subtotal' => 300,
            'eliminado' => false,
        ]);

        DB::table('cuenta_detalles')->insert([
            'cuenta_id' => $cuentaB,
            'venta_id' => $ventaId,
            'venta_detalle_id' => $detalleB,
            'cantidad' => 2,
            'subtotal' => 200,
            'eliminado' => false,
        ]);

        return [
            'mesa' => $mesaId,
            'venta' => $ventaId,
            'cuenta_a' => $cuentaA,
            'cuenta_b' => $cuentaB,
            'detalle_a' => $detalleA,
            'detalle_b' => $detalleB,
        ];
    }

    private function linea(string $metodo, float $monto, ?string $banco = null, ?float $tasa = null): array
    {
        return [
            'clave_operacion' => (string) Str::uuid(),
            'metodo_pago' => $metodo,
            'monto' => $monto,
            'banco' => $banco,
            'tasa_cambio' => $tasa,
        ];
    }

    private function cobrar(User $usuario, int $cuentaId, array $pagos, float $descuento = 0, float $propina = 0, string $motivo = '')
    {
        return $this->actingAs($usuario)->post(route('pos.cuentas.pagar', $cuentaId), [
            'descuento' => $descuento,
            'propina' => $propina,
            'descuento_motivo' => $motivo,
            'pagos' => $pagos,
        ]);
    }

    private function detalleFactura(int $facturaId): array
    {
        $factura = DB::table('facturas')->find($facturaId);

        return json_decode($factura->detalle_consumo, true);
    }

    /* ------------------------------------------------------------------
     | 1. Factura impresa = prefactura + datos del cobro
     | ------------------------------------------------------------------ */

    public function test_factura_impresa_refleja_los_mismos_conceptos_que_la_prefactura(): void
    {
        $cajero = $this->usuario('CAJERO');
        $escena = $this->montarDosSubcuentas($cajero);

        // Consumo 300 − descuento 50 = base 250; + propina 20 = total 270.
        // Pagos mixtos: 200 en efectivo + 70 con tarjeta (el total se cubre).
        $respuesta = $this->cobrar(
            $cajero,
            $escena['cuenta_a'],
            [
                $this->linea('EFECTIVO_CORDOBA', 200),
                $this->linea('TARJETA_DEBITO', 70, 'Banpro'),
            ],
            50,
            20,
            'Cliente frecuente'
        );

        $respuesta->assertStatus(200)
            ->assertJson([
                'exito' => true,
                'totales' => [
                    'consumo' => 300,
                    'descuento' => 50,
                    'base' => 250,
                    'propina' => 20,
                    'total' => 270,
                ],
                'cambio' => 0,
            ]);

        $factura = DB::table('facturas')->sole();
        $detalle = $this->detalleFactura($factura->id);

        // La factura GUARDADA conserva los mismos conceptos que la prefactura.
        $this->assertSame(270.0, (float) $factura->total);
        $this->assertSame(300.0, (float) $detalle['ajustes']['consumo']);
        $this->assertSame(50.0, (float) $detalle['ajustes']['descuento']);
        $this->assertSame(250.0, (float) $detalle['ajustes']['base']);
        $this->assertSame(20.0, (float) $detalle['ajustes']['propina']);
        $this->assertSame(270.0, (float) $detalle['ajustes']['total']);

        // La prefactura con los mismos ajustes muestra idénticos importes.
        $prefactura = $this->actingAs($cajero)->get(route('pos.cuentas.prefactura', [
            'cuentaId' => $escena['cuenta_a'],
            'descuento' => 50,
            'propina' => 20,
        ]));

        $prefactura->assertStatus(200)
            ->assertSee('Ana')
            ->assertSee('#'.$escena['cuenta_a'])
            ->assertSee('Consumo:')
            ->assertSee('Descuento:')
            ->assertSee('Base')
            ->assertSee('Propina:')
            ->assertSee('TOTAL POR COBRAR')
            ->assertSee('300.00')
            ->assertSee('50.00')
            ->assertSee('250.00')
            ->assertSee('20.00')
            ->assertSee('270.00');

        // La factura impresa por id y por número debe mostrar lo mismo.
        foreach ([['id' => $factura->id], ['numero' => $factura->numero_factura]] as $parametros) {
            $ticket = $this->actingAs($cajero)
                ->get(route('facturas.ticket', $parametros))
                ->assertStatus(200)
                ->assertSee('FAC-0001')
                ->assertSee('Ana')
                ->assertSee('#'.$escena['cuenta_a'])
                ->assertSee('Consumo:')
                ->assertSee('Descuento:')
                ->assertSee('Base')
                ->assertSee('Propina:')
                ->assertSee('300.00')
                ->assertSee('50.00')
                ->assertSee('250.00')
                ->assertSee('20.00')
                ->assertSee('270.00')
                // Pagos registrados: no basta con el resumen "MIXTO".
                ->assertSee('Pagos registrados')
                ->assertSee('Efectivo C$')
                ->assertSee('Tarjeta de débito')
                ->assertSee('Banpro')
                ->assertSee('200.00')
                ->assertSee('70.00')
                ->assertSee('Importe recibido')
                ->assertSee('Cambio entregado');

            // La factura de Ana no puede incluir el producto de Luis.
            $ticket->assertDontSee('Plato B');
        }

        // Imprimir es de solo lectura: no duplica pagos ni facturas.
        $this->assertSame(1, DB::table('facturas')->count());
        $this->assertSame(2, DB::table('pagos_cuenta')->where('cuenta_id', $escena['cuenta_a'])->count());
    }

    /* ------------------------------------------------------------------
     | 2. Persistencia y aislamiento entre subcuentas
     | ------------------------------------------------------------------ */

    public function test_cobrar_ana_no_modifica_la_cuenta_de_luis(): void
    {
        $cajero = $this->usuario('CAJERO');
        $escena = $this->montarDosSubcuentas($cajero);

        // Petición manipulada: intenta colar la cuenta y el detalle de Luis.
        // El backend debe ignorarlos y cobrar SOLO la cuenta de la ruta.
        $this->actingAs($cajero)->post(route('pos.cuentas.pagar', $escena['cuenta_a']), [
            'cuenta_id' => $escena['cuenta_b'],
            'venta_detalle_id' => $escena['detalle_b'],
            'descuento' => 50,
            'propina' => 20,
            'pagos' => [
                $this->linea('EFECTIVO_CORDOBA', 200),
                $this->linea('TARJETA_DEBITO', 70, 'Banpro'),
            ],
        ])->assertStatus(200)->assertJson(['exito' => true]);

        $cuentaA = DB::table('cuentas')->find($escena['cuenta_a']);
        $cuentaB = DB::table('cuentas')->find($escena['cuenta_b']);

        // Ana queda pagada con sus propios ajustes.
        $this->assertSame('PAGADA', $cuentaA->estado);
        $this->assertSame(300.0, (float) $cuentaA->total);
        $this->assertSame(50.0, (float) $cuentaA->descuento);
        $this->assertSame(20.0, (float) $cuentaA->propina);
        $this->assertSame('MIXTO', $cuentaA->metodo_pago);
        $this->assertSame(270.0, (float) $cuentaA->monto_recibido);
        $this->assertSame(0.0, (float) $cuentaA->cambio);

        // Luis sigue ABIERTO, con su importe y sin ajustes ni pagos.
        $this->assertSame('ABIERTA', $cuentaB->estado);
        $this->assertSame(200.0, (float) $cuentaB->total);
        $this->assertSame(0.0, (float) $cuentaB->descuento);
        $this->assertSame(0.0, (float) $cuentaB->propina);
        $this->assertNull($cuentaB->monto_recibido);
        $this->assertSame(0, DB::table('pagos_cuenta')->where('cuenta_id', $escena['cuenta_b'])->count());
        $this->assertSame(0, DB::table('facturas')->where('cuenta_id', $escena['cuenta_b'])->count());

        // La venta sigue ACTIVA: todavía hay una subcuenta con consumo.
        $this->assertSame('ACTIVA', DB::table('ventas')->find($escena['venta'])->estado);
        $this->assertSame('OCUPADA', DB::table('mesas')->find($escena['mesa'])->estado);

        // La factura de Ana solo contiene el Plato A.
        $factura = DB::table('facturas')->where('cuenta_id', $escena['cuenta_a'])->sole();
        $detalle = $this->detalleFactura($factura->id);

        $this->assertCount(1, $detalle['lineas']);
        $this->assertSame('Plato A', $detalle['lineas'][0]['producto']);
        $this->assertSame(3, $detalle['lineas'][0]['cantidad']);
        $this->assertSame(300.0, (float) $detalle['lineas'][0]['subtotal']);
        $this->assertSame('Ana', $detalle['cuenta']);
    }

    public function test_cobrar_luis_despues_genera_su_propia_factura_y_su_propio_cambio(): void
    {
        $cajero = $this->usuario('CAJERO');
        $escena = $this->montarDosSubcuentas($cajero);

        // Ana: cobro exacto en efectivo, sin ajustes.
        $this->cobrar($cajero, $escena['cuenta_a'], [$this->linea('EFECTIVO_CORDOBA', 300)])
            ->assertStatus(200)->assertJson(['exito' => true, 'cambio' => 0]);

        // Luis: paga C$ 500 por un consumo de C$ 200 → cambio C$ 300.
        $this->cobrar($cajero, $escena['cuenta_b'], [$this->linea('EFECTIVO_CORDOBA', 500)])
            ->assertStatus(200)->assertJson(['exito' => true, 'cambio' => 300]);

        $facturas = DB::table('facturas')->orderBy('id')->get();
        $this->assertCount(2, $facturas);
        $this->assertSame([$escena['cuenta_a'], $escena['cuenta_b']], $facturas->map(fn ($f) => (int) $f->cuenta_id)->all());
        $this->assertSame([300.0, 200.0], $facturas->map(fn ($f) => (float) $f->total)->all());

        // La reimpresión de Luis muestra su propio cambio y no el de Ana.
        $ticketLuis = $this->actingAs($cajero)
            ->get(route('facturas.ticket', ['id' => $facturas[1]->id]))
            ->assertStatus(200)
            ->assertSee('Luis')
            ->assertSee('#'.$escena['cuenta_b'])
            ->assertSee('200.00')
            ->assertSee('500.00')
            ->assertSee('300.00')
            ->assertSee('Cambio entregado')
            ->assertDontSee('Plato A');

        // Ana conserva su cambio en cero y su factura no muestra a Luis.
        $ticketAna = $this->actingAs($cajero)
            ->get(route('facturas.ticket', ['id' => $facturas[0]->id]))
            ->assertStatus(200)
            ->assertSee('Ana')
            ->assertSee('0.00')
            ->assertDontSee('Plato B');

        // Cada cobro tiene sus propios pagos.
        $this->assertSame(1, DB::table('pagos_cuenta')->where('cuenta_id', $escena['cuenta_a'])->count());
        $this->assertSame(1, DB::table('pagos_cuenta')->where('cuenta_id', $escena['cuenta_b'])->count());

        // Al pagar la última subcuenta, recién ahí se cierra la venta/mesa.
        $this->assertSame('PAGADA', DB::table('ventas')->find($escena['venta'])->estado);
        $this->assertSame('LIBRE', DB::table('mesas')->find($escena['mesa'])->estado);
    }

    /* ------------------------------------------------------------------
     | 3. Casos de ajustes y una sola cuenta
     | ------------------------------------------------------------------ */

    public function test_cobro_con_solo_descuento_se_refleja_en_la_factura(): void
    {
        $cajero = $this->usuario('CAJERO');
        $escena = $this->montarDosSubcuentas($cajero);

        // 300 − 30 = 270, sin propina.
        $this->cobrar($cajero, $escena['cuenta_a'], [$this->linea('EFECTIVO_CORDOBA', 270)], 30)
            ->assertStatus(200);

        $factura = DB::table('facturas')->where('cuenta_id', $escena['cuenta_a'])->sole();
        $detalle = $this->detalleFactura($factura->id);

        $this->assertSame(270.0, (float) $factura->total);
        $this->assertSame(30.0, (float) $detalle['ajustes']['descuento']);
        $this->assertSame(270.0, (float) $detalle['ajustes']['base']);
        $this->assertSame(0.0, (float) $detalle['ajustes']['propina']);

        $this->actingAs($cajero)
            ->get(route('facturas.ticket', ['id' => $factura->id]))
            ->assertStatus(200)
            ->assertSee('Descuento:')
            ->assertSee('30.00')
            ->assertSee('270.00');
    }

    public function test_cobro_con_solo_propina_se_refleja_en_la_factura(): void
    {
        $cajero = $this->usuario('CAJERO');
        $escena = $this->montarDosSubcuentas($cajero);

        // 300 + 30 = 330, sin descuento.
        $this->cobrar($cajero, $escena['cuenta_a'], [$this->linea('EFECTIVO_CORDOBA', 330)], 0, 30)
            ->assertStatus(200);

        $factura = DB::table('facturas')->where('cuenta_id', $escena['cuenta_a'])->sole();
        $detalle = $this->detalleFactura($factura->id);

        $this->assertSame(330.0, (float) $factura->total);
        $this->assertSame(0.0, (float) $detalle['ajustes']['descuento']);
        $this->assertSame(300.0, (float) $detalle['ajustes']['base']);
        $this->assertSame(30.0, (float) $detalle['ajustes']['propina']);

        $this->actingAs($cajero)
            ->get(route('facturas.ticket', ['id' => $factura->id]))
            ->assertStatus(200)
            ->assertSee('Propina:')
            ->assertSee('30.00')
            ->assertSee('330.00');
    }

    public function test_cobro_calcula_descuento_y_propina_porcentuales_en_el_servidor(): void
    {
        $cajero = $this->usuario('CAJERO');
        $escena = $this->montarDosSubcuentas($cajero);

        // Consumo 300; descuento 10 % = 30; base 270; propina 10 % = 27;
        // total 297. El navegador solo manda porcentajes.
        $this->actingAs($cajero)->post(route('pos.cuentas.pagar', $escena['cuenta_a']), [
            'descuento_porcentaje' => 10,
            'propina_porcentaje' => 10,
            'pagos' => [$this->linea('EFECTIVO_CORDOBA', 297)],
        ])->assertStatus(200);

        $factura = DB::table('facturas')->where('cuenta_id', $escena['cuenta_a'])->sole();
        $detalle = $this->detalleFactura($factura->id);

        $this->assertSame(297.0, (float) $factura->total);
        $this->assertSame(30.0, (float) $detalle['ajustes']['descuento']);
        $this->assertSame(270.0, (float) $detalle['ajustes']['base']);
        $this->assertSame(27.0, (float) $detalle['ajustes']['propina']);
    }

    public function test_cobro_no_confia_en_el_importe_manipulado_cuando_hay_porcentaje(): void
    {
        $cajero = $this->usuario('CAJERO');
        $escena = $this->montarDosSubcuentas($cajero);

        // El navegador manda un importe de descuento absurdo (casi el consumo)
        // pero también el porcentaje real (10 %). El servidor debe ignorar el
        // importe y recalcular: descuento 30, base 270, total 270.
        $this->actingAs($cajero)->post(route('pos.cuentas.pagar', $escena['cuenta_a']), [
            'descuento' => 299.99,
            'descuento_porcentaje' => 10,
            'pagos' => [$this->linea('EFECTIVO_CORDOBA', 270)],
        ])->assertStatus(200);

        $factura = DB::table('facturas')->where('cuenta_id', $escena['cuenta_a'])->sole();
        $detalle = $this->detalleFactura($factura->id);

        $this->assertSame(270.0, (float) $factura->total);
        $this->assertSame(30.0, (float) $detalle['ajustes']['descuento']);
        $this->assertSame(270.0, (float) $detalle['ajustes']['base']);
    }

    public function test_cobro_rechaza_descuento_porcentual_mayor_a_cien(): void
    {
        $cajero = $this->usuario('CAJERO');
        $escena = $this->montarDosSubcuentas($cajero);

        $this->actingAs($cajero)->postJson(route('pos.cuentas.pagar', $escena['cuenta_a']), [
            'descuento_porcentaje' => 150,
            'pagos' => [$this->linea('EFECTIVO_CORDOBA', 100)],
        ])->assertStatus(422)->assertJsonValidationErrors('descuento_porcentaje');

        // Nada se cobró.
        $this->assertSame(0, DB::table('facturas')->count());
        $this->assertSame('ABIERTA', DB::table('cuentas')->find($escena['cuenta_a'])->estado);
    }

    public function test_una_sola_cuenta_con_descuento_propina_y_pagos_mixtos_queda_pagada(): void
    {
        $cajero = $this->usuario('CAJERO');
        $escena = $this->montarDosSubcuentas($cajero);

        // Se paga solo Ana; Luis no existe en este escenario lógico, así que
        // se retira su consumo para que Ana sea la última cuenta pendiente.
        DB::table('cuenta_detalles')->where('cuenta_id', $escena['cuenta_b'])->update(['eliminado' => true]);
        DB::table('cuentas')->where('id', $escena['cuenta_b'])->update(['total' => 0]);

        // 300 − 40 = 260; + 15 = 275. Pagos: 200 efectivo + 75 tarjeta.
        $this->cobrar(
            $cajero,
            $escena['cuenta_a'],
            [
                $this->linea('EFECTIVO_CORDOBA', 200),
                $this->linea('TARJETA_CREDITO', 75, 'BAC'),
            ],
            40,
            15
        )->assertStatus(200)->assertJson(['exito' => true, 'mesa_liberada' => true]);

        $this->assertSame('PAGADA', DB::table('cuentas')->find($escena['cuenta_a'])->estado);
        $this->assertSame('PAGADA', DB::table('ventas')->find($escena['venta'])->estado);
        $this->assertSame('LIBRE', DB::table('mesas')->find($escena['mesa'])->estado);

        $this->assertSame(275.0, (float) DB::table('facturas')->sole()->total);
    }

    /* ------------------------------------------------------------------
     | 4. Historial: distinguir la subcuenta cobrada
     | ------------------------------------------------------------------ */

    public function test_historial_de_facturas_y_panel_distinguen_cada_subcuenta(): void
    {
        $gerente = $this->usuario('GERENTE');
        $escena = $this->montarDosSubcuentas($gerente);

        // Antes de cobrar, el panel de Ventas muestra ambas cuentas separadas.
        $this->actingAs($gerente)
            ->get(route('ventas.index'))
            ->assertStatus(200)
            ->assertSee('Ana')
            ->assertSee('Luis')
            ->assertSee('#'.$escena['cuenta_a'])
            ->assertSee('#'.$escena['cuenta_b'])
            ->assertSee('300.00')
            ->assertSee('200.00');

        $this->cobrar($gerente, $escena['cuenta_a'], [$this->linea('EFECTIVO_CORDOBA', 300)]);
        $this->cobrar($gerente, $escena['cuenta_b'], [$this->linea('EFECTIVO_CORDOBA', 200)]);

        // El historial de facturas identifica la cuenta cobrada en cada fila.
        $this->actingAs($gerente)
            ->get(route('facturas.index'))
            ->assertStatus(200)
            ->assertSee('FAC-0001')
            ->assertSee('FAC-0002')
            ->assertSee('Ana')
            ->assertSee('Luis')
            ->assertSee('#'.$escena['cuenta_a'])
            ->assertSee('#'.$escena['cuenta_b'])
            ->assertSee('300.00')
            ->assertSee('200.00');

        // El historial del panel de Ventas también detalla qué subcuenta se
        // cobró y con qué factura, en lugar de mostrar solo el total de la venta.
        $this->actingAs($gerente)
            ->get(route('ventas.index'))
            ->assertStatus(200)
            ->assertSee('FAC-0001')
            ->assertSee('FAC-0002')
            ->assertSee('Ana')
            ->assertSee('Luis')
            ->assertSee('#'.$escena['cuenta_a'])
            ->assertSee('#'.$escena['cuenta_b']);
    }

    /* ------------------------------------------------------------------
     | 5. Protecciones del cobro
     | ------------------------------------------------------------------ */

    public function test_pagar_ana_no_cierra_la_venta_si_luis_tiene_consumo_aunque_su_total_este_desincronizado(): void
    {
        $cajero = $this->usuario('CAJERO');
        $escena = $this->montarDosSubcuentas($cajero);

        // Simula un total denormalizado desactualizado en la subcuenta de
        // Luis. La fuente de verdad son sus cuenta_detalles (C$ 200).
        DB::table('cuentas')->where('id', $escena['cuenta_b'])->update(['total' => 0]);

        $this->cobrar($cajero, $escena['cuenta_a'], [$this->linea('EFECTIVO_CORDOBA', 300)])
            ->assertStatus(200);

        // Cobrar a Ana no puede cerrar la venta ni liberar la mesa mientras
        // Luis conserve consumo pendiente.
        $this->assertSame('ABIERTA', DB::table('cuentas')->find($escena['cuenta_b'])->estado);
        $this->assertSame('ACTIVA', DB::table('ventas')->find($escena['venta'])->estado);
        $this->assertSame('OCUPADA', DB::table('mesas')->find($escena['mesa'])->estado);

        // Y Luis debe seguir apareciendo en el panel con su importe real.
        $this->actingAs($cajero)
            ->get(route('ventas.index'))
            ->assertStatus(200)
            ->assertSee('Luis')
            ->assertSee('200.00');

        // Finalmente, Luis puede cobrarse con su consumo real.
        $this->cobrar($cajero, $escena['cuenta_b'], [$this->linea('EFECTIVO_CORDOBA', 200)])
            ->assertStatus(200);

        $this->assertSame('PAGADA', DB::table('ventas')->find($escena['venta'])->estado);
        $this->assertSame('LIBRE', DB::table('mesas')->find($escena['mesa'])->estado);
    }

    public function test_no_se_puede_cobrar_una_cuenta_de_una_venta_ya_cerrada(): void
    {
        $cajero = $this->usuario('CAJERO');
        $escena = $this->montarDosSubcuentas($cajero);

        // Estado inconsistente/heredado: la venta ya está PAGADA pero quedó
        // una cuenta ABIERTA con consumo. El backend debe rechazar el cobro.
        DB::table('ventas')->where('id', $escena['venta'])->update(['estado' => 'PAGADA']);

        $this->cobrar($cajero, $escena['cuenta_a'], [$this->linea('EFECTIVO_CORDOBA', 300)])
            ->assertStatus(422)
            ->assertJson(['exito' => false]);

        $this->assertSame('ABIERTA', DB::table('cuentas')->find($escena['cuenta_a'])->estado);
        $this->assertSame(0, DB::table('pagos_cuenta')->count());
        $this->assertSame(0, DB::table('facturas')->count());
    }

    /* ------------------------------------------------------------------
     | 6. Prefactura: desglose completo y requisito de cocina
     | ------------------------------------------------------------------ */

    public function test_prefactura_sin_ajustes_muestra_consumo_unitarios_y_ceros_explicitos(): void
    {
        $cajero = $this->usuario('CAJERO');
        $escena = $this->montarDosSubcuentas($cajero);

        DB::table('productos')->where('nombre', 'Plato A')->update(['requiere_cocina' => 1]);

        $respuesta = $this->actingAs($cajero)->get(route('pos.cuentas.prefactura', [
            'cuentaId' => $escena['cuenta_a'],
        ]));

        $respuesta->assertStatus(200)
            ->assertSee('P. unit.')
            ->assertSee('Plato A')
            ->assertSee('Cocina: Sí')
            // Cantidad, precio unitario y subtotal de ESTA cuenta.
            ->assertSee('3x')
            ->assertSee('100.00')
            ->assertSee('300.00')
            // Sin descuento ni propina se muestran ceros, no valores ausentes.
            ->assertSee('Consumo:')
            ->assertSee('Descuento:')
            ->assertSee('Base (consumo − descuento):')
            ->assertSee('Propina:')
            ->assertSee('0.00% de la base')
            ->assertSee('TOTAL POR COBRAR')
            // No mezcla la otra subcuenta.
            ->assertDontSee('Plato B')
            ->assertDontSee('Luis');

        // La prefactura es de SOLO LECTURA: no cobra, no factura, no cierra.
        $this->assertSame(0, DB::table('facturas')->count());
        $this->assertSame(0, DB::table('pagos_cuenta')->count());
        $this->assertSame('ABIERTA', DB::table('cuentas')->find($escena['cuenta_a'])->estado);
        $this->assertSame('ACTIVA', DB::table('ventas')->find($escena['venta'])->estado);
        $this->assertSame(0, DB::table('comandas')->count());
    }

    public function test_prefactura_con_solo_descuento_muestra_su_importe_y_propina_cero(): void
    {
        $cajero = $this->usuario('CAJERO');
        $escena = $this->montarDosSubcuentas($cajero);

        $this->actingAs($cajero)
            ->get(route('pos.cuentas.prefactura', [
                'cuentaId' => $escena['cuenta_a'],
                'descuento' => 50,
            ]))
            ->assertStatus(200)
            ->assertSee('Descuento:')
            ->assertSee('50.00')
            ->assertSee('Base (consumo − descuento):')
            ->assertSee('250.00')
            ->assertSee('0.00% de la base')
            ->assertSee('250.00');
    }

    public function test_prefactura_acepta_descuento_por_porcentaje_y_lo_convierte_a_importe(): void
    {
        $cajero = $this->usuario('CAJERO');
        $escena = $this->montarDosSubcuentas($cajero);

        // Consumo 300; descuento 10 % = 30; base 270.
        $this->actingAs($cajero)
            ->get(route('pos.cuentas.prefactura', [
                'cuentaId' => $escena['cuenta_a'],
                'descuento_porcentaje' => 10,
            ]))
            ->assertStatus(200)
            ->assertSee('Descuento:')
            ->assertSee('30.00')
            ->assertSee('Base (consumo − descuento):')
            ->assertSee('270.00');

        // Nada se guarda: la prefactura es solo lectura.
        $this->assertSame(0.0, (float) DB::table('cuentas')->find($escena['cuenta_a'])->descuento);
        $this->assertSame(0, DB::table('facturas')->count());
    }

    public function test_prefactura_acepta_propina_por_porcentaje_sobre_la_base_descontada(): void
    {
        $cajero = $this->usuario('CAJERO');
        $escena = $this->montarDosSubcuentas($cajero);

        // Consumo 300; descuento 10 % = 30; base 270; propina 10 % de 270 = 27;
        // total 297.
        $this->actingAs($cajero)
            ->get(route('pos.cuentas.prefactura', [
                'cuentaId' => $escena['cuenta_a'],
                'descuento_porcentaje' => 10,
                'propina_porcentaje' => 10,
            ]))
            ->assertStatus(200)
            ->assertSee('270.00')
            ->assertSee('27.00')
            ->assertSee('297.00');
    }

    public function test_prefactura_rechaza_porcentaje_de_descuento_mayor_a_cien(): void
    {
        $cajero = $this->usuario('CAJERO');
        $escena = $this->montarDosSubcuentas($cajero);

        $this->actingAs($cajero)
            ->get(route('pos.cuentas.prefactura', [
                'cuentaId' => $escena['cuenta_a'],
                'descuento_porcentaje' => 120,
            ]))
            ->assertStatus(422);
    }

    public function test_prefactura_rechaza_propina_porcentual_negativa(): void
    {
        $cajero = $this->usuario('CAJERO');
        $escena = $this->montarDosSubcuentas($cajero);

        $this->actingAs($cajero)
            ->get(route('pos.cuentas.prefactura', [
                'cuentaId' => $escena['cuenta_a'],
                'propina_porcentaje' => -5,
            ]))
            ->assertStatus(422);
    }

    public function test_cobro_con_propina_fija_en_el_servidor(): void
    {
        $cajero = $this->usuario('CAJERO');
        $escena = $this->montarDosSubcuentas($cajero);

        // Consumo 300 sin descuento; propina fija 45; total 345.
        $this->actingAs($cajero)->post(route('pos.cuentas.pagar', $escena['cuenta_a']), [
            'propina' => 45,
            'pagos' => [$this->linea('EFECTIVO_CORDOBA', 345)],
        ])->assertStatus(200);

        $factura = DB::table('facturas')->where('cuenta_id', $escena['cuenta_a'])->sole();
        $detalle = $this->detalleFactura($factura->id);

        $this->assertSame(345.0, (float) $factura->total);
        $this->assertSame(300.0, (float) $detalle['ajustes']['base']);
        $this->assertSame(45.0, (float) $detalle['ajustes']['propina']);
        $this->assertSame(0.0, (float) $detalle['ajustes']['descuento']);

        // La propina se guarda una sola vez en la cuenta.
        $this->assertSame(45.0, (float) DB::table('cuentas')->find($escena['cuenta_a'])->propina);
    }

    public function test_cobro_rechaza_descuento_fijo_mayor_al_consumo(): void
    {
        $cajero = $this->usuario('CAJERO');
        $escena = $this->montarDosSubcuentas($cajero);

        // Descuento 350 > consumo 300: se rechaza sin cobrar nada.
        $this->actingAs($cajero)->postJson(route('pos.cuentas.pagar', $escena['cuenta_a']), [
            'descuento' => 350,
            'pagos' => [$this->linea('EFECTIVO_CORDOBA', 1)],
        ])->assertStatus(422);

        $this->assertSame(0, DB::table('facturas')->count());
        $this->assertSame('ABIERTA', DB::table('cuentas')->find($escena['cuenta_a'])->estado);
    }

    public function test_prefactura_rechaza_descuento_fijo_mayor_al_consumo(): void
    {
        $cajero = $this->usuario('CAJERO');
        $escena = $this->montarDosSubcuentas($cajero);

        $this->actingAs($cajero)
            ->get(route('pos.cuentas.prefactura', [
                'cuentaId' => $escena['cuenta_a'],
                'descuento' => 350,
            ]))
            ->assertStatus(422);
    }

    public function test_prefactura_rechaza_valores_no_finitos(): void
    {
        $cajero = $this->usuario('CAJERO');
        $escena = $this->montarDosSubcuentas($cajero);

        // 1e400 desborda a INF: debe rechazarse sin romper ni persistir.
        $this->actingAs($cajero)
            ->get(route('pos.cuentas.prefactura', [
                'cuentaId' => $escena['cuenta_a'],
                'propina_porcentaje' => '1e400',
            ]))
            ->assertStatus(422);
    }

    public function test_prefactura_ignora_el_importe_manipulado_cuando_hay_porcentaje(): void
    {
        $cajero = $this->usuario('CAJERO');
        $escena = $this->montarDosSubcuentas($cajero);

        // Se envía un importe absurdo junto con el porcentaje real (10 %).
        // El servidor debe ignorar el importe y recalcular: descuento 30,
        // base 270, total 270.
        $this->actingAs($cajero)
            ->get(route('pos.cuentas.prefactura', [
                'cuentaId' => $escena['cuenta_a'],
                'descuento' => 299.99,
                'descuento_porcentaje' => 10,
            ]))
            ->assertStatus(200)
            ->assertSee('30.00')
            ->assertSee('270.00');

        // Nada se guarda: la prefactura es solo lectura.
        $this->assertSame(0.0, (float) DB::table('cuentas')->find($escena['cuenta_a'])->descuento);
    }

    public function test_prefactura_con_solo_propina_muestra_el_porcentaje_efectivo(): void
    {
        $cajero = $this->usuario('CAJERO');
        $escena = $this->montarDosSubcuentas($cajero);

        $this->actingAs($cajero)
            ->get(route('pos.cuentas.prefactura', [
                'cuentaId' => $escena['cuenta_a'],
                'propina' => 30,
            ]))
            ->assertStatus(200)
            ->assertSee('Descuento:')
            ->assertSee('0.00')
            ->assertSee('Base (consumo − descuento):')
            ->assertSee('300.00')
            // 30 / 300 = 10 % efectivo sobre la base.
            ->assertSee('10.00% de la base')
            ->assertSee('330.00')
            ->assertSee('TOTAL POR COBRAR');
    }

    public function test_prefactura_con_descuento_y_propina_usa_la_misma_base_del_cobro(): void
    {
        $cajero = $this->usuario('CAJERO');
        $escena = $this->montarDosSubcuentas($cajero);

        $this->actingAs($cajero)
            ->get(route('pos.cuentas.prefactura', [
                'cuentaId' => $escena['cuenta_a'],
                'descuento' => 50,
                'propina' => 20,
            ]))
            ->assertStatus(200)
            // Base 300 − 50 = 250; 20 / 250 = 8 % efectivo; total 270.
            ->assertSee('8.00% de la base')
            ->assertSee('250.00')
            ->assertSee('270.00');
    }

    public function test_prefactura_muestra_cocina_si_y_no_sin_crear_comandas_ni_cambiar_estados(): void
    {
        $cajero = $this->usuario('CAJERO');
        $escena = $this->montarDosSubcuentas($cajero);

        // Plato A va a cocina; se agrega un producto que NO va a cocina a la
        // MISMA cuenta para comprobar ambas indicaciones en una prefactura.
        DB::table('productos')->where('nombre', 'Plato A')->update(['requiere_cocina' => 1]);

        $productoC = DB::table('productos')->insertGetId([
            'nombre' => 'Bebida C',
            'precio' => 50,
            'requiere_cocina' => 0,
            'controla_stock' => 0,
            'activo' => 1,
        ]);

        $detalleC = DB::table('venta_detalles')->insertGetId([
            'venta_id' => $escena['venta'],
            'producto_id' => $productoC,
            'cantidad' => 2,
            'precio_unitario' => 50,
            'subtotal' => 100,
            'estado' => 'ACTIVO',
        ]);

        DB::table('cuenta_detalles')->insert([
            'cuenta_id' => $escena['cuenta_a'],
            'venta_id' => $escena['venta'],
            'venta_detalle_id' => $detalleC,
            'cantidad' => 2,
            'subtotal' => 100,
            'eliminado' => false,
        ]);

        $this->actingAs($cajero)
            ->get(route('pos.cuentas.prefactura', ['cuentaId' => $escena['cuenta_a']]))
            ->assertStatus(200)
            ->assertSee('Plato A')
            ->assertSee('Bebida C')
            ->assertSee('Cocina: Sí')
            ->assertSee('Cocina: No')
            // El subtotal real de la cuenta es 300 + 100 = 400.
            ->assertSee('400.00');

        // Imprimir/consultar la prefactura no crea comandas ni altera sus
        // estados ni la cuenta.
        $this->assertSame(0, DB::table('comandas')->count());
        $this->assertSame(0, DB::table('comanda_detalles')->count());
        $this->assertSame('ABIERTA', DB::table('cuentas')->find($escena['cuenta_a'])->estado);
        $this->assertSame('ACTIVA', DB::table('ventas')->find($escena['venta'])->estado);
    }

    public function test_prefactura_rechaza_ajustes_no_numericos(): void
    {
        $cajero = $this->usuario('CAJERO');
        $escena = $this->montarDosSubcuentas($cajero);

        $this->actingAs($cajero)
            ->get(route('pos.cuentas.prefactura', [
                'cuentaId' => $escena['cuenta_a'],
                'descuento' => 'no-es-numero',
            ]))
            ->assertStatus(422);
    }
}
