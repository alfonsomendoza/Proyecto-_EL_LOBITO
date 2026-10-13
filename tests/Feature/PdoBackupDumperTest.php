<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Backup\BackupException;
use App\Services\Backup\CompositeDumper;
use App\Services\Backup\DatabaseDumper;
use App\Services\Backup\PdoDumper;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PDO;
use Tests\TestCase;

/**
 * Pruebas del generador PHP/PDO y del dumper compuesto.
 *
 * La fuente de datos es un PDO de SQLite en memoria (aislado); NUNCA se
 * invoca mysqldump real ni se toca "sistemadecaja". Estas pruebas verifican
 * el pipeline del generador (definiciones, datos, NULL, escape, FK, archivo,
 * hash y auditoría) sin declarar por sí solas compatibilidad integral con
 * MySQL.
 */
class PdoBackupDumperTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('sqlite', DB::connection()->getDriverName(), 'Las pruebas deben ejecutarse en SQLite.');
        $this->assertSame(':memory:', (string) config('database.connections.sqlite.database'), 'La base de pruebas debe ser SQLite en memoria.');

        $this->crearEsquema();

        Storage::fake('local');
        config(['backup.disk' => 'local']);
        config(['backup.path' => 'backups']);
        config(['backup.min_bytes' => 0]);
    }

    private function crearEsquema(): void
    {
        foreach (['backup_registros', 'usuarios', 'roles'] as $tabla) {
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

        Schema::create('backup_registros', function (Blueprint $t) {
            $t->increments('id');
            $t->unsignedBigInteger('usuario_id')->nullable();
            $t->string('ip', 45)->nullable();
            $t->string('tipo', 20)->default('CREACION');
            $t->string('estado', 20)->default('EN_PROCESO');
            $t->string('nombre_archivo', 150)->nullable();
            $t->char('hash_sha256', 64)->nullable();
            $t->unsignedBigInteger('tamano_bytes')->nullable();
            $t->unsignedBigInteger('duracion_ms')->nullable();
            $t->string('mensaje', 500)->nullable();
            $t->timestamp('iniciado_en')->nullable();
            $t->timestamp('finalizado_en')->nullable();
            $t->timestamps();
        });
    }

    private function usuarioConRol(string $rol): User
    {
        $rolId = DB::table('roles')->insertGetId(['nombre' => $rol]);
        $usuario = User::create([
            'nombre' => 'Usuario ' . $rol,
            'username' => strtolower($rol) . 'test',
            'password_hash' => bcrypt('secreto'),
            'rol_id' => $rolId,
        ]);

        return $usuario;
    }

    /**
     * PDO aislado de SQLite en memoria que actúa como la "base de origen".
     */
    private function fuenteSql(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // "precio" se declara TEXT para comprobar el escape exacto de valores
        // ('0.00' / '25.50' sin normalización numérica de SQLite).
        $pdo->exec('CREATE TABLE "clientes" ('
            . '"id" INTEGER PRIMARY KEY AUTOINCREMENT,'
            . '"nombre" VARCHAR(100) NOT NULL,'
            . '"notas" TEXT,'
            . '"precio" TEXT,'
            . '"fecha" DATE)');

        // 1: apóstrofo escapado + comillas dobles. 2: NULLs. 3: vacíos.
        $pdo->exec("INSERT INTO clientes (nombre, notas, precio, fecha) VALUES ('O''Brien', 'texto con '' comillas '' y \"dobles\"', '25.50', '2026-10-12')");
        $pdo->exec("INSERT INTO clientes (nombre, notas, precio, fecha) VALUES ('Café con ñ', NULL, NULL, NULL)");
        $pdo->exec("INSERT INTO clientes (nombre, notas, precio, fecha) VALUES ('', '', '0.00', NULL)");

        // 7 registros adicionales: total 10 INSERT esperados.
        for ($i = 1; $i <= 7; $i++) {
            $dia = str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            $pdo->exec("INSERT INTO clientes (nombre, notas, precio, fecha) VALUES ('Cliente {$i}', 'nota {$i}', '10.00', '2026-02-{$dia}')");
        }

        return $pdo;
    }

    /* ------------------------------------------------------------------
     | 1. Generación PHP/PDO completa
     | ------------------------------------------------------------------ */

    public function test_dumper_pdo_genera_volcado_completo_con_null_y_caracteres_especiales(): void
    {
        $this->app->instance(DatabaseDumper::class, new PdoDumper($this->fuenteSql(), 'testdb'));
        $this->actingAs($this->usuarioConRol('ADMIN'));

        $respuesta = $this->postJson(route('backups.crear'));
        $respuesta->assertStatus(200)
            ->assertJson(['exito' => true]);

        $registro = DB::table('backup_registros')->where('estado', 'COMPLETADO')->first();
        $this->assertNotNull($registro);
        $this->assertNotEmpty($registro->hash_sha256);
        $this->assertGreaterThan(0, (int) $registro->tamano_bytes);

        $ruta = Storage::disk('local')->path('backups/' . $registro->nombre_archivo);
        $contenido = file_get_contents($ruta);
        $this->assertIsString($contenido);
        $this->assertSame($registro->hash_sha256, hash('sha256', $contenido));

        // Encabezado, definición y control de claves foráneas.
        $this->assertStringContainsString('-- Respaldo generado por Sistema POS (Laravel) - PHP/PDO', $contenido);
        $this->assertStringContainsString('DROP TABLE IF EXISTS `clientes`', $contenido);
        $this->assertStringContainsString('CREATE TABLE', $contenido);
        $this->assertStringContainsString('PRAGMA foreign_keys=OFF', $contenido);
        $this->assertStringContainsString('PRAGMA foreign_keys=ON', $contenido);
        $this->assertStringContainsString('-- Fin del respaldo.', $contenido);

        // Datos: escape de apóstrofo, UTF-8, NULL y cadena vacía.
        $this->assertStringContainsString("O''Brien", $contenido);
        $this->assertStringContainsString('Café con ñ', $contenido);
        $this->assertStringContainsString("('1', 'O''Brien', 'texto con '' comillas '' y \"dobles\"', '25.50', '2026-10-12');", $contenido);
        $this->assertStringContainsString("('3', '', '', '0.00', NULL);", $contenido);

        // Una línea INSERT por fila: sin omisiones ni cortes.
        $this->assertSame(10, substr_count($contenido, 'INSERT INTO'));
    }

    /* ------------------------------------------------------------------
     | 2. Selección automática del dumper (CompositeDumper)
     | ------------------------------------------------------------------ */

    public function test_composite_usa_pdo_cuando_la_conexion_no_es_mysql(): void
    {
        $falsoMysqldump = new class implements DatabaseDumper
        {
            public function isAvailable(): bool
            {
                return true;
            }

            public function dumpTo(string $absolutePath): void
            {
                throw new BackupException('mysqldump NO debe invocarse con conexión no MySQL.');
            }
        };

        $this->app->instance(DatabaseDumper::class, new CompositeDumper($falsoMysqldump, new PdoDumper($this->fuenteSql(), 'testdb')));
        $this->actingAs($this->usuarioConRol('ADMIN'));

        $respuesta = $this->postJson(route('backups.crear'));
        $respuesta->assertStatus(200)
            ->assertJson(['exito' => true]);

        $this->assertNotNull(DB::table('backup_registros')->where('estado', 'COMPLETADO')->first());
    }

    public function test_composite_usa_pdo_cuando_mysqldump_no_esta_disponible(): void
    {
        config(['backup.connection' => 'backup_espejo']);
        config(['database.connections.backup_espejo' => [
            'driver' => 'mysql',
            'database' => 'sistemadecaja',
        ]]);

        $falsoMysqldump = new class implements DatabaseDumper
        {
            public function isAvailable(): bool
            {
                return false;
            }

            public function dumpTo(string $absolutePath): void
            {
                throw new BackupException('mysqldump no está disponible.');
            }
        };

        $this->app->instance(DatabaseDumper::class, new CompositeDumper($falsoMysqldump, new PdoDumper($this->fuenteSql(), 'testdb')));
        $this->actingAs($this->usuarioConRol('ADMIN'));

        // Aunque la conexión configurada sea tipo MySQL, al faltar mysqldump
        // el respaldo se genera igual con el PHP/PDO (sin herramientas externas).
        $respuesta = $this->postJson(route('backups.crear'));
        $respuesta->assertStatus(200)
            ->assertJson(['exito' => true]);

        $this->assertNotNull(DB::table('backup_registros')->where('estado', 'COMPLETADO')->first());
    }

    public function test_composite_vuelve_al_generador_pdo_cuando_mysqldump_falla_en_ejecucion(): void
    {
        config(['backup.connection' => 'backup_espejo']);
        config(['database.connections.backup_espejo' => [
            'driver' => 'mysql',
            'database' => 'sistemadecaja',
        ]]);

        $mysqldumpQueFalla = new class implements DatabaseDumper
        {
            public function isAvailable(): bool
            {
                return true;
            }

            public function dumpTo(string $absolutePath): void
            {
                // Simula un binario que existe pero falla en ejecución.
                throw new BackupException('mysqldump murió en ejecución.');
            }
        };

        $this->app->instance(DatabaseDumper::class, new CompositeDumper($mysqldumpQueFalla, new PdoDumper($this->fuenteSql(), 'testdb')));
        $this->actingAs($this->usuarioConRol('ADMIN'));

        // Aunque mysqldump "esté disponible", al fallar se debe reintentar con
        // el generador PHP/PDO y completar la copia.
        $respuesta = $this->postJson(route('backups.crear'));
        $respuesta->assertStatus(200)
            ->assertJson(['exito' => true]);

        $this->assertNotNull(DB::table('backup_registros')->where('estado', 'COMPLETADO')->first());
    }

    /* ------------------------------------------------------------------
     | 3. Error de escritura deja registrada la operación y ninguna copia
     | ------------------------------------------------------------------ */

    public function test_dumper_falla_despues_de_escribir_no_deja_copia_valida(): void
    {
        $this->app->instance(DatabaseDumper::class, new class implements DatabaseDumper
        {
            public function isAvailable(): bool
            {
                return true;
            }

            public function dumpTo(string $absolutePath): void
            {
                // Escribe contenido grande y aparentemente válido y luego falla:
                // la copia NO puede quedar registrada como válida.
                $contenido = "-- MySQL dump 10.13\n"
                    . "CREATE TABLE `ventas` (`id` bigint);\n"
                    . "INSERT INTO `ventas` VALUES (1);\n";

                while (strlen($contenido) < 4096) {
                    $contenido .= "-- linea de relleno\n";
                }

                file_put_contents($absolutePath, $contenido);
                throw new BackupException('El proceso terminó con error.');
            }
        });

        $this->actingAs($this->usuarioConRol('ADMIN'));

        $respuesta = $this->postJson(route('backups.crear'));
        $respuesta->assertStatus(500)
            ->assertJson(['exito' => false]);

        $registro = DB::table('backup_registros')->where('estado', 'FALLIDO')->first();
        $this->assertNotNull($registro);
        $this->assertNull($registro->hash_sha256);

        // El archivo parcial escrito antes del error se elimina.
        $this->assertEmpty(Storage::disk('local')->allFiles('backups'));
    }

    public function test_dumper_rechaza_ruta_inaccesible_y_no_crea_archivo(): void
    {
        $dumper = new PdoDumper($this->fuenteSql(), 'testdb');

        // Directorio que no existe: fopen debe fallar limpio, sin dejar nada.
        $ruta = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'elobito_noexiste_' . uniqid() . DIRECTORY_SEPARATOR . 'backup.sql';
        $this->assertDirectoryDoesNotExist(dirname($ruta));

        try {
            $dumper->dumpTo($ruta);
            $this->fail('Debió lanzarse una BackupException.');
        } catch (BackupException $e) {
            $this->assertStringContainsString('escribir el archivo', $e->getMessage());
        }

        $this->assertFileDoesNotExist($ruta);
    }

    /* ------------------------------------------------------------------
     | 4. Reimportación del volcado generado en una base SQLite AISLADA
     | ------------------------------------------------------------------ */

    public function test_volcado_pdo_se_reimporta_en_otra_base_sqlite_aislada(): void
    {
        $this->app->instance(DatabaseDumper::class, new PdoDumper($this->fuenteSql(), 'testdb'));
        $this->actingAs($this->usuarioConRol('ADMIN'));

        $this->postJson(route('backups.crear'))
            ->assertStatus(200)
            ->assertJson(['exito' => true]);

        $registro = DB::table('backup_registros')->where('estado', 'COMPLETADO')->first();
        $this->assertNotNull($registro);

        $contenido = file_get_contents(Storage::disk('local')->path('backups/' . $registro->nombre_archivo));
        $this->assertIsString($contenido);

        // Se aplica el volcado sobre OTRA base SQLite en memoria (aislada).
        $destino = new PDO('sqlite::memory:');
        $destino->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->assertTrue($destino->exec($contenido) !== false);

        // Los datos vuelven intactos: filas, NULL y UTF-8.
        $this->assertSame(10, (int) $destino->query('SELECT COUNT(*) FROM "clientes"')->fetchColumn());
        $this->assertSame('Café con ñ', $destino->query('SELECT nombre FROM "clientes" WHERE id = 2')->fetchColumn());
        $this->assertNull($destino->query('SELECT notas FROM "clientes" WHERE id = 2')->fetchColumn());
        $this->assertSame("O'Brien", $destino->query('SELECT nombre FROM "clientes" WHERE id = 1')->fetchColumn());
        $this->assertSame('', $destino->query('SELECT notas FROM "clientes" WHERE id = 3')->fetchColumn());
    }
}