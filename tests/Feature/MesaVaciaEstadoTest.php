<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/*
 * Pruebas de MESAS VACÍAS y ESTADOS DE TRASLADO (auditoría de cuentas).
 *
 * Reglas verificadas:
 * - Abrir el POS de una mesa LIBRE NO la marca OCUPADA: la venta vacía puede
 *   existir, pero la mesa permanece LIBRE.
 * - Recién al agregar el primer producto/bebida real (POST /pos/api con
 *   accion=enviar_cocina) la mesa pasa a OCUPADA.
 * - Liberar una apertura vacía (accion=liberar_mesa_vacia) solo funciona si
 *   la venta no tiene consumos ni asignaciones pendientes; no borra historial.
 * - Al trasladar, los estados siguen el consumo real: el destino solo queda
 *   OCUPADA si trae consumos; el origen solo queda LIBRE si no le quedan
 *   consumos ni otras ventas activas.
 *
 * Corren en SQLite :memory: (phpunit.xml). Nunca tocan "sistemadecaja".
 */
class MesaVaciaEstadoTest extends TestCase
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
            'comandas',
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

    private function mesa(string $numero = '1', string $estado = 'LIBRE'): int
    {
        return DB::table('mesas')->insertGetId([
            'numero' => $numero,
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

    private function estadoMesa(int $mesaId): string
    {
        return (string) DB::table('mesas')->where('id', $mesaId)->value('estado');
    }

    /*
     * =========================================================
     * TAREA 2: MESAS VACÍAS
     * =========================================================
     */

    public function test_abrir_pos_de_mesa_libre_no_la_ocupa(): void
    {
        $admin = $this->usuario('ADMIN');
        $mesaId = $this->mesa('1', 'LIBRE');

        $this->actingAs($admin)->get('/pos/' . $mesaId)->assertStatus(200);

        // Se creó la venta vacía y su cuenta, pero la mesa sigue LIBRE.
        $this->assertSame(1, DB::table('ventas')->where('mesa_id', $mesaId)->count());
        $this->assertSame('LIBRE', $this->estadoMesa($mesaId));
        $this->assertSame(0, DB::table('venta_detalles')->count());
    }

    public function test_primer_producto_real_ocupa_la_mesa(): void
    {
        $admin = $this->usuario('ADMIN');
        $mesaId = $this->mesa('1', 'LIBRE');
        $ventaId = $this->venta($mesaId);
        $cuentaId = $this->cuenta($ventaId, 'Cuenta Principal');
        $productoId = $this->producto('Jugo natural', 25, false);

        $this->actingAs($admin)->postJson('/pos/api', [
            'accion' => 'enviar_cocina',
            'venta_id' => $ventaId,
            'cuenta_id' => $cuentaId,
            'carrito' => json_encode([['id' => $productoId, 'cantidad' => 1]]),
        ])->assertStatus(200)->assertJson(['exito' => true]);

        // Ahora sí: hay consumo real y la mesa queda OCUPADA.
        $this->assertSame('OCUPADA', $this->estadoMesa($mesaId));
        $this->assertSame(1, DB::table('venta_detalles')->count());
        $this->assertSame(25.0, (float) DB::table('cuentas')->where('id', $cuentaId)->value('total'));
    }

    public function test_liberar_mesa_vacia_la_deja_libre_sin_borrar_historial(): void
    {
        $admin = $this->usuario('ADMIN');
        $mesaId = $this->mesa('1', 'OCUPADA');
        $ventaId = $this->venta($mesaId);
        $this->cuenta($ventaId, 'Cuenta Principal');

        $this->actingAs($admin)->postJson('/pos/api', [
            'accion' => 'liberar_mesa_vacia',
            'venta_id' => $ventaId,
        ])->assertStatus(200)->assertJson(['exito' => true]);

        $this->assertSame('LIBRE', $this->estadoMesa($mesaId));
        // La venta no se elimina: se conserva como histórico (PAGADA).
        $this->assertTrue(DB::table('ventas')->where('id', $ventaId)->exists());
        $this->assertSame('PAGADA', (string) DB::table('ventas')->where('id', $ventaId)->value('estado'));
    }

    public function test_liberar_mesa_rechaza_si_hay_productos(): void
    {
        $admin = $this->usuario('ADMIN');
        $mesaId = $this->mesa('1', 'OCUPADA');
        $ventaId = $this->venta($mesaId);
        $productoId = $this->producto('Pizza', 20, true);
        DB::table('venta_detalles')->insert([
            'venta_id' => $ventaId,
            'producto_id' => $productoId,
            'cantidad' => 1,
            'precio_unitario' => 20,
            'subtotal' => 20,
            'comanda_id' => null,
            'cuenta_id' => null,
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)->postJson('/pos/api', [
            'accion' => 'liberar_mesa_vacia',
            'venta_id' => $ventaId,
        ])->assertStatus(500)->assertJson(['exito' => false]);

        $this->assertSame('OCUPADA', $this->estadoMesa($mesaId));
        $this->assertSame(1, DB::table('venta_detalles')->count());
    }

    public function test_liberar_mesa_rechaza_si_hay_asignaciones_pendientes(): void
    {
        $admin = $this->usuario('ADMIN');
        $mesaId = $this->mesa('1', 'OCUPADA');
        $ventaId = $this->venta($mesaId);
        $cuentaId = $this->cuenta($ventaId, 'Cuenta Principal');
        $productoId = $this->producto('Pizza', 20, true);

        // Línea ANULADA (no cuenta como producto activo) pero con asignación
        // pendiente en cuenta_detalles: no debe permitirse liberar.
        $detalleId = DB::table('venta_detalles')->insertGetId([
            'venta_id' => $ventaId,
            'producto_id' => $productoId,
            'cantidad' => 1,
            'precio_unitario' => 20,
            'subtotal' => 20,
            'comanda_id' => null,
            'cuenta_id' => null,
            'estado' => 'ANULADO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('cuenta_detalles')->insert([
            'cuenta_id' => $cuentaId,
            'venta_id' => $ventaId,
            'venta_detalle_id' => $detalleId,
            'cantidad' => 1,
            'subtotal' => 20,
            'eliminado' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)->postJson('/pos/api', [
            'accion' => 'liberar_mesa_vacia',
            'venta_id' => $ventaId,
        ])->assertStatus(500)->assertJson(['exito' => false]);

        $this->assertSame('OCUPADA', $this->estadoMesa($mesaId));
    }

    /*
     * =========================================================
     * TAREA 3: ESTADOS DE TRASLADO SEGÚN EL CONSUMO REAL
     * =========================================================
     */

    public function test_traslado_de_venta_sin_consumos_no_ocupa_el_destino(): void
    {
        $admin = $this->usuario('ADMIN');
        $origen = $this->mesa('1', 'LIBRE');
        $destino = $this->mesa('2', 'LIBRE');
        $ventaId = $this->venta($origen);
        $cuentaId = $this->cuenta($ventaId, 'Cuenta Principal');

        $this->actingAs($admin)->postJson('/pos/cuentas/' . $cuentaId . '/trasladar', [
            'mesa_destino_id' => $destino,
        ])->assertStatus(200)->assertJson(['exito' => true]);

        // La venta se movió, pero sin consumos ambas mesas quedan LIBRES.
        $this->assertSame($destino, (int) DB::table('ventas')->where('id', $ventaId)->value('mesa_id'));
        $this->assertSame('LIBRE', $this->estadoMesa($destino));
        $this->assertSame('LIBRE', $this->estadoMesa($origen));
    }

    public function test_traslado_con_consumos_ocupa_el_destino_y_libera_el_origen(): void
    {
        $admin = $this->usuario('ADMIN');
        $origen = $this->mesa('1', 'OCUPADA');
        $destino = $this->mesa('2', 'LIBRE');
        $ventaId = $this->venta($origen);
        $cuentaId = $this->cuenta($ventaId, 'Cuenta Principal');
        $productoId = $this->producto('Pizza', 20, true);
        DB::table('venta_detalles')->insert([
            'venta_id' => $ventaId,
            'producto_id' => $productoId,
            'cantidad' => 1,
            'precio_unitario' => 20,
            'subtotal' => 20,
            'comanda_id' => null,
            'cuenta_id' => null,
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)->postJson('/pos/cuentas/' . $cuentaId . '/trasladar', [
            'mesa_destino_id' => $destino,
        ])->assertStatus(200)->assertJson(['exito' => true]);

        $this->assertSame('OCUPADA', $this->estadoMesa($destino));
        $this->assertSame('LIBRE', $this->estadoMesa($origen));
        // No se duplican consumos.
        $this->assertSame(1, DB::table('venta_detalles')->count());
    }

    public function test_origen_con_otra_venta_activa_permanece_ocupado(): void
    {
        $admin = $this->usuario('ADMIN');
        $origen = $this->mesa('1', 'OCUPADA');
        $destino = $this->mesa('2', 'LIBRE');

        $ventaMover = $this->venta($origen);
        $cuentaMover = $this->cuenta($ventaMover, 'Cuenta Principal');

        // Otra venta activa en el MISMO origen (no debería quedar LIBRE).
        $otraVenta = $this->venta($origen);
        $this->cuenta($otraVenta, 'Otra Cuenta');

        $this->actingAs($admin)->postJson('/pos/cuentas/' . $cuentaMover . '/trasladar', [
            'mesa_destino_id' => $destino,
        ])->assertStatus(200)->assertJson(['exito' => true]);

        $this->assertSame('OCUPADA', $this->estadoMesa($origen));
    }
}
