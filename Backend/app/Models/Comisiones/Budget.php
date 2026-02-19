<?php

namespace App\Models\Comisiones;

use Illuminate\Database\Eloquent\Model;

class Budget extends Model
{
        protected $connection = 'budget';


    protected $fillable = [
    'name',
    'target_amount',
    'total_turns',
    'start_date',
    'end_date',
];
public function userRoles()
{
    return $this->hasMany(UserRoleBudget::class, 'budget_id');
}


}

