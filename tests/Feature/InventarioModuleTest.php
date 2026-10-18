<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/*
 * Pruebas de la INTERFAZ del módulo de inventario (controlador + rutas + menú).
 *
 * IMPORTANTE:
 * - Corren en SQLite :memory: (phpunit.xml); nunca contra MySQL "sistemadecaja".
 * - El esquema se construye a mano (los migrations reales usan SQL de MySQL).
 * - Cubren: permisos por rol (backend, no solo Blade), activar/desactivar,
 *   carga inicial (única), ajustes, mínimo/unidad, historial y que un producto
 *   sin control no altera el POS.
 */
class InventarioModuleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Candado de seguridad: nunca apuntar a la base MySQL real.
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', (string) config('database.connections.sqlite.database'));

        $this->crearEsquema();

        DB::table('mesas')->insert(['id' => 1, 'numero' => '1', 'estado' => 'LIBRE']);
    }

    private function crearEsquema(): void
    {
        foreach ([
            'movimientos_inventario',
            'inventario_producto',
            'anulaciones',
            'cuenta_detalles',
            'cuentas',
            'venta_detalles',
            'ventas',
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
            $t->string('numero', 50)->nullable();
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

        Schema::create('ventas', function (Blueprint $t) {
            $t->increments('id');
            $t->unsignedBigInteger('mesa_id')->nullable();
            $t->unsignedBigInteger('cliente_id')->nullable();
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

        Schema::create('inventario_producto', function (Blueprint $t) {
            $t->id();
            $t->integer('producto_id');
            $t->decimal('stock_actual', 12, 3)->default(0);
            $t->decimal('stock_minimo', 12, 3)->default(0);
            $t->string('unidad', 20)->default('unidad');
            $t->timestamps();
            $t->unique('producto_id');
            $t->foreign('producto_id')->references('id')->on('productos');
        });

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

    // ---------------- Helpers ----------------

    private function usuario(string $rolNombre): User
    {
        $rolId = DB::table('roles')->insertGetId(['nombre' => $rolNombre]);

        $usuarioId = DB::table('usuarios')->insertGetId([
            'nombre' => $rolNombre.' Test',
            'username' => strtolower($rolNombre).'_test',
            'password_hash' => bcrypt('secret'),
            'rol_id' => $rolId,
        ]);

        return User::findOrFail($usuarioId);
    }

    private function producto(bool $controlaStock, float $precio = 100, ?int $categoriaId = null): int
    {
        return DB::table('productos')->insertGetId([
            'categoria_id' => $categoriaId,
            'nombre' => 'Producto '.$controlaStock.' '.uniqid(),
            'precio' => $precio,
            'requiere_cocina' => false,
            'controla_stock' => $controlaStock,
            'activo' => true,
        ]);
    }

    private function stock(int $productoId): ?float
    {
        $valor = DB::table('inventario_producto')
            ->where('producto_id', $productoId)
            ->value('stock_actual');

        return $valor === null ? null : (float) $valor;
    }

    private function movimientos(int $productoId, ?string $tipo = null)
    {
        $query = DB::table('movimientos_inventario')->where('producto_id', $productoId);
        if ($tipo !== null) {
            $query->where('tipo', $tipo);
        }

        return $query->orderBy('id')->get();
    }

    // ---------------- Permisos por rol (backend) ----------------

    public function test_admin_puede_consultar_y_administrar(): void
    {
        $admin = $this->usuario('ADMIN');
        $p = $this->producto(true);

        $this->actingAs($admin)->get('/inventario')->assertStatus(200);
        $this->actingAs($admin)->get('/inventario/historial')->assertStatus(200);

        $this->actingAs($admin)
            ->postJson('/inventario/'.$p.'/control', ['activo' => '0'])
            ->assertStatus(200)
            ->assertJson(['exito' => true]);

        $this->assertSame(0, (int) DB::table('productos')->where('id', $p)->value('controla_stock'));
    }

    public function test_gerente_puede_administrar(): void
    {
        $gerente = $this->usuario('GERENTE');
        $p = $this->producto(false);

        $this->actingAs($gerente)
            ->postJson('/inventario/'.$p.'/control', ['activo' => '1'])
            ->assertStatus(200);

        $this->assertSame(1, (int) DB::table('productos')->where('id', $p)->value('controla_stock'));
    }

    public function test_cajero_solo_consulta_y_no_modifica(): void
    {
        $cajero = $this->usuario('CAJERO');
        $p = $this->producto(true);

        // Consulta permitida.
        $this->actingAs($cajero)->get('/inventario')->assertStatus(200);
        $this->actingAs($cajero)->get('/inventario/historial')->assertStatus(200);

        // Toda modificación prohibida en el backend (403), aunque intente la URL.
        $this->actingAs($cajero)
            ->postJson('/inventario/'.$p.'/control', ['activo' => '0'])
            ->assertStatus(403);
        $this->actingAs($cajero)
            ->postJson('/inventario/'.$p.'/carga-inicial', ['cantidad' => 10])
            ->assertStatus(403);
        $this->actingAs($cajero)
            ->postJson('/inventario/'.$p.'/ajuste', ['tipo' => 'AJUSTE_ENTRADA', 'cantidad' => 1])
            ->assertStatus(403);
        $this->actingAs($cajero)
            ->postJson('/inventario/'.$p.'/minimo', ['stock_minimo' => 5])
            ->assertStatus(403);

        // Nada se modificó.
        $this->assertSame(0, DB::table('movimientos_inventario')->count());
        $this->assertSame(1, (int) DB::table('productos')->where('id', $p)->value('controla_stock'));
    }

    public function test_mesero_no_tiene_acceso_al_modulo(): void
    {
        $mesero = $this->usuario('MESERO');
        $p = $this->producto(true);

        $this->actingAs($mesero)->get('/inventario')->assertStatus(403);
        $this->actingAs($mesero)->get('/inventario/historial')->assertStatus(403);
        $this->actingAs($mesero)
            ->postJson('/inventario/'.$p.'/control', ['activo' => '0'])
            ->assertStatus(403);
    }

    public function test_invitado_redirige_al_login(): void
    {
        $this->get('/inventario')->assertRedirect(route('login'));
        $this->get('/inventario/historial')->assertRedirect(route('login'));
    }

    // ---------------- Carga inicial ----------------

    public function test_carga_inicial_genera_movimiento_y_actualiza_stock(): void
    {
        $admin = $this->usuario('ADMIN');
        $p = $this->producto(true);

        $this->actingAs($admin)
            ->postJson('/inventario/'.$p.'/carga-inicial', [
                'cantidad' => 10,
                'observacion' => 'Conteo inicial',
            ])
            ->assertStatus(200)
            ->assertJson(['exito' => true]);

        $this->assertSame(10.0, $this->stock($p));

        $mov = $this->movimientos($p, 'CARGA_INICIAL');
        $this->assertCount(1, $mov);
        $this->assertSame(10.0, (float) $mov->first()->cantidad);
        $this->assertSame('CARGA_INICIAL', $mov->first()->origen_tipo);
        $this->assertNull($mov->first()->origen_id);
        $this->assertSame($admin->id, (int) $mov->first()->usuario_id);
        $this->assertSame('Conteo inicial', $mov->first()->observacion);
    }

    public function test_segunda_carga_inicial_es_rechazada(): void
    {
        $admin = $this->usuario('ADMIN');
        $p = $this->producto(true);

        $this->actingAs($admin)
            ->postJson('/inventario/'.$p.'/carga-inicial', ['cantidad' => 10])
            ->assertStatus(200);

        // Segunda carga: 409 y sin efecto (se conserva una sola carga).
        $this->actingAs($admin)
            ->postJson('/inventario/'.$p.'/carga-inicial', ['cantidad' => 5])
            ->assertStatus(409)
            ->assertJson(['exito' => false]);

        $this->assertSame(10.0, $this->stock($p));
        $this->assertCount(1, $this->movimientos($p, 'CARGA_INICIAL'));
    }

    public function test_carga_inicial_cantidad_invalida_es_rechazada(): void
    {
        $admin = $this->usuario('ADMIN');
        $p = $this->producto(true);

        $this->actingAs($admin)
            ->postJson('/inventario/'.$p.'/carga-inicial', ['cantidad' => 0])
            ->assertStatus(422);

        $this->actingAs($admin)
            ->postJson('/inventario/'.$p.'/carga-inicial', ['cantidad' => -3])
            ->assertStatus(422);

        $this->assertSame(0, DB::table('movimientos_inventario')->count());
    }

    /** Carga inicial rechazada cuando el producto no controla stock. */
    public function test_carga_inicial_rechazada_sin_control_de_stock(): void
    {
        $admin = $this->usuario('ADMIN');
        $p = $this->producto(false);

        $this->actingAs($admin)
            ->postJson('/inventario/'.$p.'/carga-inicial', ['cantidad' => 10])
            ->assertStatus(422)
            ->assertJson(['exito' => false]);

        $this->assertSame(0, DB::table('movimientos_inventario')->count());
        $this->assertSame(0, DB::table('inventario_producto')->where('producto_id', $p)->count());
    }

    /** Protección contra duplicados invocando el servicio dentro de una transacción. */
    public function test_carga_inicial_duplicada_via_servicio_se_rechaza(): void
    {
        $admin = $this->usuario('ADMIN');
        $p = $this->producto(true);

        $servicio = app(\App\Services\InventarioService::class);

        // Primera carga: correcta, dentro de transacción.
        DB::transaction(function () use ($servicio, $p, $admin) {
            $servicio->registrarCargaInicial($p, 10, $admin->id, 'Primera');
        });

        $this->assertSame(10.0, $this->stock($p));
        $this->assertCount(1, $this->movimientos($p, 'CARGA_INICIAL'));

        // Segunda carga del mismo producto: DomainException y sin efecto.
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Este producto ya tiene una carga inicial registrada.');
        DB::transaction(function () use ($servicio, $p, $admin) {
            $servicio->registrarCargaInicial($p, 5, $admin->id, 'Segunda');
        });

        // Se conserva una sola carga: ni stock ni movimiento se duplicaron.
        $this->assertSame(10.0, $this->stock($p));
        $this->assertCount(1, $this->movimientos($p, 'CARGA_INICIAL'));
    }

    /** Carga inicial dentro de una transacción que falla después: rollback total. */
    public function test_carga_inicial_rollback_si_falla_transaccion(): void
    {
        $admin = $this->usuario('ADMIN');
        $p = $this->producto(true);

        $servicio = app(\App\Services\InventarioService::class);

        try {
            DB::transaction(function () use ($servicio, $p, $admin) {
                $servicio->registrarCargaInicial($p, 10, $admin->id, 'Prueba');
                throw new \RuntimeException('Fallo simulado tras la carga.');
            });
        } catch (\RuntimeException $e) {
            // Esperado: la transacción se revierte por completo.
        }

        // Nada quedó registrado: ni movimiento ni fila de inventario.
        $this->assertSame(0, DB::table('movimientos_inventario')->count());
        $this->assertSame(0, DB::table('inventario_producto')->where('producto_id', $p)->count());
    }

    /**
     * Nota sobre concurrencia real: las pruebas de este archivo corren en SQLite
     * :memory:, que usa una sola conexión y no permite ejecutar dos peticiones
     * HTTP simultáneas contra el mismo producto. Por eso no hay una prueba de
     * carrera (race condition) real con hilos. La protección contra duplicados
     * bajo concurrencia depende del contrato transaccional documentado en
     * InventarioService::registrarCargaInicial (bloqueo lockForUpdate sobre
     * inventario_producto dentro de DB::transaction). Las pruebas secuenciales
     * de esta clase cubren la protección en el flujo normal de un solo hilo.
     */

    // ---------------- Ajustes ----------------

    public function test_ajustes_entrada_y_salida_registran_movimientos(): void
    {
        $admin = $this->usuario('ADMIN');
        $p = $this->producto(true);

        $this->actingAs($admin)
            ->postJson('/inventario/'.$p.'/ajuste', [
                'tipo' => 'AJUSTE_ENTRADA',
                'cantidad' => 5,
                'observacion' => 'Compra',
            ])
            ->assertStatus(200);

        $this->assertSame(5.0, $this->stock($p));

        $this->actingAs($admin)
            ->postJson('/inventario/'.$p.'/ajuste', [
                'tipo' => 'AJUSTE_SALIDA',
                'cantidad' => 2,
                'observacion' => 'Merma',
            ])
            ->assertStatus(200);

        $this->assertSame(3.0, $this->stock($p));

        $entrada = $this->movimientos($p, 'AJUSTE_ENTRADA');
        $this->assertCount(1, $entrada);
        $this->assertSame(5.0, (float) $entrada->first()->cantidad);

        $salida = $this->movimientos($p, 'AJUSTE_SALIDA');
        $this->assertCount(1, $salida);
        $this->assertSame(-2.0, (float) $salida->first()->cantidad);
        $this->assertSame('Merma', $salida->first()->observacion);
    }

    public function test_ajuste_con_cantidad_cero_o_tipo_invalido_es_rechazado(): void
    {
        $admin = $this->usuario('ADMIN');
        $p = $this->producto(true);

        $this->actingAs($admin)
            ->postJson('/inventario/'.$p.'/ajuste', ['tipo' => 'AJUSTE_ENTRADA', 'cantidad' => 0])
            ->assertStatus(422);

        $this->actingAs($admin)
            ->postJson('/inventario/'.$p.'/ajuste', ['tipo' => 'VENTA', 'cantidad' => 3])
            ->assertStatus(422);

        $this->assertSame(0, DB::table('movimientos_inventario')->count());
    }

    /** Ajuste rechazado cuando el producto no controla stock. */
    public function test_ajuste_rechazado_sin_control_de_stock(): void
    {
        $admin = $this->usuario('ADMIN');
        $p = $this->producto(false);

        $this->actingAs($admin)
            ->postJson('/inventario/'.$p.'/ajuste', ['tipo' => 'AJUSTE_ENTRADA', 'cantidad' => 5])
            ->assertStatus(422)
            ->assertJson(['exito' => false]);

        $this->assertSame(0, DB::table('movimientos_inventario')->count());
    }

    // ---------------- Activar/desactivar y mínimo ----------------

    public function test_desactivar_no_borra_existencias_ni_historial(): void
    {
        $admin = $this->usuario('ADMIN');
        $p = $this->producto(true);

        $this->actingAs($admin)
            ->postJson('/inventario/'.$p.'/carga-inicial', ['cantidad' => 8])
            ->assertStatus(200);
        $this->assertSame(8.0, $this->stock($p));

        // Desactivar el control.
        $this->actingAs($admin)
            ->postJson('/inventario/'.$p.'/control', ['activo' => '0'])
            ->assertStatus(200);

        $this->assertSame(0, (int) DB::table('productos')->where('id', $p)->value('controla_stock'));
        $this->assertSame(8.0, $this->stock($p));
        $this->assertCount(1, $this->movimientos($p));

        // Reactivar: todo se conserva.
        $this->actingAs($admin)
            ->postJson('/inventario/'.$p.'/control', ['activo' => '1'])
            ->assertStatus(200);
        $this->assertSame(8.0, $this->stock($p));
        $this->assertCount(1, $this->movimientos($p));
    }

    public function test_configurar_minimo_no_reinicia_el_stock(): void
    {
        $admin = $this->usuario('ADMIN');
        $p = $this->producto(true);

        $this->actingAs($admin)
            ->postJson('/inventario/'.$p.'/carga-inicial', ['cantidad' => 10])
            ->assertStatus(200);

        $this->actingAs($admin)
            ->postJson('/inventario/'.$p.'/minimo', ['stock_minimo' => 4, 'unidad' => 'kg'])
            ->assertStatus(200);

        $fila = DB::table('inventario_producto')->where('producto_id', $p)->first();
        $this->assertSame(10.0, (float) $fila->stock_actual);
        $this->assertSame(4.0, (float) $fila->stock_minimo);
        $this->assertSame('kg', $fila->unidad);
    }

    /** Configuración de mínimo rechazada cuando el producto no controla stock. */
    public function test_configurar_minimo_rechazado_sin_control_de_stock(): void
    {
        $admin = $this->usuario('ADMIN');
        $p = $this->producto(false);

        $this->actingAs($admin)
            ->postJson('/inventario/'.$p.'/minimo', ['stock_minimo' => 4, 'unidad' => 'kg'])
            ->assertStatus(422)
            ->assertJson(['exito' => false]);

        $this->assertSame(0, DB::table('inventario_producto')->where('producto_id', $p)->count());
    }

    // ---------------- POS no se altera ----------------

    public function test_producto_sin_control_no_altera_el_pos(): void
    {
        $admin = $this->usuario('ADMIN');
        $p = $this->producto(false);

        $ventaId = DB::table('ventas')->insertGetId(['mesa_id' => 1, 'monto' => 0, 'estado' => 'ACTIVA']);
        $cuentaId = DB::table('cuentas')->insertGetId([
            'venta_id' => $ventaId,
            'nombre' => 'Cuenta Principal',
            'total' => 0,
            'estado' => 'ABIERTA',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)
            ->postJson('/pos/api', [
                'accion' => 'enviar_cocina',
                'venta_id' => $ventaId,
                'cuenta_id' => $cuentaId,
                'carrito' => json_encode([['id' => $p, 'cantidad' => 2]]),
            ])
            ->assertStatus(200)
            ->assertJson(['exito' => true]);

        // La venta se registró con normalidad y el inventario no cambió.
        $this->assertSame(1, DB::table('venta_detalles')->where('venta_id', $ventaId)->count());
        $this->assertSame(0, DB::table('movimientos_inventario')->count());
        $this->assertSame(0, DB::table('inventario_producto')->count());
    }

    // ---------------- Historial y menú ----------------

    public function test_historial_muestra_los_movimientos(): void
    {
        $admin = $this->usuario('ADMIN');
        $p = $this->producto(true);

        $this->actingAs($admin)
            ->postJson('/inventario/'.$p.'/carga-inicial', ['cantidad' => 7, 'observacion' => 'Inicial'])
            ->assertStatus(200);

        $respuesta = $this->actingAs($admin)->get('/inventario/historial');
        $respuesta->assertStatus(200);
        $respuesta->assertSee('CARGA_INICIAL', false);
        $respuesta->assertSee('ADMIN Test', false);
        $respuesta->assertSee('Inicial', false);
    }

    public function test_el_menu_muestra_inventario_segun_el_rol(): void
    {
        $url = route('inventario.index');

        $this->actingAs($this->usuario('ADMIN'))->get('/inicio')->assertSee($url, false);
        $this->actingAs($this->usuario('GERENTE'))->get('/inicio')->assertSee($url, false);
        $this->actingAs($this->usuario('CAJERO'))->get('/inicio')->assertSee($url, false);
        $this->actingAs($this->usuario('MESERO'))->get('/inicio')->assertDontSee($url, false);
    }

    // ---------------- Migraciones pendientes (sin ejecutarlas) ----------------

    public function test_esquema_pendiente_muestra_aviso_y_no_falla(): void
    {
        $admin = $this->usuario('ADMIN');
        $p = $this->producto(true);

        // Simula el despliegue ANTES de aplicar las migraciones de inventario.
        Schema::dropIfExists('movimientos_inventario');

        $this->actingAs($admin)->get('/inventario')
            ->assertStatus(200)
            ->assertSee('no está disponible', false);

        // Las operaciones responden 503 en lugar de romper con SQL.
        $this->actingAs($admin)
            ->postJson('/inventario/'.$p.'/carga-inicial', ['cantidad' => 1])
            ->assertStatus(503);
    }
}
