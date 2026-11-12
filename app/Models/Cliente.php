<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Cliente extends Model
{
    protected $table = 'clientes';
    public $timestamps = false;

    /*
     * La tabla real usa `creado_en` (no created_at/updated_at) y, si la
     * migración de normalización está aplicada, también guarda las columnas
     * `identificacion_normalizada` y `telefono_normalizado`. Se declaran en
     * $fillable para que create()/fill() no descarten esos valores.
     */
    protected $fillable = [
        'nombre',
        'telefono',
        'identificacion',
        'direccion',
        'activo',
        'identificacion_normalizada',
        'telefono_normalizado',
        'creado_en',
    ];
}