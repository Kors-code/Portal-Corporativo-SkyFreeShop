<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdvisorBudget extends Model
{
    protected $connection = 'budget';
    protected $fillable = [
        'budget_id',
        'role_id',
        'budget_usd',
    ];
}