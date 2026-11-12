<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ConfiguracionService;
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

    public function test_cobro_rechaza_montos_y_porcentajes_con_formato_invalido(): void
    {
        $cajero = $this->usuario('CAJERO');
        $escena = $this->montarDosSubcuentas($cajero);

        $lineaValida = $this->linea('EFECTIVO_CORDOBA', 300);
        $lineaInvalida = $lineaValida;
        $lineaInvalida['monto'] = '1e3';

        $casos = [
            ['descuento_porcentaje' => 'e'],
            ['descuento_porcentaje' => '0e'],
            ['descuento_porcentaje' => '0e13212312132121'],
            ['descuento_porcentaje' => '1e3'],
            ['descuento_porcentaje' => '10abc'],
            ['descuento_porcentaje' => '25aasasdasdasdasasd'],
            ['descuento_porcentaje' => '--10'],
            ['descuento_porcentaje' => '10%%'],
            ['descuento_porcentaje' => '-10'],
            ['descuento_porcentaje' => '10.999'],
            ['propina_porcentaje' => '0e13212312132121'],
            ['propina_porcentaje' => '25aasasdasdasdasasd'],
            ['propina_porcentaje' => '10%%'],
            ['propina' => '1e3'],
            ['propina' => '-5'],
            ['descuento' => '1e3'],
            ['descuento' => '10abc'],
            ['descuento' => '10000000000'],
            ['propina' => '10000000000'],
            ['descuento_porcentaje' => '101'],
            ['monto_recibido' => '1e3'],
            ['monto_recibido' => '100000000'],
            ['pagos' => [$lineaInvalida]],
            ['pagos' => [[
                'clave_operacion' => 'pago-monto-enorme',
                'metodo_pago' => 'EFECTIVO_CORDOBA',
                'monto' => '10000000000',
            ]]],
        ];

        foreach ($casos as $caso) {
            $this->actingAs($cajero)
                ->postJson(route('pos.cuentas.pagar', $escena['cuenta_a']), $caso + [
                    'pagos' => [$lineaValida],
                ])
                ->assertStatus(422);
        }

        $this->assertSame(0, DB::table('facturas')->count());
        $this->assertSame(0, DB::table('pagos_cuenta')->count());
        $this->assertSame('ABIERTA', DB::table('cuentas')->find($escena['cuenta_a'])->estado);
    }

    public function test_descuento_y_propina_vacios_se_tratan_como_sin_ajuste(): void
    {
        $cajero = $this->usuario('CAJERO');
        $escena = $this->montarDosSubcuentas($cajero);

        // Los ajustes son opcionales: vacío = sin descuento ni propina.
        // No debe convertirse un valor INVÁLIDO en cero (eso se prueba aparte).
        $this->actingAs($cajero)->post(route('pos.cuentas.pagar', $escena['cuenta_a']), [
            'descuento' => '',
            'propina' => '',
            'pagos' => [$this->linea('EFECTIVO_CORDOBA', 300)],
        ])->assertStatus(200);

        $factura = DB::table('facturas')->where('cuenta_id', $escena['cuenta_a'])->sole();
        $detalle = $this->detalleFactura($factura->id);

        $this->assertSame(300.0, (float) $factura->total);
        $this->assertSame(0.0, (float) $detalle['ajustes']['descuento']);
        $this->assertSame(0.0, (float) $detalle['ajustes']['propina']);
    }

    public function test_cobro_acepta_porcentajes_escritos_con_simbolo_de_porcentaje(): void
    {
        $cajero = $this->usuario('CAJERO');
        $escena = $this->montarDosSubcuentas($cajero);

        // Consumo 300; descuento 10% = 30; base 270; propina 10% = 27; total 297.
        $this->actingAs($cajero)->post(route('pos.cuentas.pagar', $escena['cuenta_a']), [
            'descuento_porcentaje' => '10%',
            'propina_porcentaje' => '10%',
            'pagos' => [$this->linea('EFECTIVO_CORDOBA', 297)],
        ])->assertStatus(200);

        $factura = DB::table('facturas')->where('cuenta_id', $escena['cuenta_a'])->sole();
        $detalle = $this->detalleFactura($factura->id);

        $this->assertSame(297.0, (float) $factura->total);
        $this->assertSame(30.0, (float) $detalle['ajustes']['descuento']);
        $this->assertSame(27.0, (float) $detalle['ajustes']['propina']);
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
            ->assertDontSee('P. unit.')
            ->assertDontSee('Subtotal')
            ->assertSee('Plato A')
            ->assertSee('Cocina: Sí')
            // Cantidad y producto de ESTA cuenta (sin precio unitario ni subtotal individual).
            ->assertSee('3x')
            ->assertDontSee('100.00')
            // Sin descuento ni propina no se muestran esas filas.
            ->assertSee('Consumo:')
            ->assertDontSee('Descuento:')
            ->assertDontSee('Base (consumo − descuento):')
            ->assertDontSee('Propina:')
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

    public function test_prefactura_rechaza_porcentajes_con_formato_invalido(): void
    {
        $cajero = $this->usuario('CAJERO');
        $escena = $this->montarDosSubcuentas($cajero);

        foreach (['e', '0e', '0e13212312132121', '1e3', '10abc', '25aasasdasdasdasasd', '--10', '10%%', '-5', '10.999'] as $valor) {
            $this->actingAs($cajero)
                ->get(route('pos.cuentas.prefactura', [
                    'cuentaId' => $escena['cuenta_a'],
                    'propina_porcentaje' => $valor,
                ]))
                ->assertStatus(422);
        }
    }

    public function test_prefactura_acepta_porcentaje_con_simbolo(): void
    {
        $cajero = $this->usuario('CAJERO');
        $escena = $this->montarDosSubcuentas($cajero);

        // Consumo 300; descuento 10% = 30; base 270; propina 10% = 27; total 297.
        $this->actingAs($cajero)
            ->get(route('pos.cuentas.prefactura', [
                'cuentaId' => $escena['cuenta_a'],
                'descuento_porcentaje' => '10%',
                'propina_porcentaje' => '10%',
            ]))
            ->assertStatus(200)
            ->assertSee('30.00')
            ->assertSee('27.00')
            ->assertSee('297.00');
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
            ->assertDontSee('Descuento:')
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

    /* ------------------------------------------------------------------
     | 7. IVA en prefactura y controles fiscales del modal de cobro
     | ------------------------------------------------------------------ */

    public function test_prefactura_muestra_la_linea_de_iva_con_switch_activado(): void
    {
        $cajero = $this->usuario('CAJERO');
        $escena = $this->montarDosSubcuentas($cajero);

        DB::table('configuraciones')->insert([
            'clave' => ConfiguracionService::CLAVE_IVA,
            'valor' => '1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Consumo 300 -> IVA (15 %) = 45 -> total con IVA = 345.
        $respuesta = $this->actingAs($cajero)->get(route('pos.cuentas.prefactura', ['cuentaId' => $escena['cuenta_a']]));

        $respuesta->assertStatus(200)
            ->assertSee('IVA (15 %):')
            ->assertSee('+ C$ 45.00')
            ->assertSee('TOTAL POR COBRAR:');

        $this->assertStringContainsString('345.00', (string) $respuesta->getContent());
    }

    public function test_prefactura_no_muestra_la_linea_de_iva_con_switch_desactivado(): void
    {
        $cajero = $this->usuario('CAJERO');
        $escena = $this->montarDosSubcuentas($cajero);

        DB::table('configuraciones')->insert([
            'clave' => ConfiguracionService::CLAVE_IVA,
            'valor' => '0',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Consumo 300 -> sin IVA -> total = 300.
        $respuesta = $this->actingAs($cajero)->get(route('pos.cuentas.prefactura', ['cuentaId' => $escena['cuenta_a']]));

        $respuesta->assertStatus(200)
            ->assertDontSee('IVA (15 %):')
            ->assertSee('TOTAL POR COBRAR:')
            ->assertSee('300.00');
    }

    public function test_cobrar_modal_muestra_controles_fiscales_con_iva_y_retenciones_para_cliente_sujeto(): void
    {
        $cajero = $this->usuario('CAJERO');
        $escena = $this->montarDosSubcuentas($cajero);

        DB::table('configuraciones')->insert([
            'clave' => ConfiguracionService::CLAVE_IVA,
            'valor' => '1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('configuraciones')->insert([
            'clave' => ConfiguracionService::CLAVE_RETENCION_DGI,
            'valor' => '1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('configuraciones')->insert([
            'clave' => ConfiguracionService::CLAVE_RETENCION_ALCALDIA,
            'valor' => '1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Cliente sujeto a retenciones (como el caso real de la cuenta 53).
        $clienteId = DB::table('clientes')->insertGetId([
            'nombre' => 'Cliente Sujeto',
            'activo' => true,
            'creado_en' => now(),
            'sujeto_retenciones' => true,
        ]);
        DB::table('ventas')->where('id', $escena['venta'])->update(['cliente_id' => $clienteId]);

        $respuesta = $this->actingAs($cajero)->get('/pos/'.$escena['mesa']);

        $respuesta->assertStatus(200);

        $html = (string) $respuesta->getContent();

        // Botonera fiscal siempre presente con los tres conceptos.
        $this->assertStringContainsString('fiscal-toggle', $html);
        $this->assertStringContainsString('data-concepto="iva"', $html);
        $this->assertStringContainsString('data-concepto="dgi"', $html);
        $this->assertStringContainsString('data-concepto="alc"', $html);
        $this->assertStringContainsString('aria-pressed="${ivaActivo}"', $html);

        // Controles de descuento y propina con % y importe fijo sincronizados.
        $this->assertStringContainsString('descuento-porcentaje-cobro', $html);
        $this->assertStringContainsString('descuento-importe-cobro', $html);
        $this->assertStringContainsString('propina-porcentaje-cobro', $html);
        $this->assertStringContainsString('propina-importe-cobro', $html);
        $this->assertStringContainsString('cobro-grid', $html);
    }

    public function test_cobrar_modal_muestra_controles_fiscales_con_iva_desactivado_y_cliente_no_sujeto(): void
    {
        $cajero = $this->usuario('CAJERO');
        $escena = $this->montarDosSubcuentas($cajero);

        DB::table('configuraciones')->insert([
            'clave' => ConfiguracionService::CLAVE_IVA,
            'valor' => '0',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('configuraciones')->insert([
            'clave' => ConfiguracionService::CLAVE_RETENCION_DGI,
            'valor' => '1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Cliente NO sujeto a retenciones.
        $clienteId = DB::table('clientes')->insertGetId([
            'nombre' => 'Cliente No Sujeto',
            'activo' => true,
            'creado_en' => now(),
            'sujeto_retenciones' => false,
        ]);
        DB::table('ventas')->where('id', $escena['venta'])->update(['cliente_id' => $clienteId]);

        $respuesta = $this->actingAs($cajero)->get('/pos/'.$escena['mesa']);

        $respuesta->assertStatus(200);

        $html = (string) $respuesta->getContent();

        // El IVA global está apagado: el control se ve DESHABILITADO con nota.
        $this->assertStringContainsString('IVA desactivado en la configuración', $html);
        $this->assertStringContainsString('data-concepto="iva" data-active="${ivaActivo ? \'true\' : \'false\'}"', $html);
        $this->assertStringContainsString('Cliente no sujeto a retención', $html);
    }

    public function test_prefactura_con_todos_los_conceptos_desactivados_muestra_solo_consumo_y_total(): void
    {
        $cajero = $this->usuario('CAJERO');
        $escena = $this->montarDosSubcuentas($cajero);

        // Todos los conceptos fiscales desactivados globalmente.
        DB::table('configuraciones')->insert([
            'clave' => ConfiguracionService::CLAVE_IVA,
            'valor' => '0',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('configuraciones')->insert([
            'clave' => ConfiguracionService::CLAVE_RETENCION_DGI,
            'valor' => '0',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('configuraciones')->insert([
            'clave' => ConfiguracionService::CLAVE_RETENCION_ALCALDIA,
            'valor' => '0',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('configuraciones')->insert([
            'clave' => ConfiguracionService::CLAVE_DESCUENTO_FISCAL,
            'valor' => '0',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('configuraciones')->insert([
            'clave' => ConfiguracionService::CLAVE_PROPINA_FISCAL,
            'valor' => '0',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Consumo 300, sin descuento ni propina -> total = 300.
        $respuesta = $this->actingAs($cajero)->get(route('pos.cuentas.prefactura', [
            'cuentaId' => $escena['cuenta_a'],
        ]));

        $respuesta->assertStatus(200)
            ->assertSee('Consumo:')
            ->assertSee('C$ 300.00')
            ->assertSee('TOTAL POR COBRAR:')
            ->assertSee('C$ 300.00')
            ->assertDontSee('IVA (15 %):')
            ->assertDontSee('Descuento:')
            ->assertDontSee('Propina:')
            ->assertDontSee('Retención DGI')
            ->assertDontSee('Retención Alcaldía')
            ->assertDontSee('Conceptos fiscales')
            ->assertDontSee('ACTIVO')
            ->assertDontSee('INACTIVO');
    }

    public function test_prefactura_con_iva_y_retenciones_para_cliente_sujeto(): void
    {
        $cajero = $this->usuario('CAJERO');
        $escena = $this->montarDosSubcuentas($cajero);

        // IVA, DGI y Alcaldía habilitados globalmente.
        DB::table('configuraciones')->insert([
            'clave' => ConfiguracionService::CLAVE_IVA,
            'valor' => '1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('configuraciones')->insert([
            'clave' => ConfiguracionService::CLAVE_RETENCION_DGI,
            'valor' => '1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('configuraciones')->insert([
            'clave' => ConfiguracionService::CLAVE_RETENCION_ALCALDIA,
            'valor' => '1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Cliente sujeto a retenciones.
        $clienteId = DB::table('clientes')->insertGetId([
            'nombre' => 'Cliente Sujeto',
            'activo' => true,
            'creado_en' => now(),
            'sujeto_retenciones' => true,
        ]);
        DB::table('ventas')->where('id', $escena['venta'])->update(['cliente_id' => $clienteId]);

        // Consumo 300 -> IVA 45 (15%), DGI 6 (2%), Alcaldía 3 (1%).
        // Total factura = 345, total retenciones = 9, neto = 336.
        $respuesta = $this->actingAs($cajero)->get(route('pos.cuentas.prefactura', [
            'cuentaId' => $escena['cuenta_a'],
        ]));

        $respuesta->assertStatus(200)
            ->assertSee('Consumo:')
            ->assertSee('C$ 300.00')
            ->assertSee('IVA (15 %):')
            ->assertSee('+ C$ 45.00')
            ->assertSee('Retención DGI (2 %):')
            ->assertSee('- C$ 6.00')
            ->assertSee('Retención Alcaldía (1 %):')
            ->assertSee('- C$ 3.00')
            ->assertSee('Total retenciones:')
            ->assertSee('- C$ 9.00')
            ->assertSee('TOTAL POR COBRAR:')
            ->assertSee('C$ 336.00');
    }

    public function test_prefactura_con_propina_porcentual_manual_muestra_porcentaje_efectivo(): void
        {
        $cajero = $this->usuario('CAJERO');
        $escena = $this->montarDosSubcuentas($cajero);

        // Desactivar IVA para simplificar.
        DB::table('configuraciones')->insert([
            'clave' => ConfiguracionService::CLAVE_IVA,
            'valor' => '0',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Consumo 300, propina 10% sobre base 300 = 30 -> total = 330.
        $respuesta = $this->actingAs($cajero)->get(route('pos.cuentas.prefactura', [
            'cuentaId' => $escena['cuenta_a'],
            'propina' => '30',
        ]));

        $respuesta->assertStatus(200)
            ->assertSee('Propina:')
            ->assertSee('+ C$ 30.00')
            ->assertSee('TOTAL POR COBRAR:')
            ->assertSee('C$ 330.00');
    }

    public function test_prefactura_admin_puede_desactivar_iva_como_excepcion(): void
    {
        $admin = $this->usuario('ADMIN');
        $escena = $this->montarDosSubcuentas($admin);

        // IVA habilitado globalmente.
        DB::table('configuraciones')->insert([
            'clave' => ConfiguracionService::CLAVE_IVA,
            'valor' => '1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // ADMIN desactiva IVA para esta operación (excepcion por factura).
        $respuesta = $this->actingAs($admin)->get(route('pos.cuentas.prefactura', [
            'cuentaId' => $escena['cuenta_a'],
            'iva_habilitado' => '0',
        ]));

        $respuesta->assertStatus(200)
            ->assertSee('Consumo:')
            ->assertSee('C$ 300.00')
            ->assertDontSee('IVA (15 %):')
            ->assertSee('TOTAL POR COBRAR:')
            ->assertSee('C$ 300.00');
    }

    public function test_prefactura_gerente_puede_desactivar_iva_como_excepcion(): void
    {
        $gerente = $this->usuario('GERENTE');
        $escena = $this->montarDosSubcuentas($gerente);

        // IVA habilitado globalmente.
        DB::table('configuraciones')->insert([
            'clave' => ConfiguracionService::CLAVE_IVA,
            'valor' => '1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // GERENTE desactiva IVA para esta operación (excepcion por factura).
        $respuesta = $this->actingAs($gerente)->get(route('pos.cuentas.prefactura', [
            'cuentaId' => $escena['cuenta_a'],
            'iva_habilitado' => '0',
        ]));

        $respuesta->assertStatus(200)
            ->assertSee('Consumo:')
            ->assertSee('C$ 300.00')
            ->assertDontSee('IVA (15 %):')
            ->assertSee('TOTAL POR COBRAR:')
            ->assertSee('C$ 300.00');
    }

    public function test_prefactura_cajero_no_puede_desactivar_iva_usa_config_global(): void
    {
        $cajero = $this->usuario('CAJERO');
        $escena = $this->montarDosSubcuentas($cajero);

        // IVA habilitado globalmente.
        DB::table('configuraciones')->insert([
            'clave' => ConfiguracionService::CLAVE_IVA,
            'valor' => '1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // CAJERO intenta desactivar IVA pero el backend ignora el toggle (usa config global).
        $respuesta = $this->actingAs($cajero)->get(route('pos.cuentas.prefactura', [
            'cuentaId' => $escena['cuenta_a'],
            'iva_habilitado' => '0',
        ]));

        $respuesta->assertStatus(200)
            ->assertSee('Consumo:')
            ->assertSee('C$ 300.00')
            ->assertSee('IVA (15 %):')
            ->assertSee('+ C$ 45.00')
            ->assertSee('TOTAL POR COBRAR:')
            ->assertSee('C$ 345.00');
    }

    public function test_prefactura_cliente_no_sujeto_no_permite_retenciones(): void
    {
        $admin = $this->usuario('ADMIN');
        $escena = $this->montarDosSubcuentas($admin);

        // IVA, DGI y Alcaldía habilitados globalmente.
        foreach ([ConfiguracionService::CLAVE_IVA, ConfiguracionService::CLAVE_RETENCION_DGI, ConfiguracionService::CLAVE_RETENCION_ALCALDIA] as $clave) {
            DB::table('configuraciones')->insert([
                'clave' => $clave,
                'valor' => '1',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Cliente NO sujeto a retenciones.
        $clienteId = DB::table('clientes')->insertGetId([
            'nombre' => 'Cliente No Sujeto',
            'activo' => true,
            'creado_en' => now(),
            'sujeto_retenciones' => false,
        ]);
        DB::table('ventas')->where('id', $escena['venta'])->update(['cliente_id' => $clienteId]);

        // ADMIN intenta activar DGI y Alcaldía pero el cliente no es sujeto.
        $respuesta = $this->actingAs($admin)->get(route('pos.cuentas.prefactura', [
            'cuentaId' => $escena['cuenta_a'],
            'iva_habilitado' => '1',
            'retencion_dgi_habilitada' => '1',
            'retencion_alcaldia_habilitada' => '1',
        ]));

        $respuesta->assertStatus(200)
            ->assertSee('Consumo:')
            ->assertSee('C$ 300.00')
            ->assertSee('IVA (15 %):')
            ->assertSee('+ C$ 45.00')
            ->assertDontSee('Retención DGI')
            ->assertDontSee('Retención Alcaldía')
            ->assertSee('TOTAL POR COBRAR:')
            ->assertSee('C$ 345.00');
    }

    public function test_prefactura_excepcion_no_modifica_configuracion_global(): void
    {
        $admin = $this->usuario('ADMIN');
        $escena = $this->montarDosSubcuentas($admin);

        // IVA habilitado globalmente.
        DB::table('configuraciones')->insert([
            'clave' => ConfiguracionService::CLAVE_IVA,
            'valor' => '1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // ADMIN desactiva IVA en una prefactura (excepcion por factura).
        $this->actingAs($admin)->get(route('pos.cuentas.prefactura', [
            'cuentaId' => $escena['cuenta_a'],
            'iva_habilitado' => '0',
        ]));

        // Otra prefactura SIN el parametro de excepcion debe usar la config global (IVA activado).
        $respuesta = $this->actingAs($admin)->get(route('pos.cuentas.prefactura', [
            'cuentaId' => $escena['cuenta_a'],
        ]));

        $respuesta->assertStatus(200)
            ->assertSee('IVA (15 %):')
            ->assertSee('+ C$ 45.00')
            ->assertSee('TOTAL POR COBRAR:')
            ->assertSee('C$ 345.00');
    }

    public function test_prefactura_no_registra_cobro_ni_emite_factura(): void
    {
        $cajero = $this->usuario('CAJERO');
        $escena = $this->montarDosSubcuentas($cajero);

        // IVA habilitado globalmente.
        DB::table('configuraciones')->insert([
            'clave' => ConfiguracionService::CLAVE_IVA,
            'valor' => '1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $respuesta = $this->actingAs($cajero)->get(route('pos.cuentas.prefactura', [
            'cuentaId' => $escena['cuenta_a'],
            'iva_habilitado' => '1',
        ]));

        $respuesta->assertStatus(200);

        // La prefactura es de solo lectura: no cobra, no factura, no cierra.
        $this->assertSame(0, DB::table('facturas')->count());
        $this->assertSame(0, DB::table('pagos_cuenta')->count());
        $this->assertSame('ABIERTA', DB::table('cuentas')->find($escena['cuenta_a'])->estado);
        $this->assertSame('ACTIVA', DB::table('ventas')->find($escena['venta'])->estado);
    }
}
