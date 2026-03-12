<?php

namespace App\Models\Comisiones;

use Illuminate\Database\Eloquent\Model;

class UserCategoryBudget extends Model
{
    protected $connection = 'budget'; // 🔥 CLAVE

    protected $table = 'user_category_budgets';

    protected $fillable = [
        'budget_id',
        'user_id',
        'category_id',
        'category_classification',
        'budget_usd',
        'business_line',
    ];

    public $timestamps = true;
}