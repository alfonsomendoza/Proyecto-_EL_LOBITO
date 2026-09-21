<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    // Apuntamos a tu tabla real
    protected $table = 'usuarios';
    public $timestamps = false;

    // Los campos que se pueden llenar
    protected $fillable = [
        'nombre', 
        'username', 
        'password_hash', 
        'rol_id'
    ];

    // Laravel busca la columna 'password' por defecto, le decimos que use la tuya
    public function getAuthPassword()
    {
        return $this->password_hash;
    }

    // Relación para obtener el nombre del ROL automáticamente
    public function rol()
    {
        return $this->belongsTo(Rol::class, 'rol_id');
    }
}