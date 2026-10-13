<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDO;

/**
 * Generador de respaldo SQL nativo en PHP (sin depender de mysqldump).
 *
 * Adapta la técnica del módulo antiguo de PHP puro (SHOW TABLES +
 * SHOW CREATE TABLE + SELECT *) manteniendo el contrato DatabaseDumper
 * de Laravel, de modo que las pruebas pueden inyectar un PDO de SQLite
 * aislado y NUNCA se toca la base real "sistemadecaja".
 *
 * Seguridad y robustez:
 *  - Los nombres de tablas/columnas provienen del servidor y se escapan
 *    como identificadores SQL (backticks duplicados). No se usa el escape
 *    de valores como sustituto de la validación de identificadores.
 *  - Los valores se escapan con PDO::quote() (mecanismo del driver);
 *    NULL, vacíos, decimales, fechas y caracteres especiales quedan
 *    representados de forma restaurable.
 *  - Las columnas BLOB/binario (MySQL) se vuelcan como literal hexadecimal
 *    0x… (equivalente a --hex-blob de mysqldump) para no depender de que la
 *    conexión transforme bytes.
 *  - El volcado se escribe por TABLA y por FILA (streaming): cada fwrite se
 *    comprueba y un fallo de escritura inconclusa lanza excepción, de modo
 *    que un archivo truncado NUNCA queda registrado como respaldo válido.
 *  - Se envuelve la lectura en una transacción (snapshot) y se desactivan
 *    las comprobaciones de claves foráneas durante la importación.
 *  - La sesión/atributos de la conexión compartida de Laravel se restauran
 *    al valor previo en todos los caminos (éxito, error, apertura fallida).
 *  - Acepta PDO de MySQL/MariaDB (production) y SQLite (pruebas aisladas).
 *
 * IMPORTANTE: la compatibilidad "integral" con MySQL solo se demuestra con
 * una restauración real en una base aislada; las pruebas de este módulo en
 * SQLite verifican el pipeline (definiciones, datos, NULL, escape, FK,
 * reimportación, hash) sin declarar por sí solas la compatibilidad total.
 */
class PdoDumper implements DatabaseDumper
{
    private ?PDO $pdo;
    private ?string $database;
    private ?string $connection;
    private string $charset;

    /**
     * @param PDO|null  $pdo        PDO ya conectado (pruebas). Si es null se
     *                              resuelve de forma diferida desde la
     *                              configuración de Laravel (production).
     * @param string|null $database Nombre de la base (solo se usa en MySQL
     *                              para filtrar information_schema).
     * @param string|null $connection Conexión de Laravel a usar cuando $pdo es null.
     */
    public function __construct(
        ?PDO $pdo = null,
        ?string $database = null,
        ?string $connection = null,
        string $charset = 'utf8mb4'
    ) {
        $this->pdo = $pdo;
        $this->database = $database;
        $this->connection = $connection;
        $this->charset = $charset;
    }

