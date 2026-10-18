<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/*
 * Pruebas aisladas de la anulación lógica de ítems.
 *
 * IMPORTANTE:
 * - Corren en SQLite :memory: (configurado en phpunit.xml).
 * - El esquema se construye a mano porque los migrations reales usan SQL
 *   exclusivo de MySQL. Así ninguna prueba toca "sistemadecaja".
 * - setUp() falla a propósito si la conexión no es SQLite en memoria.
 */
class AnularDetalleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Candado de seguridad: nunca apuntar a la base MySQL real.
        $this->assertSame(
            'sqlite',
            DB::connection()->getDriverName(),
            'Las pruebas deben ejecutarse en SQLite.'
        );
        $this->assertSame(
            ':memory:',
            (string) config('database.connections.sqlite.database'),
            'La base de pruebas debe ser SQLite en memoria.'
        );

        $this->crearEsquema();
    }

    private function crearEsquema(): void
    {
        // Se eliminan primero por si una prueba reutiliza la conexión.
        foreach ([
            'movimientos_inventario',
            'anulaciones',
            'cuenta_detalles',
            'cuentas',
            'venta_detalles',
            'ventas',
            'productos',
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

        Schema::create('productos', function (Blueprint $t) {
            $t->increments('id');
            $t->string('nombre', 150);
            $t->decimal('precio', 10, 2)->default(0);
            $t->boolean('requiere_cocina')->default(false);
            $t->boolean('activo')->default(true);
        });

        // La tabla ventas real usa "monto" y no tiene created_at/updated_at.
        Schema::create('ventas', function (Blueprint $t) {
            $t->increments('id');
            $t->unsignedBigInteger('mesa_id')->nullable();
            $t->integer('apertura_id')->nullable();
            $t->string('descripcion')->nullable();
            $t->decimal('monto', 12, 2)->default(0);
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

        // Refleja el esquema real + las columnas nuevas de borrado lógico.
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

        // Inventario (Etapa 2): libro de movimientos consultado por el servicio.
        Schema::create('movimientos_inventario', function (Blueprint $t) {
            $t->id();
            $t->integer('producto_id');
            $t->string('tipo', 30);
            $t->decimal('cantidad', 12, 3);
            $t->string('origen_tipo', 30)->nullable();
            $t->unsignedBigInteger('origen_id')->nullable();
            $t->integer('usuario_id')->nullable();
            $t->string('observacion', 500)->nullable();
            $t->timestamp('creado_en')->useCurrent();
            $t->unique(['origen_tipo', 'origen_id']);
        });
    }

    private function usuario(string $rolNombre): User
    {
        $rolId = DB::table('roles')->insertGetId(['nombre' => $rolNombre]);

        $usuarioId = DB::table('usuarios')->insertGetId([
            'nombre' => $rolNombre . ' Test',
            'username' => strtolower($rolNombre) . '_test',
            'password_hash' => bcrypt('secret'),
            'rol_id' => $rolId,
        ]);

        return User::findOrFail($usuarioId);
    }

    private function crearProducto(string $nombre, float $precio, bool $cocina = false): int
    {
        return DB::table('productos')->insertGetId([
            'nombre' => $nombre,
            'precio' => $precio,
            'requiere_cocina' => $cocina,
            'activo' => true,
        ]);
    }

    private function crearVenta(array $extra = []): int
    {
        return DB::table('ventas')->insertGetId(array_merge([
            'mesa_id' => 1,
            'descripcion' => 'Consumo Mesa 1',
            'monto' => 0,
            'estado' => 'ACTIVA',
        ], $extra));
    }

    private function crearDetalle(int $ventaId, int $productoId, int $cantidad, float $precio, array $extra = []): int
    {
        return DB::table('venta_detalles')->insertGetId(array_merge([
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
        ], $extra));
    }

    private function crearCuenta(int $ventaId, array $extra = []): int
    {
        return DB::table('cuentas')->insertGetId(array_merge([
            'venta_id' => $ventaId,
            'nombre' => 'Cuenta Principal',
            'total' => 0,
            'estado' => 'ABIERTA',
            'created_at' => now(),
            'updated_at' => now(),
        ], $extra));
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
    }

    public function test_entorno_aislado_no_usa_mysql(): void
    {
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', (string) config('database.connections.sqlite.database'));
        $this->assertStringNotContainsString('sistemadecaja', (string) config('database.connections.sqlite.database'));
    }

    public function test_admin_puede_anular_y_se_recalculan_totales(): void
    {
        $admin = $this->usuario('ADMIN');
        $ventaId = $this->crearVenta();
        $p1 = $this->crearProducto('Café', 100);
        $p2 = $this->crearProducto('Jugo', 50);
        $d1 = $this->crearDetalle($ventaId, $p1, 1, 100);
        $d2 = $this->crearDetalle($ventaId, $p2, 1, 50);
        $cuentaId = $this->crearCuenta($ventaId, ['total' => 150]);
        $this->asignar($cuentaId, $ventaId, $d1, 1, 100);
        $this->asignar($cuentaId, $ventaId, $d2, 1, 50);
        DB::table('ventas')->where('id', $ventaId)->update(['monto' => 150]);

        $respuesta = $this->actingAs($admin)
            ->postJson('/pos/detalle/' . $d2 . '/anular', ['cantidad' => 1, 'motivo' => 'Cliente canceló el jugo']);

        $respuesta->assertStatus(200)->assertJson(['exito' => true]);

        // El detalle queda ANULADO (no se borra).
        $this->assertDatabaseHas('venta_detalles', ['id' => $d2, 'estado' => 'ANULADO']);
        $this->assertDatabaseHas('venta_detalles', ['id' => $d1, 'estado' => 'ACTIVO']);

        // La asignación se marca eliminada, no se borra físicamente.
        $this->assertSame(2, DB::table('cuenta_detalles')->count());
        $this->assertSame(1, (int) DB::table('cuenta_detalles')->where('venta_detalle_id', $d2)->value('eliminado'));
        $this->assertSame($admin->id, (int) DB::table('cuenta_detalles')->where('venta_detalle_id', $d2)->value('eliminado_por'));
        $this->assertNotNull(DB::table('cuenta_detalles')->where('venta_detalle_id', $d2)->value('eliminado_en'));

        // Totales recalculados.
        $this->assertSame(100.0, (float) DB::table('cuentas')->where('id', $cuentaId)->value('total'));
        $this->assertSame(100.0, (float) DB::table('ventas')->where('id', $ventaId)->value('monto'));

        // La venta sigue ACTIVA.
        $this->assertSame('ACTIVA', DB::table('ventas')->where('id', $ventaId)->value('estado'));

        // Auditoría con creado_en, sin depender de ninguna FK.
        $this->assertDatabaseCount('anulaciones', 1);
        $anulacion = DB::table('anulaciones')->first();
        $this->assertSame('ITEM_ANULADO', $anulacion->tipo);
        $this->assertSame($ventaId, (int) $anulacion->venta_id);
        $this->assertSame($d2, (int) $anulacion->venta_detalle_id);
        $this->assertSame('Cliente canceló el jugo', $anulacion->motivo);
        $this->assertSame($admin->id, (int) $anulacion->usuario_id);
        $this->assertNotNull($anulacion->creado_en);

        // No existe tabla "mesas": si el controlador la tocara, esto fallaría.
        $this->assertFalse(Schema::hasTable('mesas'));
    }

    public function test_gerente_puede_anular(): void
    {
        $gerente = $this->usuario('GERENTE');
        $ventaId = $this->crearVenta();
        $p1 = $this->crearProducto('Café', 100);
        $d1 = $this->crearDetalle($ventaId, $p1, 1, 100);
        $cuentaId = $this->crearCuenta($ventaId);
        $this->asignar($cuentaId, $ventaId, $d1, 1, 100);

        $this->actingAs($gerente)
            ->postJson('/pos/detalle/' . $d1 . '/anular', ['cantidad' => 1, 'motivo' => 'Producto equivocado'])
            ->assertStatus(200)
            ->assertJson(['exito' => true]);

        $this->assertDatabaseHas('venta_detalles', ['id' => $d1, 'estado' => 'ANULADO']);
    }

    public function test_cajero_no_puede_anular(): void
    {
        $cajero = $this->usuario('CAJERO');
        $ventaId = $this->crearVenta();
        $p1 = $this->crearProducto('Café', 100);
        $d1 = $this->crearDetalle($ventaId, $p1, 1, 100);

        $this->actingAs($cajero)
            ->postJson('/pos/detalle/' . $d1 . '/anular', ['cantidad' => 1, 'motivo' => 'Intento de cajero'])
            ->assertStatus(403);

        $this->assertDatabaseHas('venta_detalles', ['id' => $d1, 'estado' => 'ACTIVO']);
        $this->assertDatabaseCount('anulaciones', 0);
    }

    public function test_mesero_no_puede_anular(): void
    {
        $mesero = $this->usuario('MESERO');
        $ventaId = $this->crearVenta();
        $p1 = $this->crearProducto('Café', 100);
        $d1 = $this->crearDetalle($ventaId, $p1, 1, 100);

        $this->actingAs($mesero)
            ->postJson('/pos/detalle/' . $d1 . '/anular', ['cantidad' => 1, 'motivo' => 'Intento de mesero'])
            ->assertStatus(403);

        $this->assertDatabaseHas('venta_detalles', ['id' => $d1, 'estado' => 'ACTIVO']);
    }

    public function test_invitado_es_rechazado(): void
    {
        $ventaId = $this->crearVenta();
        $p1 = $this->crearProducto('Café', 100);
        $d1 = $this->crearDetalle($ventaId, $p1, 1, 100);

        $this->post('/pos/detalle/' . $d1 . '/anular', ['cantidad' => 1, 'motivo' => 'Sin sesión'])
            ->assertStatus(302);

        $this->assertDatabaseHas('venta_detalles', ['id' => $d1, 'estado' => 'ACTIVO']);
    }

    public function test_motivo_es_obligatorio(): void
    {
        $admin = $this->usuario('ADMIN');
        $ventaId = $this->crearVenta();
        $p1 = $this->crearProducto('Café', 100);
        $d1 = $this->crearDetalle($ventaId, $p1, 1, 100);

        $this->actingAs($admin)
            ->postJson('/pos/detalle/' . $d1 . '/anular', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('motivo');

        $this->assertDatabaseHas('venta_detalles', ['id' => $d1, 'estado' => 'ACTIVO']);
    }

    public function test_no_anula_detalle_asociado_a_cuenta_pagada(): void
    {
        $admin = $this->usuario('ADMIN');
        $ventaId = $this->crearVenta();
        $p1 = $this->crearProducto('Café', 100);
        $d1 = $this->crearDetalle($ventaId, $p1, 1, 100);
        $cuentaId = $this->crearCuenta($ventaId, ['estado' => 'PAGADA', 'total' => 100]);
        $this->asignar($cuentaId, $ventaId, $d1, 1, 100);

        $this->actingAs($admin)
            ->postJson('/pos/detalle/' . $d1 . '/anular', ['cantidad' => 1, 'motivo' => 'Intento en cuenta pagada'])
            ->assertStatus(422);

        $this->assertDatabaseHas('venta_detalles', ['id' => $d1, 'estado' => 'ACTIVO']);
        $this->assertSame(0, (int) DB::table('cuenta_detalles')->where('venta_detalle_id', $d1)->value('eliminado'));
    }

    public function test_no_anula_si_la_venta_esta_cerrada(): void
    {
        $admin = $this->usuario('ADMIN');
        $ventaId = $this->crearVenta(['estado' => 'PAGADA']);
        $p1 = $this->crearProducto('Café', 100);
        $d1 = $this->crearDetalle($ventaId, $p1, 1, 100);
        $cuentaId = $this->crearCuenta($ventaId);
        $this->asignar($cuentaId, $ventaId, $d1, 1, 100);

        $this->actingAs($admin)
            ->postJson('/pos/detalle/' . $d1 . '/anular', ['cantidad' => 1, 'motivo' => 'Venta cerrada'])
            ->assertStatus(422);

        $this->assertDatabaseHas('venta_detalles', ['id' => $d1, 'estado' => 'ACTIVO']);
    }

    public function test_no_se_puede_anular_dos_veces(): void
    {
        $admin = $this->usuario('ADMIN');
        $ventaId = $this->crearVenta();
        $p1 = $this->crearProducto('Café', 100);
        $d1 = $this->crearDetalle($ventaId, $p1, 1, 100);
        $cuentaId = $this->crearCuenta($ventaId);
        $this->asignar($cuentaId, $ventaId, $d1, 1, 100);

        $this->actingAs($admin)
            ->postJson('/pos/detalle/' . $d1 . '/anular', ['cantidad' => 1, 'motivo' => 'Primera anulación'])
            ->assertStatus(200);

        $this->actingAs($admin)
            ->postJson('/pos/detalle/' . $d1 . '/anular', ['cantidad' => 1, 'motivo' => 'Segunda anulación'])
            ->assertStatus(422);

        $this->assertDatabaseCount('anulaciones', 1);
    }

    public function test_anular_todos_los_items_deja_venta_activa_con_monto_cero(): void
    {
        $admin = $this->usuario('ADMIN');
        $ventaId = $this->crearVenta();
        $p1 = $this->crearProducto('Café', 100);
        $p2 = $this->crearProducto('Jugo', 50);
        $d1 = $this->crearDetalle($ventaId, $p1, 1, 100);
        $d2 = $this->crearDetalle($ventaId, $p2, 1, 50);
        $cuentaId = $this->crearCuenta($ventaId, ['total' => 150]);
        $this->asignar($cuentaId, $ventaId, $d1, 1, 100);
        $this->asignar($cuentaId, $ventaId, $d2, 1, 50);
        DB::table('ventas')->where('id', $ventaId)->update(['monto' => 150]);

        foreach ([$d1, $d2] as $detalleId) {
            $this->actingAs($admin)
                ->postJson('/pos/detalle/' . $detalleId . '/anular', ['cantidad' => 1, 'motivo' => 'Anulación total de prueba'])
                ->assertStatus(200);
        }

        $this->assertSame(0.0, (float) DB::table('ventas')->where('id', $ventaId)->value('monto'));
        $this->assertSame('ACTIVA', DB::table('ventas')->where('id', $ventaId)->value('estado'));
        $this->assertSame(0.0, (float) DB::table('cuentas')->where('id', $cuentaId)->value('total'));
        $this->assertSame(2, (int) DB::table('cuenta_detalles')->where('eliminado', true)->count());
        $this->assertDatabaseCount('anulaciones', 2);
    }

    public function test_cuentas_excluye_asignaciones_anuladas(): void
    {
        $admin = $this->usuario('ADMIN');
        $ventaId = $this->crearVenta();
        $p1 = $this->crearProducto('Café', 100);
        $p2 = $this->crearProducto('Jugo', 50);
        $d1 = $this->crearDetalle($ventaId, $p1, 1, 100);
        $d2 = $this->crearDetalle($ventaId, $p2, 1, 50);
        $cuentaId = $this->crearCuenta($ventaId, ['total' => 150]);
        $this->asignar($cuentaId, $ventaId, $d1, 1, 100);
        $this->asignar($cuentaId, $ventaId, $d2, 1, 50);

        $this->actingAs($admin)
            ->postJson('/pos/detalle/' . $d2 . '/anular', ['cantidad' => 1, 'motivo' => 'Quitar jugo'])
            ->assertStatus(200);

        $respuesta = $this->actingAs($admin)->getJson('/pos/1/cuentas')->assertStatus(200);

        $datos = $respuesta->json();
        $idsAsignados = array_column($datos['asignaciones'], 'venta_detalle_id');

        $this->assertContains($d1, array_map('intval', $idsAsignados));
        $this->assertNotContains($d2, array_map('intval', $idsAsignados));

        $cuenta = collect($datos['cuentas'])->firstWhere('id', $cuentaId);
        $this->assertSame(100.0, (float) $cuenta['total']);

        $idsDetalles = array_column($datos['detalles'], 'id');
        $this->assertNotContains($d2, array_map('intval', $idsDetalles));
    }

    public function test_cantidad_es_obligatoria(): void
    {
        $admin = $this->usuario('ADMIN');
        $ventaId = $this->crearVenta();
        $p1 = $this->crearProducto('Café', 100);
        $d1 = $this->crearDetalle($ventaId, $p1, 2, 100);

        $this->actingAs($admin)
            ->postJson('/pos/detalle/' . $d1 . '/anular', ['motivo' => 'Sin cantidad'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('cantidad');

        // Omitir la cantidad ya no anula la línea completa.
        $this->assertDatabaseHas('venta_detalles', [
            'id' => $d1,
            'cantidad' => 2,
            'estado' => 'ACTIVO',
        ]);
        $this->assertDatabaseCount('anulaciones', 0);
    }

    public function test_anulacion_parcial_reduce_cantidad_subtotal_y_totales(): void
    {
        $admin = $this->usuario('ADMIN');
        $ventaId = $this->crearVenta();
        $p1 = $this->crearProducto('Café', 100);
        $d1 = $this->crearDetalle($ventaId, $p1, 3, 100);
        $cuentaId = $this->crearCuenta($ventaId, ['total' => 300]);
        $this->asignar($cuentaId, $ventaId, $d1, 3, 300);
        DB::table('ventas')->where('id', $ventaId)->update(['monto' => 300]);

        $this->actingAs($admin)
            ->postJson('/pos/detalle/' . $d1 . '/anular', ['cantidad' => 1, 'motivo' => 'Se sirvió una menos'])
            ->assertStatus(200)
            ->assertJson([
                'exito' => true,
                'cantidad_anulada' => 1,
                'cantidad_restante' => 2,
                'estado' => 'ACTIVO',
            ]);

        // La línea conserva las 2 unidades restantes.
        $this->assertSame(2, (int) DB::table('venta_detalles')->where('id', $d1)->value('cantidad'));
        $this->assertSame(200.0, (float) DB::table('venta_detalles')->where('id', $d1)->value('subtotal'));
        $this->assertDatabaseHas('venta_detalles', ['id' => $d1, 'estado' => 'ACTIVO']);

        // La asignación abierta baja de 3 a 2 y no se elimina.
        $asignacion = DB::table('cuenta_detalles')->where('venta_detalle_id', $d1)->first();
        $this->assertSame(2, (int) $asignacion->cantidad);
        $this->assertSame(200.0, (float) $asignacion->subtotal);
        $this->assertSame(0, (int) $asignacion->eliminado);

        // Totales recalculados.
        $this->assertSame(200.0, (float) DB::table('cuentas')->where('id', $cuentaId)->value('total'));
        $this->assertSame(200.0, (float) DB::table('ventas')->where('id', $ventaId)->value('monto'));

        // Auditoría con la cantidad y el importe reales anulados.
        $anulacion = DB::table('anulaciones')->first();
        $this->assertSame(1, (int) $anulacion->cantidad);
        $this->assertSame(100.0, (float) $anulacion->monto);
    }

    public function test_no_puede_anular_mas_de_lo_disponible(): void
    {
        $admin = $this->usuario('ADMIN');
        $ventaId = $this->crearVenta();
        $p1 = $this->crearProducto('Café', 100);
        $d1 = $this->crearDetalle($ventaId, $p1, 2, 100);

        $this->actingAs($admin)
            ->postJson('/pos/detalle/' . $d1 . '/anular', ['cantidad' => 3, 'motivo' => 'Más de lo permitido'])
            ->assertStatus(422);

        $this->assertDatabaseHas('venta_detalles', [
            'id' => $d1,
            'cantidad' => 2,
            'estado' => 'ACTIVO',
        ]);
        $this->assertDatabaseCount('anulaciones', 0);
    }

    public function test_anulacion_parcial_sin_asignaciones(): void
    {
        $admin = $this->usuario('ADMIN');
        $ventaId = $this->crearVenta();
        $p1 = $this->crearProducto('Café', 100);
        $d1 = $this->crearDetalle($ventaId, $p1, 2, 100);
        DB::table('ventas')->where('id', $ventaId)->update(['monto' => 200]);

        $this->actingAs($admin)
            ->postJson('/pos/detalle/' . $d1 . '/anular', ['cantidad' => 1, 'motivo' => 'Sin cuenta asignada'])
            ->assertStatus(200)
            ->assertJson([
                'cantidad_restante' => 1,
                'estado' => 'ACTIVO',
            ]);

        $this->assertSame(1, (int) DB::table('venta_detalles')->where('id', $d1)->value('cantidad'));
        $this->assertSame(100.0, (float) DB::table('venta_detalles')->where('id', $d1)->value('subtotal'));
        $this->assertSame(100.0, (float) DB::table('ventas')->where('id', $ventaId)->value('monto'));
    }

    public function test_anulacion_parcial_respeta_unidades_pagadas(): void
    {
        $admin = $this->usuario('ADMIN');
        $ventaId = $this->crearVenta();
        $p1 = $this->crearProducto('Café', 100);

        // Línea de 2 unidades: 1 ya pagada y 1 en una cuenta abierta.
        $d1 = $this->crearDetalle($ventaId, $p1, 2, 100);
        $cuentaPagada = $this->crearCuenta($ventaId, ['estado' => 'PAGADA', 'total' => 100]);
        $cuentaAbierta = $this->crearCuenta($ventaId, ['nombre' => 'Cuenta 2', 'total' => 100]);
        $this->asignar($cuentaPagada, $ventaId, $d1, 1, 100);
        $this->asignar($cuentaAbierta, $ventaId, $d1, 1, 100);

        // Anular 1 unidad debe consumir solo la unidad de la cuenta abierta.
        $this->actingAs($admin)
            ->postJson('/pos/detalle/' . $d1 . '/anular', ['cantidad' => 1, 'motivo' => 'Unidad abierta'])
            ->assertStatus(200)
            ->assertJson([
                'cantidad_restante' => 1,
                'estado' => 'ACTIVO',
            ]);

        // La asignación pagada permanece intacta; la abierta se elimina.
        $this->assertSame(0, (int) DB::table('cuenta_detalles')->where('cuenta_id', $cuentaPagada)->value('eliminado'));
        $this->assertSame(1, (int) DB::table('cuenta_detalles')->where('cuenta_id', $cuentaAbierta)->value('eliminado'));

        // Ya solo queda la unidad pagada: intentar anularla debe rechazarse.
        $this->actingAs($admin)
            ->postJson('/pos/detalle/' . $d1 . '/anular', ['cantidad' => 1, 'motivo' => 'Intentar tocar la pagada'])
            ->assertStatus(422);

        $this->assertSame(1, (int) DB::table('venta_detalles')->where('id', $d1)->value('cantidad'));
    }
}
