<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FirmaDigital extends Model
{
    use HasFactory;

    protected $connection = 'mysql_personal';

    protected $table = 'firmas_digitales';

    protected $fillable = [
        'entrega_id',
        'empleado_id',
        'tipo_firma',
        'firma_data',
        'formato',
        'ip_address',
        'user_agent',
        'fecha_firma',
    ];

    protected $casts = [
        'fecha_firma' => 'datetime',
    ];

    public function entrega()
    {
        return $this->belongsTo(Entrega::class);
    }

    public function empleado()
    {
        return $this->belongsTo(Empleado::class);
    }
}