    public function isAvailable(): bool
    {
        try {
            return in_array($this->driver(), ['mysql', 'mariadb', 'sqlite'], true);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function dumpTo(string $absolutePath): void
    {
        $pdo = $this->pdo();
        $driver = $this->driver();

        if (!in_array($driver, ['mysql', 'mariadb', 'sqlite'], true)) {
            throw new BackupException('El generador PHP/PDO no admite la conexión configurada.');
        }

        $esMysql = in_array($driver, ['mysql', 'mariadb'], true);

        // Estado previo de la configuración de sesión para restaurarla siempre
        // al terminar (la conexión es la compartida por Laravel).
        $unbufferedAnterior = null;
        $fkAnterior = null;

        if ($esMysql) {
            // SET NAMES hace commit implícito: se ejecuta ANTES de la transacción.
            $charset = preg_replace('/[^A-Za-z0-9_]/', '', $this->charset) ?: 'utf8mb4';
            $pdo->exec('SET NAMES ' . $charset);

            try {
                $unbufferedAnterior = $pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
            } catch (\Throwable $e) {
                $unbufferedAnterior = null;
            }

            // SELECT * se lee fila por fila (mejor esfuerzo) para no cargar
            // tablas grandes en memoria. Si el driver no lo permite, sigue
            // funcionando en modo buffer.
            try {
                $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
            } catch (\Throwable $e) {
                // Opcional: se mantiene el comportamiento por defecto.
            }

            try {
                $fkAnterior = (string) $pdo->query('SELECT @@session.foreign_key_checks')->fetchColumn();
            } catch (\Throwable $e) {
                $fkAnterior = null;
            }
        }

        try {
            $manejador = fopen($absolutePath, 'wb');
        } catch (\Throwable $e) {
            if ($esMysql) {
                $this->restaurarSesion($pdo, $unbufferedAnterior);
            }

            throw new BackupException('No fue posible escribir el archivo de respaldo.');
        }

        if ($manejador === false) {
            if ($esMysql) {
                $this->restaurarSesion($pdo, $unbufferedAnterior);
            }

            throw new BackupException('No fue posible escribir el archivo de respaldo.');
        }

        try {
            // Lecturas dentro de una transacción para un snapshot más coherente.
            $pdo->exec($esMysql ? 'START TRANSACTION' : 'BEGIN');

            $fkOff = $esMysql ? 'SET FOREIGN_KEY_CHECKS=0' : 'PRAGMA foreign_keys=OFF';
            $fkOn = $esMysql ? 'SET FOREIGN_KEY_CHECKS=1' : 'PRAGMA foreign_keys=ON';

            $pdo->exec($fkOff);

            $this->escribir($manejador, "-- Respaldo generado por Sistema POS (Laravel) - PHP/PDO\n");
            $this->escribir($manejador, '-- Fecha: ' . date('Y-m-d H:i:s') . "\n");
            $this->escribir($manejador, '-- Driver: ' . $driver . "\n");
            $this->escribir($manejador, $fkOff . ";\n\n");

            foreach ($this->tablas($pdo, $esMysql) as $tabla) {
                $this->escribirTabla($manejador, $pdo, $esMysql, (string) $tabla);
            }

            // Se restaura la sesión a su valor anterior (si se pudo leer) y se
            // escribe la orden para el importador dentro del propio archivo.
            if ($esMysql && $fkAnterior !== null) {
                $pdo->exec($fkAnterior === '1' ? 'SET FOREIGN_KEY_CHECKS=1' : 'SET FOREIGN_KEY_CHECKS=0');
            } else {
                $pdo->exec($fkOn);
            }

            $this->escribir($manejador, $fkOn . ";\n\n-- Fin del respaldo.\n");

            // El commit se confirma DENTRO del try: si falla, el archivo se
            // limpia y la operación queda como FALLIDO.
            $pdo->exec('COMMIT');
        } catch (\Throwable $e) {
            try {
                $pdo->exec('ROLLBACK');
            } catch (\Throwable $e2) {
                // La conexión pudo quedar rota; se ignora el cierre de rollback.
            }

            fclose($manejador);
            @unlink($absolutePath);

            if ($esMysql) {
                $this->restaurarSesion($pdo, $unbufferedAnterior);
            }

            // No se exponen detalles del SQL ni de la base al usuario.
            Log::warning('PdoDumper falló: ' . $e->getMessage());
            throw new BackupException('No fue posible completar el respaldo con el generador PHP/PDO.');
        }

        fclose($manejador);

        if ($esMysql) {
            $this->restaurarSesion($pdo, $unbufferedAnterior);
        }
    }

    /**
     * Escapa un identificador SQL rodeándolo de backticks (los backticks
     * internos se duplican según la sintaxis de MySQL).
     */
    public static function ident(string $identificador): string
    {
        return '`' . str_replace('`', '``', $identificador) . '`';
    }

    /**
     * Escribe una cadena y falla si el streameo no pudo registrar TODO el
     * contenido (fwrite false o escritura corta). Un archivo parcial NUNCA
     * debe quedar como copia válida.
     */
    private function escribir($manejador, string $texto): void
    {
        if ($texto === '') {
            return;
        }

        $bytes = fwrite($manejador, $texto);

        if ($bytes === false || $bytes < strlen($texto)) {
            throw new BackupException('No fue posible escribir el archivo de respaldo.');
        }
    }

    /**
     * Lista las tablas base de la base de datos actual.
     */
    private function tablas(PDO $pdo, bool $esMysql): array
    {
        if ($esMysql) {
            $stmt = $pdo->prepare(
                "SELECT TABLE_NAME FROM information_schema.tables"
                . " WHERE TABLE_SCHEMA = :db AND TABLE_TYPE = 'BASE TABLE'"
                . " ORDER BY TABLE_NAME"
            );
            $stmt->execute([':db' => $this->database()]);

            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        }

        $stmt = $pdo->query(
            "SELECT name FROM sqlite_master"
            . " WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"
            . " ORDER BY name"
        );

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Escribe la definición y los datos de una tabla (por filas).
     */
    private function escribirTabla($manejador, PDO $pdo, bool $esMysql, string $tabla): void
    {
        $ident = self::ident($tabla);

        $this->escribir($manejador, 'DROP TABLE IF EXISTS ' . $ident . ";\n");

        if ($esMysql) {
            // La definición incluye claves, índices, FKs, engine y charset.
            $stmt = $pdo->query('SHOW CREATE TABLE ' . $ident);
            $fila = $stmt->fetch(PDO::FETCH_NUM);
            $stmt->closeCursor();

            if (!$fila || empty($fila[1])) {
                throw new BackupException('Definición de tabla no recuperada.');
            }

            $this->escribir($manejador, $fila[1] . ";\n\n");
        } else {
            // SQLite: la definición ya está en sqlite_master.sql.
            $stmt = $pdo->prepare(
                "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?"
            );
            $stmt->execute([$tabla]);
            $sql = $stmt->fetchColumn();

            if (!is_string($sql) || $sql === '') {
                throw new BackupException('Definición de tabla no recuperada.');
            }

            $this->escribir($manejador, rtrim($sql, ";\n") . ";\n\n");
        }

        // Solo MySQL: nombres de columnas BLOB/binarias para volcarlas en hex.
        $binarias = $esMysql ? $this->columnasBinarias($pdo, $tabla) : [];

        // SELECT * por filas: se escribe cada INSERT a medida que se lee.
        $stmt = $pdo->query('SELECT * FROM ' . $ident);

        $primerRegistro = true;
        $listaColumnas = '';

        while (($fila = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            if ($primerRegistro) {
                $columnas = array_keys($fila);
                $listaColumnas = implode(
                    ', ',
                    array_map(static fn ($c) => self::ident((string) $c), $columnas)
                );
                $primerRegistro = false;
            }

            $partes = [];

            foreach ($fila as $columna => $valor) {
                $partes[] = $this->formatearValor($valor, isset($binarias[$columna]));
            }

            $this->escribir(
                $manejador,
                'INSERT INTO ' . $ident . ' (' . $listaColumnas . ') VALUES ('
                . implode(', ', $partes) . ");\n"
            );
        }

        $stmt->closeCursor();

        if (!$primerRegistro) {
            $this->escribir($manejador, "\n");
        }
    }

    /**
     * Nombres de columnas de tipo binario/BLOB de una tabla (MySQL).
     *
     * @return array<string, int> mapa columna => 1 para consulta por isset().
     */
    private function columnasBinarias(PDO $pdo, string $tabla): array
    {
        $stmt = $pdo->prepare(
            "SELECT COLUMN_NAME FROM information_schema.columns"
            . " WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :tabla"
            . " AND DATA_TYPE IN ('binary','varbinary','tinyblob','blob','mediumblob','longblob')"
        );
        $stmt->execute([':db' => $this->database(), ':tabla' => $tabla]);

        $columnas = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

        return array_flip($columnas);
    }

    /**
     * Representa un valor SQL: NULL literal, 0x… para binario, o valor
     * escapado con PDO::quote().
     */
    private function formatearValor(mixed $valor, bool $binaria = false): string
    {
        if ($valor === null) {
            return 'NULL';
        }

        if ($binaria) {
            // Literal hexadecimal restaurable en MySQL (como --hex-blob).
            return '0x' . bin2hex((string) $valor);
        }

        return $this->pdo()->quote((string) $valor);
    }

    /**
     * Devuelve el modo unbuffered de la conexión compartida a su valor previo.
     */
    private function restaurarSesion(PDO $pdo, $unbufferedAnterior): void
    {
        if ($unbufferedAnterior === null) {
            return;
        }

        try {
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $unbufferedAnterior);
        } catch (\Throwable $e) {
            // Nunca se debe fallar el respaldo por un ajuste de atributos.
        }
    }

    private function driver(): string
    {
        return (string) $this->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    private function pdo(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        $conn = $this->connection ?? (string) (config('backup.connection') ?: config('database.default'));
        $this->pdo = DB::connection($conn)->getPdo();
        $this->connection = $conn;

        return $this->pdo;
    }

    private function database(): string
    {
        if ($this->database === null) {
            $conn = $this->connection ?? (string) (config('backup.connection') ?: config('database.default'));
            $this->database = (string) (config('database.connections.' . $conn . '.database') ?? '');
        }

        return $this->database;
    }
}