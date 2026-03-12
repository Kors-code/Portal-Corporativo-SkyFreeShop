<?php

namespace App\Models\Comisiones;

use Illuminate\Database\Eloquent\Model;

class AdvisorSpecialist extends Model
{
    protected $connection = 'budget';

    protected $table = 'advisor_specialists';

     protected $fillable = [
        'budget_id',
        'user_id',
        'business_line',
        'category_id',
        'valid_from',
        'valid_to',
        'created_by',
        'note'
    ];

    protected $dates = [
        'valid_from',
        'valid_to'
    ];

    public $timestamps = true;
}