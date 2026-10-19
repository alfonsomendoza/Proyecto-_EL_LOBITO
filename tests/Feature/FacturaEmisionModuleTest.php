<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Concerns\CreaEsquemaCompleto;
use Tests\TestCase;

/*
 * Pruebas de la EMISIÓN de facturas desde el cobro del POS.
 *
 * IMPORTANTE:
 * - Corren en SQLite :memory: (configurado en phpunit.xml); nunca escriben en
 *   la base real "sistemadecaja".
 * - El esquema se construye con el trait compartido, que replica el MySQL real.
 * - setUp() falla a propósito si la conexión no es SQLite en memoria.
 *
 * Reglas comprobadas:
 * - Cobrar una cuenta emite EXACTAMENTE una factura, asociada a esa cuenta.
 * - La numeración FAC-#### no se repite ni se solapa entre cobros.
 * - Un número ocupado por un registro previo se omite en lugar de fallar.
 * - Cobrar dos veces la misma cuenta no genera una segunda factura.
 * - Si la factura no se puede guardar, el cobro entero se revierte.
 * - Permisos: MESERO no cobra y por lo tanto no emite facturas.
 * - Un monto insuficiente no emite factura.
 * - Facturas distintas por cada cuenta de una mesa dividida.
 * - La factura emitida se puede imprimir con su detalle real.
 */
