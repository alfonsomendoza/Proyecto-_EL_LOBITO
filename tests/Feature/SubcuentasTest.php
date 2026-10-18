<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/*
 * Pruebas aisladas de las SUBCUENTAS (Fase 2) y del POS por cuenta.
 *
 * Cubren:
 * - Crear subcuentas vacías con los cuatro roles permitidos.
 * - Rechazo de roles no autorizados y de nombres duplicados.
 * - POS acotado con ?cuenta= (productos y total por cuenta, sin duplicar).
 * - Los pedidos nuevos se guardan en la subcuenta seleccionada.
 * - La división oculta (CANCELADA) la cuenta original que queda vacía.
 * - La anulación acotada a una cuenta solo afecta a esa cuenta.
 *
 * Corren en SQLite :memory: (phpunit.xml). El esquema se construye a mano
 * porque los migrations reales usan SQL de MySQL. Nunca tocan "sistemadecaja".
 */
class SubcuentasTest extends TestCase
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
            'movimientos_inventario',
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

    private function cuenta(int $ventaId, string $nombre, string $estado = 'ABIERTA'): int
    {
        return DB::table('cuentas')->insertGetId([
            'venta_id' => $ventaId,
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
    }

    public function test_entorno_aislado_no_usa_mysql(): void
    {
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertStringNotContainsString('sistemadecaja', (string) config('database.connections.sqlite.database'));
    }

    /* =========================================================
     * FASE 2: CREAR SUBCUENTA VACÍA
     * ========================================================= */

    public function test_los_cuatro_roles_pueden_crear_subcuenta_vacia(): void
    {
        foreach (['ADMIN', 'GERENTE', 'CAJERO', 'MESERO'] as $rol) {
            $this->crearEsquema();

            $usuario = $this->usuario($rol);
            $mesaId = $this->mesa(1);
            $ventaId = $this->venta($mesaId);
            $this->cuenta($ventaId, 'Cuenta Principal');

            $respuesta = $this->actingAs($usuario)
                ->postJson('/mesas/' . $mesaId . '/cuentas', ['nombre' => 'Ana']);

            $respuesta->assertStatus(200)->assertJson(['exito' => true]);

            $nueva = DB::table('cuentas')->where('venta_id', $ventaId)->where('nombre', 'Ana')->first();
            $this->assertNotNull($nueva, 'El rol ' . $rol . ' debería poder crear la subcuenta.');
            $this->assertSame('ABIERTA', $nueva->estado);
            $this->assertSame(0.0, (float) $nueva->total);
            // Nunca se crea una segunda venta.
            $this->assertSame(1, DB::table('ventas')->count());
        }
    }

    public function test_rol_no_autorizado_no_puede_crear_subcuenta(): void
    {
        $supervisor = $this->usuario('SUPERVISOR');
        $mesaId = $this->mesa(1);
        $ventaId = $this->venta($mesaId);
        $this->cuenta($ventaId, 'Cuenta Principal');

        $this->actingAs($supervisor)
            ->postJson('/mesas/' . $mesaId . '/cuentas', ['nombre' => 'Ana'])
            ->assertStatus(403);

        $this->assertSame(1, DB::table('cuentas')->count());
    }

    public function test_nombre_duplicado_se_rechaza_sin_distinguir_mayusculas(): void
    {
        $admin = $this->usuario('ADMIN');
        $mesaId = $this->mesa(1);
        $ventaId = $this->venta($mesaId);
        $this->cuenta($ventaId, 'Ana');

        $this->actingAs($admin)
            ->postJson('/mesas/' . $mesaId . '/cuentas', ['nombre' => 'ana'])
            ->assertStatus(422)
            ->assertJson(['exito' => false]);

        $this->assertSame(1, DB::table('cuentas')->count());
    }

    public function test_nombre_de_cuenta_cancelada_se_puede_reutilizar(): void
    {
        $admin = $this->usuario('ADMIN');
        $mesaId = $this->mesa(1);
        $ventaId = $this->venta($mesaId);
        $this->cuenta($ventaId, 'Ana', 'CANCELADA');

        $this->actingAs($admin)
            ->postJson('/mesas/' . $mesaId . '/cuentas', ['nombre' => 'Ana'])
            ->assertStatus(200)
            ->assertJson(['exito' => true]);

        $this->assertSame(2, DB::table('cuentas')->whereRaw('LOWER(nombre) = ?', ['ana'])->count());
    }

    public function test_no_crea_subcuenta_si_la_mesa_no_tiene_venta_activa(): void
    {
        $admin = $this->usuario('ADMIN');
        $mesaId = $this->mesa(1);

        $this->actingAs($admin)
            ->postJson('/mesas/' . $mesaId . '/cuentas', ['nombre' => 'Ana'])
            ->assertStatus(422)
            ->assertJson(['exito' => false]);

        $this->assertSame(0, DB::table('cuentas')->count());
        $this->assertSame(0, DB::table('ventas')->count());
    }

    /* =========================================================
     * POS POR CUENTA (?cuenta=)
     * ========================================================= */

    public function test_pos_sin_cuenta_muestra_el_total_general(): void
    {
        $admin = $this->usuario('ADMIN');
        $mesaId = $this->mesa(1);
        $ventaId = $this->venta($mesaId);
        $p = $this->producto('Gaseosa', 30);
        $d1 = $this->detalle($ventaId, $p, 2, 30);
        $principal = $this->cuenta($ventaId, 'Cuenta Principal');
        $ana = $this->cuenta($ventaId, 'Ana');
        $this->asignar($principal, $ventaId, $d1, 1, 30);
        $this->asignar($ana, $ventaId, $d1, 1, 30);

        $respuesta = $this->actingAs($admin)->get('/pos/' . $mesaId);
        $respuesta->assertStatus(200);

        $historial = $respuesta->viewData('historial');
        $this->assertNull($respuesta->viewData('cuentaActual'));
        // La vista general ve la línea completa (2 unidades = 60).
        $this->assertSame(1, $historial->count());
        $this->assertSame(2, (int) $historial->first()->cantidad);
        $this->assertSame(60.0, (float) $historial->first()->subtotal);
    }

    public function test_pos_con_cuenta_muestra_solo_los_productos_y_total_de_esa_cuenta(): void
    {
        $admin = $this->usuario('ADMIN');
        $mesaId = $this->mesa(1);
        $ventaId = $this->venta($mesaId);
        $p = $this->producto('Gaseosa', 30);
        $d1 = $this->detalle($ventaId, $p, 2, 30);
        $principal = $this->cuenta($ventaId, 'Cuenta Principal');
        $ana = $this->cuenta($ventaId, 'Ana');
        $this->asignar($principal, $ventaId, $d1, 1, 30);
        $this->asignar($ana, $ventaId, $d1, 1, 30);

        $respuesta = $this->actingAs($admin)->get('/pos/' . $mesaId . '?cuenta=' . $ana);
        $respuesta->assertStatus(200);

        $this->assertNotNull($respuesta->viewData('cuentaActual'));
        $this->assertSame($ana, (int) $respuesta->viewData('cuentaActual')->id);
        $this->assertSame(30.0, (float) $respuesta->viewData('totalCuenta'));

        // Solo la unidad asignada a Ana, NO la línea completa (2 unidades).
        $historial = $respuesta->viewData('historial');
        $this->assertSame(1, $historial->count());
        $this->assertSame(1, (int) $historial->first()->cantidad);
        $this->assertSame(30.0, (float) $historial->first()->subtotal);
    }

    public function test_pos_rechaza_cuenta_inexistente_o_de_otra_venta(): void
    {
        $admin = $this->usuario('ADMIN');
        $mesaId = $this->mesa(1);
        $ventaId = $this->venta($mesaId);
        $p = $this->producto('Gaseosa', 30);
        $this->detalle($ventaId, $p, 1, 30);
        $this->cuenta($ventaId, 'Cuenta Principal');

        // Otra venta/mesa con su propia cuenta.
        $otraMesa = $this->mesa(2);
        $otraVenta = $this->venta($otraMesa);
        $ajena = $this->cuenta($otraVenta, 'Ajena');

        // Cuenta de otra venta: se rechaza el acceso a esa cuenta concreta.
        $this->actingAs($admin)
            ->get('/pos/' . $mesaId . '?cuenta=' . $ajena)
            ->assertStatus(404);

        // Cuenta inexistente: también se rechaza.
        $this->actingAs($admin)
            ->get('/pos/' . $mesaId . '?cuenta=999999')
            ->assertStatus(404);

        // La vista general (sin ?cuenta=) sigue funcionando.
        $this->actingAs($admin)
            ->get('/pos/' . $mesaId)
            ->assertStatus(200);
    }

    /* =========================================================
     * PEDIDO NUEVO EN LA SUBCUENTA SELECCIONADA
     * ========================================================= */

    public function test_nuevo_pedido_se_guarda_en_la_subcuenta_seleccionada(): void
    {
        $admin = $this->usuario('ADMIN');
        $mesaId = $this->mesa(1);
        $ventaId = $this->venta($mesaId);
        $p = $this->producto('Gaseosa', 30, false);
        $principal = $this->cuenta($ventaId, 'Cuenta Principal');
        $ana = $this->cuenta($ventaId, 'Ana');

        $this->actingAs($admin)->postJson('/pos/api', [
            'accion' => 'enviar_cocina',
            'venta_id' => $ventaId,
            'cuenta_id' => $ana,
            'carrito' => json_encode([['id' => $p, 'cantidad' => 2]]),
        ])->assertStatus(200)->assertJson(['exito' => true]);

        // El detalle quedó asignado SOLO a Ana.
        $detalleId = DB::table('venta_detalles')->where('venta_id', $ventaId)->value('id');
        $this->assertSame($ana, (int) DB::table('cuenta_detalles')->where('venta_detalle_id', $detalleId)->value('cuenta_id'));
        $this->assertSame(60.0, (float) DB::table('cuentas')->where('id', $ana)->value('total'));
        $this->assertSame(0.0, (float) DB::table('cuentas')->where('id', $principal)->value('total'));
    }

    public function test_nuevo_pedido_rechaza_una_cuenta_que_no_esta_abierta(): void
    {
        $admin = $this->usuario('ADMIN');
        $mesaId = $this->mesa(1);
        $ventaId = $this->venta($mesaId);
        $p = $this->producto('Gaseosa', 30, false);
        $this->cuenta($ventaId, 'Cuenta Principal');
        $pagada = $this->cuenta($ventaId, 'Pagada', 'PAGADA');

        $this->actingAs($admin)->postJson('/pos/api', [
            'accion' => 'enviar_cocina',
            'venta_id' => $ventaId,
            'cuenta_id' => $pagada,
            'carrito' => json_encode([['id' => $p, 'cantidad' => 1]]),
        ])->assertStatus(422)->assertJson(['exito' => false]);

        $this->assertSame(0, DB::table('venta_detalles')->count());
    }

    /* =========================================================
     * DIVISIÓN: LA CUENTA ORIGINAL VACÍA SE OCULTA
     * ========================================================= */

    public function test_division_oculta_cuenta_original_que_queda_vacia(): void
    {
        $admin = $this->usuario('ADMIN');
        $mesaId = $this->mesa(1);
        $ventaId = $this->venta($mesaId);
        $p = $this->producto('Gaseosa', 30);
        $d1 = $this->detalle($ventaId, $p, 2, 30);
        $principal = $this->cuenta($ventaId, 'Cuenta Principal');
        $ana = $this->cuenta($ventaId, 'Ana');
        $this->asignar($principal, $ventaId, $d1, 2, 60);

        // Se mueven TODAS las unidades a Ana: la principal queda vacía.
        $this->actingAs($admin)->postJson('/pos/' . $mesaId . '/cuentas/dividir', [
            'asignaciones' => [
                ['cuenta_id' => $ana, 'venta_detalle_id' => $d1, 'cantidad' => 2],
            ],
        ])->assertStatus(200)->assertJson(['exito' => true]);

        // La cuenta original no se borra: se oculta (CANCELADA) y en cero.
        $original = DB::table('cuentas')->where('id', $principal)->first();
        $this->assertNotNull($original);
        $this->assertSame('CANCELADA', $original->estado);
        $this->assertSame(0.0, (float) $original->total);

        // El consumo sigue intacto (historial) y ahora pertenece a Ana.
        $this->assertSame(1, DB::table('venta_detalles')->where('id', $d1)->count());
        $this->assertSame(60.0, (float) DB::table('cuentas')->where('id', $ana)->value('total'));

        // El panel ya no la muestra.
        $panel = $this->actingAs($admin)->get('/mesas/' . $mesaId . '/cuentas');
        $ids = $panel->viewData('cuentas')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertNotContains($principal, $ids);
        $this->assertContains($ana, $ids);
    }

    public function test_division_no_cancela_una_cuenta_nueva_vacia(): void
    {
        $admin = $this->usuario('ADMIN');
        $mesaId = $this->mesa(1);
        $ventaId = $this->venta($mesaId);
        $p = $this->producto('Gaseosa', 30);
        $d1 = $this->detalle($ventaId, $p, 2, 30);
        $principal = $this->cuenta($ventaId, 'Cuenta Principal');
        $this->asignar($principal, $ventaId, $d1, 2, 60);

        // Se crea "Ana" pero no se le asigna nada: la principal conserva todo.
        $this->actingAs($admin)->postJson('/pos/' . $mesaId . '/cuentas/dividir', [
            'cuentas_nuevas' => ['Ana'],
            'asignaciones' => [
                ['cuenta_id' => $principal, 'venta_detalle_id' => $d1, 'cantidad' => 2],
            ],
        ])->assertStatus(200)->assertJson(['exito' => true]);

        $this->assertSame('ABIERTA', DB::table('cuentas')->where('id', $principal)->value('estado'));
        $nueva = DB::table('cuentas')->where('venta_id', $ventaId)->where('nombre', 'Ana')->first();
        $this->assertNotNull($nueva);
        $this->assertSame('ABIERTA', $nueva->estado);
    }

    /* =========================================================
     * ANULACIÓN ACOTADA A UNA CUENTA
     * ========================================================= */

    public function test_anular_acotado_a_una_cuenta_solo_afecta_esa_cuenta(): void
    {
        $admin = $this->usuario('ADMIN');
        $mesaId = $this->mesa(1);
        $ventaId = $this->venta($mesaId);
        $p = $this->producto('Gaseosa', 30);
        $d1 = $this->detalle($ventaId, $p, 4, 30);
        $principal = $this->cuenta($ventaId, 'Cuenta Principal');
        $ana = $this->cuenta($ventaId, 'Ana');
        $this->asignar($principal, $ventaId, $d1, 2, 60);
        $this->asignar($ana, $ventaId, $d1, 2, 60);
        DB::table('cuentas')->where('id', $principal)->update(['total' => 60]);
        DB::table('cuentas')->where('id', $ana)->update(['total' => 60]);
        DB::table('ventas')->where('id', $ventaId)->update(['monto' => 120]);

        $this->actingAs($admin)->postJson('/pos/detalle/' . $d1 . '/anular', [
            'cantidad' => 1,
            'motivo' => 'Producto en mal estado',
            'cuenta_id' => $ana,
        ])->assertStatus(200)->assertJson(['exito' => true]);

        // Ana baja a 1 unidad (30); la principal NO se toca (2 unidades, 60).
        $this->assertSame(1, (int) DB::table('cuenta_detalles')->where('cuenta_id', $ana)->where('venta_detalle_id', $d1)->value('cantidad'));
        $this->assertSame(30.0, (float) DB::table('cuentas')->where('id', $ana)->value('total'));
        $this->assertSame(2, (int) DB::table('cuenta_detalles')->where('cuenta_id', $principal)->where('venta_detalle_id', $d1)->value('cantidad'));
        $this->assertSame(60.0, (float) DB::table('cuentas')->where('id', $principal)->value('total'));

        // La línea de venta baja de 4 a 3 (90).
        $this->assertSame(3, (int) DB::table('venta_detalles')->where('id', $d1)->value('cantidad'));
        $this->assertSame(90.0, (float) DB::table('ventas')->where('id', $ventaId)->value('monto'));
    }

    public function test_anular_acotado_a_una_cuenta_pagada_se_rechaza(): void
    {
        $admin = $this->usuario('ADMIN');
        $mesaId = $this->mesa(1);
        $ventaId = $this->venta($mesaId);
        $p = $this->producto('Gaseosa', 30);
        $d1 = $this->detalle($ventaId, $p, 2, 30);
        $pagada = $this->cuenta($ventaId, 'Pagada', 'PAGADA');
        $this->asignar($pagada, $ventaId, $d1, 2, 60);

        $this->actingAs($admin)->postJson('/pos/detalle/' . $d1 . '/anular', [
            'cantidad' => 1,
            'motivo' => 'Intento indebido',
            'cuenta_id' => $pagada,
        ])->assertStatus(422)->assertJson(['exito' => false]);

        $this->assertSame(2, (int) DB::table('venta_detalles')->where('id', $d1)->value('cantidad'));
        $this->assertSame(2, (int) DB::table('cuenta_detalles')->where('cuenta_id', $pagada)->value('cantidad'));
    }
}
