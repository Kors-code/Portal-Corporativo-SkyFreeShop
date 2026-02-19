<?php

namespace App\Models\Personal;

use Illuminate\Database\Eloquent\Model;

class Usuario extends Model
{
    protected $connection = 'mysql_personal';
    protected $table = 'usuarios';
    protected $primaryKey = 'cedula';
    public $incrementing = false; // Si la clave primaria no es auto-incremental
    protected $keyType = 'string'; // Si la clave primaria no es un entero

    protected $fillable = [
        'cedula',
        'colaborador',
        'email',
        'cargo',
        'contacto',
    ];
}
