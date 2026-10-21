<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Concerns\CreaEsquemaTrasladosPagos;
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
    use CreaEsquemaTrasladosPagos;

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
            'facturas',
            'anulaciones',
            'cuenta_detalles',
            'cuentas',
            'venta_detalles',
            'comandas',
            'ventas',
            'productos',
            'categorias',
            'mesas',
            'clientes',
            'usuarios',
            'roles',
        ] as $tabla) {
            Schema::dropIfExists($tabla);
        }

        Schema::create('roles', function (Blueprint $t) {
            $t->increments('id');
            $t->string('nombre', 30);
        });

        // Espejo de la tabla real "clientes" (sin created_at/updated_at:
        // usa la columna "creado_en"). Necesaria para validar el cliente.
        Schema::create('clientes', function (Blueprint $t) {
            $t->increments('id');
            $t->string('nombre', 150);
            $t->string('telefono', 20)->nullable();
            $t->string('identificacion', 50)->nullable();
            $t->text('direccion')->nullable();
            $t->boolean('activo')->nullable()->default(true);
            $t->timestamp('creado_en')->nullable();
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
            // Columna real: desactivar una categoría no borra sus productos.
            $t->boolean('activa')->default(true);
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

        // Espejo de la tabla real "facturas": sirve como historial de pago de
        // una venta. Su existencia impide reabrir/recuperar una venta como si
        // estuviera vacía.
        Schema::create('facturas', function (Blueprint $t) {
            $t->increments('id');
            $t->string('numero_factura', 20)->nullable();
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

    private function cliente(string $nombre): int
    {
        return DB::table('clientes')->insertGetId([
            'nombre' => $nombre,
            'activo' => true,
            'creado_en' => now(),
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

    private function factura(int $ventaId, float $total = 0): int
    {
        return DB::table('facturas')->insertGetId([
            'numero_factura' => 'F-' . uniqid(),
            'venta_id' => $ventaId,
            'cuenta_id' => null,
            'mesa' => null,
            'detalle_consumo' => null,
            'total' => $total,
            'fecha_emision' => now(),
            'usuario_id' => null,
            'estado' => 'EMITIDA',
        ]);
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

    public function test_liberar_mesa_inexistente_responde_404(): void
    {
        $admin = $this->usuario('ADMIN');

        $this->actingAs($admin)->postJson('/pos/api', [
            'accion' => 'liberar_mesa_vacia',
            'venta_id' => 99999,
        ])->assertStatus(404)->assertJson(['exito' => false]);
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
        ])->assertStatus(409)->assertJson(['exito' => false]);

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
        ])->assertStatus(409)->assertJson(['exito' => false]);

        $this->assertSame('OCUPADA', $this->estadoMesa($mesaId));
    }

    public function test_liberar_mesa_rechaza_si_hay_saldo_en_cuenta_abierta(): void
    {
        $admin = $this->usuario('ADMIN');
        $mesaId = $this->mesa('1', 'OCUPADA');
        $ventaId = $this->venta($mesaId);
        $cuentaId = $this->cuenta($ventaId, 'Cuenta Principal');
        DB::table('cuentas')->where('id', $cuentaId)->update(['total' => 80]);

        // El panel NO debe ofrecer liberar una mesa con saldo pendiente.
        $resp = $this->actingAs($admin)->get('/mesas/' . $mesaId . '/cuentas');
        $resp->assertStatus(200);
        $this->assertFalse((bool) $resp->viewData('mesaVacia'));

        // Y el backend revalida: liberar falla y no cambia la mesa.
        $this->actingAs($admin)->postJson('/pos/api', [
            'accion' => 'liberar_mesa_vacia',
            'venta_id' => $ventaId,
        ])->assertStatus(409)->assertJson(['exito' => false]);

        $this->assertSame('OCUPADA', $this->estadoMesa($mesaId));
        $this->assertSame('ABIERTA', (string) DB::table('cuentas')->where('id', $cuentaId)->value('estado'));
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

    /*
     * =========================================================
     * TAREA 4: APERTURA DE MESAS DESDE EL MONITOR (abrir_mesa)
     * =========================================================
     */

    public function test_abrir_mesa_desde_monitor_no_ocupa_la_mesa_sin_productos(): void
    {
        $admin = $this->usuario('ADMIN');
        $mesaId = $this->mesa('1', 'LIBRE');

        $this->actingAs($admin)->postJson('/mesas/api', [
            'accion' => 'abrir_mesa',
            'mesa_id' => $mesaId,
        ])->assertStatus(200)->assertJson(['exito' => true]);

        // Se crea una única venta vacía con su cuenta principal, pero la mesa
        // permanece LIBRE hasta que se registre el primer producto real.
        $this->assertSame('LIBRE', $this->estadoMesa($mesaId));
        $this->assertSame(1, DB::table('ventas')->where('mesa_id', $mesaId)->count());
        $this->assertSame(1, DB::table('cuentas')->count());
        $this->assertSame(0, DB::table('venta_detalles')->count());
        $this->assertSame(0, DB::table('cuenta_detalles')->count());
    }

    public function test_abrir_mesa_desde_monitor_y_primer_producto_la_ocupa(): void
    {
        $admin = $this->usuario('ADMIN');
        $mesaId = $this->mesa('1', 'LIBRE');
        $productoId = $this->producto('Jugo natural', 25, false);

        $this->actingAs($admin)->postJson('/mesas/api', [
            'accion' => 'abrir_mesa',
            'mesa_id' => $mesaId,
        ])->assertStatus(200)->assertJson(['exito' => true]);

        $this->assertSame('LIBRE', $this->estadoMesa($mesaId));

        $ventaId = (int) DB::table('ventas')->where('mesa_id', $mesaId)->value('id');
        $cuentaId = (int) DB::table('cuentas')->where('venta_id', $ventaId)->value('id');

        $this->actingAs($admin)->postJson('/pos/api', [
            'accion' => 'enviar_cocina',
            'venta_id' => $ventaId,
            'cuenta_id' => $cuentaId,
            'carrito' => json_encode([['id' => $productoId, 'cantidad' => 1]]),
        ])->assertStatus(200)->assertJson(['exito' => true]);

        // Recién con el primer producto real la mesa queda OCUPADA.
        $this->assertSame('OCUPADA', $this->estadoMesa($mesaId));
        $this->assertSame(1, DB::table('venta_detalles')->count());
    }

    public function test_reabrir_mesa_con_venta_activa_no_duplica_venta_ni_cuenta(): void
    {
        $admin = $this->usuario('ADMIN');
        $mesaId = $this->mesa('1', 'LIBRE');
        $ventaId = $this->venta($mesaId);
        $this->cuenta($ventaId, 'Cuenta Principal');

        $this->actingAs($admin)->postJson('/mesas/api', [
            'accion' => 'abrir_mesa',
            'mesa_id' => $mesaId,
        ])->assertStatus(200)->assertJson(['exito' => true, 'venta_id' => $ventaId]);

        // No se crea una segunda venta ni una segunda cuenta principal.
        $this->assertSame(1, DB::table('ventas')->where('mesa_id', $mesaId)->count());
        $this->assertSame(1, DB::table('cuentas')->where('venta_id', $ventaId)->count());
        $this->assertSame('LIBRE', $this->estadoMesa($mesaId));
    }

    public function test_reabrir_mesa_no_borra_el_cliente_existente(): void
    {
        $admin = $this->usuario('ADMIN');
        $mesaId = $this->mesa('1', 'LIBRE');
        $ventaId = $this->venta($mesaId);
        DB::table('ventas')->where('id', $ventaId)->update(['cliente_id' => 7]);
        $this->cuenta($ventaId, 'Cuenta Principal');

        // Sin enviar cliente_id no se debe perder el cliente previo.
        $this->actingAs($admin)->postJson('/mesas/api', [
            'accion' => 'abrir_mesa',
            'mesa_id' => $mesaId,
        ])->assertStatus(200)->assertJson(['exito' => true]);

        $this->assertSame(7, (int) DB::table('ventas')->where('id', $ventaId)->value('cliente_id'));
    }

    public function test_abrir_mesa_inexistente_responde_404(): void
    {
        $admin = $this->usuario('ADMIN');

        $this->actingAs($admin)->postJson('/mesas/api', [
            'accion' => 'abrir_mesa',
            'mesa_id' => 99999,
        ])->assertStatus(404)->assertJson(['exito' => false]);

        // No se creó ninguna venta ni cuenta.
        $this->assertSame(0, DB::table('ventas')->count());
        $this->assertSame(0, DB::table('cuentas')->count());
    }

    public function test_abrir_mesa_sin_mesa_id_responde_422(): void
    {
        $admin = $this->usuario('ADMIN');

        $this->actingAs($admin)->postJson('/mesas/api', [
            'accion' => 'abrir_mesa',
        ])->assertStatus(422)->assertJson(['exito' => false]);

        $this->assertSame(0, DB::table('ventas')->count());
    }

    public function test_abrir_mesa_con_cliente_inexistente_responde_422(): void
    {
        $admin = $this->usuario('ADMIN');
        $mesaId = $this->mesa('1', 'LIBRE');

        $this->actingAs($admin)->postJson('/mesas/api', [
            'accion' => 'abrir_mesa',
            'mesa_id' => $mesaId,
            'cliente_id' => 4242,
        ])->assertStatus(422)->assertJson(['exito' => false]);

        // Con un cliente inválido no se crea nada.
        $this->assertSame(0, DB::table('ventas')->count());
    }

    public function test_abrir_mesa_con_cliente_valido_lo_asocia(): void
    {
        $admin = $this->usuario('ADMIN');
        $mesaId = $this->mesa('1', 'LIBRE');
        $clienteId = $this->cliente('Ana');

        $this->actingAs($admin)->postJson('/mesas/api', [
            'accion' => 'abrir_mesa',
            'mesa_id' => $mesaId,
            'cliente_id' => $clienteId,
        ])->assertStatus(200)->assertJson(['exito' => true]);

        $this->assertSame($clienteId, (int) DB::table('ventas')->where('mesa_id', $mesaId)->value('cliente_id'));
    }

    public function test_abrir_mesa_sin_autenticar_responde_401(): void
    {
        $mesaId = $this->mesa('1', 'LIBRE');

        $this->postJson('/mesas/api', [
            'accion' => 'abrir_mesa',
            'mesa_id' => $mesaId,
        ])->assertStatus(401);

        $this->assertSame(0, DB::table('ventas')->count());
    }

    public function test_abrir_mesa_es_idempotente_ante_doble_solicitud(): void
    {
        $admin = $this->usuario('ADMIN');
        $mesaId = $this->mesa('1', 'LIBRE');

        $this->actingAs($admin)->postJson('/mesas/api', [
            'accion' => 'abrir_mesa',
            'mesa_id' => $mesaId,
        ])->assertStatus(200)->assertJson(['exito' => true]);

        $this->actingAs($admin)->postJson('/mesas/api', [
            'accion' => 'abrir_mesa',
            'mesa_id' => $mesaId,
        ])->assertStatus(200)->assertJson(['exito' => true]);

        // Dos aperturas seguidas => una sola venta y una sola cuenta.
        $this->assertSame(1, DB::table('ventas')->where('mesa_id', $mesaId)->count());
        $this->assertSame(1, DB::table('cuentas')->count());
        $this->assertSame('LIBRE', $this->estadoMesa($mesaId));
    }

    public function test_abrir_mesa_reutiliza_venta_activa_sin_cuenta_creando_principal(): void
    {
        $admin = $this->usuario('ADMIN');
        $mesaId = $this->mesa('1', 'LIBRE');

        // Venta activa SIN cuentas (caso real observado en la base: mesa 15).
        $ventaId = $this->venta($mesaId);

        $this->actingAs($admin)->postJson('/mesas/api', [
            'accion' => 'abrir_mesa',
            'mesa_id' => $mesaId,
        ])->assertStatus(200)->assertJson(['exito' => true, 'venta_id' => $ventaId]);

        // Se reutiliza la venta y se le crea UNA cuenta principal.
        $this->assertSame(1, DB::table('ventas')->where('mesa_id', $mesaId)->count());
        $this->assertSame(1, DB::table('cuentas')->where('venta_id', $ventaId)->count());
        $this->assertSame('ABIERTA', (string) DB::table('cuentas')->where('venta_id', $ventaId)->value('estado'));
        $this->assertSame('LIBRE', $this->estadoMesa($mesaId));
    }

    public function test_abrir_mesa_con_venta_sin_cuentas_pero_con_consumo_activo_rechaza_409(): void
    {
        $admin = $this->usuario('ADMIN');
        $mesaId = $this->mesa('1', 'OCUPADA');
        $ventaId = $this->venta($mesaId);
        $productoId = $this->producto('Pizza', 20, true);

        // Venta SIN cuentas pero con un consumo ACTIVO: no está realmente
        // vacía; recuperarla crearía una cuenta principal sobre consumo real.
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

        $this->actingAs($admin)->postJson('/mesas/api', [
            'accion' => 'abrir_mesa',
            'mesa_id' => $mesaId,
        ])->assertStatus(409)->assertJson(['exito' => false]);

        $this->assertSame(0, DB::table('cuentas')->where('venta_id', $ventaId)->count());
    }

    public function test_abrir_mesa_con_venta_sin_cuentas_pero_con_historial_de_pago_rechaza_409(): void
    {
        $admin = $this->usuario('ADMIN');
        $mesaId = $this->mesa('1', 'OCUPADA');
        $ventaId = $this->venta($mesaId);

        // Sin cuentas, pero con datos de cobro: hubo pago (caso real venta 7).
        DB::table('ventas')->where('id', $ventaId)->update([
            'metodo_pago' => 'EFECTIVO_CORDOBA',
            'monto_recibido' => 1000,
        ]);

        $this->actingAs($admin)->postJson('/mesas/api', [
            'accion' => 'abrir_mesa',
            'mesa_id' => $mesaId,
        ])->assertStatus(409)->assertJson(['exito' => false]);

        $this->assertSame(0, DB::table('cuentas')->where('venta_id', $ventaId)->count());
    }

    public function test_abrir_mesa_con_venta_sin_cuentas_pero_con_factura_rechaza_409(): void
    {
        $admin = $this->usuario('ADMIN');
        $mesaId = $this->mesa('1', 'OCUPADA');
        $ventaId = $this->venta($mesaId);
        $this->factura($ventaId, 870);

        $this->actingAs($admin)->postJson('/mesas/api', [
            'accion' => 'abrir_mesa',
            'mesa_id' => $mesaId,
        ])->assertStatus(409)->assertJson(['exito' => false]);

        $this->assertSame(0, DB::table('cuentas')->where('venta_id', $ventaId)->count());
    }

    public function test_abrir_mesa_con_venta_realmente_vacia_sin_historial_recupera(): void
    {
        $admin = $this->usuario('ADMIN');
        $mesaId = $this->mesa('1', 'OCUPADA');
        $ventaId = $this->venta($mesaId);

        // Venta sin cuentas, sin consumos, sin cobro y sin facturas: es una
        // apertura vacía huérfana (caso real mesa 15) => se recupera creando
        // su cuenta principal.
        $this->actingAs($admin)->postJson('/mesas/api', [
            'accion' => 'abrir_mesa',
            'mesa_id' => $mesaId,
        ])->assertStatus(200)->assertJson(['exito' => true, 'venta_id' => $ventaId]);

        $this->assertSame(1, DB::table('cuentas')->where('venta_id', $ventaId)->count());
        $this->assertSame('ABIERTA', (string) DB::table('cuentas')->where('venta_id', $ventaId)->value('estado'));
    }

    public function test_abrir_mesa_con_cuenta_cancelada_no_la_reabre_ni_duplica(): void
    {
        $admin = $this->usuario('ADMIN');
        $mesaId = $this->mesa('1', 'LIBRE');
        $ventaId = $this->venta($mesaId);
        $cuentaId = $this->cuenta($ventaId, 'Cuenta Principal', 'CANCELADA');

        $this->actingAs($admin)->postJson('/mesas/api', [
            'accion' => 'abrir_mesa',
            'mesa_id' => $mesaId,
        ])->assertStatus(409)->assertJson(['exito' => false]);

        // La cuenta cancelada sigue cancelada y no se creó una nueva.
        $this->assertSame(1, DB::table('cuentas')->where('venta_id', $ventaId)->count());
        $this->assertSame('CANCELADA', (string) DB::table('cuentas')->where('id', $cuentaId)->value('estado'));
    }

    public function test_abrir_mesa_con_cuenta_pagada_protege_el_historial(): void
    {
        $admin = $this->usuario('ADMIN');
        $mesaId = $this->mesa('1', 'LIBRE');
        $ventaId = $this->venta($mesaId);
        $cuentaId = $this->cuenta($ventaId, 'Cuenta Principal', 'PAGADA');
        DB::table('cuentas')->where('id', $cuentaId)->update(['total' => 150]);

        $this->actingAs($admin)->postJson('/mesas/api', [
            'accion' => 'abrir_mesa',
            'mesa_id' => $mesaId,
        ])->assertStatus(409)->assertJson(['exito' => false]);

        // La cuenta cobrada es intocable: no se reabre ni se duplica.
        $this->assertSame(1, DB::table('cuentas')->where('venta_id', $ventaId)->count());
        $this->assertSame('PAGADA', (string) DB::table('cuentas')->where('id', $cuentaId)->value('estado'));
        $this->assertSame(150.0, (float) DB::table('cuentas')->where('id', $cuentaId)->value('total'));
        $this->assertSame('LIBRE', $this->estadoMesa($mesaId));
    }

    public function test_abrir_mesa_respeta_cuenta_abierta_existente_sin_duplicar(): void
    {
        $admin = $this->usuario('ADMIN');
        $mesaId = $this->mesa('1', 'LIBRE');
        $ventaId = $this->venta($mesaId);
        $cuentaId = $this->cuenta($ventaId, 'Cuenta Principal');

        $this->actingAs($admin)->postJson('/mesas/api', [
            'accion' => 'abrir_mesa',
            'mesa_id' => $mesaId,
        ])->assertStatus(200)->assertJson(['exito' => true, 'venta_id' => $ventaId]);

        $this->assertSame(1, DB::table('cuentas')->where('venta_id', $ventaId)->count());
        $this->assertSame($cuentaId, (int) DB::table('cuentas')->where('venta_id', $ventaId)->value('id'));
    }

    /*
     * =========================================================
     * TAREA 4: RECUPERACIÓN DE MESAS VACÍAS DESDE EL PANEL
     * =========================================================
     */

    public function test_panel_de_mesa_vacia_ofrece_volver_al_pos_y_liberar(): void
    {
        $admin = $this->usuario('ADMIN');
        $mesaId = $this->mesa('1', 'OCUPADA');
        $ventaId = $this->venta($mesaId);
        $this->cuenta($ventaId, 'Cuenta Principal');

        $respuesta = $this->actingAs($admin)->get('/mesas/' . $mesaId . '/cuentas');

        $respuesta->assertStatus(200);
        $this->assertTrue((bool) $respuesta->viewData('mesaVacia'));
        $this->assertTrue((bool) $respuesta->viewData('puedeLiberar'));

        // El panel ofrece volver al POS y liberar la mesa vacía.
        $respuesta->assertSee('Ir al POS');
        $respuesta->assertSee('Liberar mesa');
    }

    public function test_panel_de_mesa_con_consumo_no_ofrece_liberar(): void
    {
        $admin = $this->usuario('ADMIN');
        $mesaId = $this->mesa('1', 'OCUPADA');
        $ventaId = $this->venta($mesaId);
        $cuentaId = $this->cuenta($ventaId, 'Cuenta Principal');
        $productoId = $this->producto('Pizza', 20, true);

        $detalleId = DB::table('venta_detalles')->insertGetId([
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
        DB::table('cuentas')->where('id', $cuentaId)->update(['total' => 20]);

        $respuesta = $this->actingAs($admin)->get('/mesas/' . $mesaId . '/cuentas');
        $respuesta->assertStatus(200);
        $this->assertFalse((bool) $respuesta->viewData('mesaVacia'));
        $respuesta->assertDontSee('Liberar mesa');
    }

    public function test_recuperar_mesa_vacia_conserva_historial(): void
    {
        $admin = $this->usuario('ADMIN');
        $mesaId = $this->mesa('1', 'OCUPADA');
        $ventaId = $this->venta($mesaId);
        $cuentaId = $this->cuenta($ventaId, 'Cuenta Principal');

        $this->actingAs($admin)->postJson('/pos/api', [
            'accion' => 'liberar_mesa_vacia',
            'venta_id' => $ventaId,
        ])->assertStatus(200)->assertJson(['exito' => true]);

        $this->assertSame('LIBRE', $this->estadoMesa($mesaId));
        // Historial conservado: ni la venta ni la cuenta se eliminan.
        $this->assertTrue(DB::table('ventas')->where('id', $ventaId)->exists());
        $this->assertTrue(DB::table('cuentas')->where('id', $cuentaId)->exists());
        $this->assertSame('PAGADA', (string) DB::table('ventas')->where('id', $ventaId)->value('estado'));
    }

    public function test_permisos_del_panel_y_de_liberar_no_cambian(): void
    {
        foreach (['ADMIN', 'GERENTE', 'CAJERO', 'MESERO'] as $rol) {
            $this->crearEsquema();

            $usuario = $this->usuario($rol);
            $mesaId = $this->mesa('1', 'OCUPADA');
            $ventaId = $this->venta($mesaId);
            $this->cuenta($ventaId, 'Cuenta Principal');

            $resp = $this->actingAs($usuario)->get('/mesas/' . $mesaId . '/cuentas');
            $resp->assertStatus(200);

            // Crear subcuentas: los cuatro roles (sin cambios).
            $this->assertTrue((bool) $resp->viewData('puedeCrear'));

            // Dividir y trasladar: solo ADMIN, GERENTE y CAJERO (sin cambios).
            $esAdminGerenteCajero = in_array($rol, ['ADMIN', 'GERENTE', 'CAJERO'], true);
            $this->assertSame($esAdminGerenteCajero, (bool) $resp->viewData('puedeDividir'));
            $this->assertSame($esAdminGerenteCajero, (bool) $resp->viewData('puedeTrasladar'));

            // Liberar mesa vacía: reutiliza liberarMesa(), ya accesible vía POS
            // para los cuatro roles. No se amplía ni se restringe.
            $this->assertTrue((bool) $resp->viewData('puedeLiberar'));
        }
    }

    public function test_rol_ajeno_no_recibe_acciones_del_panel(): void
    {
        $supervisor = $this->usuario('SUPERVISOR');
        $mesaId = $this->mesa('1', 'OCUPADA');
        $ventaId = $this->venta($mesaId);
        $this->cuenta($ventaId, 'Cuenta Principal');

        $resp = $this->actingAs($supervisor)->get('/mesas/' . $mesaId . '/cuentas');
        $resp->assertStatus(200);
        $this->assertFalse((bool) $resp->viewData('puedeLiberar'));
        $this->assertFalse((bool) $resp->viewData('puedeCrear'));
    }
}
