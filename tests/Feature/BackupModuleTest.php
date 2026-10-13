<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Backup\DatabaseDumper;
use App\Services\BackupService;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Pruebas del módulo de copias de seguridad.
 *
 * Se usa SQLite en memoria (phpunit.xml), Storage::fake() y un dumper SIMULADO.
 * NUNCA se invoca mysqldump real ni se toca "sistemadecaja".
 */
class BackupModuleTest extends TestCase
{
    private static int $sufijoArchivo = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('sqlite', DB::connection()->getDriverName(), 'Las pruebas deben ejecutarse en SQLite.');
        $this->assertSame(':memory:', (string) config('database.connections.sqlite.database'), 'La base de pruebas debe ser SQLite en memoria.');

        $this->crearEsquema();

        Storage::fake('local');
        config(['backup.disk' => 'local']);
        config(['backup.path' => 'backups']);
        config(['backup.min_bytes' => 2048]);
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

    /* ------------------------------------------------------------------
     | Helpers
     | ------------------------------------------------------------------ */

    private function contenidoSqlValido(int $bytes = 4096): string
    {
        $base = implode("\n", [
            '-- MySQL dump 10.13  Distrib 8.0.43, for Win64 (x86_64)',
            '-- Host: localhost    Database: sistemadecaja',
            'CREATE TABLE `ventas` (',
            '  `id` bigint NOT NULL AUTO_INCREMENT,',
            '  PRIMARY KEY (`id`)',
            ') ENGINE=InnoDB;',
            'INSERT INTO `ventas` VALUES (1,1);',
        ]) . "\n";

        while (strlen($base) < $bytes) {
            $base .= "-- linea de relleno para superar el tamano minimo esperado\n";
        }

        return $base;
    }

    private function dumperExitoso(): DatabaseDumper
    {
        return new class implements DatabaseDumper
        {
            public function isAvailable(): bool
            {
                return true;
            }

            public function dumpTo(string $absolutePath): void
            {
                $contenido = '-- MySQL dump 10.13  Distrib 8.0.43, for Win64 (x86_64)' . PHP_EOL
                    . '-- Host: localhost    Database: sistemadecaja' . PHP_EOL
                    . 'CREATE TABLE `ventas` (`id` bigint);' . PHP_EOL
                    . 'INSERT INTO `ventas` VALUES (1);' . PHP_EOL;

                // Supera el mínimo de bytes configurado para pasar la validación.
                while (strlen($contenido) < 4096) {
                    $contenido .= "-- linea de relleno del volcado de prueba\n";
                }

                file_put_contents($absolutePath, $contenido);
            }
        };
    }

    private function dumperIncompleto(): DatabaseDumper
    {
        return new class implements DatabaseDumper
        {
            public function isAvailable(): bool
            {
                return true;
            }

            public function dumpTo(string $absolutePath): void
            {
                file_put_contents($absolutePath, '-- volcado incompleto');
            }
        };
    }

