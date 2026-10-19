<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Concerns\CreaEsquemaTrasladosPagos;
use Tests\TestCase;

/*
 * Pruebas del TRASLADO DE CUENTAS ENTRE MESAS (Fase 6).
 *
 * Reglas verificadas:
 * - Solo ADMIN, GERENTE y CAJERO (MESERO recibe 403, sin cambios).
 * - Se mueve la venta activa con TODAS sus subcuentas, sin duplicar ni
 *   perder datos (ventas, venta_detalles, cuentas y cuenta_detalles intactos).
 * - Las comandas siguen perteneciendo a la venta; solo cambian de mesa.
 * - Se rechaza trasladar una cuenta PAGADA/CANCELADA, a la misma mesa,
 *   a una mesa ocupada o inexistente.
 * - Los estados de mesa quedan coherentes (origen LIBRE, destino OCUPADA).
 *
 * SQLite :memory: (phpunit.xml). Nunca tocan "sistemadecaja".
 */
class TrasladoCuentasTest extends TestCase
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

        // Trazabilidad de traslados + pagos/ajustes de cobro (replica las
        // migraciones 2026_10_18): exigido por pos() y trasladarConsumoCuenta.
        $this->crearEsquemaTrasladosPagos();
    }

    private function usuario(string $rolNombre): User
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

    /**
     * Escenario completo: mesa origen OCUPADA con venta ACTIVA, una comanda,
     * una línea de cocina y dos subcuentas. Mesa destino LIBRE.
     *
     * @return array<string,int>
     */
    private function escenario(string $estadoDestino = 'LIBRE'): array
    {
        $origen = DB::table('mesas')->insertGetId(['numero' => '1', 'capacidad' => 4, 'estado' => 'OCUPADA']);
        $destino = DB::table('mesas')->insertGetId(['numero' => '2', 'capacidad' => 4, 'estado' => $estadoDestino]);

        $venta = DB::table('ventas')->insertGetId(['mesa_id' => $origen, 'monto' => 90, 'fecha' => now(), 'estado' => 'ACTIVA']);

        $comanda = DB::table('comandas')->insertGetId([
            'venta_id' => $venta,
            'mesa_id' => $origen,
            'numero_ticket' => 7,
            'estado' => 'ABIERTA',
            'creado_en' => now(),
        ]);

        $producto = DB::table('productos')->insertGetId(['nombre' => 'Hamburguesa', 'precio' => 30, 'requiere_cocina' => true, 'activo' => true]);
        $detalle = DB::table('venta_detalles')->insertGetId([
            'venta_id' => $venta,
            'producto_id' => $producto,
            'cantidad' => 3,
            'precio_unitario' => 30,
            'subtotal' => 90,
            'comanda_id' => $comanda,
            'cuenta_id' => null,
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $cuentaPrincipal = DB::table('cuentas')->insertGetId(['venta_id' => $venta, 'nombre' => 'Principal', 'total' => 30, 'estado' => 'ABIERTA', 'created_at' => now(), 'updated_at' => now()]);
        $cuentaAna = DB::table('cuentas')->insertGetId(['venta_id' => $venta, 'nombre' => 'Ana', 'total' => 60, 'estado' => 'ABIERTA', 'created_at' => now(), 'updated_at' => now()]);

        DB::table('cuenta_detalles')->insert([
            ['cuenta_id' => $cuentaPrincipal, 'venta_id' => $venta, 'venta_detalle_id' => $detalle, 'cantidad' => 1, 'subtotal' => 30, 'eliminado' => false, 'created_at' => now(), 'updated_at' => now()],
            ['cuenta_id' => $cuentaAna, 'venta_id' => $venta, 'venta_detalle_id' => $detalle, 'cantidad' => 2, 'subtotal' => 60, 'eliminado' => false, 'created_at' => now(), 'updated_at' => now()],
        ]);

        return [
            'origen' => $origen,
            'destino' => $destino,
            'venta' => $venta,
            'comanda' => $comanda,
            'detalle' => $detalle,
            'cuentaPrincipal' => $cuentaPrincipal,
            'cuentaAna' => $cuentaAna,
        ];
    }

    private function trasladar(User $usuario, int $cuentaId, int $destinoId)
    {
        return $this->actingAs($usuario)->postJson('/pos/cuentas/' . $cuentaId . '/trasladar', [
            'mesa_destino_id' => $destinoId,
        ]);
    }

    public function test_admin_gerente_y_cajero_pueden_trasladar(): void
    {
        foreach (['ADMIN', 'GERENTE', 'CAJERO'] as $rol) {
            $this->crearEsquema();

            $usuario = $this->usuario($rol);
            $e = $this->escenario();

            $this->trasladar($usuario, $e['cuentaAna'], $e['destino'])
                ->assertStatus(200)
                ->assertJson(['exito' => true]);

            $this->assertSame($e['destino'], (int) DB::table('ventas')->where('id', $e['venta'])->value('mesa_id'));
        }
    }

    public function test_mesero_no_puede_trasladar_y_no_hay_cambios(): void
    {
        $mesero = $this->usuario('MESERO');
        $e = $this->escenario();

        $this->trasladar($mesero, $e['cuentaAna'], $e['destino'])->assertStatus(403);

        // Nada cambió.
        $this->assertSame($e['origen'], (int) DB::table('ventas')->where('id', $e['venta'])->value('mesa_id'));
        $this->assertSame('OCUPADA', DB::table('mesas')->where('id', $e['origen'])->value('estado'));
        $this->assertSame('LIBRE', DB::table('mesas')->where('id', $e['destino'])->value('estado'));
    }

    public function test_traslado_mueve_la_venta_sin_duplicar_ni_perder_datos(): void
    {
        $admin = $this->usuario('ADMIN');
        $e = $this->escenario();

        $this->trasladar($admin, $e['cuentaAna'], $e['destino'])
            ->assertStatus(200)
            ->assertJson(['exito' => true]);

        // La venta se movió; no se creó otra venta.
        $this->assertSame(1, DB::table('ventas')->count());
        $this->assertSame($e['destino'], (int) DB::table('ventas')->where('id', $e['venta'])->value('mesa_id'));

        // Las subcuentas siguen perteneciendo a la MISMA venta.
        $this->assertSame(2, DB::table('cuentas')->where('venta_id', $e['venta'])->count());
        $this->assertSame($e['venta'], (int) DB::table('cuentas')->where('id', $e['cuentaAna'])->value('venta_id'));

        // No se duplican ni desaparecen detalles/asignaciones.
        $this->assertSame(1, DB::table('venta_detalles')->count());
        $this->assertSame(2, DB::table('cuenta_detalles')->count());
        $this->assertSame(90.0, (float) DB::table('venta_detalles')->where('id', $e['detalle'])->value('subtotal'));
        $this->assertSame(60.0, (float) DB::table('cuentas')->where('id', $e['cuentaAna'])->value('total'));
        $this->assertSame(30.0, (float) DB::table('cuentas')->where('id', $e['cuentaPrincipal'])->value('total'));
    }

    public function test_la_comanda_conserva_su_venta_y_solo_cambia_de_mesa(): void
    {
        $admin = $this->usuario('ADMIN');
        $e = $this->escenario();

        $this->trasladar($admin, $e['cuentaAna'], $e['destino'])->assertStatus(200);

        $comanda = DB::table('comandas')->where('id', $e['comanda'])->first();
        $this->assertSame($e['venta'], (int) $comanda->venta_id);
        $this->assertSame($e['destino'], (int) $comanda->mesa_id);
        $this->assertSame(7, (int) $comanda->numero_ticket);
        // La cantidad de la línea no se toca.
        $this->assertSame(3, (int) DB::table('venta_detalles')->where('id', $e['detalle'])->value('cantidad'));
    }

    public function test_traslado_actualiza_estados_de_mesa(): void
    {
        $admin = $this->usuario('ADMIN');
        $e = $this->escenario();

        $this->trasladar($admin, $e['cuentaAna'], $e['destino'])->assertStatus(200);

        $this->assertSame('LIBRE', DB::table('mesas')->where('id', $e['origen'])->value('estado'));
        $this->assertSame('OCUPADA', DB::table('mesas')->where('id', $e['destino'])->value('estado'));
    }

    public function test_traslado_rechaza_cuenta_pagada(): void
    {
        $admin = $this->usuario('ADMIN');
        $e = $this->escenario();
        DB::table('cuentas')->where('id', $e['cuentaAna'])->update(['estado' => 'PAGADA']);

        $this->trasladar($admin, $e['cuentaAna'], $e['destino'])
            ->assertStatus(422)
            ->assertJson(['exito' => false]);

        $this->assertSame($e['origen'], (int) DB::table('ventas')->where('id', $e['venta'])->value('mesa_id'));
    }

    public function test_traslado_rechaza_la_misma_mesa(): void
    {
        $admin = $this->usuario('ADMIN');
        $e = $this->escenario();

        $this->trasladar($admin, $e['cuentaAna'], $e['origen'])
            ->assertStatus(422)
            ->assertJson(['exito' => false]);
    }

    public function test_traslado_rechaza_mesa_destino_ocupada(): void
    {
        $admin = $this->usuario('ADMIN');
        $e = $this->escenario('OCUPADA');

        $this->trasladar($admin, $e['cuentaAna'], $e['destino'])
            ->assertStatus(422)
            ->assertJson(['exito' => false]);

        // Sin cambios parciales.
        $this->assertSame($e['origen'], (int) DB::table('ventas')->where('id', $e['venta'])->value('mesa_id'));
        $this->assertSame('OCUPADA', DB::table('mesas')->where('id', $e['origen'])->value('estado'));
    }

    public function test_traslado_rechaza_mesa_destino_inexistente(): void
    {
        $admin = $this->usuario('ADMIN');
        $e = $this->escenario();

        // La validación estándar de Laravel responde 422 con "errors".
        $this->trasladar($admin, $e['cuentaAna'], 999999)->assertStatus(422);

        $this->assertSame($e['origen'], (int) DB::table('ventas')->where('id', $e['venta'])->value('mesa_id'));
    }

    public function test_panel_ofrece_traslado_solo_a_roles_autorizados(): void
    {
        $admin = $this->usuario('ADMIN');
        $e = $this->escenario();

        $panel = $this->actingAs($admin)->get('/mesas/' . $e['origen'] . '/cuentas');
        $panel->assertStatus(200);
        // Renderiza el Blade (fuerza la compilación/ejecución de la vista).
        $panel->assertSee('Trasladar');
        $this->assertTrue($panel->viewData('puedeTrasladar'));
        $destinos = $panel->viewData('mesasDestino')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains($e['destino'], $destinos);

        $mesero = $this->usuario('MESERO');
        $panelMesero = $this->actingAs($mesero)->get('/mesas/' . $e['origen'] . '/cuentas');
        $panelMesero->assertStatus(200);
        $this->assertFalse($panelMesero->viewData('puedeTrasladar'));
    }

    /**
     * El panel publica DOS traslados distintos:
     *
     *  1. Mesa completa (`data-mesa-numero`), para mover el pedido a otra mesa.
     *  2. Por cuenta individual (`data-cuenta-nombre`), para mover SOLO el
     *     consumo de una subcuenta respetando su historial propio.
     *
     * Este test antes aseguraba que NO existía el traslado por cuenta (cuando
     * el POS no lo soportaba). Ahora ese botón es parte de la funcionalidad de
     * subcuentas, así que se comprueba que se publica.
     */
    public function test_panel_publica_traslado_de_mesa_y_el_individual_por_cuenta(): void
    {
        $admin = $this->usuario('ADMIN');
        $e = $this->escenario();

        $panel = $this->actingAs($admin)->get('/mesas/' . $e['origen'] . '/cuentas');
        $panel->assertStatus(200);

        // Acción de mesa completa. Se comprueba el marcado real del botón
        // (data-mesa-numero) porque el texto "Trasladar" también aparece en el
        // JavaScript, que se renderiza siempre.
        $panel->assertSee('Trasladar mesa');
        $panel->assertSee('data-mesa-numero');

        // Traslado INDIVIDUAL por cuenta: el botón solo se pinta para cuentas
        // ABIERTAS con consumo, y se identifica por su nombre.
        $panel->assertSee('data-cuenta-nombre');

        // Existe una cuenta ABIERTA de referencia para el traslado.
        $this->assertNotNull($panel->viewData('cuentaTrasladoId'));
    }

    /** El MESERO no ve la acción de traslado (ni en la UI ni en el backend). */
    public function test_panel_no_publica_traslado_de_mesa_para_mesero(): void
    {
        $mesero = $this->usuario('MESERO');
        $e = $this->escenario();

        $panel = $this->actingAs($mesero)->get('/mesas/' . $e['origen'] . '/cuentas');
        $panel->assertStatus(200);
        // No se renderiza el botón (marcador data-mesa-numero), aunque el
        // JavaScript siempre se incluya.
        $panel->assertDontSee('data-mesa-numero');
        $this->assertFalse($panel->viewData('puedeTrasladar'));
    }
}
