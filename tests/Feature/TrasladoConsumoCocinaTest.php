<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\CreaEsquemaCompleto;
use Tests\TestCase;

/**
 * Verificación del TRASLADO INDIVIDUAL DE CUENTA (trasladarConsumoCuenta,
 * ruta POST /pos/cuentas/{id}/trasladar-consumo) y su efecto en el MONITOR
 * DE COCINA (CocinaController::lineasActivas, GET /cocina/api/panel).
 *
 * Escenarios comprobados (uno por prueba):
 *  1. Las líneas trasladadas aparecen en la mesa DESTINO del monitor.
 *  2. Un producto EN_PREPARACION conserva ese estado tras el traslado.
 *  3. Un producto LISTO continúa LISTO después del traslado.
 *  4. La comanda compartida con otra cuenta no se ve afectada (mesa,
 *     estado y comportamiento de sus líneas).
 *  5. No hay duplicados: ni en el monitor ni en la base de datos.
 *  6. Refrescar el panel devuelve el mismo resultado, coherente con la BD.
 *
 * El escenario usa UN SOLO pedido (una comanda) repartido entre dos cuentas,
 * que es como nace realmente una comanda compartida.
 *
 * SQLite :memory: (phpunit.xml). Nunca toca "sistemadecaja".
 */
class TrasladoConsumoCocinaTest extends TestCase
{
    use CreaEsquemaCompleto;

    private User $admin;

    private User $cocinero;

    /** ids del escenario: mesas, venta, comanda, productos, líneas y cuentas. */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', (string) config('database.connections.sqlite.database'));

        $this->crearEsquemaCompleto();

        $this->admin = $this->usuario('ADMIN');
        $this->cocinero = $this->usuario('COCINERO');

