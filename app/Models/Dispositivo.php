<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Dispositivo extends Model
{
    protected $table = 'dispositivos';
    public $timestamps = false;

    protected $fillable = [
        'identificador',
        'nombre',
        'token_dispositivo',
        'usuario_id',
        'ip_registro',
        'user_agent',
        'registrado_en',
        'ultimo_uso',
        'autorizado',
        'activo',
        'desautorizado_en',
        'desautorizado_por',
    ];

    protected $casts = [
        'autorizado' => 'boolean',
        'activo' => 'boolean',
        'registrado_en' => 'datetime',
        'ultimo_uso' => 'datetime',
        'desautorizado_en' => 'datetime',
    ];

    public function usuario()
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}
