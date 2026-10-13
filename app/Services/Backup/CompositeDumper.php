<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

/**
 * Selecciona el método de generación del respaldo:
 *
 *  - Si la conexión es MySQL/MariaDB y el binario mysqldump está disponible,
 *    se intenta primero el volcado clásico (MysqldumpDumper).
 *  - Si mysqldump no está disponible O falla en ejecución, se usa el
 *    generador PHP/PDO (PdoDumper), que no depende de herramientas externas.
 *  - Si la conexión no es MySQL, siempre se usa el generador PHP/PDO.
 *
 * Así el sistema puede generar respaldos reales incluso en máquinas donde
 * mysqldump no existe (o falla) sin necesidad de editar .env.
 */
class CompositeDumper implements DatabaseDumper
{
    public function __construct(
        private DatabaseDumper $mysqldump,
        private DatabaseDumper $pdo
    ) {
    }

    public function isAvailable(): bool
    {
        if ($this->conexionEsMysql()) {
            return $this->mysqldump->isAvailable() || $this->pdo->isAvailable();
        }

        return $this->pdo->isAvailable();
    }

    public function dumpTo(string $absolutePath): void
    {
        if (!$this->conexionEsMysql()) {
            $this->pdo->dumpTo($absolutePath);

            return;
        }

        if ($this->mysqldump->isAvailable()) {
            try {
                $this->mysqldump->dumpTo($absolutePath);

                return;
            } catch (BackupException $e) {
                // mysqldump está disponible pero falló en ejecución: se elimina
                // cualquier resto parcial y se intenta el generador PHP/PDO.
                Log::warning('mysqldump falló en ejecución, se reintenta con PHP/PDO: ' . $e->getMessage());

                if (is_file($absolutePath)) {
                    @unlink($absolutePath);
                }
            }
        }

        $this->pdo->dumpTo($absolutePath);
    }

    /**
     * Determina si la conexión activa del respaldo es MySQL/MariaDB.
     */
    private function conexionEsMysql(): bool
    {
        $conn = (string) (Config::get('backup.connection') ?: Config::get('database.default'));
        $driver = (string) (Config::get('database.connections.' . $conn . '.driver') ?? '');

        return in_array($driver, ['mysql', 'mariadb'], true);
    }
}