class FacturaEmisionModuleTest extends TestCase
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
     * Monta una mesa con una venta activa y tantas cuentas como se pidan,
     * cada una con su propio consumo repartido en venta_detalles y
     * cuenta_detalles (misma fuente que usa el cobro y la factura).
     */
    private function montarMesa(User $cajero, int $numeroCuentas = 1, float $precio = 100, int $unidades = 2): array
    {
        $mesaId = DB::table('mesas')->insertGetId([
            'numero' => 'Mesa 9',
            'capacidad' => 4,
            'estado' => 'OCUPADA',
        ]);

        $ventaId = DB::table('ventas')->insertGetId([
            'mesa_id' => $mesaId,
            'descripcion' => 'Mesa 9',
            'monto' => $precio * $unidades * $numeroCuentas,
            'estado' => 'ACTIVA',
            'usuario_id' => $cajero->id,
            'fecha' => now(),
        ]);

        $cuentas = [];

        for ($i = 1; $i <= $numeroCuentas; $i++) {
            $productoId = DB::table('productos')->insertGetId([
                'nombre' => 'Plato '.$i,
                'precio' => $precio,
                'requiere_cocina' => 0,
                'controla_stock' => 0,
                'activo' => 1,
            ]);

            $detalleId = DB::table('venta_detalles')->insertGetId([
                'venta_id' => $ventaId,
                'producto_id' => $productoId,
                'cantidad' => $unidades,
                'precio_unitario' => $precio,
                'subtotal' => $precio * $unidades,
                'estado' => 'ACTIVO',
            ]);

            $cuentaId = DB::table('cuentas')->insertGetId([
                'venta_id' => $ventaId,
                'nombre' => 'Cuenta '.$i,
                'total' => $precio * $unidades,
                'estado' => 'ABIERTA',
            ]);

            DB::table('cuenta_detalles')->insert([
                'cuenta_id' => $cuentaId,
                'venta_id' => $ventaId,
                'venta_detalle_id' => $detalleId,
                'cantidad' => $unidades,
                'subtotal' => $precio * $unidades,
                'eliminado' => false,
            ]);

            $cuentas[] = $cuentaId;
        }

        return ['mesa' => $mesaId, 'venta' => $ventaId, 'cuentas' => $cuentas];
    }

    private function cobrar(User $cajero, int $cuentaId, float $recibido)
    {
        return $this->actingAs($cajero)->post(route('pos.cuentas.pagar', $cuentaId), [
            'metodo_pago' => 'EFECTIVO',
            'monto_recibido' => $recibido,
        ]);
    }

    /* ------------------------------------------------------------------
     | Emisión
     | ------------------------------------------------------------------ */

    public function test_cobro_emite_factura_asociada_a_la_cuenta(): void
    {
        $cajero = $this->usuario('CAJERO');
        $escena = $this->montarMesa($cajero, 1, 100, 2); // total C$ 200

        $this->cobrar($cajero, $escena['cuentas'][0], 500)
            ->assertStatus(200)
            ->assertJson(['exito' => true, 'factura' => 'FAC-0001']);

        $factura = DB::table('facturas')->sole();

        $this->assertSame('FAC-0001', $factura->numero_factura);
        $this->assertSame($escena['venta'], (int) $factura->venta_id);
        $this->assertSame($escena['cuentas'][0], (int) $factura->cuenta_id);
        $this->assertSame($cajero->id, (int) $factura->usuario_id);
        $this->assertSame('EMITIDA', $factura->estado);
        $this->assertSame(200.0, (float) $factura->total);

        // El detalle guardado debe reflejar el consumo realmente cobrado.
        $detalle = json_decode($factura->detalle_consumo, true);
        $this->assertCount(1, $detalle['lineas']);
        $this->assertSame('Plato 1', $detalle['lineas'][0]['producto']);
        $this->assertSame(2, $detalle['lineas'][0]['cantidad']);
        $this->assertSame(200.0, (float) $detalle['lineas'][0]['subtotal']);

        // La cuenta y la venta quedan efectivamente pagadas.
        $this->assertSame('PAGADA', DB::table('cuentas')->find($escena['cuentas'][0])->estado);
        $this->assertSame('PAGADA', DB::table('ventas')->find($escena['venta'])->estado);
        $this->assertSame('LIBRE', DB::table('mesas')->find($escena['mesa'])->estado);
    }

    public function test_total_de_la_factura_coincide_con_lo_cobrado(): void
    {
        $cajero = $this->usuario('CAJERO');
        $escena = $this->montarMesa($cajero, 1, 65, 3); // C$ 195

        $this->cobrar($cajero, $escena['cuentas'][0], 200)->assertStatus(200);

        $totalCuenta = (float) DB::table('cuenta_detalles')
            ->where('cuenta_id', $escena['cuentas'][0])
            ->where('eliminado', false)
            ->sum('subtotal');

        $this->assertSame($totalCuenta, (float) DB::table('facturas')->sole()->total);
    }

    /* ------------------------------------------------------------------
     | Numeración
     | ------------------------------------------------------------------ */

    public function test_numeracion_consecutiva_sin_repetirse(): void
    {
        $cajero = $this->usuario('CAJERO');
        $escena = $this->montarMesa($cajero, 2, 100, 1);

        $this->cobrar($cajero, $escena['cuentas'][0], 100)->assertStatus(200);
        $this->cobrar($cajero, $escena['cuentas'][1], 100)->assertStatus(200);

        $this->assertSame(
            ['FAC-0001', 'FAC-0002'],
            DB::table('facturas')->orderBy('id')->pluck('numero_factura')->all()
        );
    }

    public function test_numero_ocupado_por_un_registro_previo_se_omite(): void
    {
        $cajero = $this->usuario('CAJERO');

        // Un registro anterior ocupa FAC-0002 (la que le tocaría al cobro).
        DB::table('facturas')->insert([
            'numero_factura' => 'FAC-0002',
            'venta_id' => DB::table('ventas')->insertGetId([
                'mesa_id' => null,
                'descripcion' => 'manual',
                'monto' => 10,
                'estado' => 'PAGADA',
            ]),
            'mesa' => 'Mesa manual',
            'detalle_consumo' => json_encode(['nota' => 'manual']),
            'total' => 10,
            'fecha_emision' => now(),
            'usuario_id' => $cajero->id,
            'estado' => 'EMITIDA',
        ]);

        $escena = $this->montarMesa($cajero, 1, 100, 1);

        // El cobro obtiene el id 2 -> FAC-0002 está ocupada, debe saltar a 0003.
        $this->cobrar($cajero, $escena['cuentas'][0], 100)
            ->assertStatus(200)
            ->assertJson(['exito' => true, 'factura' => 'FAC-0003']);

        $this->assertSame(
            ['FAC-0002', 'FAC-0003'],
            DB::table('facturas')->orderBy('id')->pluck('numero_factura')->all()
        );
    }

    /* ------------------------------------------------------------------
     | Duplicados y transaccionalidad
     | ------------------------------------------------------------------ */

    public function test_cobrar_dos_veces_no_genera_una_segunda_factura(): void
    {
        $cajero = $this->usuario('CAJERO');
        $escena = $this->montarMesa($cajero, 1, 100, 1);

        $this->cobrar($cajero, $escena['cuentas'][0], 100)->assertStatus(200);

        // El segundo cobro debe rechazarse: la cuenta ya no está ABIERTA.
        $this->cobrar($cajero, $escena['cuentas'][0], 100)
            ->assertStatus(422)
            ->assertJson(['exito' => false]);

        $this->assertSame(1, DB::table('facturas')->count());
    }

    public function test_monto_insuficiente_no_emite_factura(): void
    {
        $cajero = $this->usuario('CAJERO');
        $escena = $this->montarMesa($cajero, 1, 100, 2); // C$ 200

        $this->cobrar($cajero, $escena['cuentas'][0], 150)
            ->assertStatus(422)
            ->assertJson(['exito' => false]);

        $this->assertSame(0, DB::table('facturas')->count());
        $this->assertSame('ABIERTA', DB::table('cuentas')->find($escena['cuentas'][0])->estado);
    }

    public function test_si_no_se_puede_guardar_la_factura_se_revuelve_el_cobro(): void
    {
        $cajero = $this->usuario('CAJERO');
        $escena = $this->montarMesa($cajero, 1, 100, 2);

        // Se elimina la tabla para forzar el fallo de la inserción de la
        // factura. El pago NO debe quedar registrado sin su comprobante.
        Schema::drop('facturas');

        $this->cobrar($cajero, $escena['cuentas'][0], 500)
            ->assertStatus(422)
            ->assertJson(['exito' => false]);

        // Nada del cobro debe haberse persistido.
        $this->assertSame('ABIERTA', DB::table('cuentas')->find($escena['cuentas'][0])->estado);
        $this->assertSame('ACTIVA', DB::table('ventas')->find($escena['venta'])->estado);
        $this->assertSame('OCUPADA', DB::table('mesas')->find($escena['mesa'])->estado);
    }

    /* ------------------------------------------------------------------
     | Permisos
     | ------------------------------------------------------------------ */

    public function test_mesero_no_puede_cobrar_ni_emitir_factura(): void
    {
        $mesero = $this->usuario('MESERO');
        $cajero = $this->usuario('CAJERO');
        $escena = $this->montarMesa($cajero, 1, 100, 1);

        $this->cobrar($mesero, $escena['cuentas'][0], 100)
            ->assertStatus(403);

        $this->assertSame(0, DB::table('facturas')->count());
        $this->assertSame('ABIERTA', DB::table('cuentas')->find($escena['cuentas'][0])->estado);
    }

    public function test_cocinero_no_puede_cobrar_ni_emitir_factura(): void
    {
        $cocinero = $this->usuario('COCINERO');
        $cajero = $this->usuario('CAJERO');
        $escena = $this->montarMesa($cajero, 1, 100, 1);

        // Petición directa al endpoint (como haría un navegador manipulado):
        // el backend responde 403 sin tocar nada.
        $this->cobrar($cocinero, $escena['cuentas'][0], 100)
            ->assertStatus(403);

        $this->assertSame(0, DB::table('facturas')->count());
        $this->assertSame(0, DB::table('pagos_cuenta')->count());
        $this->assertSame('ABIERTA', DB::table('cuentas')->find($escena['cuentas'][0])->estado);
        $this->assertSame('ACTIVA', DB::table('ventas')->find($escena['venta'])->estado);
    }

    public function test_admin_y_gerente_tambien_pueden_cobrar(): void
    {
        // ADMIN y GERENTE conservan el permiso de cobro actual.
        foreach (['ADMIN', 'GERENTE'] as $rolNombre) {
            $usuario = $this->usuario($rolNombre);
            $escena = $this->montarMesa($usuario, 1, 100, 1);

            $this->cobrar($usuario, $escena['cuentas'][0], 100)
                ->assertStatus(200)
                ->assertJson(['exito' => true]);

            $this->assertSame('PAGADA', DB::table('cuentas')->find($escena['cuentas'][0])->estado);
        }
    }

    /* ------------------------------------------------------------------
     | Mesa dividida
     | ------------------------------------------------------------------ */

    public function test_cada_cuenta_dividida_genera_su_propia_factura(): void
    {
        $cajero = $this->usuario('CAJERO');
        $escena = $this->montarMesa($cajero, 2, 150, 1);

        $this->cobrar($cajero, $escena['cuentas'][0], 150)->assertStatus(200);

        // Mientras quede una cuenta abierta la mesa NO se libera y la venta
        // permanece ACTIVA; aun así la primera factura ya quedó emitida.
        $this->assertSame('ACTIVA', DB::table('ventas')->find($escena['venta'])->estado);
        $this->assertSame('OCUPADA', DB::table('mesas')->find($escena['mesa'])->estado);
        $this->assertSame(1, DB::table('facturas')->count());

        $this->cobrar($cajero, $escena['cuentas'][1], 150)->assertStatus(200);

        $facturas = DB::table('facturas')->orderBy('id')->get();

        $this->assertCount(2, $facturas);
        $this->assertSame(['FAC-0001', 'FAC-0002'], $facturas->pluck('numero_factura')->all());
        $this->assertSame(
            [$escena['cuentas'][0], $escena['cuentas'][1]],
            $facturas->map(fn ($f) => (int) $f->cuenta_id)->all()
        );
        // Ambas apuntan a la misma venta.
        $this->assertSame([$escena['venta'], $escena['venta']], $facturas->map(fn ($f) => (int) $f->venta_id)->all());
    }

    /* ------------------------------------------------------------------
     | Consulta e impresión
     | ------------------------------------------------------------------ */

    public function test_factura_emitida_se_puede_imprimir_con_su_detalle(): void
    {
        $cajero = $this->usuario('CAJERO');
        $escena = $this->montarMesa($cajero, 1, 100, 2);

        $this->cobrar($cajero, $escena['cuentas'][0], 300)->assertStatus(200);

        $factura = DB::table('facturas')->sole();

        $this->actingAs($cajero)
            ->get(route('facturas.ticket', ['id' => $factura->id]))
            ->assertStatus(200)
            ->assertSee('FAC-0001')
            ->assertSee('Plato 1')
            ->assertSee('200.00');
    }

    public function test_listado_de_facturas_muestra_las_emitidas_por_el_pos(): void
    {
        $cajero = $this->usuario('CAJERO');
        $gerente = $this->usuario('GERENTE');
        $escena = $this->montarMesa($cajero, 1, 100, 2);

        $this->cobrar($cajero, $escena['cuentas'][0], 200)->assertStatus(200);

        $this->actingAs($gerente)
            ->get(route('facturas.index'))
            ->assertStatus(200)
            ->assertSee('FAC-0001')
            ->assertSee('EFECTIVO');
    }
}
