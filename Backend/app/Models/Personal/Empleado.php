<?php

namespace App\Models\Personal;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Personal\historialcargo; // ✅ asegúrate de importar el modelo correcto
use App\Models\Personal\Llamado;        // ✅ si Llamado está en la misma carpeta

class Empleado extends Model
{
    use HasFactory, SoftDeletes;

    // ✅ Aquí va el punto y coma al final
    protected $connection = 'mysql_personal';
    protected $table = 'empleados';

    protected $fillable = [
        'colaborador', 'cedula', 'estado', 'fecha_ingreso', 'rh', 'genero', 'edad', 'fecha_nacimiento',
        'contacto', 'email', 'ciudad_residencia', 'direccion', 'nivel_academico', 'profesion', 'nivel_ingles',
        'hijos', 'vehiculo', 'tipo_vivienda', 'estrato', 'estado_civil', 'eps', 'caja_pension', 'cesantias',
        'jefe_inmediato', 'sede', 'antiguedad', 'fecha_retiro', 'causa_retiro', 'motivo_retiro'
    ];

    public function historialCargos()
    {
        return $this->hasMany(HistorialCargo::class, 'empleado_id');
    }

    public function llamados()
    {
        return $this->hasMany(Llamado::class, 'empleado_id');
    }

    public function positionAtDate($date)
    {
        return $this->historialCargos()
            ->where('fecha_ingreso', '<=', $date)
            ->where(function($q) use ($date) {
                $q->where('fecha_retiro', '>=', $date)
                  ->orWhereNull('fecha_retiro');
            })
            ->orderBy('fecha_ingreso', 'desc')
            ->first();
    }
}
