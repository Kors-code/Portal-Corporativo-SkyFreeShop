<?php

namespace App\Models\Comisiones;

use Illuminate\Database\Eloquent\Model;

class BudgetUserTurn extends Model
{
        protected $connection = 'budget';

    protected $table = 'budget_user_turns';
    protected $guarded = [];
    public $timestamps = false; // si tu tabla no tiene timestamps
}
