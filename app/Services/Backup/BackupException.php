<?php

namespace App\Services\Backup;

/**
 * Excepción controlada del módulo de respaldo.
 *
 * Los mensajes nunca contienen credenciales ni datos sensibles.
 */
class BackupException extends \RuntimeException
{
}