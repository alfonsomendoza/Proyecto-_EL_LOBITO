<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Rol extends Model
{
    // Apuntamos explícitamente a tu tabla de roles
    protected $table = 'roles';
    
    // Desactivamos los timestamps porque tu tabla no usa created_at / updated_at
    public $timestamps = false;

    protected $fillable = [
        'nombre'
    ];
}