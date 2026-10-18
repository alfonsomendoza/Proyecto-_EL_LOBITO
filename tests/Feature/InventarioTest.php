<?php

namespace Tests\Feature;

use App\Http\Controllers\VentaController;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/*
 * Pruebas aisladas del módulo de inventario (Etapa 2).
 *
 * IMPORTANTE:
 * - Corren en SQLite :memory: (configurado en phpunit.xml).
 * - El esquema se construye a mano porque los migrations reales usan SQL
 *   exclusivo de MySQL. Así ninguna prueba toca "sistemadecaja".
 * - setUp() falla a propósito si la conexión no es SQLite en memoria.
 *
 * CONVENCIÓN DE "cantidad": se guarda como variación FIRMADA del stock.
 *   VENTA       -> negativa (ej: -3)
 *   ANULACION   -> positiva (ej: +1)
 *   CANCELACION -> positiva (ej: +2)
 * Las pruebas comprueban esta convención y el saldo resultante.
 */
class InventarioTest extends TestCase
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
            // Columna nueva de la Etapa 1: control opcional de inventario.
            $t->boolean('controla_stock')->default(false);
            $t->boolean('activo')->default(true);
        });

        // La tabla ventas real usa "monto" y no tiene created_at/updated_at.
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

        // Inventario (Etapa 1): saldo actual por producto controlado.
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

        // Inventario (Etapa 1): libro histórico de movimientos.
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
            'nombre' => $rolNombre . ' Test',
            'username' => strtolower($rolNombre) . '_test',
            'password_hash' => bcrypt('secret'),
            'rol_id' => $rolId,
        ]);

        return User::findOrFail($usuarioId);
    }

    private function producto(bool $controlaStock, float $precio = 100): int
    {
        return DB::table('productos')->insertGetId([
            'nombre' => 'Producto ' . uniqid(),
            'precio' => $precio,
            'requiere_cocina' => false,
            'controla_stock' => $controlaStock,
            'activo' => true,
        ]);
    }

    private function venta(array $extra = []): int
    {
        return DB::table('ventas')->insertGetId(array_merge([
            'mesa_id' => 1,
            'monto' => 0,
            'estado' => 'ACTIVA',
        ], $extra));
    }

    private function cuenta(int $ventaId, array $extra = []): int
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

    /**
     * Registra un pedido por la misma vía que el POS (acción enviar_cocina).
     *
     * @param array<int,int> $productosCantidad [producto_id => cantidad]
     */
    private function enviar(int $ventaId, int $cuentaId, array $productosCantidad): void
    {
        $carrito = [];
        foreach ($productosCantidad as $productoId => $cantidad) {
            $carrito[] = ['id' => $productoId, 'cantidad' => $cantidad];
        }

        $this->postJson('/pos/api', [
            'accion' => 'enviar_cocina',
            'venta_id' => $ventaId,
            'cuenta_id' => $cuentaId,
            'carrito' => json_encode($carrito),
        ])->assertStatus(200)->assertJson(['exito' => true]);
    }

    private function detalleId(int $ventaId, int $productoId): int
    {
        return (int) DB::table('venta_detalles')
            ->where('venta_id', $ventaId)
            ->where('producto_id', $productoId)
            ->orderBy('id')
            ->value('id');
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

    private function anular(int $detalleId, int $cantidad, User $usuario, string $motivo = 'Prueba de anulación')
    {
        return $this->actingAs($usuario)
            ->postJson('/pos/detalle/' . $detalleId . '/anular', [
                'cantidad' => $cantidad,
                'motivo' => $motivo,
            ]);
    }

    private function cancelar(int $ventaId, User $usuario)
    {
        return $this->actingAs($usuario)->app->make(VentaController::class)
            ->cancelar($ventaId);
    }

    // ---------------- Casos ----------------

    public function test_entorno_aislado_no_usa_mysql(): void
    {
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', (string) config('database.connections.sqlite.database'));
        $this->assertStringNotContainsString('sistemadecaja', (string) config('database.connections.sqlite.database'));
    }

    /** 1. Venta con control activado: descuenta stock y registra VENTA. */
    public function test_venta_con_control_activado_descuenta_stock(): void
    {
        $admin = $this->usuario('ADMIN');
        $p = $this->producto(true, 100);
        $ventaId = $this->venta();
        $cuentaId = $this->cuenta($ventaId);

        $this->actingAs($admin);
        $this->enviar($ventaId, $cuentaId, [$p => 3]);

        $detalleId = $this->detalleId($ventaId, $p);

        $this->assertSame(-3.0, $this->stock($p));

        $mov = $this->movimientos($p, 'VENTA');
        $this->assertCount(1, $mov);
        $this->assertSame(-3.0, (float) $mov->first()->cantidad);
        $this->assertSame('VENTA', $mov->first()->origen_tipo);
        $this->assertSame($detalleId, (int) $mov->first()->origen_id);
        $this->assertSame($admin->id, (int) $mov->first()->usuario_id);
        $this->assertNotNull($mov->first()->creado_en);
    }

    /** 1b. La fila se crea una sola vez y no se reinicia en operaciones sucesivas. */
    public function test_primera_fila_se_crea_una_vez_y_no_se_reinicia(): void
    {
        $admin = $this->usuario('ADMIN');
        $p = $this->producto(true, 100);

        // Primera operación: crea la fila (stock 0) y aplica la salida.
        $venta1 = $this->venta();
        $cuenta1 = $this->cuenta($venta1);
        $this->actingAs($admin);
        $this->enviar($venta1, $cuenta1, [$p => 3]);

        $this->assertSame(1, DB::table('inventario_producto')->where('producto_id', $p)->count());
        $this->assertSame(-3.0, $this->stock($p));

        // Segunda operación del mismo producto: la fila NO se vuelve a crear ni
        // se reinicia; el stock se acumula.
        $venta2 = $this->venta();
        $cuenta2 = $this->cuenta($venta2);
        $this->enviar($venta2, $cuenta2, [$p => 2]);

        $this->assertSame(1, DB::table('inventario_producto')->where('producto_id', $p)->count());
        $this->assertSame(-5.0, $this->stock($p));
        $this->assertCount(2, $this->movimientos($p, 'VENTA'));
    }

    /** 1c. Con las migraciones de inventario pendientes, la venta no falla. */
    public function test_venta_no_falla_si_migraciones_de_inventario_pendientes(): void
    {
        $admin = $this->usuario('ADMIN');
        $p = $this->producto(true, 100);
        $ventaId = $this->venta();
        $cuentaId = $this->cuenta($ventaId);

        // Simula el despliegue ANTES de aplicar las migraciones de inventario:
        // el libro de movimientos todavía no existe en el esquema.
        Schema::dropIfExists('movimientos_inventario');

        $this->actingAs($admin);
        // enviar() exige 200 y exito = true: si el inventario intentara escribir
        // en la tabla ausente, el pedido devolvería 422 y la prueba fallaría.
        $this->enviar($ventaId, $cuentaId, [$p => 3]);

        $this->assertSame(1, DB::table('venta_detalles')->where('venta_id', $ventaId)->count());
    }

    /** 2. Venta sin control activado: no genera movimientos ni filas. */
    public function test_venta_sin_control_no_genera_movimientos(): void
    {
        $admin = $this->usuario('ADMIN');
        $p = $this->producto(false, 100);
        $ventaId = $this->venta();
        $cuentaId = $this->cuenta($ventaId);

        $this->actingAs($admin);
        $this->enviar($ventaId, $cuentaId, [$p => 2]);

        $this->assertSame(0, DB::table('movimientos_inventario')->count());
        $this->assertSame(0, DB::table('inventario_producto')->count());
    }

    /** 3. La venta nunca se bloquea: se permite stock negativo. */
    public function test_venta_permite_stock_negativo(): void
    {
        $admin = $this->usuario('ADMIN');
        $p = $this->producto(true, 100);
        // Existencia inicial 1.
        DB::table('inventario_producto')->insert([
            'producto_id' => $p,
            'stock_actual' => 1,
            'stock_minimo' => 0,
            'unidad' => 'unidad',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $ventaId = $this->venta();
        $cuentaId = $this->cuenta($ventaId);

        $this->actingAs($admin);
        $this->enviar($ventaId, $cuentaId, [$p => 5]);

        $this->assertSame(-4.0, $this->stock($p));
        $this->assertCount(1, $this->movimientos($p, 'VENTA'));
    }

    /** 4. Varias líneas del mismo producto: un movimiento por línea. */
    public function test_varias_lineas_mismo_producto(): void
    {
        $admin = $this->usuario('ADMIN');
        $p = $this->producto(true, 100);
        $ventaId = $this->venta();
        $cuentaId = $this->cuenta($ventaId);

        $this->actingAs($admin);
        // Mismo producto en dos líneas distintas del carrito.
        $this->postJson('/pos/api', [
            'accion' => 'enviar_cocina',
            'venta_id' => $ventaId,
            'cuenta_id' => $cuentaId,
            'carrito' => json_encode([
                ['id' => $p, 'cantidad' => 2],
                ['id' => $p, 'cantidad' => 3],
            ]),
        ])->assertStatus(200)->assertJson(['exito' => true]);

        $mov = $this->movimientos($p, 'VENTA');
        $this->assertCount(2, $mov);
        $this->assertSame(-2.0, (float) $mov[0]->cantidad);
        $this->assertSame(-3.0, (float) $mov[1]->cantidad);
        $this->assertNotSame((int) $mov[0]->origen_id, (int) $mov[1]->origen_id);
        $this->assertSame(-5.0, $this->stock($p));
    }

    /** 5. Venta con varios productos: solo se mueven los controlados. */
    public function test_venta_con_varios_productos(): void
    {
        $admin = $this->usuario('ADMIN');
        $pControl1 = $this->producto(true, 100);
        $pSinControl = $this->producto(false, 100);
        $pControl2 = $this->producto(true, 50);
        $ventaId = $this->venta();
        $cuentaId = $this->cuenta($ventaId);

        $this->actingAs($admin);
        $this->enviar($ventaId, $cuentaId, [
            $pControl1 => 1,
            $pSinControl => 1,
            $pControl2 => 2,
        ]);

        $this->assertSame(-1.0, $this->stock($pControl1));
        $this->assertSame(-2.0, $this->stock($pControl2));
        $this->assertNull($this->stock($pSinControl));

        $this->assertCount(1, $this->movimientos($pControl1, 'VENTA'));
        $this->assertCount(1, $this->movimientos($pControl2, 'VENTA'));
        $this->assertCount(0, $this->movimientos($pSinControl));
        $this->assertSame(2, DB::table('movimientos_inventario')->count());
    }

    /** 6. Anulación parcial: repone la cantidad anulada con ANULACION. */
    public function test_anulacion_parcial_repone_stock(): void
    {
        $admin = $this->usuario('ADMIN');
        $p = $this->producto(true, 100);
        $ventaId = $this->venta();
        $cuentaId = $this->cuenta($ventaId);

        $this->actingAs($admin);
        $this->enviar($ventaId, $cuentaId, [$p => 3]);
        $this->assertSame(-3.0, $this->stock($p));

        $detalleId = $this->detalleId($ventaId, $p);
        $this->anular($detalleId, 1, $admin)->assertStatus(200)->assertJson(['exito' => true]);

        $this->assertSame(-2.0, $this->stock($p));

        $anulacion = $this->movimientos($p, 'ANULACION');
        $this->assertCount(1, $anulacion);
        $this->assertSame(1.0, (float) $anulacion->first()->cantidad);
        $this->assertSame('ANULACION', $anulacion->first()->origen_tipo);

        // El origen es el id real de "anulaciones", no el de venta_detalles.
        $anulacionId = (int) DB::table('anulaciones')->value('id');
        $this->assertSame($anulacionId, (int) $anulacion->first()->origen_id);
    }

    /** 7. Varias anulaciones parciales de una misma línea. */
    public function test_varias_anulaciones_parciales_misma_linea(): void
    {
        $admin = $this->usuario('ADMIN');
        $p = $this->producto(true, 100);
        $ventaId = $this->venta();
        $cuentaId = $this->cuenta($ventaId);

        $this->actingAs($admin);
        $this->enviar($ventaId, $cuentaId, [$p => 3]);
        $detalleId = $this->detalleId($ventaId, $p);

        $this->anular($detalleId, 1, $admin)->assertStatus(200);
        $this->anular($detalleId, 1, $admin)->assertStatus(200);

        $this->assertSame(-1.0, $this->stock($p));

        $anulaciones = $this->movimientos($p, 'ANULACION');
        $this->assertCount(2, $anulaciones);
        $this->assertSame(2, DB::table('anulaciones')->count());
        $this->assertNotSame((int) $anulaciones[0]->origen_id, (int) $anulaciones[1]->origen_id);
    }

    /** 8. Cancelación después de anulaciones: repone solo lo pendiente. */
    public function test_cancelacion_despues_de_anulacion_repone_lo_pendiente(): void
    {
        $admin = $this->usuario('ADMIN');
        $p = $this->producto(true, 100);
        $ventaId = $this->venta();
        $cuentaId = $this->cuenta($ventaId);

        $this->actingAs($admin);
        // Venta de 5 -> VENTA = -5
        $this->enviar($ventaId, $cuentaId, [$p => 5]);
        $detalleId = $this->detalleId($ventaId, $p);

        // Anulación parcial de 2 -> ANULACION = +2
        $this->anular($detalleId, 2, $admin)->assertStatus(200);
        $this->assertSame(-3.0, $this->stock($p));

        // Cancelación -> CANCELACION = +3 (no +5)
        $respuesta = $this->cancelar($ventaId, $admin);
        $this->assertSame(200, $respuesta->getStatusCode());

        $this->assertSame(0.0, $this->stock($p));

        $cancelacion = $this->movimientos($p, 'CANCELACION');
        $this->assertCount(1, $cancelacion);
        $this->assertSame(3.0, (float) $cancelacion->first()->cantidad);
        $this->assertSame($detalleId, (int) $cancelacion->first()->origen_id);

        // Neto de la línea: -5 + 2 + 3 = 0.
        $this->assertSame(0.0, (float) $this->movimientos($p)->sum('cantidad'));
    }

    /** 9 (y 11). Línea sin movimiento VENTA: no se repone nada. */
    public function test_cancelacion_de_linea_sin_movimiento_venta_no_repone(): void
    {
        $admin = $this->usuario('ADMIN');
        $p = $this->producto(true, 100);
        $ventaId = $this->venta();
        $cuentaId = $this->cuenta($ventaId);

        // Detalle insertado a mano: simula una venta histórica anterior a la
        // activación del control (no existe movimiento VENTA).
        DB::table('venta_detalles')->insert([
            'venta_id' => $ventaId,
            'producto_id' => $p,
            'cantidad' => 4,
            'precio_unitario' => 100,
            'subtotal' => 400,
            'comanda_id' => null,
            'cuenta_id' => null,
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $respuesta = $this->cancelar($ventaId, $admin);
        $this->assertSame(200, $respuesta->getStatusCode());
        $this->assertSame(0, DB::table('movimientos_inventario')->count());
        $this->assertSame(0, DB::table('inventario_producto')->count());
    }

    /** 9b. Anulación de una línea sin movimiento VENTA tampoco repone. */
    public function test_anulacion_de_linea_sin_movimiento_venta_no_repone(): void
    {
        $admin = $this->usuario('ADMIN');
        $p = $this->producto(true, 100);
        $ventaId = $this->venta();

        $detalleId = DB::table('venta_detalles')->insertGetId([
            'venta_id' => $ventaId,
            'producto_id' => $p,
            'cantidad' => 2,
            'precio_unitario' => 100,
            'subtotal' => 200,
            'comanda_id' => null,
            'cuenta_id' => null,
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->anular($detalleId, 1, $admin)->assertStatus(200);

        $this->assertSame(0, DB::table('movimientos_inventario')->count());
        $this->assertSame(0, DB::table('inventario_producto')->count());
    }

    /** 10. El índice único impide duplicar un mismo evento de origen. */
    public function test_movimiento_duplicado_es_bloqueado_por_unique(): void
    {
        $p = $this->producto(true, 100);

        $insertar = fn () => DB::table('movimientos_inventario')->insert([
            'producto_id' => $p,
            'tipo' => 'VENTA',
            'cantidad' => -1,
            'origen_tipo' => 'VENTA',
            'origen_id' => 999,
            'usuario_id' => null,
            'observacion' => null,
            'creado_en' => now(),
        ]);

        $insertar();

        $this->expectException(QueryException::class);
        $insertar();
    }

    /** 10b. El servicio no duplica movimientos al reintentar en transacción. */
    public function test_servicio_no_duplica_si_falla_reintento(): void
    {
        $p = $this->producto(true, 100);
        $ventaId = $this->venta();
        $detalleId = DB::table('venta_detalles')->insertGetId([
            'venta_id' => $ventaId,
            'producto_id' => $p,
            'cantidad' => 2,
            'precio_unitario' => 100,
            'subtotal' => 200,
            'comanda_id' => null,
            'cuenta_id' => null,
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $servicio = app(\App\Services\InventarioService::class);
        $linea = ['venta_detalle_id' => $detalleId, 'producto_id' => $p, 'cantidad' => 2];

        // Primer registro: correcto.
        DB::transaction(fn () => $servicio->registrarVenta([$linea], null));
        $this->assertSame(-2.0, $this->stock($p));

        // Segundo registro del mismo origen: la UNIQUE falla y revierte todo,
        // por lo que el stock no se vuelve a descontar.
        try {
            DB::transaction(fn () => $servicio->registrarVenta([$linea], null));
            $this->fail('Se esperaba una violación de unicidad.');
        } catch (QueryException $e) {
            // Esperado.
        }

        $this->assertSame(-2.0, $this->stock($p));
        $this->assertSame(1, DB::table('movimientos_inventario')->count());
    }

    /** 12. Desactivar/reactivar el control conserva existencias e historial. */
    public function test_desactivar_y_reactivar_conserva_existencias_e_historial(): void
    {
        $admin = $this->usuario('ADMIN');
        $p = $this->producto(true, 100);
        $ventaId = $this->venta();
        $cuentaId = $this->cuenta($ventaId);

        $this->actingAs($admin);
        $this->enviar($ventaId, $cuentaId, [$p => 3]);
        $this->assertSame(-3.0, $this->stock($p));

        // Desactivar el control.
        DB::table('productos')->where('id', $p)->update(['controla_stock' => false]);
        $this->assertSame(-3.0, $this->stock($p));
        $this->assertCount(1, $this->movimientos($p));

        // Una venta nueva con el control apagado no mueve el inventario.
        $ventaId2 = $this->venta();
        $cuentaId2 = $this->cuenta($ventaId2);
        $this->enviar($ventaId2, $cuentaId2, [$p => 1]);
        $this->assertSame(-3.0, $this->stock($p));

        // Reactivar: se conserva el saldo y el historial.
        DB::table('productos')->where('id', $p)->update(['controla_stock' => true]);
        $this->assertSame(-3.0, $this->stock($p));
        $this->assertCount(1, $this->movimientos($p));
    }

    /** 12b. La reversión usa el historial, no el controla_stock actual. */
    public function test_reversion_no_depende_del_control_actual(): void
    {
        $admin = $this->usuario('ADMIN');
        $p = $this->producto(true, 100);
        $ventaId = $this->venta();
        $cuentaId = $this->cuenta($ventaId);

        $this->actingAs($admin);
        $this->enviar($ventaId, $cuentaId, [$p => 4]);
        $this->assertSame(-4.0, $this->stock($p));

        // Se desactiva el control DESPUÉS de la venta.
        DB::table('productos')->where('id', $p)->update(['controla_stock' => false]);

        // Cancelar igualmente repone: existe el movimiento VENTA original.
        $respuesta = $this->cancelar($ventaId, $admin);
        $this->assertSame(200, $respuesta->getStatusCode());

        $this->assertSame(0.0, $this->stock($p));
        $this->assertCount(1, $this->movimientos($p, 'CANCELACION'));
    }

    /** 13. Rollback: si la operación de inventario falla, no queda nada. */
    public function test_rollback_si_falla_operacion_de_inventario(): void
    {
        $p = $this->producto(true, 100);
        $ventaId = $this->venta();
        $detalleId = DB::table('venta_detalles')->insertGetId([
            'venta_id' => $ventaId,
            'producto_id' => $p,
            'cantidad' => 2,
            'precio_unitario' => 100,
            'subtotal' => 200,
            'comanda_id' => null,
            'cuenta_id' => null,
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $servicio = app(\App\Services\InventarioService::class);
        $linea = ['venta_detalle_id' => $detalleId, 'producto_id' => $p, 'cantidad' => 2];

        try {
            DB::transaction(function () use ($servicio, $linea) {
                $servicio->registrarVenta([$linea], null);
                // Falla posterior simulada: debe revertir el movimiento y el stock.
                throw new \RuntimeException('Fallo simulado tras el inventario.');
            });
        } catch (\RuntimeException $e) {
            // Esperado.
        }

        $this->assertSame(0, DB::table('movimientos_inventario')->count());
        $this->assertSame(0, DB::table('inventario_producto')->count());
    }

    /** 14. Varios productos controlados se procesan de forma determinista. */
    public function test_procesamiento_determinista_de_varios_productos(): void
    {
        $admin = $this->usuario('ADMIN');
        // Se crean en orden arbitrario y se envían en orden inverso.
        $pA = $this->producto(true, 100);
        $pB = $this->producto(true, 100);
        $ventaId = $this->venta();
        $cuentaId = $this->cuenta($ventaId);

        $this->actingAs($admin);
        $this->enviar($ventaId, $cuentaId, [$pB => 2, $pA => 3]);

        $this->assertSame(-3.0, $this->stock($pA));
        $this->assertSame(-2.0, $this->stock($pB));
        $this->assertCount(1, $this->movimientos($pA, 'VENTA'));
        $this->assertCount(1, $this->movimientos($pB, 'VENTA'));
    }

    /** 15. Las autorizaciones existentes no cambian con el inventario. */
    public function test_autorizaciones_existentes_no_cambian(): void
    {
        $cajero = $this->usuario('CAJERO');
        $mesero = $this->usuario('MESERO');
        $admin = $this->usuario('ADMIN');
        $gerente = $this->usuario('GERENTE');

        $p = $this->producto(true, 100);
        $ventaId = $this->venta();
        $cuentaId = $this->cuenta($ventaId);

        // El POS (enviar pedido) sigue permitido para CAJERO y repone inventario.
        $this->actingAs($cajero);
        $this->enviar($ventaId, $cuentaId, [$p => 2]);
        $this->assertSame(-2.0, $this->stock($p));

        $detalleId = $this->detalleId($ventaId, $p);

        // CAJERO y MESERO no pueden anular: 403 y sin movimiento de reposición.
        $this->anular($detalleId, 1, $cajero)->assertStatus(403);
        $this->anular($detalleId, 1, $mesero)->assertStatus(403);
        $this->assertSame(0, $this->movimientos($p, 'ANULACION')->count());

        // ADMIN y GERENTE sí pueden anular.
        $this->anular($detalleId, 1, $admin)->assertStatus(200);
        $this->anular($detalleId, 1, $gerente)->assertStatus(200);
        $this->assertSame(2, $this->movimientos($p, 'ANULACION')->count());
        $this->assertSame(0.0, $this->stock($p));
    }
}
