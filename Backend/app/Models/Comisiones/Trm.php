<?php

namespace App\Models\Comisiones;

use Illuminate\Database\Eloquent\Model;

class Trm extends Model
{
        protected $connection = 'budget';

    protected $fillable = [
        'date',
        'value',
    ];

    protected $casts = [
        'date' => 'date',
        'value' => 'float',
    ];
}
