<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/*
 * Pruebas aisladas del módulo "Historial de Facturas".
 *
 * IMPORTANTE:
 * - Corren en SQLite :memory: (configurado en phpunit.xml).
 * - El esquema se construye a mano porque las tablas reales viven en MySQL;
 *   así ninguna prueba escribe en la base "sistemadecaja".
 * - setUp() falla a propósito si la conexión no es SQLite en memoria.
 *
 * Reglas comprobadas:
 * - ADMIN y GERENTE listan e imprimen; CAJERO solo imprime; MESERO: 403.
 * - Filtros del listado (estado, número).
 * - Filtro por cliente (por nombre y por identificación), combinado con
 *   estado, número y rango de fechas.
 * - Las facturas históricas sin cliente (`venta.cliente_id = NULL`) siguen
 *   apareciendo cuando no se filtra por cliente, y no se duplican con JOIN.
 * - Búsqueda de una factura por id o por número.
 * - Factura inexistente / sin parámetros: 404.
 * - Imprimir NO modifica datos.
 */
class FacturaHistorialModuleTest extends TestCase
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
        foreach (['facturas', 'ventas', 'cuentas', 'usuarios', 'roles', 'clientes'] as $tabla) {
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

        // `clientes` refleja las columnas reales de MySQL. El historial de
        // facturas la une por `ventas.cliente_id`; por eso debe existir en el
        // esquema de pruebas aunque no se modifique ninguna tabla real.
        Schema::create('clientes', function (Blueprint $t) {
            $t->increments('id');
            $t->string('nombre', 150);
            $t->string('telefono', 20)->nullable();
            $t->string('identificacion', 50)->nullable();
            $t->text('direccion')->nullable();
            $t->boolean('activo')->default(true);
            $t->timestamp('creado_en')->nullable();
        });

        Schema::create('ventas', function (Blueprint $t) {
            $t->increments('id');
            $t->unsignedBigInteger('cliente_id')->nullable();
            $t->string('descripcion')->nullable();
            $t->decimal('monto', 12, 2)->default(0);
            $t->string('metodo_pago', 30)->nullable();
            $t->string('banco', 30)->nullable();
            $t->decimal('monto_recibido', 10, 2)->nullable();
            $t->decimal('cambio', 10, 2)->nullable();
            $t->string('estado', 20)->default('PAGADA');
            $t->dateTime('fecha')->nullable();
        });

        // `cuentas` es necesaria porque las consultas de facturas unen el cobro
        // registrado en la cuenta (POS Laravel) con el de `ventas` (PHP puro).
        Schema::create('cuentas', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->integer('venta_id');
            $t->string('nombre', 255)->nullable();
            $t->decimal('total', 10, 2)->default(0);
            $t->string('estado', 255)->default('ABIERTA');
            $t->string('metodo_pago', 30)->nullable();
            $t->string('banco', 30)->nullable();
            $t->decimal('monto_recibido', 12, 2)->nullable();
            $t->decimal('cambio', 12, 2)->nullable();
            $t->integer('usuario_cobro')->nullable();
            $t->timestamps();
        });

        Schema::create('facturas', function (Blueprint $t) {
            $t->increments('id');
            $t->string('numero_factura', 20);
            $t->integer('venta_id');
            $t->integer('cuenta_id')->nullable();
            $t->string('mesa', 50);
            $t->text('detalle_consumo')->nullable();
            $t->decimal('total', 10, 2)->default(0);
            $t->dateTime('fecha_emision')->nullable();
            $t->integer('usuario_id')->nullable();
            $t->string('estado', 20)->default('EMITIDA');
        });
    }

    /* ------------------------------------------------------------------
     | Constructores de datos
     | ------------------------------------------------------------------ */

    private function usuario(string $rolNombre): User
    {
        $rolId = DB::table('roles')->insertGetId(['nombre' => $rolNombre]);

        $usuarioId = DB::table('usuarios')->insertGetId([
            'nombre' => $rolNombre.' Prueba',
            'username' => strtolower($rolNombre).'_fact',
            'password_hash' => bcrypt('secret'),
            'rol_id' => $rolId,
        ]);

        return User::findOrFail($usuarioId);
    }

    /**
     * Crea un cliente real (nombre obligatorio; identificación opcional) y
     * devuelve su id para asociarlo a una venta.
     */
    private function cliente(string $nombre, ?string $identificacion = null): int
    {
        return DB::table('clientes')->insertGetId([
            'nombre' => $nombre,
            'identificacion' => $identificacion,
            'activo' => 1,
        ]);
    }

    private function factura(
        string $numero,
        float $total,
        string $estado = 'EMITIDA',
        string $mesa = 'Mesa 1',
        ?int $usuarioId = null,
        ?string $nota = '2x Cerveza + 1x Hamburguesa',
        ?int $clienteId = null,
        ?string $fechaEmision = null
    ): int {
        $ventaId = DB::table('ventas')->insertGetId([
            'cliente_id' => $clienteId,
            'descripcion' => $mesa,
            'monto' => $total,
            'metodo_pago' => 'EFECTIVO',
            'banco' => null,
            'monto_recibido' => $total,
            'cambio' => 0,
            'estado' => 'PAGADA',
            'fecha' => now(),
        ]);

        return DB::table('facturas')->insertGetId([
            'numero_factura' => $numero,
            'venta_id' => $ventaId,
            'cuenta_id' => null,
            'mesa' => $mesa,
            'detalle_consumo' => $nota === null ? null : json_encode(['nota' => $nota], JSON_UNESCAPED_UNICODE),
            'total' => $total,
            'fecha_emision' => $fechaEmision ?? now(),
            'usuario_id' => $usuarioId,
            'estado' => $estado,
        ]);
    }

    /* ------------------------------------------------------------------
     | 1. Acceso
     | ------------------------------------------------------------------ */

    public function test_admin_y_gerente_ven_el_historial(): void
    {
        foreach (['ADMIN', 'GERENTE'] as $rol) {
            $this->actingAs($this->usuario($rol))
                ->get(route('facturas.index'))
                ->assertStatus(200);
        }
    }

    public function test_cajero_y_mesero_no_ven_el_historial(): void
    {
        foreach (['CAJERO', 'MESERO'] as $rol) {
            $this->actingAs($this->usuario($rol))
                ->get(route('facturas.index'))
                ->assertStatus(403);
        }
    }

    public function test_admin_gerente_y_cajero_imprimen_una_factura(): void
    {
        $facturaId = $this->factura('FAC-0001', 1500);

        foreach (['ADMIN', 'GERENTE', 'CAJERO'] as $rol) {
            $this->actingAs($this->usuario($rol))
                ->get(route('facturas.ticket', ['id' => $facturaId]))
                ->assertStatus(200);
        }
    }

    public function test_mesero_no_imprime_una_factura(): void
    {
        $facturaId = $this->factura('FAC-0001', 1500);

        $this->actingAs($this->usuario('MESERO'))
            ->get(route('facturas.ticket', ['id' => $facturaId]))
            ->assertStatus(403);
    }

    public function test_no_autenticado_es_redirigido_al_login(): void
    {
        $this->get(route('facturas.index'))->assertRedirect(route('login'));
    }

    /* ------------------------------------------------------------------
     | 2. Listado y filtros
     | ------------------------------------------------------------------ */

    public function test_listado_muestra_las_facturas(): void
    {
        $this->factura('FAC-0001', 1500, 'EMITIDA', 'Mesa 1');
        $this->factura('FAC-0002', 800, 'ANULADA', 'Mesa 2');

        $this->actingAs($this->usuario('ADMIN'))
            ->get(route('facturas.index'))
            ->assertStatus(200)
            ->assertSee('FAC-0001')
            ->assertSee('FAC-0002')
            ->assertSee('1,500.00')
            ->assertSee('ANULADA');
    }

    public function test_filtro_por_estado(): void
    {
        // Se usan números que no coinciden con el placeholder "FAC-0001" del
        // formulario de filtros, para que assertDontSee sea concluyente.
        $this->factura('FAC-1001', 1500, 'EMITIDA');
        $this->factura('FAC-1002', 800, 'ANULADA');

        $this->actingAs($this->usuario('ADMIN'))
            ->get(route('facturas.index', ['estado' => 'ANULADA']))
            ->assertStatus(200)
            ->assertSee('FAC-1002')
            ->assertDontSee('FAC-1001');
    }

    public function test_filtro_por_numero(): void
    {
        $this->factura('FAC-1001', 1500);
        $this->factura('FAC-1002', 800);

        $this->actingAs($this->usuario('ADMIN'))
            ->get(route('facturas.index', ['numero' => 'FAC-1002']))
            ->assertStatus(200)
            ->assertSee('FAC-1002')
            ->assertDontSee('FAC-1001');
    }

    public function test_listado_vacio_no_falla(): void
    {
        $this->actingAs($this->usuario('ADMIN'))
            ->get(route('facturas.index'))
            ->assertStatus(200)
            ->assertSee('No hay facturas');
    }

    /* ------------------------------------------------------------------
     | 3. Ticket
     | ------------------------------------------------------------------ */

    public function test_ticket_por_id_y_por_numero(): void
    {
        $facturaId = $this->factura('FAC-0007', 1234.5);
        $admin = $this->usuario('ADMIN');

        $this->actingAs($admin)
            ->get(route('facturas.ticket', ['id' => $facturaId]))
            ->assertStatus(200)
            ->assertSee('FACTURA DE VENTA')
            ->assertSee('FAC-0007')
            ->assertSee('C$ 1,234.50')
            ->assertSee('2x Cerveza');

        $this->actingAs($admin)
            ->get(route('facturas.ticket', ['numero' => 'FAC-0007']))
            ->assertStatus(200)
            ->assertSee('FAC-0007');
    }

    public function test_ticket_inexistente_devuelve_404(): void
    {
        $this->actingAs($this->usuario('ADMIN'))
            ->get(route('facturas.ticket', ['id' => 9999]))
            ->assertStatus(404);
    }

    public function test_ticket_sin_parametros_devuelve_404(): void
    {
        $this->actingAs($this->usuario('ADMIN'))
            ->get(route('facturas.ticket'))
            ->assertStatus(404);
    }

    public function test_factura_anulada_se_marca_en_el_ticket(): void
    {
        $facturaId = $this->factura('FAC-0009', 500, 'ANULADA');

        $this->actingAs($this->usuario('ADMIN'))
            ->get(route('facturas.ticket', ['id' => $facturaId]))
            ->assertStatus(200)
            ->assertSee('FACTURA ANULADA');
    }

    public function test_imprimir_no_modifica_datos(): void
    {
        $facturaId = $this->factura('FAC-0001', 1500);

        $antes = [
            'facturas' => DB::table('facturas')->count(),
            'ventas' => DB::table('ventas')->count(),
            'suma' => (float) DB::table('facturas')->sum('total'),
        ];

        $admin = $this->usuario('ADMIN');
        $this->actingAs($admin)->get(route('facturas.index'))->assertStatus(200);
        $this->actingAs($admin)->get(route('facturas.ticket', ['id' => $facturaId]))->assertStatus(200);

        $despues = [
            'facturas' => DB::table('facturas')->count(),
            'ventas' => DB::table('ventas')->count(),
            'suma' => (float) DB::table('facturas')->sum('total'),
        ];

        $this->assertSame($antes, $despues, 'Imprimir/consultar no debe modificar datos.');
    }

    /* ------------------------------------------------------------------
     | 4. Filtro por cliente (factura -> venta -> cliente)
     | ------------------------------------------------------------------ */

    public function test_filtro_por_cliente_por_nombre(): void
    {
        $ana = $this->cliente('Ana Torres', '001-1234567-8');
        $luis = $this->cliente('Luis Pérez', '402-7654321-0');

        $this->factura('FAC-2001', 1500, clienteId: $ana);
        $this->factura('FAC-2002', 800, clienteId: $luis);

        $this->actingAs($this->usuario('ADMIN'))
            ->get(route('facturas.index', ['cliente' => 'Ana']))
            ->assertStatus(200)
            ->assertSee('FAC-2001')
            ->assertDontSee('FAC-2002');
    }

    public function test_filtro_por_cliente_por_identificacion(): void
    {
        $ana = $this->cliente('Ana Torres', '001-1234567-8');
        $luis = $this->cliente('Luis Pérez', '402-7654321-0');

        $this->factura('FAC-2101', 1500, clienteId: $ana);
        $this->factura('FAC-2102', 800, clienteId: $luis);

        $this->actingAs($this->usuario('ADMIN'))
            ->get(route('facturas.index', ['cliente' => '402-7654321-0']))
            ->assertStatus(200)
            ->assertSee('FAC-2102')
            ->assertDontSee('FAC-2101');
    }

    public function test_cliente_sin_facturas_no_devuelve_resultados(): void
    {
        $this->cliente('Cliente Sin Compras', '999-0000000-0');

        $this->actingAs($this->usuario('ADMIN'))
            ->get(route('facturas.index', ['cliente' => 'Sin Compras']))
            ->assertStatus(200)
            ->assertSee('No hay facturas');
    }

    public function test_filtro_cliente_combinado_con_rango_de_fechas(): void
    {
        $ana = $this->cliente('Ana Torres', '001-1234567-8');

        $this->factura('FAC-2201', 1500, clienteId: $ana, fechaEmision: '2026-10-01 10:00:00');
        $this->factura('FAC-2202', 800, clienteId: $ana, fechaEmision: '2026-10-05 10:00:00');

        $this->actingAs($this->usuario('ADMIN'))
            ->get(route('facturas.index', [
                'cliente' => 'Ana',
                'desde' => '2026-10-03',
                'hasta' => '2026-10-10',
            ]))
            ->assertStatus(200)
            ->assertSee('FAC-2202')
            ->assertDontSee('FAC-2201');
    }

    public function test_filtro_cliente_combinado_con_estado_y_numero(): void
    {
        $ana = $this->cliente('Ana Torres', '001-1234567-8');

        $this->factura('FAC-2301', 1500, 'EMITIDA', clienteId: $ana);
        $this->factura('FAC-2302', 800, 'ANULADA', clienteId: $ana);

        $admin = $this->usuario('ADMIN');

        $this->actingAs($admin)
            ->get(route('facturas.index', ['cliente' => 'Ana', 'estado' => 'ANULADA']))
            ->assertStatus(200)
            ->assertSee('FAC-2302')
            ->assertDontSee('FAC-2301');

        $this->actingAs($admin)
            ->get(route('facturas.index', ['cliente' => 'Ana', 'numero' => 'FAC-2301']))
            ->assertStatus(200)
            ->assertSee('FAC-2301')
            ->assertDontSee('FAC-2302');
    }

    public function test_facturas_historicas_sin_cliente_aparecen_sin_filtro(): void
    {
        // Factura histórica: su venta tiene `cliente_id = NULL` (caso real del
        // PHP puro). No debe desaparecer mientras no se filtre por cliente.
        $this->factura('FAC-2400', 1000);

        $ana = $this->cliente('Ana Torres', '001-1234567-8');
        $this->factura('FAC-2401', 500, clienteId: $ana);

        $admin = $this->usuario('ADMIN');

        // Sin filtro de cliente, la histórica sigue visible junto a la otra.
        $this->actingAs($admin)
            ->get(route('facturas.index'))
            ->assertStatus(200)
            ->assertSee('FAC-2400')
            ->assertSee('FAC-2401');

        // Al filtrar por un cliente solo aparece la que tiene cliente asociado.
        $this->actingAs($admin)
            ->get(route('facturas.index', ['cliente' => 'Ana']))
            ->assertStatus(200)
            ->assertSee('FAC-2401')
            ->assertDontSee('FAC-2400');
    }

    public function test_filtro_por_cliente_no_duplica_facturas(): void
    {
        $ana = $this->cliente('Ana Torres', '001-1234567-8');

        $this->factura('FAC-2501', 100, clienteId: $ana);
        $this->factura('FAC-2502', 200, clienteId: $ana);

        $respuesta = $this->actingAs($this->usuario('ADMIN'))
            ->get(route('facturas.index', ['cliente' => 'Ana']));

        $respuesta->assertStatus(200);

        $html = $respuesta->getContent();

        $this->assertSame(1, substr_count($html, 'FAC-2501'), 'FAC-2501 no debe duplicarse.');
        $this->assertSame(1, substr_count($html, 'FAC-2502'), 'FAC-2502 no debe duplicarse.');
    }

    public function test_los_filtros_se_conservan_en_el_formulario(): void
    {
        $this->cliente('Ana Torres', '001-1234567-8');

        $this->actingAs($this->usuario('ADMIN'))
            ->get(route('facturas.index', [
                'cliente' => 'Ana Torres',
                'numero' => 'FAC-9999',
                'estado' => 'ANULADA',
                'desde' => '2026-10-01',
                'hasta' => '2026-10-31',
            ]))
            ->assertStatus(200)
            ->assertSee('value="Ana Torres"', false)
            ->assertSee('value="FAC-9999"', false)
            ->assertSee('value="2026-10-01"', false)
            ->assertSee('value="2026-10-31"', false);
    }

    public function test_filtro_cliente_no_afecta_permisos(): void
    {
        $this->cliente('Ana Torres', '001-1234567-8');

        foreach (['CAJERO', 'MESERO'] as $rol) {
            $this->actingAs($this->usuario($rol))
                ->get(route('facturas.index', ['cliente' => 'Ana']))
                ->assertStatus(403);
        }
    }
}