    private function dumperDisponeblePeroNoDisponible(): DatabaseDumper
    {
        return new class implements DatabaseDumper
        {
            public function isAvailable(): bool
            {
                return false;
            }

            public function dumpTo(string $absolutePath): void
            {
                throw new \RuntimeException('mysqldump no disponible');
            }
        };
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

    private function backupCompletoEnDisco(int $usuarioId = 1): array
    {
        $contenido = $this->contenidoSqlValido();
        $hash = hash('sha256', $contenido);

        self::$sufijoArchivo++;
        $nombre = 'backup_sistemadecaja_' . now()->format('Ymd_His') . '_' . self::$sufijoArchivo . '.sql';
        Storage::disk('local')->put('backups/' . $nombre, $contenido);

        $id = DB::table('backup_registros')->insertGetId([
            'usuario_id' => $usuarioId,
            'tipo' => 'CREACION',
            'estado' => 'COMPLETADO',
            'nombre_archivo' => $nombre,
            'hash_sha256' => $hash,
            'tamano_bytes' => strlen($contenido),
            'mensaje' => 'Copia generada y verificada.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['id' => $id, 'nombre' => $nombre, 'hash' => $hash];
    }

    /* ------------------------------------------------------------------
     | 1. Generación exitosa
     | ------------------------------------------------------------------ */

    public function test_admin_puede_crear_copia_y_queda_auditada(): void
    {
        $this->app->instance(DatabaseDumper::class, $this->dumperExitoso());
        $this->actingAs($this->usuarioConRol('ADMIN'));

        $respuesta = $this->postJson(route('backups.crear'));
        $respuesta->assertStatus(200)
            ->assertJson(['exito' => true]);

        $registro = DB::table('backup_registros')->where('estado', 'COMPLETADO')->first();
        $this->assertNotNull($registro);
        $this->assertSame('CREACION', $registro->tipo);
        $this->assertSame(1, (int) $registro->usuario_id);
        $this->assertNotEmpty($registro->hash_sha256);
        $this->assertGreaterThan(0, (int) $registro->tamano_bytes);
        $this->assertNotNull($registro->iniciado_en);
        $this->assertNotNull($registro->finalizado_en);

        $this->assertTrue(Storage::disk('local')->exists('backups/' . $registro->nombre_archivo));

        // El hash guardado coincide con el contenido real del archivo.
        $contenido = Storage::disk('local')->get('backups/' . $registro->nombre_archivo);
        $this->assertSame($registro->hash_sha256, hash('sha256', $contenido));
    }

    public function test_gerente_tambien_puede_crear_copia(): void
    {
        $this->app->instance(DatabaseDumper::class, $this->dumperExitoso());
        $this->actingAs($this->usuarioConRol('GERENTE'));

        $this->postJson(route('backups.crear'))
            ->assertStatus(200)
            ->assertJson(['exito' => true]);
    }

    /* ------------------------------------------------------------------
     | 2. Autorización
     | ------------------------------------------------------------------ */

    public function test_cajero_y_mesero_son_rechazados(): void
    {
        $this->app->instance(DatabaseDumper::class, $this->dumperExitoso());

        foreach (['CAJERO', 'MESERO'] as $rol) {
            $this->actingAs($this->usuarioConRol($rol));

            $this->postJson(route('backups.crear'))
                ->assertStatus(403)
                ->assertJson(['exito' => false]);

            $this->get(route('backups.index'))->assertStatus(403);
        }

        // Ninguna operación se registró.
        $this->assertSame(0, DB::table('backup_registros')->count());
        $this->assertEmpty(Storage::disk('local')->allFiles('backups'));
    }

    public function test_acceso_no_autenticado(): void
    {
        $this->get(route('backups.index'))->assertStatus(302);
        $this->postJson(route('backups.crear'))->assertStatus(401);
    }

    /* ------------------------------------------------------------------
     | 3. Descarga protegida
     | ------------------------------------------------------------------ */

    public function test_admin_puede_descargar_copia(): void
    {
        $this->app->instance(DatabaseDumper::class, $this->dumperExitoso());
        $this->actingAs($this->usuarioConRol('ADMIN'));

        $copia = $this->backupCompletoEnDisco(1);

        $this->get(route('backups.descargar', $copia['id']))
            ->assertStatus(200)
            ->assertDownload($copia['nombre']);
    }

    public function test_cajero_no_puede_descargar(): void
    {
        $this->app->instance(DatabaseDumper::class, $this->dumperExitoso());
        $copia = $this->backupCompletoEnDisco(1);

        $this->actingAs($this->usuarioConRol('CAJERO'));

        $this->get(route('backups.descargar', $copia['id']))->assertStatus(403);
    }

    public function test_nombres_manipulados_no_se_descargan(): void
    {
        $this->app->instance(DatabaseDumper::class, $this->dumperExitoso());
        $this->actingAs($this->usuarioConRol('ADMIN'));

        foreach (['../../.env', '../backup_x.sql', 'backup_evil_20261012_100000.sql.php', 'C:\\windows\\backup.sql'] as $malicioso) {
            $id = DB::table('backup_registros')->insertGetId([
                'estado' => 'COMPLETADO',
                'nombre_archivo' => $malicioso,
            ]);

            $this->get(route('backups.descargar', $id))->assertStatus(404);
        }
    }

    /* ------------------------------------------------------------------
     | 4. Fallos del proceso y archivos inválidos
     | ------------------------------------------------------------------ */

    public function test_proceso_externo_fallido_marca_fallido_y_no_deja_archivo(): void
    {
        $this->app->instance(DatabaseDumper::class, new class implements DatabaseDumper
        {
            public function isAvailable(): bool
            {
                return true;
            }

            public function dumpTo(string $absolutePath): void
            {
                throw new \RuntimeException('mysqldump terminó con error (código 255).');
            }
        });

        $this->actingAs($this->usuarioConRol('ADMIN'));

        $this->postJson(route('backups.crear'))
            ->assertStatus(500)
            ->assertJson(['exito' => false]);

        $registro = DB::table('backup_registros')->where('estado', 'FALLIDO')->first();
        $this->assertNotNull($registro);
        $this->assertStringContainsString('código 255', (string) $registro->mensaje);
        $this->assertEmpty(Storage::disk('local')->allFiles('backups'));
    }

    public function test_archivo_vacio_o_incompleto_marca_fallido(): void
    {
        $this->app->instance(DatabaseDumper::class, $this->dumperIncompleto());
        $this->actingAs($this->usuarioConRol('ADMIN'));

        $this->postJson(route('backups.crear'))
            ->assertStatus(500)
            ->assertJson(['exito' => false]);

        $registro = DB::table('backup_registros')->where('estado', 'FALLIDO')->first();
        $this->assertNotNull($registro);
        $this->assertNull($registro->hash_sha256);
        $this->assertEmpty(Storage::disk('local')->allFiles('backups'));
    }

    public function test_timeout_o_error_de_proceso_se_notifica_sin_secretos(): void
    {
        $this->app->instance(DatabaseDumper::class, new class implements DatabaseDumper
        {
            public function isAvailable(): bool
            {
                return true;
            }

            public function dumpTo(string $absolutePath): void
            {
                throw new \RuntimeException('El respaldo excedió el tiempo máximo de ejecución configurado.');
            }
        });

        $this->actingAs($this->usuarioConRol('ADMIN'));

        $respuesta = $this->postJson(route('backups.crear'))->assertStatus(500);

        $respuesta->assertDontSee('password');
        $respuesta->assertDontSee('sistemadecaja_user');

        $registro = DB::table('backup_registros')->where('estado', 'FALLIDO')->first();
        $this->assertNotNull($registro);
        $this->assertStringNotContainsString('password', (string) $registro->mensaje);
    }

    /* ------------------------------------------------------------------
     | 5. Concurrencia
     | ------------------------------------------------------------------ */

    public function test_ejecuciones_simultaneas_son_bloqueadas(): void
    {
        $this->app->instance(DatabaseDumper::class, $this->dumperExitoso());
        $this->actingAs($this->usuarioConRol('ADMIN'));

        /** @var Lock $lock */
        $lock = Cache::lock('el-lobito:backup:operacion', 60);
        $this->assertTrue($lock->get());

        try {
            $this->postJson(route('backups.crear'))
                ->assertStatus(409)
                ->assertJson(['exito' => false]);
        } finally {
            $lock->release();
        }

        // No quedan registros EN_PROCESO huérfanos de la petición bloqueada.
        $this->assertSame(0, DB::table('backup_registros')->where('estado', 'EN_PROCESO')->count());
    }

    /* ------------------------------------------------------------------
     | 6. Eliminación manual
     | ------------------------------------------------------------------ */

    public function test_eliminacion_manual_autorizada_y_auditada(): void
    {
        $this->app->instance(DatabaseDumper::class, $this->dumperExitoso());
        $this->actingAs($this->usuarioConRol('ADMIN'));

        $antigua = $this->backupCompletoEnDisco(1);
        $reciente = $this->backupCompletoEnDisco(1);

        $respuesta = $this->deleteJson(route('backups.eliminar', $antigua['id']));
        $respuesta->assertStatus(200)
            ->assertJson(['exito' => true]);

        $this->assertFalse(Storage::disk('local')->exists('backups/' . $antigua['nombre']));
        $this->assertSame('ELIMINADO', DB::table('backup_registros')->where('id', $antigua['id'])->value('estado'));

        $auditoria = DB::table('backup_registros')
            ->where('tipo', 'ELIMINACION')
            ->where('estado', 'ELIMINADO')
            ->first();
        $this->assertNotNull($auditoria);

        // La copia más reciente sigue intacta.
        $this->assertSame('COMPLETADO', DB::table('backup_registros')->where('id', $reciente['id'])->value('estado'));
        $this->assertTrue(Storage::disk('local')->exists('backups/' . $reciente['nombre']));
    }

    public function test_la_copia_mas_reciente_esta_protegida(): void
    {
        $this->app->instance(DatabaseDumper::class, $this->dumperExitoso());
        $this->actingAs($this->usuarioConRol('ADMIN'));

        $unica = $this->backupCompletoEnDisco(1);

        $this->deleteJson(route('backups.eliminar', $unica['id']))
            ->assertStatus(409)
            ->assertJson(['exito' => false]);

        $this->assertTrue(Storage::disk('local')->exists('backups/' . $unica['nombre']));
        $this->assertSame('COMPLETADO', DB::table('backup_registros')->where('id', $unica['id'])->value('estado'));
    }

    public function test_cajero_no_puede_eliminar(): void
    {
        $this->app->instance(DatabaseDumper::class, $this->dumperExitoso());
        $copia = $this->backupCompletoEnDisco(1);

        $this->actingAs($this->usuarioConRol('CAJERO'));

        $this->deleteJson(route('backups.eliminar', $copia['id']))->assertStatus(403);
        $this->assertTrue(Storage::disk('local')->exists('backups/' . $copia['nombre']));
    }

    public function test_gerente_tambien_puede_eliminar_copia_no_reciente(): void
    {
        $this->app->instance(DatabaseDumper::class, $this->dumperExitoso());
        $this->actingAs($this->usuarioConRol('GERENTE'));

        $antigua = $this->backupCompletoEnDisco(1);
        $reciente = $this->backupCompletoEnDisco(2);

        $this->deleteJson(route('backups.eliminar', $antigua['id']))
            ->assertStatus(200)
            ->assertJson(['exito' => true]);

        $this->assertFalse(Storage::disk('local')->exists('backups/' . $antigua['nombre']));
        $this->assertSame('ELIMINADO', DB::table('backup_registros')->where('id', $antigua['id'])->value('estado'));

        // El GERENTE no elimina la copia más reciente ni la de otro usuario por error.
        $this->assertSame('COMPLETADO', DB::table('backup_registros')->where('id', $reciente['id'])->value('estado'));
    }

    public function test_mesero_no_puede_eliminar(): void
    {
        $this->app->instance(DatabaseDumper::class, $this->dumperExitoso());
        $copia = $this->backupCompletoEnDisco(1);

        $this->actingAs($this->usuarioConRol('MESERO'));

        $this->deleteJson(route('backups.eliminar', $copia['id']))->assertStatus(403);
        $this->assertTrue(Storage::disk('local')->exists('backups/' . $copia['nombre']));
    }

    public function test_eliminar_id_inexistente_responde_404(): void
    {
        $this->app->instance(DatabaseDumper::class, $this->dumperExitoso());
        $this->actingAs($this->usuarioConRol('ADMIN'));

        // Se genera una copia válida para que el más reciente de la consulta
        // interna no interfiera, pero se pide eliminar un id inexistente.
        $this->backupCompletoEnDisco(1);

        $this->deleteJson(route('backups.eliminar', 99999))
            ->assertStatus(404)
            ->assertJson(['exito' => false]);
    }

    public function test_nombre_manipulado_no_se_elimina(): void
    {
        $this->app->instance(DatabaseDumper::class, $this->dumperExitoso());
        $this->actingAs($this->usuarioConRol('ADMIN'));

        // Registro con nombre que escapa al patrón del módulo (traversal).
        $id = DB::table('backup_registros')->insertGetId([
            'usuario_id' => 1,
            'tipo' => 'CREACION',
            'estado' => 'COMPLETADO',
            'nombre_archivo' => 'backup_../../../.env',
            'mensaje' => 'Registro manipulado de prueba.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->deleteJson(route('backups.eliminar', $id))
            ->assertStatus(422)
            ->assertJson(['exito' => false]);

        // No se marca como eliminado ni se crea auditoría de borrado.
        $this->assertSame('COMPLETADO', DB::table('backup_registros')->where('id', $id)->value('estado'));
        $this->assertNull(DB::table('backup_registros')->where('tipo', 'ELIMINACION')->where('estado', 'ELIMINADO')->first());
    }

    public function test_la_interfaz_envia_la_peticion_de_eliminacion_con_token_csrf(): void
    {
        $this->app->instance(DatabaseDumper::class, $this->dumperExitoso());
        $this->actingAs($this->usuarioConRol('ADMIN'));

        $copia = $this->backupCompletoEnDisco(1);

        $html = $this->get(route('backups.index'))->assertStatus(200)->getContent();

        // El botón de borrado existe y usa la ruta DELETE del backend.
        $this->assertStringContainsString('btn-eliminar-backup', $html);
        $this->assertStringContainsString(route('backups.eliminar', 'ID'), $html);

        // El token CSRF se envía por cabecera (no solo en el cuerpo FormData),
        // que es lo que Laravel puede leer en una petición DELETE.
        $this->assertStringContainsString('X-CSRF-TOKEN', $html);
    }

    public function test_error_al_eliminar_el_archivo_no_se_presenta_como_exito(): void
    {
        // Servicio que simula un fallo del sistema de archivos (unlink false).
        $servicioFallido = new class($this->dumperExitoso()) extends BackupService
        {
            protected function eliminarArchivo(string $ruta): bool
            {
                return false;
            }
        };

        $this->app->instance(BackupService::class, $servicioFallido);
        $this->actingAs($this->usuarioConRol('ADMIN'));

        $antigua = $this->backupCompletoEnDisco(1);
        $reciente = $this->backupCompletoEnDisco(2);

        $this->deleteJson(route('backups.eliminar', $antigua['id']))
            ->assertStatus(500)
            ->assertJson(['exito' => false]);

        // Sí el archivo no se pudo borrar, el historial NO se marca ELIMINADO
        // y no se crea un registro de auditoría de éxito.
        $this->assertSame('COMPLETADO', DB::table('backup_registros')->where('id', $antigua['id'])->value('estado'));
        $this->assertTrue(Storage::disk('local')->exists('backups/' . $antigua['nombre']));
        $this->assertNull(DB::table('backup_registros')->where('tipo', 'ELIMINACION')->where('estado', 'ELIMINADO')->first());
    }

    /* ------------------------------------------------------------------
     | 7. Sin restauración operativa
     | ------------------------------------------------------------------ */

    public function test_no_existe_ruta_de_restauracion_operativa(): void
    {
        $this->assertFalse(Route::has('backups.restaurar'));

        foreach (Route::getRoutes() as $ruta) {
            $this->assertStringNotContainsStringIgnoringCase('/backups/restaur', $ruta->uri());
            $this->assertStringNotContainsStringIgnoringCase('restaurar', $ruta->getAction('controller') ?? '');
        }
    }

    public function test_panel_muestra_historial_y_herramienta(): void
    {
        $this->app->instance(DatabaseDumper::class, $this->dumperExitoso());
        $this->actingAs($this->usuarioConRol('ADMIN'));

        $copia = $this->backupCompletoEnDisco(1);

        $this->get(route('backups.index'))
            ->assertStatus(200)
            ->assertSee('Copias de Seguridad')
            ->assertSee($copia['nombre'])
            ->assertSee('COMPLETADO');
    }

    /* ------------------------------------------------------------------
     | 8. Almacenamiento no público
     | ------------------------------------------------------------------ */

    public function test_el_respaldo_vive_en_almacenamiento_privado(): void
    {
        $this->app->instance(DatabaseDumper::class, $this->dumperExitoso());
        $this->actingAs($this->usuarioConRol('ADMIN'));

        $copia = $this->backupCompletoEnDisco(1);

        // El disco local apunta a storage/app/private, distinto del disco público.
        $rutaPrivada = Storage::disk('local')->path('');
        $rutaPublica = Storage::disk('public')->path('');

        $this->assertNotSame($rutaPublica, $rutaPrivada);
        $this->assertStringNotContainsString('public' . DIRECTORY_SEPARATOR, $rutaPrivada);

        // No existe una URL pública que sirva archivos desde el disco privado.
        $this->assertNotSame(200, $this->get('/storage/backups/' . $copia['nombre'])->status());
    }

    public function test_nombre_generado_sigue_el_patron_controlado(): void
    {
        // Se simula la conexión real: la base "sistemadecaja" se lee solo de config.
        config(['database.connections.backup_espejo' => [
            'driver' => 'mysql',
            'database' => 'sistemadecaja',
            'host' => '127.0.0.1',
            'port' => '3306',
            'username' => 'usuario',
            'password' => 'password',
        ]]);
        config(['backup.connection' => 'backup_espejo']);

        $this->app->instance(DatabaseDumper::class, $this->dumperExitoso());
        $this->actingAs($this->usuarioConRol('ADMIN'));

        $this->postJson(route('backups.crear'))->assertStatus(200);

        $nombre = DB::table('backup_registros')->where('estado', 'COMPLETADO')->value('nombre_archivo');

        $this->assertMatchesRegularExpression('/^backup_sistemadecaja_\d{8}_\d{6}\.sql$/', (string) $nombre);
    }
}