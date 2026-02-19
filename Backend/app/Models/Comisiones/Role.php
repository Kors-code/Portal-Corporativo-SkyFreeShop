<?php
namespace App\Models\Comisiones;
use Illuminate\Database\Eloquent\Model;

class Role extends Model { 
        protected $connection = 'budget';

    public function userBudgets()
{
    return $this->hasMany(UserRoleBudget::class, 'role_id');
}
}