        // La cocina digital debe estar habilitada para ver el monitor.
        $this->actingAs($this->admin)
            ->postJson('/configuracion/api', ['modulo' => 'cocina', 'habilitado' => true])
            ->assertStatus(200);
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
     * Escenario real:
     *  - Mesa 1 OCUPADA con venta ACTIVA y UN pedido de cocina con tres
     *    platos (una sola comanda).
     *  - Ese pedido se reparte entre dos cuentas: "Ana" queda con dos platos
     *    y "Principal" conserva el tercero (así nace la comanda compartida).
     *  - Estados de cocina: plato A = EN_PREPARACION, plato B = LISTO,
     *    plato C (de Principal) = PENDIENTE.
     *  - Mesa 2 LIBRE, lista para recibir el traslado de la cuenta "Ana".
     *
     * Con $avanzarEstados = false todas las líneas se quedan PENDIENTE.
     */
    private function preparar(bool $avanzarEstados = true): void
    {
        $mesa1 = DB::table('mesas')->insertGetId(['numero' => '1', 'capacidad' => 4, 'estado' => 'LIBRE']);
        $mesa2 = DB::table('mesas')->insertGetId(['numero' => '2', 'capacidad' => 4, 'estado' => 'LIBRE']);

        $venta1 = DB::table('ventas')->insertGetId([
            'mesa_id' => $mesa1, 'monto' => 0, 'estado' => 'ACTIVA', 'fecha' => now(),
        ]);

        $cuentaPrincipal = DB::table('cuentas')->insertGetId([
            'venta_id' => $venta1, 'nombre' => 'Cuenta Principal', 'total' => 0,
            'estado' => 'ABIERTA', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $prodA = DB::table('productos')->insertGetId(['nombre' => 'Ensalada', 'precio' => 10, 'requiere_cocina' => 1, 'activo' => true]);
        $prodB = DB::table('productos')->insertGetId(['nombre' => 'Sopa', 'precio' => 15, 'requiere_cocina' => 1, 'activo' => true]);
        $prodC = DB::table('productos')->insertGetId(['nombre' => 'Carne', 'precio' => 20, 'requiere_cocina' => 1, 'activo' => true]);

        // Un solo envío => una sola comanda con los tres platos.
        $this->actingAs($this->admin)->postJson('/pos/api', [
            'accion' => 'enviar_cocina',
            'venta_id' => $venta1,
            'carrito' => json_encode([
                ['id' => $prodA, 'cantidad' => 2, 'notas' => 'sin cebolla'],
                ['id' => $prodB, 'cantidad' => 1, 'notas' => null],
                ['id' => $prodC, 'cantidad' => 3, 'notas' => null],
            ]),
        ])->assertStatus(200)->assertJson(['exito' => true]);

        $vdA = (int) DB::table('venta_detalles')->where('venta_id', $venta1)->where('producto_id', $prodA)->value('id');
        $vdB = (int) DB::table('venta_detalles')->where('venta_id', $venta1)->where('producto_id', $prodB)->value('id');
        $vdC = (int) DB::table('venta_detalles')->where('venta_id', $venta1)->where('producto_id', $prodC)->value('id');
        $comanda = (int) DB::table('comandas')->where('venta_id', $venta1)->value('id');

        // Reparto de la MISMA comanda entre dos cuentas (como hace la
        // división de cuentas): Ana se queda con A y B, Principal con C.
        $cuentaAna = DB::table('cuentas')->insertGetId([
            'venta_id' => $venta1, 'nombre' => 'Ana', 'total' => 0,
            'estado' => 'ABIERTA', 'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ([[$vdA, 2, 20.0], [$vdB, 1, 15.0]] as [$vd, $cant, $sub]) {
            DB::table('cuenta_detalles')
                ->where('cuenta_id', $cuentaPrincipal)
                ->where('venta_detalle_id', $vd)
                ->update(['eliminado' => true, 'eliminado_en' => now(), 'updated_at' => now()]);

            DB::table('cuenta_detalles')->insert([
                'cuenta_id' => $cuentaAna, 'venta_id' => $venta1, 'venta_detalle_id' => $vd,
                'cantidad' => $cant, 'subtotal' => $sub,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // Totales como los dejaría la división real de cuentas.
        DB::table('cuentas')->where('id', $cuentaPrincipal)->update([
            'total' => DB::table('cuenta_detalles')->where('cuenta_id', $cuentaPrincipal)->where('eliminado', false)->sum('subtotal'),
            'updated_at' => now(),
        ]);

        // Estados de cocina del escenario (opcional: para comprobar que un
        // traslado también funciona con todo todavía en cola).
        if ($avanzarEstados) {
            $this->avanzarEstado($vdA, 'EN_PREPARACION');
            $this->avanzarEstado($vdB, 'EN_PREPARACION');
            $this->avanzarEstado($vdB, 'LISTO');
        }

        $this->ids = compact(
            'mesa1', 'mesa2', 'venta1', 'cuentaPrincipal', 'cuentaAna',
            'prodA', 'prodB', 'prodC', 'vdA', 'vdB', 'vdC', 'comanda'
        );
    }

    /** Avanza el estado de una línea usando el endpoint real de cocina. */
    private function avanzarEstado(int $detalleId, string $estado): void
    {
        $this->actingAs($this->cocinero)
            ->postJson("/cocina/api/detalles/{$detalleId}/estado", ['estado' => $estado])
            ->assertStatus(200)
            ->assertJson(['exito' => true]);
    }

    /** Traslado real de la cuenta "Ana" a la mesa 2. */
    private function trasladarAna()
    {
        return $this->actingAs($this->admin)->postJson(
            '/pos/cuentas/'.$this->ids['cuentaAna'].'/trasladar-consumo',
            ['mesa_destino_id' => $this->ids['mesa2'], 'clave_operacion' => 'test-'.uniqid()]
        );
    }

    /** Líneas actuales del monitor de cocina. */
    private function panel(): array
    {
        return $this->actingAs($this->cocinero)
            ->getJson('/cocina/api/panel')
            ->assertStatus(200)
            ->json('lineas');
    }

    /** Localiza en el panel la línea de una venta_detalles concreta. */
    private function lineaDelPanel(array $lineas, int $ventaDetalleId): ?array
    {
        foreach ($lineas as $linea) {
            if ((int) $linea['id'] === $ventaDetalleId) {
                return $linea;
            }
        }

        return null;
    }

    /** Venta creada en la mesa destino (o null si el traslado no creó una). */
    private function ventaDestino(): ?object
    {
        return DB::table('ventas')->where('mesa_id', $this->ids['mesa2'])->first();
    }

    /* ------------------------------------------------------------------
     | 1 + 6. Cambio de mesa en el monitor y actualización consistente
     | ------------------------------------------------------------------ */

    public function test_las_lineas_trasladadas_aparecen_en_la_mesa_destino_y_el_refresco_es_consistente(): void
    {
        $this->preparar();

        $resp = $this->trasladarAna();
        $this->assertSame(200, $resp->status(), 'Error del traslado: '.(string) $resp->json('error'));
        $this->assertTrue((bool) $resp->json('exito'));

        $ventaDestino = $this->ventaDestino();
        $this->assertNotNull($ventaDestino, 'El traslado debe crear (o usar) una venta en la mesa destino.');

        $destino = DB::table('venta_detalles')
            ->where('venta_id', $ventaDestino->id)
            ->orderBy('id')
            ->get()
            ->keyBy('producto_id');

        $this->assertCount(2, $destino, 'Los dos platos de la cuenta Ana deben estar en la venta destino.');

        $lineas = $this->panel();
        $this->assertCount(3, $lineas, 'Dos platos en destino + el plato que quedó en la mesa 1.');

        // (1) Los platos trasladados se asocian a la mesa DESTINO.
        foreach (['prodA', 'prodB'] as $clave) {
            $linea = $this->lineaDelPanel($lineas, (int) $destino[$this->ids[$clave]]->id);
            $this->assertNotNull($linea, 'La línea trasladada debe verse en el monitor.');
            $this->assertSame('2', (string) $linea['mesa'], 'La línea trasladada debe mostrarse en la mesa destino.');
        }

        // El plato que quedó en la mesa 1 sigue mostrándose allí.
        $lineaC = $this->lineaDelPanel($lineas, $this->ids['vdC']);
        $this->assertNotNull($lineaC);
        $this->assertSame('1', (string) $lineaC['mesa']);

        // (6) Refresco del monitor: mismo resultado y coherente con la BD.
        $this->assertSame($lineas, $this->panel(), 'Un segundo refresco no debe cambiar el resultado.');

        // Las tres líneas comparten la MISMA comanda (mismo ticket): el
        // comanda_id compartido no debe fundir ni duplicar ninguna línea.
        $this->assertCount(1, array_unique(array_column($lineas, 'comanda')), 'Todas deben pertenecer a la misma comanda.');

        foreach ($lineas as $linea) {
            $vd = DB::table('venta_detalles')->where('id', $linea['id'])->first();
            $this->assertNotNull($vd);
            $mesa = DB::table('mesas')
                ->join('ventas', 'ventas.mesa_id', '=', 'mesas.id')
                ->where('ventas.id', $vd->venta_id)
                ->value('numero');

            $this->assertSame((string) $mesa, (string) $linea['mesa'], 'La mesa del monitor debe venir de la venta de la línea.');
            $this->assertSame($vd->estado_preparacion, $linea['estado'], 'El estado del monitor debe ser el de la base de datos.');
            $this->assertSame((int) $vd->cantidad, (int) $linea['cantidad']);
        }
    }

    /* ------------------------------------------------------------------
     | 2. En preparación
     | ------------------------------------------------------------------ */

    public function test_producto_en_preparacion_conserva_el_estado_tras_el_traslado(): void
    {
        $this->preparar();

        $this->trasladarAna()->assertStatus(200)->assertJson(['exito' => true]);

        $ventaDestino = $this->ventaDestino();
        $destino = DB::table('venta_detalles')
            ->where('venta_id', $ventaDestino->id)
            ->where('producto_id', $this->ids['prodA'])
            ->first();

        $this->assertNotNull($destino, 'Debe existir la línea de destino del plato A.');
        $this->assertSame('EN_PREPARACION', $destino->estado_preparacion, 'El estado no debe reiniciarse a PENDIENTE.');
        $this->assertSame($this->ids['comanda'], (int) $destino->comanda_id, 'La línea de destino debe conservar la comanda original.');
        $this->assertSame($this->ids['vdA'], (int) $destino->trasladado_desde_id);

        $linea = $this->lineaDelPanel($this->panel(), (int) $destino->id);
        $this->assertNotNull($linea);
        $this->assertSame('EN_PREPARACION', $linea['estado']);
        $this->assertSame('2', (string) $linea['mesa']);

        // Comportamiento posterior: cocina puede seguir avanzando la línea
        // trasladada (EN_PREPARACION -> LISTO) sin errores.
        $this->avanzarEstado((int) $destino->id, 'LISTO');
        $this->assertSame('LISTO', DB::table('venta_detalles')->where('id', $destino->id)->value('estado_preparacion'));
    }

    /* ------------------------------------------------------------------
     | 3. Pedido listo
     | ------------------------------------------------------------------ */

    public function test_producto_listo_continua_listo_despues_del_traslado(): void
    {
        $this->preparar();

        $this->trasladarAna()->assertStatus(200)->assertJson(['exito' => true]);

        $ventaDestino = $this->ventaDestino();
        $destino = DB::table('venta_detalles')
            ->where('venta_id', $ventaDestino->id)
            ->where('producto_id', $this->ids['prodB'])
            ->first();

        $this->assertNotNull($destino, 'Debe existir la línea de destino del plato B.');
        $this->assertSame('LISTO', $destino->estado_preparacion, 'Un plato LISTO no debe volver a la cola de preparación.');
        $this->assertSame($this->ids['comanda'], (int) $destino->comanda_id);

        $linea = $this->lineaDelPanel($this->panel(), (int) $destino->id);
        $this->assertNotNull($linea);
        $this->assertSame('LISTO', $linea['estado']);
        $this->assertSame('2', (string) $linea['mesa']);
    }

    /* ------------------------------------------------------------------
     | Extra. Línea PENDIENTE tras el traslado
     | ------------------------------------------------------------------ */

    /** Un plato que todavía está en cola (PENDIENTE) aparece en la mesa 2. */
    public function test_linea_pendiente_se_muestra_en_la_mesa_destino_tras_el_traslado(): void
    {
        $this->preparar(false); // nada avanzado: A, B y C siguen PENDIENTES

        $this->trasladarAna()->assertStatus(200)->assertJson(['exito' => true]);

        $ventaDestino = $this->ventaDestino();
        $destinos = DB::table('venta_detalles')
            ->where('venta_id', $ventaDestino->id)
            ->get()
            ->keyBy('producto_id');

        $lineas = $this->panel();
        $this->assertCount(3, $lineas, 'Dos platos trasladados + el que quedó en la mesa 1.');

        foreach (['prodA', 'prodB'] as $clave) {
            $linea = $this->lineaDelPanel($lineas, (int) $destinos[$this->ids[$clave]]->id);
            $this->assertNotNull($linea, 'La línea trasladada debe verse en el monitor.');
            $this->assertSame('PENDIENTE', $linea['estado'], 'Sin avanzar estados, debe seguir en cola.');
            $this->assertSame('2', (string) $linea['mesa']);
        }

        $lineaC = $this->lineaDelPanel($lineas, $this->ids['vdC']);
        $this->assertSame('1', (string) $lineaC['mesa']);
        $this->assertSame('PENDIENTE', $lineaC['estado']);
    }

    /* ------------------------------------------------------------------
     | 4. Comanda compartida con otra cuenta
     | ------------------------------------------------------------------ */

    public function test_la_comanda_compartida_no_se_ve_afectada_por_el_traslado(): void
    {
        $this->preparar();

        $comandaAntes = DB::table('comandas')->where('id', $this->ids['comanda'])->first();
        $lineaCAntes = DB::table('venta_detalles')->where('id', $this->ids['vdC'])->first();
        $principalAntes = DB::table('cuentas')->where('id', $this->ids['cuentaPrincipal'])->first();

        $this->trasladarAna()->assertStatus(200)->assertJson(['exito' => true]);

        // La comanda no cambia de venta, mesa, estado ni ticket.
        $comandaDespues = DB::table('comandas')->where('id', $this->ids['comanda'])->first();
        $this->assertEquals($comandaAntes, $comandaDespues, 'La comanda compartida no debe modificarse.');

        // La línea de la OTRA cuenta (Principal) queda intacta en BD...
        $lineaCDespues = DB::table('venta_detalles')->where('id', $this->ids['vdC'])->first();
        $this->assertEquals($lineaCAntes, $lineaCDespues, 'La línea de otra cuenta de la misma comanda no debe tocarse.');

        // ...y su cuenta sigue abierta con su consumo.
        $principalDespues = DB::table('cuentas')->where('id', $this->ids['cuentaPrincipal'])->first();
        $this->assertSame('ABIERTA', $principalDespues->estado);
        $this->assertSame($this->ids['venta1'], (int) $principalDespues->venta_id);
        $this->assertEquals($principalAntes->total, $principalDespues->total);

        // Sigue en la mesa 1, PENDIENTE, y responde en cocina (comportamiento).
        $linea = $this->lineaDelPanel($this->panel(), $this->ids['vdC']);
        $this->assertNotNull($linea, 'La línea de la otra cuenta debe seguir en el monitor.');
        $this->assertSame('1', (string) $linea['mesa']);
        $this->assertSame('PENDIENTE', $linea['estado']);

        $this->avanzarEstado($this->ids['vdC'], 'EN_PREPARACION');
        $this->assertSame('EN_PREPARACION', DB::table('venta_detalles')->where('id', $this->ids['vdC'])->value('estado_preparacion'));
    }

    /* ------------------------------------------------------------------
     | 5. Sin duplicados
     | ------------------------------------------------------------------ */

    public function test_sin_duplicados_ni_reentrada_en_la_cola_de_preparacion(): void
    {
        $this->preparar();

        $this->trasladarAna()->assertStatus(200)->assertJson(['exito' => true]);

        $lineas = $this->panel();

        // Una sola línea por producto (sin duplicar en el monitor).
        $productos = array_column($lineas, 'producto');
        $this->assertCount(3, $productos);
        $this->assertSame($productos, array_unique($productos), 'Ningún producto debe aparecer dos veces.');

        $ids = array_map(fn ($l) => (int) $l['id'], $lineas);
        $this->assertSame($ids, array_unique($ids), 'Ninguna venta_detalles debe aparecer dos veces.');

        // Las líneas de origen vaciadas (cantidad 0) no reaparecen como
        // tareas fantasma ni vuelven a PENDIENTE.
        foreach (['vdA', 'vdB'] as $clave) {
            $origen = DB::table('venta_detalles')->where('id', $this->ids[$clave])->first();
            $this->assertSame(0, (int) $origen->cantidad, 'La línea de origen debe quedar sin unidades.');
            $this->assertSame('ACTIVO', $origen->estado);
            $this->assertNotContains($this->ids[$clave], $ids, 'La línea vaciada no debe listarse en el monitor.');
        }

        // En la base: 3 líneas originales + 2 de destino = 5 (sin duplicados).
        $this->assertSame(5, DB::table('venta_detalles')->count());
        $this->assertSame(2, DB::table('venta_detalles')->where('venta_id', $this->ventaDestino()->id)->count());

        // Trazabilidad: una línea de traslado por plato movido.
        $this->assertSame(2, DB::table('traslado_detalles')->count());
    }

    /* ------------------------------------------------------------------
     | Extra. Traslado PARCIAL de una línea compartida por dos cuentas
     | ------------------------------------------------------------------ */

    /**
     * Caso fino de "sin duplicados": la cuenta Ana tiene solo 1 de las 2
     * unidades del plato A; la otra unidad es de Principal. Al trasladar Ana:
     *  - El origen conserva 1 unidad con su comanda y estado intactos.
     *  - El destino recibe 1 unidad con el mismo estado.
     *  - El monitor muestra AMBAS líneas (una por mesa) y la suma de
     *    unidades sigue siendo la original: nada se pierde ni se duplica.
     */
    public function test_traslado_parcial_de_linea_compartida_no_pierde_ni_duplica_unidades(): void
    {
        $this->preparar();

        // Reparto parcial: Ana pasa a tener 1 de las 2 unidades del plato A
        // y Principal recupera la otra (mismo patrón que la división real).
        DB::table('cuenta_detalles')
            ->where('cuenta_id', $this->ids['cuentaAna'])
            ->where('venta_detalle_id', $this->ids['vdA'])
            ->update(['cantidad' => 1, 'subtotal' => 10.0, 'updated_at' => now()]);

        DB::table('cuenta_detalles')
            ->where('cuenta_id', $this->ids['cuentaPrincipal'])
            ->where('venta_detalle_id', $this->ids['vdA'])
            ->update(['eliminado' => false, 'eliminado_por' => null, 'eliminado_en' => null, 'cantidad' => 1, 'subtotal' => 10.0, 'updated_at' => now()]);

        DB::table('cuentas')->where('id', $this->ids['cuentaPrincipal'])->update([
            'total' => DB::table('cuenta_detalles')->where('cuenta_id', $this->ids['cuentaPrincipal'])->where('eliminado', false)->sum('subtotal'),
            'updated_at' => now(),
        ]);

        $this->trasladarAna()->assertStatus(200)->assertJson(['exito' => true]);

        // Origen: le queda 1 unidad del plato A, con comanda y estado.
        $origen = DB::table('venta_detalles')->where('id', $this->ids['vdA'])->first();
        $this->assertSame(1, (int) $origen->cantidad);
        $this->assertSame('EN_PREPARACION', $origen->estado_preparacion);
        $this->assertSame($this->ids['comanda'], (int) $origen->comanda_id);

        // Destino: la otra unidad, con el mismo estado y comanda.
        $destino = DB::table('venta_detalles')
            ->where('venta_id', $this->ventaDestino()->id)
            ->where('producto_id', $this->ids['prodA'])
            ->first();
        $this->assertNotNull($destino);
        $this->assertSame(1, (int) $destino->cantidad);
        $this->assertSame('EN_PREPARACION', $destino->estado_preparacion);
        $this->assertSame($this->ids['comanda'], (int) $destino->comanda_id);

        // Monitor: dos líneas del plato A (una por mesa) y las unidades
        // totales siguen siendo 2 (1 + 1).
        $lineas = $this->panel();
        $lineasA = array_values(array_filter($lineas, fn ($l) => $l['producto'] === 'Ensalada'));
        $this->assertCount(2, $lineasA, 'Cada mesa debe mostrar su propia unidad del plato A.');

        $mesas = array_map(fn ($l) => (string) $l['mesa'], $lineasA);
        sort($mesas);
        $this->assertSame(['1', '2'], $mesas);

        $unidades = array_sum(array_map(fn ($l) => (int) $l['cantidad'], $lineasA));
        $this->assertSame(2, $unidades, 'Las unidades del monitor deben sumar las originales (sin duplicar ni perder).');
    }
}
