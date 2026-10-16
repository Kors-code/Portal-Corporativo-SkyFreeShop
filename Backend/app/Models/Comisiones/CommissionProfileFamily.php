<?php

namespace App\Models\Comisiones;

use Illuminate\Database\Eloquent\Model;

class CommissionProfileFamily extends Model
{
    protected $connection = 'budget';

    protected $fillable = ['status', 'status_changed_at'];

    protected $casts = ['status_changed_at' => 'datetime'];

    public function versions()
    {
        return $this->hasMany(CommissionProfile::class, 'family_id');
    }
}
