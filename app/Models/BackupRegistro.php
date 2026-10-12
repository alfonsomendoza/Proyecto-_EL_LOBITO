<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Historial y auditoría de las operaciones del módulo de respaldo.
 *
 * Estados: EN_PROCESO | COMPLETADO | FALLIDO | ELIMINADO.
 * Nunca almacena credenciales ni contenido sensible del volcado.
 */
class BackupRegistro extends Model
{
    protected $table = 'backup_registros';

    protected $fillable = [
        'usuario_id',
        'ip',
        'tipo',
        'estado',
        'nombre_archivo',
        'hash_sha256',
        'tamano_bytes',
        'duracion_ms',
        'mensaje',
        'iniciado_en',
        'finalizado_en',
    ];

    protected $casts = [
        'usuario_id' => 'integer',
        'tamano_bytes' => 'integer',
        'duracion_ms' => 'integer',
        'iniciado_en' => 'datetime',
        'finalizado_en' => 'datetime',
    ];

    public function usuario()
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}