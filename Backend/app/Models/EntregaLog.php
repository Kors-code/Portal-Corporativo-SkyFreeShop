<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EntregaLog extends Model
{
    use HasFactory;

    protected $connection = 'mysql_personal';

    protected $table = 'entrega_log';

    public $timestamps = false;

    protected $fillable = [
        'entrega_id',
        'empleado_id',
        'accion',
        'detalles',
        'ip_address',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
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
