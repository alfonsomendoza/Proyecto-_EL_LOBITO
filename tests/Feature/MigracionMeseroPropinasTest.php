<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

class MigracionMeseroPropinasTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // ------------------------------------------------------------
        // Seguridad (corren en SQLite :memory:).
        // ------------------------------------------------------------
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', (string) config('database.connections.sqlite.database'));

        foreach (['mesa_asignaciones', 'cuentas', 'ventas', 'mesas', 'usuarios', 'roles'] as $tabla) {
            Schema::dropIfExists($tabla);
        }

        Schema::create('roles', function (Blueprint $t) {
            $t->increments('id');
            $t->string('nombre', 30);
        });

        Schema::create('usuarios', function (Blueprint $t) {
            $t->increments('id');
            $t->string('nombre', 100)->nullable();
            $t->integer('rol_id')->nullable();
            $t->boolean('activo')->default(true);
        });

        Schema::create('mesas', function (Blueprint $t) {
            $t->increments('id');
            $t->string('numero', 20)->nullable();
        });

        Schema::create('ventas', function (Blueprint $t) {
            $t->increments('id');
            $t->unsignedBigInteger('mesa_id')->nullable();
            $t->unsignedBigInteger('cliente_id')->nullable();
            $t->integer('apertura_id')->nullable();
            $t->integer('usuario_id')->nullable();
            $t->string('descripcion')->nullable();
            $t->decimal('monto', 12, 2)->default(0);
            $t->string('estado', 20)->default('ACTIVA');
            $t->timestamp('fecha')->nullable();
        });

        Schema::create('cuentas', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->integer('venta_id');
            $t->string('nombre');
            $t->decimal('total', 10, 2)->default(0);
            $t->string('estado')->default('ABIERTA');
            $t->timestamps();
        });
    }

    private function migracion(): object
    {
        // Carga la migración real sin ejecutarla contra MySQL.
        return require database_path('migrations/2026_11_12_000001_mesero_responsable_propinas.php');
    }

    private function prepararDatosBase(): void
    {
        DB::table('roles')->insertGetId(['nombre' => 'ADMIN']);
        DB::table('usuarios')->insertGetId(['nombre' => 'Admin', 'rol_id' => 1, 'activo' => 1]);
        DB::table('mesas')->insertGetId(['numero' => 'M1']);
        DB::table('ventas')->insertGetId([
            'mesa_id' => 1,
            'usuario_id' => 1,
            'descripcion' => 'Test',
            'monto' => 0,
            'estado' => 'ACTIVA',
            'fecha' => now(),
        ]);
    }

    public function test_rollback_bloqueado_si_existe_historial(): void
    {
        $migracion = $this->migracion();
        $migracion->up();

        $this->prepararDatosBase();

        DB::table('mesa_asignaciones')->insert([
            'venta_id' => 1,
            'mesa_id' => 1,
            'mesero_id' => 1,
            'asignado_por' => 1,
            'motivo' => 'APERTURA',
            'creado_en' => now(),
        ]);

        $this->expectException(\RuntimeException::class);
        $migracion->down();
    }

    public function test_rollback_permitido_si_no_existe_historial(): void
    {
        $migracion = $this->migracion();
        $migracion->up();

        $this->assertTrue(Schema::hasTable('mesa_asignaciones'));
        $this->assertTrue(Schema::hasColumn('ventas', 'mesero_responsable_id'));
        $this->assertTrue(Schema::hasColumn('cuentas', 'mesero_responsable_id'));

        $migracion->down();

        $this->assertFalse(Schema::hasTable('mesa_asignaciones'));
        $this->assertFalse(Schema::hasColumn('ventas', 'mesero_responsable_id'));
        $this->assertFalse(Schema::hasColumn('cuentas', 'mesero_responsable_id'));
    }

    /* ------------------------------------------------------------------
     | FK requeridas: detección, idempotencia y errores
     * ------------------------------------------------------------------ */

    private function fkEnTabla(string $tabla, string $columna): array
    {
        return DB::select("PRAGMA foreign_key_list('{$tabla}')");
    }

    public function test_up_crea_las_seis_fk_restringiendo_borrado(): void
    {
        $migracion = $this->migracion();
        $migracion->up();

        $fks = ['mesa_asignaciones', 'ventas', 'cuentas'];
        $total = 0;

        foreach ($fks as $tabla) {
            $total += count($this->fkEnTabla($tabla, ''));
        }

        $this->assertSame(6, $total);
    }

    public function test_fk_ya_existente_compatible_no_se_duplica(): void
    {
        Schema::dropIfExists('mesa_asignaciones');
        Schema::dropIfExists('ventas');
        Schema::dropIfExists('cuentas');
        Schema::dropIfExists('usuarios');
        Schema::dropIfExists('mesas');
        Schema::dropIfExists('roles');

        Schema::create('roles', function (Blueprint $t) { $t->increments('id'); $t->string('nombre')->nullable(); });
        Schema::create('usuarios', function (Blueprint $t) { $t->increments('id'); $t->string('nombre')->nullable(); });
        Schema::create('mesas', function (Blueprint $t) { $t->increments('id'); $t->string('numero')->nullable(); });
        Schema::create('ventas', function (Blueprint $t) {
            $t->increments('id');
            $t->unsignedBigInteger('mesa_id')->nullable();
            $t->integer('usuario_id')->nullable();
            $t->integer('mesero_responsable_id')->nullable();
            $t->string('descripcion')->nullable();
            $t->decimal('monto', 12, 2)->default(0);
            $t->string('estado')->default('ACTIVA');
            $t->foreign('mesero_responsable_id', 'fk_ventas_mesero_custom')
                ->references('id')->on('usuarios')->onDelete('restrict');
        });
        Schema::create('cuentas', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->integer('venta_id');
            $t->string('nombre')->nullable();
            $t->integer('mesero_responsable_id')->nullable();
        });

        $migracion = $this->migracion();
        $migracion->up();

        $fksVentas = $this->fkEnTabla('ventas', 'mesero_responsable_id');
        $this->assertCount(1, $fksVentas, 'No debe duplicarse una FK existente compatible.');

        $fksCuentas = $this->fkEnTabla('cuentas', 'mesero_responsable_id');
        $this->assertNotEmpty($fksCuentas, 'Debe crearse la FK de cuentas.');
    }

    public function test_fk_incompatible_falla_claramente(): void
    {
        Schema::dropIfExists('mesa_asignaciones');
        Schema::dropIfExists('ventas');
        Schema::dropIfExists('usuarios');

        Schema::create('usuarios', function (Blueprint $t) { $t->increments('id'); $t->string('nombre')->nullable(); });
        Schema::create('ventas', function (Blueprint $t) {
            $t->increments('id');
            $t->unsignedBigInteger('mesa_id')->nullable();
            $t->integer('usuario_id')->nullable();
            $t->integer('mesero_responsable_id')->nullable();
            $t->string('descripcion')->nullable();
            $t->decimal('monto', 12, 2)->default(0);
            $t->string('estado')->default('ACTIVA');
            $t->foreign('mesero_responsable_id')->references('id')->on('usuarios')->onDelete('set null');
        });

        $migracion = $this->migracion();

        try {
            $migracion->up();
            $this->fail('Se esperaba una excepción por FK incompatible.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('incompatible', $e->getMessage());
        }
    }

    public function test_fk_huerfanos_falla_antes_de_crear_la_fk(): void
    {
        Schema::dropIfExists('mesa_asignaciones');
        Schema::dropIfExists('cuentas');
        Schema::dropIfExists('ventas');
        Schema::dropIfExists('usuarios');

        Schema::create('usuarios', function (Blueprint $t) { $t->increments('id'); $t->string('nombre')->nullable(); });
        Schema::create('ventas', function (Blueprint $t) {
            $t->increments('id');
            $t->unsignedBigInteger('mesa_id')->nullable();
            $t->integer('usuario_id')->nullable();
            $t->integer('mesero_responsable_id')->nullable();
            $t->string('descripcion')->nullable();
            $t->decimal('monto', 12, 2)->default(0);
            $t->string('estado')->default('ACTIVA');
        });
        Schema::create('cuentas', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->integer('venta_id');
            $t->string('nombre');
            $t->integer('mesero_responsable_id')->nullable();
        });
        DB::table('cuentas')->insert([
            'venta_id' => 1,
            'nombre' => 'Hogar',
            'mesero_responsable_id' => 999, // inexistente
        ]);

        $migracion = $this->migracion();

        try {
            $migracion->up();
            $this->fail('Se esperaba una excepción por referencias sin usuario.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('sin referencia', $e->getMessage());
        }
    }

    public function test_error_de_fk_no_se_oculta(): void
    {
        // Forzar un error creando una tabla con una columna no compatible.
        Schema::dropIfExists('mesa_asignaciones');
        Schema::dropIfExists('ventas');
        Schema::dropIfExists('cuentas');
        Schema::dropIfExists('mesas');
        Schema::dropIfExists('usuarios');
        Schema::dropIfExists('roles');

        Schema::create('usuarios', function (Blueprint $t) { $t->increments('id'); $t->string('nombre')->nullable(); });
        Schema::create('mesas', function (Blueprint $t) { $t->increments('id'); $t->string('numero')->nullable(); });
        Schema::create('ventas', function (Blueprint $t) {
            $t->bigInteger('id');
            $t->unsignedBigInteger('mesa_id')->nullable();
            $t->string('descripcion')->nullable();
            $t->decimal('monto', 12, 2)->default(0);
            $t->string('estado')->default('ACTIVA');
        });
        // cuentas sin columna id compatible para test exacction: se ignora.
        Schema::create('cuentas', function (Blueprint $t) { $t->bigIncrements('id'); $t->string('nombre')->nullable(); });

        // Esto NO debe tragarse ningún error.
        $migracion = $this->migracion();

        try {
            $migracion->up();
        } catch (\Throwable $e) {
            $this->assertInstanceOf(\RuntimeException::class, $e);
            $this->assertNotEmpty($e->getMessage());
        }
    }

    private function esquemaConFkCustomizados(): void
    {
        foreach (['mesa_asignaciones', 'cuentas', 'ventas', 'mesas', 'usuarios', 'roles'] as $tabla) {
            Schema::dropIfExists($tabla);
        }

        Schema::create('roles', function (Blueprint $t) { $t->increments('id'); $t->string('nombre', 30); });
        Schema::create('usuarios', function (Blueprint $t) {
            $t->increments('id');
            $t->string('nombre', 100)->nullable();
            $t->integer('rol_id')->nullable();
            $t->boolean('activo')->default(true);
        });
        Schema::create('mesas', function (Blueprint $t) { $t->increments('id'); $t->string('numero', 20)->nullable(); });
        Schema::create('ventas', function (Blueprint $t) {
            $t->increments('id');
            $t->unsignedBigInteger('mesa_id')->nullable();
            $t->integer('mesero_responsable_id')->nullable();
            $t->integer('usuario_id')->nullable();
            $t->string('descripcion')->nullable();
            $t->decimal('monto', 12, 2)->default(0);
            $t->string('estado', 20)->default('ACTIVA');
            $t->foreign('mesero_responsable_id', 'mi_fk_custom_ventas_mesero')->references('id')->on('usuarios')->onDelete('restrict');
        });
        Schema::create('cuentas', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->integer('venta_id');
            $t->string('nombre')->nullable();
            $t->integer('mesero_responsable_id')->nullable();
            $t->foreign('mesero_responsable_id', 'mi_fk_custom_cuentas_mesero')->references('id')->on('usuarios')->onDelete('restrict');
        });
    }

    public function test_rollback_con_fk_preexistente_de_nombre_diferente(): void
    {
        $this->esquemaConFkCustomizados();

        // Verificar que SQLite expone las FK con los nombres custom.
        $fksVentas = DB::select('PRAGMA foreign_key_list(ventas)');
        $nombres = collect($fksVentas)->pluck('table')->values();
        $this->assertTrue($nombres->contains('usuarios'));

        $migracion = $this->migracion();

        // up() debe reconocer las FK existentes, no duplicar ni fallar.
        $migracion->up();

        $this->assertTrue(Schema::hasTable('mesa_asignaciones'));
        $this->assertTrue(Schema::hasColumn('ventas', 'mesero_responsable_id'));
        $this->assertTrue(Schema::hasColumn('cuentas', 'mesero_responsable_id'));

        // Auditoría: no se duplicaron las FK preexistentes de ventas/cuentas.
        $fksFinalVentas = DB::select('PRAGMA foreign_key_list(ventas)');
        $this->assertCount(1, $fksFinalVentas);
        $fksFinalCuentas = DB::select('PRAGMA foreign_key_list(cuentas)');
        $this->assertCount(1, $fksFinalCuentas);

        // down() debe eliminar columnas (la tabla nueva se reconstruye).
        $migracion->down();

        $this->assertFalse(Schema::hasTable('mesa_asignaciones'));
        $this->assertFalse(Schema::hasColumn('ventas', 'mesero_responsable_id'));
        $this->assertFalse(Schema::hasColumn('cuentas', 'mesero_responsable_id'));
    }

    public function test_rollback_bloqueado_con_fk_preexistente_de_nombre_diferente(): void
    {
        $this->esquemaConFkCustomizados();

        $migracion = $this->migracion();
        $migracion->up();

        $rolId = DB::table('roles')->insertGetId(['nombre' => 'ADMIN']);
        $usuarioId = DB::table('usuarios')->insertGetId(['nombre' => 'Admin', 'rol_id' => $rolId, 'activo' => 1]);
        $mesaId = DB::table('mesas')->insertGetId(['numero' => 'M1']);
        $ventaId = DB::table('ventas')->insertGetId(['mesa_id' => $mesaId, 'usuario_id' => $usuarioId, 'descripcion' => 't', 'monto' => 0]);

        DB::table('mesa_asignaciones')->insert([
            'venta_id' => $ventaId,
            'mesa_id' => $mesaId,
            'mesero_id' => $usuarioId,
            'asignado_por' => $usuarioId,
            'motivo' => 'APERTURA',
            'creado_en' => now(),
        ]);

        $this->expectException(\RuntimeException::class);
        $migracion->down();

        // Historial y columnas siguen intactos.
        $this->assertSame(1, DB::table('mesa_asignaciones')->count());
        $this->assertTrue(Schema::hasColumn('ventas', 'mesero_responsable_id'));
        $this->assertTrue(Schema::hasColumn('cuentas', 'mesero_responsable_id'));
    }

    public function test_rollback_conserva_historial_cuando_se_bloquea(): void
    {
        $migracion = $this->migracion();
        $migracion->up();

        $this->prepararDatosBase();

        DB::table('mesa_asignaciones')->insert([
            'venta_id' => 1,
            'mesa_id' => 1,
            'mesero_id' => 1,
            'asignado_por' => 1,
            'motivo' => 'APERTURA',
            'creado_en' => now(),
        ]);

        try {
            $migracion->down();
        } catch (\RuntimeException $e) {
            // Se espera: el historial se conserva intacto.
        }

        $this->assertTrue(Schema::hasTable('mesa_asignaciones'));
        $this->assertSame(1, DB::table('mesa_asignaciones')->count());
    }
}
