<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CajaApertura extends Model
{
    // Apuntamos explícitamente a tu tabla original
    protected $table = 'caja_apertura';
    
    // Desactivamos los timestamps automáticos de Laravel porque usas tu propia columna 'fecha'
    public $timestamps = false;

    protected $fillable = [
        'fecha', 
        'cajero', 
        'turno', 
        'caja_id', 
        'monto_inicial', 
        'conteo_json', 
        'estado', 
        'usuario_id'
    ];

    // Casteamos automáticamente el JSON y los numéricos
    protected $casts = [
        'conteo_json' => 'array',
        'monto_inicial' => 'float',
        'fecha' => 'datetime'
    ];
}