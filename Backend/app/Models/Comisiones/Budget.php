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
    'closed_at',
    'is_closed',
];

protected $casts = [
    'is_closed' => 'boolean',
    'closed_at' => 'datetime',
];
public function userRoles()
{
    return $this->hasMany(UserRoleBudget::class, 'budget_id');
}


}

