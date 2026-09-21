<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Cliente extends Model
{
    protected $table = 'clientes';
    public $timestamps = false;

    // Ajusta estos campos si los nombres en tu tabla original son diferentes
    protected $fillable = ['nombre', 'telefono', 'identificacion', 'direccion', 'activo'];
}