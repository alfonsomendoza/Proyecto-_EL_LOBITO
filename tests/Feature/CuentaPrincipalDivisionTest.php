<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Concerns\CreaEsquemaTrasladosPagos;
use Tests\TestCase;

/*
 * Pruebas del comportamiento de la CUENTA PRINCIPAL tras dividir una mesa.
 *
 * Reglas verificadas:
 * - División completa: la principal pasa a CANCELADA y desaparece del panel
 *   y del POS, pero sus registros siguen existiendo (no se borra).
 * - División parcial: la principal conserva su consumo pendiente y sigue
 *   visible/operativa.
 * - Una cuenta principal CANCELADA no puede abrirse, cobrarse ni trasladarse
 *   (validado en backend, no solo ocultando botones).
 * - Las subcuentas conservan consumos, cantidades, totales e historial.
 *
 * El flujo reproduce el real: primero existen las cuentas (la principal con el
 * consumo y las subcuentas vacías) y luego se reparte el consumo llamando a
 * VentaController@guardarDivision, que es la lógica ya implementada.
 *
 * SQLite :memory: (phpunit.xml). Nunca tocan "sistemadecaja".
 */
class CuentaPrincipalDivisionTest extends TestCase
{
    use CreaEsquemaTrasladosPagos;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', (string) config('database.connections.sqlite.database'));

