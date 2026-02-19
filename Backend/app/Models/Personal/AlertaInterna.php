<?php

namespace App\Models\Personal;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AlertaInterna extends Model
{
    use HasFactory;
    
    protected $connection = 'mysql_personal';

    protected $table = 'alertas_internas';

    protected $fillable = [
        'codigo',
        'descripcion'
    ];
}
