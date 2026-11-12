<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Codigo2FAAdministrado extends Model
{
    protected $table = 'codigos_2fa_administrados';
    public $timestamps = false;

    protected $fillable = [
        'usuario_id',
        'codigo_hash',
        'generado_por',
        'generado_en',
        'expira_en',
        'usado_en',
        'usado',
        'intentos',
        'ip_entrega',
    ];

    protected $casts = [
        'generado_en' => 'datetime',
        'expira_en' => 'datetime',
        'usado_en' => 'datetime',
        'usado' => 'boolean',
    ];

    public function usuario()
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    public function generador()
    {
        return $this->belongsTo(User::class, 'generado_por');
    }
}
