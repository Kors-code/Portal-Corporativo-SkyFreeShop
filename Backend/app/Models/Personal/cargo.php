<?php

namespace App\Models\Personal;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class cargo extends Model
{
    use HasFactory;
    
    protected $connection = 'mysql_personal';

    protected $table = 'cargos';

    protected $fillable = ['nombre','area','funcion','jornada','tipo_contrato'];

    public function historial()
    {
        return $this->hasMany(HistorialCargo::class, 'cargo_id');
    }
}
