<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Almacenamiento de los respaldos
    |--------------------------------------------------------------------------
    |
    | 'disk'   : disco de Laravel donde se guardan las copias. El disco 'local'
    |            apunta a storage/app/private, fuera de public/.
    | 'path'   : subdirectorio dentro del disco.
    |
    */

    'disk' => env('BACKUP_DISK', 'local'),
    'path' => env('BACKUP_PATH', 'backups'),

    /*
    |--------------------------------------------------------------------------
    | Conexión de base de datos a respaldar
    |--------------------------------------------------------------------------
    |
    | Por defecto se usa la conexión por defecto de la aplicación (sistemadecaja).
    | Útil para apuntar a una base aislada en entornos de prueba/restauración.
    |
    */

    'connection' => env('BACKUP_DB_CONNECTION'),

    /*
    |--------------------------------------------------------------------------
    | Herramienta de volcado (mysqldump)
    |--------------------------------------------------------------------------
    |
    | 'binary'  : ruta absoluta del ejecutable o nombre simple si está en el PATH.
    |             En este equipo el ejecutable existe en:
    |             C:\Program Files\MySQL\MySQL Server 8.0\bin\mysqldump.exe
    |             pero NO está en el PATH, por lo que conviene configurarlo.
    | 'timeout' : segundos máximos de ejecución del proceso.
    |
    */

    'mysqldump' => [
        'binary' => env('BACKUP_MYSQLDUMP_PATH', 'mysqldump'),
        'timeout' => (int) env('BACKUP_TIMEOUT', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Validación de integridad
    |--------------------------------------------------------------------------
    |
    | 'min_bytes' : tamaño mínimo aceptable de un volcado. Un archivo menor se
    |               considera vacío/incompleto y la copia queda como FALLIDO.
    |
    */

    'min_bytes' => (int) env('BACKUP_MIN_BYTES', 2048),

    /*
    |--------------------------------------------------------------------------
    | Concurrencia
    |--------------------------------------------------------------------------
    |
    | 'lock_seconds' : duración del candado atómico que evita dos respaldos (o
    |                  un respaldo y una eliminación) simultáneos.
    |
    */

    'lock_seconds' => (int) env('BACKUP_LOCK_SECONDS', 60),
];