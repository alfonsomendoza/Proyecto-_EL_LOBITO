<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditoriaSeguridad extends Model
{
    protected $table = 'auditoria_seguridad';
    public $timestamps = false;

    protected $fillable = [
        'usuario_id',
        'evento',
        'ip',
        'user_agent',
        'motivo',
        'detalles',
        'creado_en',
    ];

    protected $casts = [
        'detalles' => 'array',
        'creado_en' => 'datetime',
    ];

    public function usuario()
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}
