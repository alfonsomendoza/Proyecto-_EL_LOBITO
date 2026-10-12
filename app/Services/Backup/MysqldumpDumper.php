<?php

namespace App\Services\Backup;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Implementación real de DatabaseDumper usando mysqldump.
 *
 * Seguridad:
 *  - El comando se construye como ARRAY (sin shell ni concatenación de strings),
 *    por lo que no hay inyección por argumentos.
 *  - Las credenciales NUNCA se pasan como argumentos del proceso: se entregan
 *    mediante un archivo temporal --defaults-extra-file que se elimina en un
 *    bloque finally.
 *  - Los mensajes de error no incluyen contraseñas ni datos sensibles.
 *  - Existe un tiempo máximo de ejecución configurable.
 */
class MysqldumpDumper implements DatabaseDumper
{
    public function isAvailable(): bool
    {
        $bin = (string) config('backup.mysqldump.binary');

        if ($bin === '') {
            return false;
        }

        // Ruta absoluta configurada: basta con comprobar el archivo.
        if (preg_match('/[\\\\\/]/', $bin)) {
            return PHP_OS_FAMILY === 'Windows' ? is_file($bin) : (is_file($bin) && is_executable($bin));
        }

        // Nombre simple: se busca en el PATH del sistema.
        $probe = new Process(PHP_OS_FAMILY === 'Windows' ? ['where', $bin] : ['which', $bin]);

        try {
            $probe->setTimeout(10)->run();

            return $probe->isSuccessful();
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function dumpTo(string $absolutePath): void
    {
        $config = $this->configuracionConexion();

        $binary = (string) config('backup.mysqldump.binary');

        if ($binary === '') {
            throw new BackupException('La ruta de mysqldump no está configurada.');
        }

        if (!$this->isAvailable()) {
            throw new BackupException('No se encontró mysqldump en la ruta configurada. Revisa BACKUP_MYSQLDUMP_PATH.');
        }

        // Archivo temporal de credenciales (se elimina en finally).
        $tmp = $this->crearArchivoCredenciales($config);

        try {
            $args = [
                $binary,
                '--defaults-extra-file=' . $tmp,
                '--host=' . ($config['host'] ?? '127.0.0.1'),
                '--port=' . ($config['port'] ?? '3306'),
                '--single-transaction',
                '--no-tablespaces',
                '--default-character-set=' . ($config['charset'] ?? 'utf8mb4'),
                '--result-file=' . $absolutePath,
                (string) ($config['database'] ?? ''),
            ];

            $process = new Process($args);
            $process->setTimeout((float) config('backup.mysqldump.timeout', 300));
            $process->run();

            if (!$process->isSuccessful()) {
                throw new BackupException('mysqldump terminó con error (código ' . $process->getExitCode() . ').');
            }
        } catch (ProcessTimedOutException $e) {
            throw new BackupException('El respaldo excedió el tiempo máximo de ejecución configurado.');
        } finally {
            $this->borrarArchivoCredenciales($tmp);
        }
    }

    /**
     * Obtiene la configuración de la conexión a respaldar.
     */
    private function configuracionConexion(): array
    {
        $conn = (string) (config('backup.connection') ?: config('database.default'));
        $config = (array) config('database.connections.' . $conn);

        if (empty($config) || !in_array($config['driver'] ?? '', ['mysql', 'mariadb'], true)) {
            throw new BackupException('La conexión configurada no es MySQL/MariaDB o no existe.');
        }

        return $config;
    }

    /**
     * Crea el archivo --defaults-extra-file con las credenciales.
     *
     * El archivo se crea con permisos restrictivos y una ubicación temporal;
     * se elimina siempre en el finally de dumpTo().
     */
    private function crearArchivoCredenciales(array $config): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'elobito_dump_');

        if ($tmp === false) {
            throw new BackupException('No fue posible crear el archivo temporal de credenciales.');
        }

        // Permisos restrictivos (en Windows este control es limitado; se
        // documenta la limitación en docs/BACKUP.md).
        @chmod($tmp, 0600);

        $contenido = implode(PHP_EOL, [
            '[client]',
            'user=' . $this->escapar((string) ($config['username'] ?? '')),
            'password=' . $this->escapar((string) ($config['password'] ?? '')),
            'host=' . ($config['host'] ?? '127.0.0.1'),
            'port=' . ($config['port'] ?? '3306'),
        ]) . PHP_EOL;

        if (@file_put_contents($tmp, $contenido) === false) {
            @unlink($tmp);
            throw new BackupException('No fue posible escribir el archivo temporal de credenciales.');
        }

        return $tmp;
    }

    /**
     * Escapa un valor para el formato de opciones de MySQL.
     */
    private function escapar(string $valor): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $valor) . '"';
    }

    /**
     * Elimina el archivo temporal de credenciales de forma segura.
     */
    private function borrarArchivoCredenciales(string $ruta): void
    {
        if ($ruta !== '' && is_file($ruta)) {
            @unlink($ruta);
        }
    }
}