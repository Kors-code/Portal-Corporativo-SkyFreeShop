<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Empleado extends Model
{
    use HasFactory, SoftDeletes;

    protected $connection = 'mysql_personal';

    protected $table = 'empleados';

    protected $fillable = [
        'colaborador',
        'cedula',
        'estado',
        'fecha_ingreso',
        'rh',
        'genero',
        'edad',
        'fecha_nacimiento',
        'contacto',
        'email',
        'ciudad_residencia',
        'direccion',
        'nivel_academico',
        'profesion',
        'nivel_ingles',
        'hijos',
        'vehiculo',
        'tipo_vivienda',
        'estrato',
        'estado_civil',
        'eps',
        'caja_pension',
        'cesantias',
        'jefe_inmediato',
        'sede',
        'antiguedad',
        'fecha_retiro',
        'causa_retiro',
        'motivo_retiro',
        'firma_personal',
    ];

    protected $casts = [
        'fecha_ingreso' => 'date',
        'fecha_nacimiento' => 'date',
        'fecha_retiro' => 'date',
        'hijos' => 'boolean',
        'vehiculo' => 'boolean',
    ];

    protected $hidden = [
        'firma_personal',
    ];

    // Relaciones
    public function entregasRealizadas()
    {
        return $this->hasMany(Entrega::class, 'lider_entrega_id');
    }

    public function entregasRecibidas()
    {
        return $this->hasMany(Entrega::class, 'lider_recibe_id');
    }

    public function firmas()
    {
        return $this->hasMany(FirmaDigital::class);
    }

    // Scopes
    public function scopeActivos($query)
    {
        return $query->where('estado', 'ACTIVO');
    }

    public function scopeLideres($query)
    {
        return $query->where('estado', 'ACTIVO')
                     ->whereNotNull('email')
                     ->where('email', '!=', '');
    }
}