        $this->crearEsquema();
    }

    private function crearEsquema(): void
    {
        foreach (['anulaciones', 'cuenta_detalles', 'cuentas', 'venta_detalles', 'comandas', 'ventas', 'productos', 'categorias', 'mesas', 'usuarios', 'roles'] as $tabla) {
            Schema::dropIfExists($tabla);
        }

        Schema::create('roles', function (Blueprint $t) {
            $t->increments('id');
            $t->string('nombre', 30);
        });

        Schema::create('usuarios', function (Blueprint $t) {
            $t->increments('id');
            $t->string('nombre', 100)->nullable();
            $t->string('username', 50)->nullable();
            $t->string('password_hash', 255)->nullable();
            $t->integer('rol_id')->nullable();
        });

        Schema::create('mesas', function (Blueprint $t) {
            $t->increments('id');
            $t->string('numero', 20)->nullable();
            $t->integer('capacidad')->nullable();
            $t->string('estado', 20)->default('LIBRE');
        });

        Schema::create('categorias', function (Blueprint $t) {
            $t->increments('id');
            $t->string('nombre', 100);
        });

        Schema::create('productos', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('categoria_id')->nullable();
            $t->string('nombre', 150);
            $t->decimal('precio', 10, 2)->default(0);
            $t->boolean('requiere_cocina')->default(false);
            $t->boolean('controla_stock')->default(false);
            $t->boolean('activo')->default(true);
        });

        Schema::create('comandas', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->integer('venta_id')->nullable();
            $t->unsignedBigInteger('mesa_id')->nullable();
            $t->integer('numero_ticket')->nullable();
            $t->unsignedBigInteger('usuario_id')->nullable();
            $t->unsignedBigInteger('cliente_id')->nullable();
            $t->string('descripcion')->nullable();
            $t->string('estado', 30)->default('ABIERTA');
            $t->decimal('total', 10, 2)->default(0);
            $t->integer('conteo_impresiones')->default(0);
            $t->timestamp('creado_en')->nullable();
        });

        Schema::create('ventas', function (Blueprint $t) {
            $t->increments('id');
            $t->unsignedBigInteger('mesa_id')->nullable();
            $t->unsignedBigInteger('cliente_id')->nullable();
            $t->integer('apertura_id')->nullable();
            $t->string('descripcion')->nullable();
            $t->decimal('monto', 12, 2)->default(0);
            $t->string('metodo_pago', 30)->nullable();
            $t->string('banco', 30)->nullable();
            $t->decimal('monto_recibido', 12, 2)->nullable();
            $t->decimal('cambio', 12, 2)->nullable();
            $t->timestamp('fecha')->nullable();
            $t->string('estado', 20)->default('ACTIVA');
            $t->integer('usuario_id')->nullable();
        });

        Schema::create('venta_detalles', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->integer('venta_id');
            $t->integer('producto_id');
            $t->integer('cantidad');
            $t->decimal('precio_unitario', 10, 2);
            $t->decimal('subtotal', 10, 2);
            $t->integer('comanda_id')->nullable();
            $t->integer('cuenta_id')->nullable();
            $t->string('estado', 255)->default('ACTIVO');
            // Módulo de cocina: observación por línea y estado de preparación.
            // Se recrean aquí porque los tests usan SQLite en memoria.
            $t->text('notas')->nullable();
            $t->string('estado_preparacion', 20)->nullable();
            $t->timestamps();
        });

        Schema::create('cuentas', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->integer('venta_id');
            $t->string('nombre', 255);
            $t->decimal('total', 10, 2)->default(0);
            $t->string('estado', 255)->default('ABIERTA');
            $t->integer('apertura_id')->nullable();
            $t->string('metodo_pago', 30)->nullable();
            $t->string('banco', 30)->nullable();
            $t->decimal('monto_recibido', 12, 2)->nullable();
            $t->decimal('cambio', 12, 2)->nullable();
            $t->unsignedBigInteger('usuario_cobro')->nullable();
            $t->timestamps();
        });

        Schema::create('cuenta_detalles', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('cuenta_id');
            $t->integer('venta_id');
            $t->unsignedBigInteger('venta_detalle_id');
            $t->unsignedInteger('cantidad');
            $t->decimal('subtotal', 10, 2);
            $t->boolean('eliminado')->default(false);
            $t->integer('eliminado_por')->nullable();
            $t->timestamp('eliminado_en')->nullable();
            $t->timestamps();
            $t->unique(['cuenta_id', 'venta_detalle_id']);
        });

        Schema::create('anulaciones', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('tipo', 30);
            $t->integer('venta_id')->nullable();
            $t->unsignedBigInteger('venta_detalle_id')->nullable();
            $t->integer('comanda_id')->nullable();
            $t->integer('producto_id')->nullable();
            $t->integer('cantidad')->nullable();
            $t->decimal('monto', 10, 2)->nullable();
            $t->text('motivo');
            $t->integer('usuario_id')->nullable();
            $t->string('estado_previo', 255)->nullable();
            $t->timestamp('creado_en')->nullable();
        });

        // Tabla real de MySQL. El cobro emite una factura por cuenta pagada
        // (VentaController::pagarCuenta), por lo que debe existir en el esquema.
        Schema::create('facturas', function (Blueprint $t) {
            $t->increments('id');
            $t->string('numero_factura', 20)->unique();
            $t->integer('venta_id');
            $t->integer('cuenta_id')->nullable();
            $t->string('mesa', 50)->nullable();
            $t->text('detalle_consumo')->nullable();
            $t->decimal('total', 10, 2)->default(0);
            $t->dateTime('fecha_emision')->nullable();
            $t->integer('usuario_id')->nullable();
            $t->string('estado', 20)->default('EMITIDA');
        });

        // Trazabilidad de traslados + pagos/ajustes de cobro (replica las
        // migraciones 2026_10_18): exigido por pos(), trasladarConsumoCuenta
        // y pagos mixtos.
        $this->crearEsquemaTrasladosPagos();
    }

    private function usuario(string $rolNombre = 'ADMIN'): User
    {
        $rolId = DB::table('roles')->insertGetId(['nombre' => $rolNombre]);
        $usuarioId = DB::table('usuarios')->insertGetId([
            'nombre' => $rolNombre . ' Test',
            'username' => strtolower($rolNombre) . '_' . uniqid(),
            'password_hash' => bcrypt('secret'),
            'rol_id' => $rolId,
        ]);

        return User::findOrFail($usuarioId);
    }

    private function crearCuenta(int $ventaId, string $nombre, float $total = 0, string $estado = 'ABIERTA'): int
    {
        return DB::table('cuentas')->insertGetId([
            'venta_id' => $ventaId,
            'nombre' => $nombre,
            'total' => $total,
            'estado' => $estado,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Escenario: mesa con venta activa, comanda y dos líneas de cocina
     * asignadas a la cuenta "Cuenta Principal" (30 + 60 = 90). Existen dos
     * subcuentas vacías (Ana y Luis) listas para recibir la división.
     *
     * @return array<string,int>
     */
    private function escenario(): array
    {
        $mesa = DB::table('mesas')->insertGetId(['numero' => '1', 'capacidad' => 4, 'estado' => 'OCUPADA']);
        $venta = DB::table('ventas')->insertGetId(['mesa_id' => $mesa, 'monto' => 90, 'fecha' => now(), 'estado' => 'ACTIVA']);
        $comanda = DB::table('comandas')->insertGetId(['venta_id' => $venta, 'mesa_id' => $mesa, 'numero_ticket' => 1, 'estado' => 'ABIERTA', 'creado_en' => now()]);
        $producto = DB::table('productos')->insertGetId(['nombre' => 'Hamburguesa', 'precio' => 30, 'requiere_cocina' => true, 'activo' => true]);

        $linea1 = DB::table('venta_detalles')->insertGetId(['venta_id' => $venta, 'producto_id' => $producto, 'cantidad' => 1, 'precio_unitario' => 30, 'subtotal' => 30, 'comanda_id' => $comanda, 'estado' => 'ACTIVO', 'created_at' => now(), 'updated_at' => now()]);
        $linea2 = DB::table('venta_detalles')->insertGetId(['venta_id' => $venta, 'producto_id' => $producto, 'cantidad' => 2, 'precio_unitario' => 30, 'subtotal' => 60, 'comanda_id' => $comanda, 'estado' => 'ACTIVO', 'created_at' => now(), 'updated_at' => now()]);

        $principal = $this->crearCuenta($venta, 'Cuenta Principal', 90);

        $ana = $this->crearCuenta($venta, 'Ana');
        $luis = $this->crearCuenta($venta, 'Luis');

        DB::table('cuenta_detalles')->insert([
            ['cuenta_id' => $principal, 'venta_id' => $venta, 'venta_detalle_id' => $linea1, 'cantidad' => 1, 'subtotal' => 30, 'eliminado' => false, 'created_at' => now(), 'updated_at' => now()],
            ['cuenta_id' => $principal, 'venta_id' => $venta, 'venta_detalle_id' => $linea2, 'cantidad' => 2, 'subtotal' => 60, 'eliminado' => false, 'created_at' => now(), 'updated_at' => now()],
        ]);

        return compact('mesa', 'venta', 'comanda', 'producto', 'linea1', 'linea2', 'principal', 'ana', 'luis');
    }

    /** Divide todas las unidades: línea 1 -> Ana, línea 2 -> Luis. */
    private function dividirCompleta(User $usuario, array $e): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($usuario)->postJson('/pos/' . $e['mesa'] . '/cuentas/dividir', [
            'asignaciones' => [
                ['cuenta_id' => $e['ana'], 'venta_detalle_id' => $e['linea1'], 'cantidad' => 1],
                ['cuenta_id' => $e['luis'], 'venta_detalle_id' => $e['linea2'], 'cantidad' => 2],
            ],
        ]);
    }

    /* =========================================================
     * DIVISIÓN COMPLETA
     * ========================================================= */

    public function test_division_completa_cancela_la_principal_y_conserva_subcuentas(): void
    {
        $admin = $this->usuario();
        $e = $this->escenario();

        $this->dividirCompleta($admin, $e)->assertStatus(200)->assertJson(['exito' => true]);

        // La principal NO se borra: queda CANCELADA y en cero.
        $principal = DB::table('cuentas')->where('id', $e['principal'])->first();
        $this->assertNotNull($principal, 'La cuenta principal no debe borrarse.');
        $this->assertSame('CANCELADA', $principal->estado);
        $this->assertSame(0.0, (float) $principal->total);

        // Desaparece del panel de cuentas activas; las subcuentas permanecen.
        $panel = $this->actingAs($admin)->get('/mesas/' . $e['mesa'] . '/cuentas');
        $idsPanel = $panel->viewData('cuentas')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertNotContains($e['principal'], $idsPanel);
        $this->assertContains($e['ana'], $idsPanel);
        $this->assertContains($e['luis'], $idsPanel);

        // Desaparece también del POS (misma validación de backend).
        $pos = $this->actingAs($admin)->get('/pos/' . $e['mesa']);
        $idsPos = $pos->viewData('cuentas')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertNotContains($e['principal'], $idsPos);

        // Si se pide ?cuenta=principal (cancelada) por URL, el backend rechaza
        // el acceso a esa cuenta concreta (no muestra la venta completa).
        $this->actingAs($admin)
            ->get('/pos/' . $e['mesa'] . '?cuenta=' . $e['principal'])
            ->assertStatus(404);
    }

    public function test_las_subcuentas_conservan_consumos_totales_e_historial(): void
    {
        $admin = $this->usuario();
        $e = $this->escenario();

        $this->dividirCompleta($admin, $e)->assertStatus(200);

        $this->assertSame(30.0, (float) DB::table('cuentas')->where('id', $e['ana'])->value('total'));
        $this->assertSame(60.0, (float) DB::table('cuentas')->where('id', $e['luis'])->value('total'));
        $this->assertSame(1, (int) DB::table('cuenta_detalles')->where('cuenta_id', $e['ana'])->where('venta_detalle_id', $e['linea1'])->value('cantidad'));
        $this->assertSame(2, (int) DB::table('cuenta_detalles')->where('cuenta_id', $e['luis'])->where('venta_detalle_id', $e['linea2'])->value('cantidad'));

        // El historial de la venta (líneas y comanda) no se toca.
        $this->assertSame(2, DB::table('venta_detalles')->count());
        $this->assertSame(1, (int) DB::table('venta_detalles')->where('id', $e['linea1'])->value('cantidad'));
        $this->assertSame(2, (int) DB::table('venta_detalles')->where('id', $e['linea2'])->value('cantidad'));
        $this->assertSame(1, DB::table('comandas')->count());
        $this->assertSame(90.0, (float) DB::table('venta_detalles')->sum('subtotal'));
    }

    /* =========================================================
     * DIVISIÓN PARCIAL
     * ========================================================= */

    public function test_division_parcial_mantiene_la_principal_con_su_saldo(): void
    {
        $admin = $this->usuario();
        $e = $this->escenario();

        // Solo la línea 1 pasa a Ana; la línea 2 permanece en la principal.
        $this->actingAs($admin)->postJson('/pos/' . $e['mesa'] . '/cuentas/dividir', [
            'asignaciones' => [
                ['cuenta_id' => $e['ana'], 'venta_detalle_id' => $e['linea1'], 'cantidad' => 1],
                ['cuenta_id' => $e['principal'], 'venta_detalle_id' => $e['linea2'], 'cantidad' => 2],
            ],
        ])->assertStatus(200)->assertJson(['exito' => true]);

        // La principal conserva su saldo pendiente y NO se oculta.
        $principal = DB::table('cuentas')->where('id', $e['principal'])->first();
        $this->assertSame('ABIERTA', $principal->estado);
        $this->assertSame(60.0, (float) $principal->total);

        $panel = $this->actingAs($admin)->get('/mesas/' . $e['mesa'] . '/cuentas');
        $idsPanel = $panel->viewData('cuentas')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains($e['principal'], $idsPanel);

        // La subcuenta con consumo también sigue visible y con su total.
        $this->assertSame(30.0, (float) DB::table('cuentas')->where('id', $e['ana'])->value('total'));
    }

    /* =========================================================
     * PRINCIPAL CANCELADA: ACCIONES RECHAZADAS EN BACKEND
     * ========================================================= */

    public function test_principal_cancelada_no_puede_cobrarse(): void
    {
        $admin = $this->usuario();
        $e = $this->escenario();
        $this->dividirCompleta($admin, $e)->assertStatus(200);

        $this->actingAs($admin)->postJson('/pos/cuentas/' . $e['principal'] . '/pagar', [
            'metodo_pago' => 'EFECTIVO',
            'monto_recibido' => 100,
        ])->assertStatus(422)->assertJson(['exito' => false]);

        $this->assertSame('CANCELADA', DB::table('cuentas')->where('id', $e['principal'])->value('estado'));
    }

    public function test_principal_cancelada_no_puede_trasladarse(): void
    {
        $admin = $this->usuario();
        $e = $this->escenario();
        $this->dividirCompleta($admin, $e)->assertStatus(200);

        $destino = DB::table('mesas')->insertGetId(['numero' => '2', 'capacidad' => 4, 'estado' => 'LIBRE']);

        $this->actingAs($admin)->postJson('/pos/cuentas/' . $e['principal'] . '/trasladar', [
            'mesa_destino_id' => $destino,
        ])->assertStatus(422)->assertJson(['exito' => false]);

        // La venta no se movió.
        $this->assertSame($e['mesa'], (int) DB::table('ventas')->where('id', $e['venta'])->value('mesa_id'));
    }

    public function test_la_principal_no_aparece_como_accion_de_traslado_en_el_panel(): void
    {
        $admin = $this->usuario();
        $e = $this->escenario();
        $this->dividirCompleta($admin, $e)->assertStatus(200);

        $panel = $this->actingAs($admin)->get('/mesas/' . $e['mesa'] . '/cuentas');
        $panel->assertStatus(200);

        // El panel solo ofrece traslado para cuentas activas; la principal
        // cancelada no está entre las cuentas listadas.
        $ids = $panel->viewData('cuentas')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertNotContains($e['principal'], $ids);
    }

    /* =========================================================
     * CUENTA PRINCIPAL VACÍA: NO APARECE COMO ACTIVA EN EL PANEL
     * ========================================================= */

    /** Crea una mesa con una venta vacía y devuelve sus ids. */
    private function mesaYVenta(string $estadoMesa = 'OCUPADA'): array
    {
        $mesa = DB::table('mesas')->insertGetId(['numero' => '7', 'capacidad' => 4, 'estado' => $estadoMesa]);
        $venta = DB::table('ventas')->insertGetId(['mesa_id' => $mesa, 'monto' => 0, 'fecha' => now(), 'estado' => 'ACTIVA']);

        return [$mesa, $venta];
    }

    /** Ids de las cuentas que el panel muestra para una mesa. */
    private function idsPanel(int $mesaId, User $usuario): array
    {
        return $this->actingAs($usuario)
            ->get('/mesas/' . $mesaId . '/cuentas')
            ->viewData('cuentas')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** Asigna a una cuenta una línea de venta activa con su subtotal. */
    private function asignarConsumo(int $cuentaId, int $ventaId, int $productoId, int $cantidad, float $subtotal): int
    {
        $linea = DB::table('venta_detalles')->insertGetId([
            'venta_id' => $ventaId,
            'producto_id' => $productoId,
            'cantidad' => $cantidad,
            'precio_unitario' => $subtotal / $cantidad,
            'subtotal' => $subtotal,
            'comanda_id' => null,
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('cuenta_detalles')->insert([
            'cuenta_id' => $cuentaId,
            'venta_id' => $ventaId,
            'venta_detalle_id' => $linea,
            'cantidad' => $cantidad,
            'subtotal' => $subtotal,
            'eliminado' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $linea;
    }

    public function test_panel_oculta_la_principal_vacia_y_conserva_su_registro(): void
    {
        $admin = $this->usuario();
        [$mesa, $venta] = $this->mesaYVenta();

        // La principal nace vacía (sin consumo). Ana también está vacía.
        $principal = $this->crearCuenta($venta, 'Cuenta Principal');
        $ana = $this->crearCuenta($venta, 'Ana');

        $ids = $this->idsPanel($mesa, $admin);

        // La principal vacía deja de listarse; la subcuenta vacía SIGUE visible
        // porque el usuario la necesita para agregarle productos.
        $this->assertNotContains($principal, $ids);
        $this->assertContains($ana, $ids);

        // El registro no se borra ni se cancela: su historial se conserva.
        $this->assertNotNull(DB::table('cuentas')->where('id', $principal)->first());
        $this->assertSame('ABIERTA', DB::table('cuentas')->where('id', $principal)->value('estado'));
    }

    public function test_panel_muestra_la_principal_con_consumo_propio(): void
    {
        $admin = $this->usuario();
        [$mesa, $venta] = $this->mesaYVenta();
        $producto = DB::table('productos')->insertGetId(['nombre' => 'Pizza', 'precio' => 30, 'requiere_cocina' => true, 'activo' => true]);

        $principal = $this->crearCuenta($venta, 'Cuenta Principal', 30);
        $this->asignarConsumo($principal, $venta, $producto, 1, 30);

        $ids = $this->idsPanel($mesa, $admin);
        $this->assertContains($principal, $ids, 'La principal con consumo propio debe seguir visible.');
    }

    public function test_panel_no_oculta_la_principal_con_total_cero_si_tiene_consumo_activo(): void
    {
        $admin = $this->usuario();
        [$mesa, $venta] = $this->mesaYVenta();
        $producto = DB::table('productos')->insertGetId(['nombre' => 'Pizza', 'precio' => 30, 'requiere_cocina' => true, 'activo' => true]);

        // La columna "total" quedó desincronizada en cero, pero SÍ existe una
        // asignación activa: no debe ocultarse (un cero NO es suficiente).
        $principal = $this->crearCuenta($venta, 'Cuenta Principal', 0);
        $this->asignarConsumo($principal, $venta, $producto, 1, 30);

        $ids = $this->idsPanel($mesa, $admin);
        $this->assertContains($principal, $ids, 'Un total en cero no debe ocultar consumo activo.');
    }

    public function test_panel_oculta_la_principal_vacia_y_muestra_subcuenta_con_consumo(): void
    {
        $admin = $this->usuario();
        [$mesa, $venta] = $this->mesaYVenta();
        $producto = DB::table('productos')->insertGetId(['nombre' => 'Gaseosa', 'precio' => 30, 'requiere_cocina' => false, 'activo' => true]);

        // Escenario real: el consumo se cargó directamente en la subcuenta Ana
        // (vía enviar_cocina con cuenta_id), así que la principal nunca tuvo
        // consumo y queda vacía.
        $principal = $this->crearCuenta($venta, 'Cuenta Principal');
        $ana = $this->crearCuenta($venta, 'Ana', 30);
        $this->asignarConsumo($ana, $venta, $producto, 1, 30);

        $ids = $this->idsPanel($mesa, $admin);
        $this->assertNotContains($principal, $ids);
        $this->assertContains($ana, $ids, 'La subcuenta con consumo pendiente debe seguir visible.');
    }
}
