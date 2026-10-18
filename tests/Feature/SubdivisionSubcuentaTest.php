<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/*
 * Pruebas de la SUBDIVISIÓN DE UNA SUBCUENTA (auditoría de cuentas).
 *
 * Regla de negocio:
 * - Dividir una subcuenta debe crear cuentas nuevas derivadas de ELLA.
 * - La subdivisión opera SOLO con los consumos y cantidades de la cuenta de
 *   origen; nunca reutiliza ni modifica cuentas hermanas de la mesa.
 * - No se puede superar, duplicar ni perder unidades del origen.
 * - El origen vacío sale de las cuentas activas (CANCELADA) conservando su
 *   registro; si conserva unidades permanece ABIERTA.
 * - El POS acotado (?cuenta=) muestra solo los consumos de esa cuenta y
 *   rechaza (404) cuentas canceladas o ajenas.
 *
 * Corren en SQLite :memory: (phpunit.xml). Nunca tocan "sistemadecaja".
 */
class SubdivisionSubcuentaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('sqlite', DB::connection()->getDriverName(), 'Las pruebas deben ejecutarse en SQLite.');
        $this->assertSame(':memory:', (string) config('database.connections.sqlite.database'), 'La base de pruebas debe ser SQLite en memoria.');

        $this->crearEsquema();
    }

    private function crearEsquema(): void
    {
        foreach ([
            'anulaciones',
            'cuenta_detalles',
            'cuentas',
            'venta_detalles',
            'ventas',
            'comandas',
            'productos',
            'categorias',
            'mesas',
            'usuarios',
            'roles',
        ] as $tabla) {
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
            $t->decimal('tasa_cambio', 12, 4)->nullable();
            $t->decimal('cambio', 12, 2)->nullable();
            $t->timestamp('fecha')->nullable();
            $t->string('estado', 20)->default('ACTIVA');
            $t->integer('usuario_id')->nullable();
            $t->decimal('descuento_porcentaje', 5, 2)->default(0);
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

        Schema::create('cuentas', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->integer('venta_id');
            // Jerarquía de subdivisión: de qué cuenta deriva esta subcuenta.
            $t->unsignedBigInteger('cuenta_origen_id')->nullable();
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

    private function mesa(int $numero = 1, string $estado = 'OCUPADA'): int
    {
        return DB::table('mesas')->insertGetId([
            'numero' => (string) $numero,
            'capacidad' => 4,
            'estado' => $estado,
        ]);
    }

    private function producto(string $nombre, float $precio, bool $cocina = false): int
    {
        return DB::table('productos')->insertGetId([
            'categoria_id' => null,
            'nombre' => $nombre,
            'precio' => $precio,
            'requiere_cocina' => $cocina,
            'activo' => true,
        ]);
    }

    private function venta(int $mesaId, string $estado = 'ACTIVA'): int
    {
        return DB::table('ventas')->insertGetId([
            'mesa_id' => $mesaId,
            'cliente_id' => null,
            'apertura_id' => null,
            'descripcion' => 'Consumo Mesa',
            'monto' => 0,
            'fecha' => now(),
            'estado' => $estado,
            'usuario_id' => null,
            'descuento_porcentaje' => 0,
        ]);
    }

    private function cuenta(int $ventaId, string $nombre, string $estado = 'ABIERTA', ?int $origenId = null): int
    {
        return DB::table('cuentas')->insertGetId([
            'venta_id' => $ventaId,
            'cuenta_origen_id' => $origenId,
            'nombre' => $nombre,
            'total' => 0,
            'estado' => $estado,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function detalle(int $ventaId, int $productoId, int $cantidad, float $precio): int
    {
        return DB::table('venta_detalles')->insertGetId([
            'venta_id' => $ventaId,
            'producto_id' => $productoId,
            'cantidad' => $cantidad,
            'precio_unitario' => $precio,
            'subtotal' => $cantidad * $precio,
            'comanda_id' => null,
            'cuenta_id' => null,
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function asignar(int $cuentaId, int $ventaId, int $detalleId, int $cantidad, float $subtotal): void
    {
        DB::table('cuenta_detalles')->insert([
            'cuenta_id' => $cuentaId,
            'venta_id' => $ventaId,
            'venta_detalle_id' => $detalleId,
            'cantidad' => $cantidad,
            'subtotal' => $subtotal,
            'eliminado' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('cuentas')->where('id', $cuentaId)->update(['total' => $subtotal]);
    }

    private function divisionUrl(int $mesaId): string
    {
        return '/pos/' . $mesaId . '/cuentas/dividir';
    }

    private function activas(int $detalleId): int
    {
        return (int) DB::table('cuenta_detalles')
            ->where('venta_detalle_id', $detalleId)
            ->where('eliminado', false)
            ->sum('cantidad');
    }

    /*
     * =========================================================
     * SUBDIVISIÓN DE UNA SUBCUENTA
     * =========================================================
     */

    public function test_subdividir_subcuenta_crea_nuevas_hijas_y_no_toca_hermana(): void
    {
        $admin = $this->usuario('ADMIN');
        $mesaId = $this->mesa(1);
        $ventaId = $this->venta($mesaId);
        $p = $this->producto('Gaseosa', 30);
        $d1 = $this->detalle($ventaId, $p, 4, 30);

        $ana = $this->cuenta($ventaId, 'Ana');
        $luis = $this->cuenta($ventaId, 'Luis');
        $this->asignar($ana, $ventaId, $d1, 2, 60);
        $this->asignar($luis, $ventaId, $d1, 2, 60);

        // Paso 1: se crea la subcuenta hija de Ana sin mover unidades.
        $this->actingAs($admin)->postJson($this->divisionUrl($mesaId), [
            'cuenta_origen_id' => $ana,
            'cuentas_nuevas' => ['Ana-1'],
            'asignaciones' => [
                ['cuenta_id' => $ana, 'venta_detalle_id' => $d1, 'cantidad' => 2],
            ],
        ])->assertStatus(200)->assertJson(['exito' => true]);

        $ana1 = (int) DB::table('cuentas')->where('nombre', 'Ana-1')->value('id');
        $hija = DB::table('cuentas')->where('id', $ana1)->first();
        $this->assertNotNull($hija);
        $this->assertSame($ana, (int) $hija->cuenta_origen_id);
        $this->assertSame('ABIERTA', $hija->estado);

        // Paso 2: se mueven las 2 unidades de Ana a su nueva hija.
        $this->actingAs($admin)->postJson($this->divisionUrl($mesaId), [
            'cuenta_origen_id' => $ana,
            'asignaciones' => [
                ['cuenta_id' => $ana1, 'venta_detalle_id' => $d1, 'cantidad' => 2],
            ],
        ])->assertStatus(200)->assertJson(['exito' => true]);

        // El origen queda vacío: sale de las activas (CANCELADA) conservando su registro.
        $origen = DB::table('cuentas')->where('id', $ana)->first();
        $this->assertNotNull($origen);
        $this->assertSame('CANCELADA', $origen->estado);

        // La hija recibe las 2 unidades.
        $this->assertSame(2, (int) DB::table('cuenta_detalles')->where('cuenta_id', $ana1)->where('venta_detalle_id', $d1)->value('cantidad'));
        $this->assertSame(60.0, (float) DB::table('cuentas')->where('id', $ana1)->value('total'));

        // La subcuenta HERMANA (Luis) no se toca.
        $this->assertSame(2, (int) DB::table('cuenta_detalles')->where('cuenta_id', $luis)->where('venta_detalle_id', $d1)->value('cantidad'));
        $this->assertSame(60.0, (float) DB::table('cuentas')->where('id', $luis)->value('total'));
        $this->assertSame('ABIERTA', DB::table('cuentas')->where('id', $luis)->value('estado'));

        // No hay duplicación: siguen existiendo 4 unidades repartidas.
        $this->assertSame(4, $this->activas($d1));
    }

    public function test_no_cancela_subcuenta_vacia_al_crear_otra_hija_y_reparte_bien(): void
    {
        $admin = $this->usuario('ADMIN');
        $mesaId = $this->mesa(1);
        $ventaId = $this->venta($mesaId);
        $p = $this->producto('Ceviche', 20);
        $d1 = $this->detalle($ventaId, $p, 4, 20); // subtotal original 80

        // Cuenta principal A con todo el consumo de d1.
        $a = $this->cuenta($ventaId, 'A');
        $this->asignar($a, $ventaId, $d1, 4, 80);

        // Cuenta hermana AJENA a la subdivisión (otra rama), con su propio
        // consumo, para comprobar que no se toca.
        $d2 = $this->detalle($ventaId, $p, 1, 15);
        $luis = $this->cuenta($ventaId, 'Luis');
        $this->asignar($luis, $ventaId, $d2, 1, 15);

        // Paso 1: crear la subcuenta A1 vacía (aún sin repartir).
        $this->actingAs($admin)->postJson($this->divisionUrl($mesaId), [
            'cuenta_origen_id' => $a,
            'cuentas_nuevas' => ['A1'],
            'asignaciones' => [
                ['cuenta_id' => $a, 'venta_detalle_id' => $d1, 'cantidad' => 4],
            ],
        ])->assertStatus(200)->assertJson(['exito' => true]);

        $a1 = (int) DB::table('cuentas')->where('nombre', 'A1')->value('id');
        $this->assertSame('ABIERTA', DB::table('cuentas')->where('id', $a1)->value('estado'));

        // Paso 2: crear A2 en una petición POSTERIOR, antes de terminar la
        // distribución. Antes del arreglo, esta petición cancelaba a A1 vacía.
        $this->actingAs($admin)->postJson($this->divisionUrl($mesaId), [
            'cuenta_origen_id' => $a,
            'cuentas_nuevas' => ['A2'],
            'asignaciones' => [
                ['cuenta_id' => $a, 'venta_detalle_id' => $d1, 'cantidad' => 4],
            ],
        ])->assertStatus(200)->assertJson(['exito' => true]);

        $a2 = (int) DB::table('cuentas')->where('nombre', 'A2')->value('id');

        // La subcuenta hermana vacía A1 debe seguir disponible (ABIERTA).
        $this->assertSame('ABIERTA', DB::table('cuentas')->where('id', $a1)->value('estado'));
        $this->assertSame('ABIERTA', DB::table('cuentas')->where('id', $a2)->value('estado'));
        $this->assertSame('ABIERTA', DB::table('cuentas')->where('id', $a)->value('estado'));

        // Paso 3: repartir el consumo entre A1 y A2.
        $this->actingAs($admin)->postJson($this->divisionUrl($mesaId), [
            'cuenta_origen_id' => $a,
            'asignaciones' => [
                ['cuenta_id' => $a1, 'venta_detalle_id' => $d1, 'cantidad' => 2],
                ['cuenta_id' => $a2, 'venta_detalle_id' => $d1, 'cantidad' => 2],
            ],
        ])->assertStatus(200)->assertJson(['exito' => true]);

        // A1 y A2 reciben cantidades y subtotales correctos (20 c/u la unidad).
        $this->assertSame(2, (int) DB::table('cuenta_detalles')->where('cuenta_id', $a1)->where('venta_detalle_id', $d1)->value('cantidad'));
        $this->assertSame(40.0, (float) DB::table('cuenta_detalles')->where('cuenta_id', $a1)->where('venta_detalle_id', $d1)->value('subtotal'));
        $this->assertSame(40.0, (float) DB::table('cuentas')->where('id', $a1)->value('total'));
        $this->assertSame('ABIERTA', DB::table('cuentas')->where('id', $a1)->value('estado'));

        $this->assertSame(2, (int) DB::table('cuenta_detalles')->where('cuenta_id', $a2)->where('venta_detalle_id', $d1)->value('cantidad'));
        $this->assertSame(40.0, (float) DB::table('cuenta_detalles')->where('cuenta_id', $a2)->where('venta_detalle_id', $d1)->value('subtotal'));
        $this->assertSame(40.0, (float) DB::table('cuentas')->where('id', $a2)->value('total'));
        $this->assertSame('ABIERTA', DB::table('cuentas')->where('id', $a2)->value('estado'));

        // El origen A quedó completamente vacío: se cancela conservando registro.
        $origen = DB::table('cuentas')->where('id', $a)->first();
        $this->assertNotNull($origen);
        $this->assertSame('CANCELADA', $origen->estado);

        // La cuenta hermana ajena (Luis) no cambia en nada.
        $this->assertSame('ABIERTA', DB::table('cuentas')->where('id', $luis)->value('estado'));
        $this->assertSame(15.0, (float) DB::table('cuentas')->where('id', $luis)->value('total'));
        $this->assertSame(1, (int) DB::table('cuenta_detalles')->where('cuenta_id', $luis)->where('venta_detalle_id', $d2)->value('cantidad'));

        // No se duplican ni pierden consumos: 4 unidades y 80 de importe.
        $this->assertSame(4, $this->activas($d1));
        $suma = (float) DB::table('cuenta_detalles')->where('venta_detalle_id', $d1)->where('eliminado', false)->sum('subtotal');
        $this->assertSame(80.0, $suma);
    }

    public function test_subdivision_parcial_conserva_saldo_y_productos_del_origen(): void
    {
        $admin = $this->usuario('ADMIN');
        $mesaId = $this->mesa(1);
        $ventaId = $this->venta($mesaId);
        $p = $this->producto('Gaseosa', 30);
        $d1 = $this->detalle($ventaId, $p, 3, 30);

        $ana = $this->cuenta($ventaId, 'Ana');
        $this->asignar($ana, $ventaId, $d1, 3, 90);
        $ana1 = $this->cuenta($ventaId, 'Ana-1', 'ABIERTA', $ana);

        // Reparto parcial: 1 se queda en Ana, 2 van a Ana-1.
        $this->actingAs($admin)->postJson($this->divisionUrl($mesaId), [
            'cuenta_origen_id' => $ana,
            'asignaciones' => [
                ['cuenta_id' => $ana, 'venta_detalle_id' => $d1, 'cantidad' => 1],
                ['cuenta_id' => $ana1, 'venta_detalle_id' => $d1, 'cantidad' => 2],
            ],
        ])->assertStatus(200)->assertJson(['exito' => true]);

        // El origen conserva su saldo y sigue ABIERTA.
        $this->assertSame('ABIERTA', DB::table('cuentas')->where('id', $ana)->value('estado'));
        $this->assertSame(1, (int) DB::table('cuenta_detalles')->where('cuenta_id', $ana)->where('venta_detalle_id', $d1)->value('cantidad'));
        $this->assertSame(30.0, (float) DB::table('cuentas')->where('id', $ana)->value('total'));
        $this->assertSame(2, (int) DB::table('cuenta_detalles')->where('cuenta_id', $ana1)->where('venta_detalle_id', $d1)->value('cantidad'));
        $this->assertSame(60.0, (float) DB::table('cuentas')->where('id', $ana1)->value('total'));

        // El total repartido sigue siendo el original (sin duplicar ni perder).
        $this->assertSame(3, $this->activas($d1));
        $this->assertSame(90.0, (float) DB::table('cuenta_detalles')->where('venta_detalle_id', $d1)->where('eliminado', false)->sum('subtotal'));
    }

    public function test_subdivision_solo_admite_lineas_de_la_cuenta_origen(): void
    {
        $admin = $this->usuario('ADMIN');
        $mesaId = $this->mesa(1);
        $ventaId = $this->venta($mesaId);
        $p = $this->producto('Gaseosa', 30);
        $d1 = $this->detalle($ventaId, $p, 2, 30);
        $d2 = $this->detalle($ventaId, $this->producto('Jugo', 20), 2, 20);

        $ana = $this->cuenta($ventaId, 'Ana');
        $luis = $this->cuenta($ventaId, 'Luis');
        $this->asignar($ana, $ventaId, $d1, 2, 60);
        $this->asignar($luis, $ventaId, $d2, 2, 40);

        // Se intenta repartir una línea que NO pertenece a Ana (es de Luis).
        $this->actingAs($admin)->postJson($this->divisionUrl($mesaId), [
            'cuenta_origen_id' => $ana,
            'asignaciones' => [
                ['cuenta_id' => $ana, 'venta_detalle_id' => $d2, 'cantidad' => 2],
            ],
        ])->assertStatus(422)->assertJson(['exito' => false]);

        // Nada cambió.
        $this->assertSame(2, (int) DB::table('cuenta_detalles')->where('cuenta_id', $ana)->where('venta_detalle_id', $d1)->value('cantidad'));
        $this->assertSame(2, (int) DB::table('cuenta_detalles')->where('cuenta_id', $luis)->where('venta_detalle_id', $d2)->value('cantidad'));
    }

    public function test_subdivision_no_permite_superar_las_unidades_del_origen(): void
    {
        $admin = $this->usuario('ADMIN');
        $mesaId = $this->mesa(1);
        $ventaId = $this->venta($mesaId);
        $p = $this->producto('Gaseosa', 30);
        $d1 = $this->detalle($ventaId, $p, 2, 30);

        $ana = $this->cuenta($ventaId, 'Ana');
        $this->asignar($ana, $ventaId, $d1, 2, 60);
        $ana1 = $this->cuenta($ventaId, 'Ana-1', 'ABIERTA', $ana);

        // 2 + 2 = 4 sobre una línea de 2: se rechaza.
        $this->actingAs($admin)->postJson($this->divisionUrl($mesaId), [
            'cuenta_origen_id' => $ana,
            'asignaciones' => [
                ['cuenta_id' => $ana, 'venta_detalle_id' => $d1, 'cantidad' => 2],
                ['cuenta_id' => $ana1, 'venta_detalle_id' => $d1, 'cantidad' => 2],
            ],
        ])->assertStatus(422)->assertJson(['exito' => false]);

        $this->assertSame(2, $this->activas($d1));
    }

    public function test_no_se_puede_subdividir_una_cuenta_cancelada(): void
    {
        $admin = $this->usuario('ADMIN');
        $mesaId = $this->mesa(1);
        $ventaId = $this->venta($mesaId);
        $p = $this->producto('Gaseosa', 30);
        $d1 = $this->detalle($ventaId, $p, 2, 30);

        $ana = $this->cuenta($ventaId, 'Ana', 'CANCELADA');
        $this->asignar($ana, $ventaId, $d1, 2, 60);

        $this->actingAs($admin)->postJson($this->divisionUrl($mesaId), [
            'cuenta_origen_id' => $ana,
            'asignaciones' => [
                ['cuenta_id' => $ana, 'venta_detalle_id' => $d1, 'cantidad' => 2],
            ],
        ])->assertStatus(422)->assertJson(['exito' => false]);

        $this->assertSame(2, $this->activas($d1));
    }

    public function test_no_se_puede_subdividir_una_cuenta_pagada(): void
    {
        $admin = $this->usuario('ADMIN');
        $mesaId = $this->mesa(1);
        $ventaId = $this->venta($mesaId);
        $p = $this->producto('Gaseosa', 30);
        $d1 = $this->detalle($ventaId, $p, 2, 30);

        $ana = $this->cuenta($ventaId, 'Ana', 'PAGADA');
        $this->asignar($ana, $ventaId, $d1, 2, 60);

        $this->actingAs($admin)->postJson($this->divisionUrl($mesaId), [
            'cuenta_origen_id' => $ana,
            'asignaciones' => [
                ['cuenta_id' => $ana, 'venta_detalle_id' => $d1, 'cantidad' => 2],
            ],
        ])->assertStatus(422)->assertJson(['exito' => false]);
    }

    public function test_no_se_puede_subdividir_hacia_una_cuenta_hermana(): void
    {
        $admin = $this->usuario('ADMIN');
        $mesaId = $this->mesa(1);
        $ventaId = $this->venta($mesaId);
        $p = $this->producto('Gaseosa', 30);
        $d1 = $this->detalle($ventaId, $p, 2, 30);

        $ana = $this->cuenta($ventaId, 'Ana');
        $luis = $this->cuenta($ventaId, 'Luis');
        $this->asignar($ana, $ventaId, $d1, 2, 60);

        // Intentar mover el consumo de Ana hacia Luis (cuenta hermana): se rechaza.
        $this->actingAs($admin)->postJson($this->divisionUrl($mesaId), [
            'cuenta_origen_id' => $ana,
            'asignaciones' => [
                ['cuenta_id' => $luis, 'venta_detalle_id' => $d1, 'cantidad' => 2],
            ],
        ])->assertStatus(422)->assertJson(['exito' => false]);

        $this->assertSame(2, (int) DB::table('cuenta_detalles')->where('cuenta_id', $ana)->where('venta_detalle_id', $d1)->value('cantidad'));
        $this->assertSame(0, (int) DB::table('cuenta_detalles')->where('cuenta_id', $luis)->count());
    }

    /*
     * =========================================================
     * POS ACOTADO A UNA CUENTA
     * =========================================================
     */

    public function test_pos_acotado_muestra_solo_los_consumos_de_la_cuenta(): void
    {
        $admin = $this->usuario('ADMIN');
        $mesaId = $this->mesa(1);
        $ventaId = $this->venta($mesaId);
        $p = $this->producto('Gaseosa', 30);
        $d1 = $this->detalle($ventaId, $p, 4, 30);

        $ana = $this->cuenta($ventaId, 'Ana');
        $luis = $this->cuenta($ventaId, 'Luis');
        $this->asignar($ana, $ventaId, $d1, 2, 60);
        $this->asignar($luis, $ventaId, $d1, 2, 60);

        $respuesta = $this->actingAs($admin)->get('/pos/' . $mesaId . '?cuenta=' . $ana);
        $respuesta->assertStatus(200);

        $historial = $respuesta->viewData('historial');
        $this->assertSame(1, $historial->count());
        $this->assertSame(2, (int) $historial->first()->cantidad);
        $this->assertSame(60.0, (float) $respuesta->viewData('totalCuenta'));
    }

    public function test_pos_rechaza_una_cuenta_cancelada_por_url(): void
    {
        $admin = $this->usuario('ADMIN');
        $mesaId = $this->mesa(1);
        $ventaId = $this->venta($mesaId);
        $p = $this->producto('Gaseosa', 30);
        $d1 = $this->detalle($ventaId, $p, 2, 30);
        $ana = $this->cuenta($ventaId, 'Ana', 'CANCELADA');
        $this->asignar($ana, $ventaId, $d1, 2, 60);

        // Cuenta cancelada por URL manipulada: se rechaza el acceso a esa cuenta.
        $this->actingAs($admin)
            ->get('/pos/' . $mesaId . '?cuenta=' . $ana)
            ->assertStatus(404);

        // La vista general sigue disponible.
        $this->actingAs($admin)
            ->get('/pos/' . $mesaId)
            ->assertStatus(200);
    }

    public function test_pos_rechaza_una_cuenta_inexistente_o_ajena(): void
    {
        $admin = $this->usuario('ADMIN');
        $mesaId = $this->mesa(1);
        $ventaId = $this->venta($mesaId);
        $this->detalle($ventaId, $this->producto('Gaseosa', 30), 1, 30);

        $otraMesa = $this->mesa(2);
        $ajena = $this->cuenta($this->venta($otraMesa), 'Ajena');

        $this->actingAs($admin)
            ->get('/pos/' . $mesaId . '?cuenta=' . $ajena)
            ->assertStatus(404);

        $this->actingAs($admin)
            ->get('/pos/' . $mesaId . '?cuenta=999999')
            ->assertStatus(404);
    }

    public function test_subdivision_conserva_el_registro_de_la_cuenta_de_origen(): void
    {
        $admin = $this->usuario('ADMIN');
        $mesaId = $this->mesa(1);
        $ventaId = $this->venta($mesaId);
        $p = $this->producto('Gaseosa', 30);
        $d1 = $this->detalle($ventaId, $p, 2, 30);

        $ana = $this->cuenta($ventaId, 'Ana');
        $this->asignar($ana, $ventaId, $d1, 2, 60);
        $ana1 = $this->cuenta($ventaId, 'Ana-1', 'ABIERTA', $ana);

        $this->actingAs($admin)->postJson($this->divisionUrl($mesaId), [
            'cuenta_origen_id' => $ana,
            'asignaciones' => [
                ['cuenta_id' => $ana1, 'venta_detalle_id' => $d1, 'cantidad' => 2],
            ],
        ])->assertStatus(200)->assertJson(['exito' => true]);

        // La cuenta de origen NO se elimina físicamente: se conserva (CANCELADA).
        $this->assertTrue(DB::table('cuentas')->where('id', $ana)->exists());
        $this->assertSame('CANCELADA', DB::table('cuentas')->where('id', $ana)->value('estado'));

        // La línea de venta original permanece intacta.
        $this->assertTrue(DB::table('venta_detalles')->where('id', $d1)->exists());
        $this->assertSame(2, (int) DB::table('venta_detalles')->where('id', $d1)->value('cantidad'));
    }

    /*
     * =========================================================
     * AISLAMIENTO: CUENTAS VACÍAS NO PUEDEN DIVIDIR
     * =========================================================
     */

    public function test_regresion_principal_vacia_no_divide_ni_muestra_subcuentas(): void
    {
        $admin = $this->usuario('ADMIN');
        $mesaId = $this->mesa(1);
        $ventaId = $this->venta($mesaId);
        $p = $this->producto('Pizza', 20);
        $d1 = $this->detalle($ventaId, $p, 4, 20); // C$80

        // A tiene todo el consumo (4 unidades / C$80).
        $a = $this->cuenta($ventaId, 'A');
        $this->asignar($a, $ventaId, $d1, 4, 80);

        // Crear A1 y A2 y repartir 2/2: A queda sin consumos propios.
        $this->actingAs($admin)->postJson($this->divisionUrl($mesaId), [
            'cuenta_origen_id' => $a,
            'cuentas_nuevas' => ['A1'],
            'asignaciones' => [['cuenta_id' => $a, 'venta_detalle_id' => $d1, 'cantidad' => 4]],
        ])->assertStatus(200)->assertJson(['exito' => true]);
        $a1 = (int) DB::table('cuentas')->where('nombre', 'A1')->value('id');

        $this->actingAs($admin)->postJson($this->divisionUrl($mesaId), [
            'cuenta_origen_id' => $a,
            'cuentas_nuevas' => ['A2'],
            'asignaciones' => [['cuenta_id' => $a, 'venta_detalle_id' => $d1, 'cantidad' => 4]],
        ])->assertStatus(200)->assertJson(['exito' => true]);
        $a2 = (int) DB::table('cuentas')->where('nombre', 'A2')->value('id');

        $this->actingAs($admin)->postJson($this->divisionUrl($mesaId), [
            'cuenta_origen_id' => $a,
            'asignaciones' => [
                ['cuenta_id' => $a1, 'venta_detalle_id' => $d1, 'cantidad' => 2],
                ['cuenta_id' => $a2, 'venta_detalle_id' => $d1, 'cantidad' => 2],
            ],
        ])->assertStatus(200)->assertJson(['exito' => true]);

        // A quedó vacía: no se muestra (CANCELADA -> 404 por URL).
        $this->assertSame('CANCELADA', DB::table('cuentas')->where('id', $a)->value('estado'));
        $this->actingAs($admin)
            ->get('/pos/' . $mesaId . '?cuenta=' . $a)
            ->assertStatus(404);

        // División directa de A: rechazada y sin asignar los C$80 de A1/A2.
        $this->actingAs($admin)->postJson($this->divisionUrl($mesaId), [
            'cuenta_origen_id' => $a,
            'asignaciones' => [
                ['cuenta_id' => $a1, 'venta_detalle_id' => $d1, 'cantidad' => 2],
                ['cuenta_id' => $a2, 'venta_detalle_id' => $d1, 'cantidad' => 2],
            ],
        ])->assertStatus(422)->assertJson(['exito' => false]);

        $this->assertSame(2, (int) DB::table('cuenta_detalles')->where('cuenta_id', $a1)->where('venta_detalle_id', $d1)->value('cantidad'));
        $this->assertSame(2, (int) DB::table('cuenta_detalles')->where('cuenta_id', $a2)->where('venta_detalle_id', $d1)->value('cantidad'));

        // A1 se subdivide en nuevas hijas sin modificar A2.
        $this->actingAs($admin)->postJson($this->divisionUrl($mesaId), [
            'cuenta_origen_id' => $a1,
            'cuentas_nuevas' => ['A1-1'],
            'asignaciones' => [['cuenta_id' => $a1, 'venta_detalle_id' => $d1, 'cantidad' => 2]],
        ])->assertStatus(200)->assertJson(['exito' => true]);
        $a11 = (int) DB::table('cuentas')->where('nombre', 'A1-1')->value('id');

        $this->actingAs($admin)->postJson($this->divisionUrl($mesaId), [
            'cuenta_origen_id' => $a1,
            'cuentas_nuevas' => ['A1-2'],
            'asignaciones' => [['cuenta_id' => $a1, 'venta_detalle_id' => $d1, 'cantidad' => 2]],
        ])->assertStatus(200)->assertJson(['exito' => true]);
        $a12 = (int) DB::table('cuentas')->where('nombre', 'A1-2')->value('id');

        $this->actingAs($admin)->postJson($this->divisionUrl($mesaId), [
            'cuenta_origen_id' => $a1,
            'asignaciones' => [
                ['cuenta_id' => $a11, 'venta_detalle_id' => $d1, 'cantidad' => 1],
                ['cuenta_id' => $a12, 'venta_detalle_id' => $d1, 'cantidad' => 1],
            ],
        ])->assertStatus(200)->assertJson(['exito' => true]);

        // A2 permanece intacta (estado, cantidad y total).
        $this->assertSame('ABIERTA', DB::table('cuentas')->where('id', $a2)->value('estado'));
        $this->assertSame(2, (int) DB::table('cuenta_detalles')->where('cuenta_id', $a2)->where('venta_detalle_id', $d1)->value('cantidad'));
        $this->assertSame(40.0, (float) DB::table('cuentas')->where('id', $a2)->value('total'));
        $this->assertSame(40.0, (float) DB::table('cuentas')->where('id', $a11)->value('total') + (float) DB::table('cuentas')->where('id', $a12)->value('total'));

        // Sin pérdidas ni duplicaciones: 4 unidades y C$80 exactos.
        $this->assertSame(4, $this->activas($d1));
        $this->assertSame(80.0, (float) DB::table('cuenta_detalles')->where('venta_detalle_id', $d1)->where('eliminado', false)->sum('subtotal'));
    }

    public function test_principal_vacia_abierta_no_divide_aunque_tenga_subcuentas_con_consumo(): void
    {
        $admin = $this->usuario('ADMIN');
        $mesaId = $this->mesa(1);
        $ventaId = $this->venta($mesaId);
        $p = $this->producto('Pizza', 20);
        $d1 = $this->detalle($ventaId, $p, 4, 20);

        // A está ABIERTA pero SIN consumos propios; su hija A1 tiene el consumo.
        $a = $this->cuenta($ventaId, 'A');
        $a1 = $this->cuenta($ventaId, 'A1', 'ABIERTA', $a);
        $this->asignar($a1, $ventaId, $d1, 4, 80);

        // Intentar dividir A no puede usar el consumo de su subcuenta.
        $this->actingAs($admin)->postJson($this->divisionUrl($mesaId), [
            'cuenta_origen_id' => $a,
            'asignaciones' => [['cuenta_id' => $a1, 'venta_detalle_id' => $d1, 'cantidad' => 4]],
        ])->assertStatus(422)->assertJson(['exito' => false]);

        // Nada cambió.
        $this->assertSame(4, (int) DB::table('cuenta_detalles')->where('cuenta_id', $a1)->where('venta_detalle_id', $d1)->value('cantidad'));
        $this->assertSame('ABIERTA', DB::table('cuentas')->where('id', $a)->value('estado'));
    }

    public function test_subcuenta_vacia_no_puede_dividir_consumos_ajenos(): void
    {
        $admin = $this->usuario('ADMIN');
        $mesaId = $this->mesa(1);
        $ventaId = $this->venta($mesaId);
        $p = $this->producto('Pizza', 20);
        $d1 = $this->detalle($ventaId, $p, 2, 20);

        $a = $this->cuenta($ventaId, 'A');
        $ana = $this->cuenta($ventaId, 'Ana', 'ABIERTA', $a); // subcuenta vacía
        $luis = $this->cuenta($ventaId, 'Luis');               // hermana con consumo
        $this->asignar($luis, $ventaId, $d1, 2, 40);

        $this->actingAs($admin)->postJson($this->divisionUrl($mesaId), [
            'cuenta_origen_id' => $ana,
            'asignaciones' => [['cuenta_id' => $luis, 'venta_detalle_id' => $d1, 'cantidad' => 2]],
        ])->assertStatus(422)->assertJson(['exito' => false]);

        // El consumo de Luis sigue intacto.
        $this->assertSame(2, (int) DB::table('cuenta_detalles')->where('cuenta_id', $luis)->where('venta_detalle_id', $d1)->value('cantidad'));
        $this->assertSame(0, (int) DB::table('cuenta_detalles')->where('cuenta_id', $ana)->count());
    }

    public function test_dividir_una_cuenta_no_toca_subcuentas_con_consumo_ni_usa_sus_consumos(): void
    {
        $admin = $this->usuario('ADMIN');
        $mesaId = $this->mesa(1);
        $ventaId = $this->venta($mesaId);
        $pizza = $this->producto('Pizza', 20);
        $gaseosa = $this->producto('Gaseosa', 30);

        // Línea de pizza compartida: A tiene 2 unidades y A2 tiene 1.
        $dPizza = $this->detalle($ventaId, $pizza, 3, 20); // subtotal 60
        // Línea de gaseosa: A1 con 2 unidades.
        $dGaseosa = $this->detalle($ventaId, $gaseosa, 2, 30); // subtotal 60

        $a = $this->cuenta($ventaId, 'A');
        $a1 = $this->cuenta($ventaId, 'A1', 'ABIERTA', $a);
        $a2 = $this->cuenta($ventaId, 'A2', 'ABIERTA', $a);

        $this->asignar($a, $ventaId, $dPizza, 2, 40);     // A:  2 pizzas  = C$40
        $this->asignar($a1, $ventaId, $dGaseosa, 2, 60);  // A1: 2 gaseosas = C$60
        $this->asignar($a2, $ventaId, $dPizza, 1, 20);    // A2: 1 pizza    = C$20

        // No se pueden usar los consumos de A1/A2 para dividir A.
        $this->actingAs($admin)->postJson($this->divisionUrl($mesaId), [
            'cuenta_origen_id' => $a,
            'asignaciones' => [
                ['cuenta_id' => $a1, 'venta_detalle_id' => $dGaseosa, 'cantidad' => 2],
                ['cuenta_id' => $a2, 'venta_detalle_id' => $dPizza, 'cantidad' => 1],
            ],
        ])->assertStatus(422)->assertJson(['exito' => false]);

        // División válida: reparte SOLO las 2 pizzas propias de A con una hija nueva.
        $this->actingAs($admin)->postJson($this->divisionUrl($mesaId), [
            'cuenta_origen_id' => $a,
            'cuentas_nuevas' => ['A3'],
            'asignaciones' => [
                ['cuenta_id' => $a, 'venta_detalle_id' => $dPizza, 'cantidad' => 2],
            ],
        ])->assertStatus(200)->assertJson(['exito' => true]);

        $a3 = (int) DB::table('cuentas')->where('nombre', 'A3')->value('id');

        $this->actingAs($admin)->postJson($this->divisionUrl($mesaId), [
            'cuenta_origen_id' => $a,
            'asignaciones' => [
                ['cuenta_id' => $a, 'venta_detalle_id' => $dPizza, 'cantidad' => 1],
                ['cuenta_id' => $a3, 'venta_detalle_id' => $dPizza, 'cantidad' => 1],
            ],
        ])->assertStatus(200)->assertJson(['exito' => true]);

        // A1 (gaseosas) intacta: cantidad, importe y estado.
        $this->assertSame('ABIERTA', DB::table('cuentas')->where('id', $a1)->value('estado'));
        $this->assertSame(2, (int) DB::table('cuenta_detalles')->where('cuenta_id', $a1)->where('venta_detalle_id', $dGaseosa)->value('cantidad'));
        $this->assertSame(60.0, (float) DB::table('cuentas')->where('id', $a1)->value('total'));

        // A2 (pizza) intacta: cantidad, importe y estado.
        $this->assertSame('ABIERTA', DB::table('cuentas')->where('id', $a2)->value('estado'));
        $this->assertSame(1, (int) DB::table('cuenta_detalles')->where('cuenta_id', $a2)->where('venta_detalle_id', $dPizza)->value('cantidad'));
        $this->assertSame(20.0, (float) DB::table('cuentas')->where('id', $a2)->value('total'));

        // A reparte sus 2 pizzas con A3 (1 y 1) sin perder ni duplicar.
        $this->assertSame(1, (int) DB::table('cuenta_detalles')->where('cuenta_id', $a)->where('venta_detalle_id', $dPizza)->value('cantidad'));
        $this->assertSame(1, (int) DB::table('cuenta_detalles')->where('cuenta_id', $a3)->where('venta_detalle_id', $dPizza)->value('cantidad'));

        // Totales globales exactos: 3 pizzas (60) y 2 gaseosas (60).
        $this->assertSame(3, $this->activas($dPizza));
        $this->assertSame(2, $this->activas($dGaseosa));
        $this->assertSame(60.0, (float) DB::table('cuenta_detalles')->where('venta_detalle_id', $dPizza)->where('eliminado', false)->sum('subtotal'));
        $this->assertSame(60.0, (float) DB::table('cuenta_detalles')->where('venta_detalle_id', $dGaseosa)->where('eliminado', false)->sum('subtotal'));
    }

    public function test_interfaz_boton_dividir_solo_con_consumos_propios(): void
    {
        $admin = $this->usuario('ADMIN');
        $mesaId = $this->mesa(1);
        $ventaId = $this->venta($mesaId);
        $p = $this->producto('Gaseosa', 30);
        $d1 = $this->detalle($ventaId, $p, 2, 30);

        $llena = $this->cuenta($ventaId, 'Llena');
        $this->asignar($llena, $ventaId, $d1, 2, 60);
        $vacia = $this->cuenta($ventaId, 'Vacia'); // sin consumos propios

        $conConsumo = $this->actingAs($admin)
            ->get('/pos/' . $mesaId . '?cuenta=' . $llena)
            ->assertStatus(200);
        $this->assertTrue($conConsumo->viewData('puedeDividirCuenta'));

        $sinConsumo = $this->actingAs($admin)
            ->get('/pos/' . $mesaId . '?cuenta=' . $vacia)
            ->assertStatus(200);
        $this->assertFalse($sinConsumo->viewData('puedeDividirCuenta'));
        $sinConsumo->assertSee('title="La cuenta no tiene consumos propios para dividir"', false);
    }

    public function test_permisos_de_subdivision_se_mantienen(): void
    {
        // ADMIN, GERENTE y CAJERO pueden subdividir una cuenta con consumo.
        foreach (['ADMIN', 'GERENTE', 'CAJERO'] as $i => $rol) {
            $usuario = $this->usuario($rol);
            $mesaId = $this->mesa($i + 1);
            $ventaId = $this->venta($mesaId);
            $p = $this->producto('Prod' . $i, 10);
            $d1 = $this->detalle($ventaId, $p, 2, 10);
            $x = $this->cuenta($ventaId, 'X' . $i);
            $this->asignar($x, $ventaId, $d1, 2, 20);

            $this->actingAs($usuario)->postJson($this->divisionUrl($mesaId), [
                'cuenta_origen_id' => $x,
                'cuentas_nuevas' => ['Y' . $i],
                'asignaciones' => [['cuenta_id' => $x, 'venta_detalle_id' => $d1, 'cantidad' => 2]],
            ])->assertStatus(200)->assertJson(['exito' => true]);
        }

        // MESERO (y sin sesión) no pueden: no se modifica nada.
        $mesero = $this->usuario('MESERO');
        $mesaId = $this->mesa(9);
        $ventaId = $this->venta($mesaId);
        $p = $this->producto('ProdM', 10);
        $d1 = $this->detalle($ventaId, $p, 2, 10);
        $x = $this->cuenta($ventaId, 'XM');
        $this->asignar($x, $ventaId, $d1, 2, 20);

        $this->actingAs($mesero)->postJson($this->divisionUrl($mesaId), [
            'cuenta_origen_id' => $x,
            'cuentas_nuevas' => ['YM'],
            'asignaciones' => [['cuenta_id' => $x, 'venta_detalle_id' => $d1, 'cantidad' => 2]],
        ])->assertStatus(403)->assertJson(['exito' => false]);

        $this->assertSame(2, (int) DB::table('cuenta_detalles')->where('cuenta_id', $x)->where('venta_detalle_id', $d1)->value('cantidad'));
        $this->assertFalse(DB::table('cuentas')->where('nombre', 'YM')->exists());
    }
}
