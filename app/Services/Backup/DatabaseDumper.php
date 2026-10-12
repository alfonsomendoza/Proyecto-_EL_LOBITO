<?php

namespace App\Services\Backup;

/**
 * Contrato que aísla el módulo del programa externo de volcado.
 *
 * Permite sustituir la implementación en las pruebas por una simulada,
 * de modo que NUNCA se invoca mysqldump real contra "sistemadecaja".
 */
interface DatabaseDumper
{
    /**
     * Indica si la herramienta configurada está disponible.
     */
    public function isAvailable(): bool;

    /**
     * Genera el volcado de la base de datos en la ruta absoluta indicada.
     *
     * @throws BackupException si el proceso falla, se agota el tiempo o la
     *                         herramienta no está disponible.
     */
    public function dumpTo(string $absolutePath): void;
}