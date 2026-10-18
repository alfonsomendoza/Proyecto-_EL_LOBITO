<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ETAPA 1 - Protección del rol COCINERO.
 *
 * Verifica la autorización en el SERVIDOR (no solo ocultar botones) para que
 * COCINERO:
 *  - reciba 403 en las rutas financieras (POS, cuentas, catálogo, caja, clientes),
 *  - no pueda crear ventas, abrir cuentas ni abrir caja,
 *  - no reciba precios ni montos en las respuestas HTML/JSON a las que accede,
 *  - conserve el acceso a las impresiones de cocina,
 * y que ADMIN, GERENTE, CAJERO y MESERO conserven sus permisos anteriores.
 *
 * Corre en SQLite :memory: (phpunit.xml). Nunca toca MySQL ni "sistemadecaja".
 */
class CocinaPermisosTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('sqlite', DB::connection()->getDriverName(), 'Las pruebas deben ejecutarse en SQLite.');
        $this->assertSame(':memory:', (string) config('database.connections.sqlite.database'), 'La base de pruebas debe ser SQLite en memoria.');

        $this->crearEsquema();
    }

    /** Esquema manual (SQLite). Las migraciones reales son de MySQL y no aplican. */
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
            'caja_apertura',
        ] as $tabla) {
            Schema::dropIfExists($tabla);
        }

        Schema::create('roles', function (Blueprint $t) {
            $t->increments('id');
            $t->string('nombre', 30);
        });

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

        // Tabla de apertura de caja (modelo App\Models\CajaApertura).
        Schema::create('caja_apertura', function (Blueprint $t) {
            $t->increments('id');
            $t->dateTime('fecha')->nullable();
            $t->string('cajero', 100)->nullable();
            $t->string('turno', 50)->nullable();
            $t->integer('caja_id')->nullable();
            $t->decimal('monto_inicial', 12, 2)->default(0);
            $t->text('conteo_json')->nullable();
            $t->string('estado', 20)->default('ABIERTA');
            $t->integer('usuario_id')->nullable();
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

    private function venta(int $mesaId, float $monto = 0): int
    {
        return DB::table('ventas')->insertGetId([
            'mesa_id' => $mesaId,
            'cliente_id' => null,
            'apertura_id' => null,
            'descripcion' => 'Consumo Mesa',
            'monto' => $monto,
            'fecha' => now(),
            'estado' => 'ACTIVA',
            'usuario_id' => null,
            'descuento_porcentaje' => 0,
        ]);
    }

    private function cuenta(int $ventaId, string $nombre = 'Cuenta Principal'): int
    {
        return DB::table('cuentas')->insertGetId([
            'venta_id' => $ventaId,
            'nombre' => $nombre,
            'total' => 0,
            'estado' => 'ABIERTA',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function comanda(int $ventaId, int $mesaId, int $ticket = 1): int
    {
        return DB::table('comandas')->insertGetId([
            'venta_id' => $ventaId,
            'mesa_id' => $mesaId,
            'numero_ticket' => $ticket,
            'usuario_id' => null,
            'cliente_id' => null,
            'descripcion' => null,
            'estado' => 'ABIERTA',
            'total' => 0,
            'conteo_impresiones' => 0,
            'creado_en' => now(),
        ]);
    }

    private function detalleCocina(int $ventaId, int $productoId, ?int $comandaId, int $cantidad = 1): int
    {
        return DB::table('venta_detalles')->insertGetId([
            'venta_id' => $ventaId,
            'producto_id' => $productoId,
            'cantidad' => $cantidad,
            'precio_unitario' => 0,
            'subtotal' => 0,
            'comanda_id' => $comandaId,
            'cuenta_id' => null,
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /* ==================================================================
     | 1. COCINERO recibe 403 en las rutas financieras
     | ================================================================== */

    public function test_cocinero_recibe_403_en_rutas_financieras(): void
    {
        $cocinero = $this->usuario('COCINERO');
        $mesaId = $this->mesa('1', 'LIBRE');

        // POS con precios.
        $this->actingAs($cocinero)->get('/pos/' . $mesaId)->assertStatus(403);

        // API del POS (devuelve precios/totales y crea comandas).
        $this->actingAs($cocinero)->postJson('/pos/api', [])->assertStatus(403);

        // Listados de cuentas/subcuentas con totales (JSON y panel HTML).
        $this->actingAs($cocinero)->get('/pos/' . $mesaId . '/cuentas')->assertStatus(403);
        $this->actingAs($cocinero)->get('/mesas/' . $mesaId . '/cuentas')->assertStatus(403);

        // Catálogo comercial con precios.
        $this->actingAs($cocinero)->get('/catalogo')->assertStatus(403);

        // Apertura de caja y ticket con montos.
        $this->actingAs($cocinero)->get('/caja/apertura')->assertStatus(403);
        $this->actingAs($cocinero)->post('/caja/apertura', ['monto_inicial' => 100])->assertStatus(403);
        $this->actingAs($cocinero)->get('/caja/ticket-apertura')->assertStatus(403);

        // Clientes.
        $this->actingAs($cocinero)->get('/clientes')->assertStatus(403);

        // Operaciones de mesas (crear / abrir ventas).
        $this->actingAs($cocinero)->postJson('/mesas/api', ['accion' => 'abrir_mesa', 'mesa_id' => $mesaId])->assertStatus(403)
            ->assertJson(['exito' => false]);
    }

    /* ==================================================================
     | 2. COCINERO no puede crear ventas ni abrir cuentas
     | ================================================================== */

    public function test_cocinero_no_puede_crear_ventas_ni_abrir_cuentas(): void
    {
        $cocinero = $this->usuario('COCINERO');
        $mesaId = $this->mesa('1', 'LIBRE');

        // Abrir mesa (crea venta + cuenta) por HTTP directo: bloqueado.
        $this->actingAs($cocinero)->postJson('/mesas/api', [
            'accion' => 'abrir_mesa',
            'mesa_id' => $mesaId,
        ])->assertStatus(403);

        // Crear subcuenta directamente: bloqueado.
        $this->actingAs($cocinero)->postJson('/mesas/' . $mesaId . '/cuentas', [
            'nombre' => 'Ana',
        ])->assertStatus(403);

        // Enviar pedido desde el POS (crea consumo): bloqueado.
        $this->actingAs($cocinero)->postJson('/pos/api', [
            'accion' => 'enviar_cocina',
            'venta_id' => 1,
            'cuenta_id' => 1,
            'carrito' => json_encode([['id' => 1, 'cantidad' => 1]]),
        ])->assertStatus(403);

        // Nada se creó.
        $this->assertSame(0, DB::table('ventas')->count());
        $this->assertSame(0, DB::table('cuentas')->count());
        $this->assertSame(0, DB::table('venta_detalles')->count());
    }

    /* ==================================================================
     | 3. COCINERO no puede abrir caja
     | ================================================================== */

    public function test_cocinero_no_puede_abrir_caja(): void
    {
        $cocinero = $this->usuario('COCINERO');

        $this->actingAs($cocinero)->postJson('/caja/apertura', [
            'monto_inicial' => 500,
        ])->assertStatus(403)->assertJson(['exito' => false]);

        $this->assertSame(0, DB::table('caja_apertura')->count());
    }

    /* ==================================================================
     | 4. COCINERO no recibe precios ni montos
     | ================================================================== */

    public function test_cocinero_no_recibe_precios_ni_montos(): void
    {
        $cocinero = $this->usuario('COCINERO');
        $mesaId = $this->mesa('2', 'OCUPADA');
        $ventaId = $this->venta($mesaId, 987654.00);
        $this->cuenta($ventaId);
        $productoId = $this->producto('Pizza Secreta', 987654.00, true);
        $comandaId = $this->comanda($ventaId, $mesaId);
        $this->detalleCocina($ventaId, $productoId, $comandaId);

        // Consulta de mesas (permitida): no debe filtrar montos.
        $respMesas = $this->actingAs($cocinero)->get('/mesas');
        $respMesas->assertStatus(200);
        $respMesas->assertDontSee('987654');

        // Impresión de cocina (permitida): muestra el plato, nunca el precio.
        $respComanda = $this->actingAs($cocinero)->get('/pos/comanda/' . $comandaId);
        $respComanda->assertStatus(200);
        $respComanda->assertSee('Pizza Secreta');
        $respComanda->assertDontSee('987654');

        // Las respuestas con montos quedan bloqueadas.
        $this->actingAs($cocinero)->get('/pos/' . $mesaId)->assertStatus(403);
        $this->actingAs($cocinero)->get('/pos/' . $mesaId . '/cuentas')->assertStatus(403);
    }

    /* ==================================================================
     | 5. COCINERO conserva las impresiones de cocina
     | ================================================================== */

    public function test_cocinero_conserva_impresiones_de_cocina(): void
    {
        $cocinero = $this->usuario('COCINERO');
        $mesaId = $this->mesa('3', 'OCUPADA');
        $ventaId = $this->venta($mesaId);
        $this->cuenta($ventaId);
        $productoId = $this->producto('Hamburguesa', 50, true);
        $comandaId = $this->comanda($ventaId, $mesaId);
        $detalleId = $this->detalleCocina($ventaId, $productoId, $comandaId, 2);

        $this->actingAs($cocinero)->get('/pos/comanda/' . $comandaId)->assertStatus(200);
        $this->actingAs($cocinero)->get('/pos/comanda-detalle/' . $detalleId)->assertStatus(200);
        $this->actingAs($cocinero)->get('/pos/comanda-completa/' . $ventaId)->assertStatus(200);
        $this->actingAs($cocinero)->get('/pos/comandas/' . $ventaId)->assertStatus(200);
    }

    /* ==================================================================
     | 6. COCINERO conserva la consulta de mesas e inicio
     | ================================================================== */

    public function test_cocinero_conserva_consulta_de_mesas_e_inicio(): void
    {
        $cocinero = $this->usuario('COCINERO');
        $this->mesa('1', 'LIBRE');

        $this->actingAs($cocinero)->get('/inicio')->assertStatus(200);
        $this->actingAs($cocinero)->get('/mesas')->assertStatus(200);
    }

    /* ==================================================================
     | 7. COCINERO sigue bloqueado en los módulos ya restringidos
     | ================================================================== */

    public function test_cocinero_sigue_bloqueado_en_modulos_ya_restringidos(): void
    {
        $cocinero = $this->usuario('COCINERO');

        $this->actingAs($cocinero)->get('/gastos')->assertStatus(403);
        $this->actingAs($cocinero)->get('/inventario')->assertStatus(403);
        $this->actingAs($cocinero)->get('/backups')->assertStatus(403);
        $this->actingAs($cocinero)->get('/usuarios')->assertRedirect(route('inicio'));
        $this->actingAs($cocinero)->get('/roles')->assertRedirect(route('inicio'));
    }

    /* ==================================================================
     | 8. ADMIN, GERENTE, CAJERO y MESERO conservan sus permisos
     | ================================================================== */

    public function test_los_demas_roles_conservan_sus_permisos(): void
    {
        foreach (['ADMIN', 'GERENTE', 'CAJERO', 'MESERO'] as $rol) {
            $this->crearEsquema();

            $usuario = $this->usuario($rol);
            $mesaId = $this->mesa('1', 'LIBRE');
            $ventaId = $this->venta($mesaId);
            $this->cuenta($ventaId);

            $this->actingAs($usuario)->get('/pos/' . $mesaId)->assertStatus(200);
            $this->actingAs($usuario)->get('/catalogo')->assertStatus(200);
            $this->actingAs($usuario)->get('/clientes')->assertStatus(200);
            $this->actingAs($usuario)->get('/caja/apertura')->assertStatus(200);
            $this->actingAs($usuario)->get('/mesas/' . $mesaId . '/cuentas')->assertStatus(200);
            $this->actingAs($usuario)->get('/pos/' . $mesaId . '/cuentas')->assertStatus(200);

            // API del POS (consulta de venta): disponible para los cuatro.
            $this->actingAs($usuario)->postJson('/pos/api', ['venta_id' => $ventaId])
                ->assertStatus(200)->assertJson(['exito' => true]);

            // Abrir mesa (crea venta/cuenta): disponible para los cuatro.
            $this->actingAs($usuario)->postJson('/mesas/api', [
                'accion' => 'abrir_mesa',
                'mesa_id' => $mesaId,
            ])->assertStatus(200)->assertJson(['exito' => true]);

            // Abrir caja: disponible para los cuatro (permiso preexistente).
            $this->actingAs($usuario)->postJson('/caja/apertura', [
                'monto_inicial' => 100,
            ])->assertStatus(200)->assertJson(['exito' => true]);
        }
    }

    /* ==================================================================
     | 9. Usuarios no autenticados siguen sujetos a la autenticación
     | ================================================================== */

    public function test_no_autenticado_queda_sujeto_a_la_autenticacion(): void
    {
        $mesaId = $this->mesa('1', 'LIBRE');

        $this->get('/pos/' . $mesaId)->assertRedirect(route('login'));
        $this->get('/caja/apertura')->assertRedirect(route('login'));
        $this->get('/catalogo')->assertRedirect(route('login'));
        $this->get('/clientes')->assertRedirect(route('login'));

        // Peticiones JSON sin sesión: 401.
        $this->postJson('/pos/api', [])->assertStatus(401);
        $this->postJson('/mesas/api', ['accion' => 'abrir_mesa', 'mesa_id' => $mesaId])->assertStatus(401);
    }
}
