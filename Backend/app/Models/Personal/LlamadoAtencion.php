<?php

namespace App\Models\Personal;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LlamadoAtencion extends Model
{
    use HasFactory;

    protected $connection = 'mysql_personal';
    
    protected $table = 'llamados_atencion';

    protected $fillable = [
    'empleado_id',
    'nombre',
    'cedula',
    'cargo',
    'cargo_jefe',
    'jefe',
    'jefe_cedula',
    'fecha',
    'fecha_evento',
    'hora',
    'fase',
    'grupo',
    'orientacion',
    'detalle',
    'ruta_pdf',
    'codigo',
    'descripcion',
];


    public function empleado()
    {
        return $this->belongsTo(Empleado::class, 'empleado_id');
    }
}
