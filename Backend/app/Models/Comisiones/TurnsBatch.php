<?php

namespace App\Models\Comisiones;

use Illuminate\Database\Eloquent\Model;

class TurnsBatch extends Model
{
        protected $connection = 'budget';

    protected $table = 'turns_batches';
    protected $guarded = [];
    protected $casts = [
        'errors' => 'array',
    ];

    public function turns()
    {
        return $this->hasMany(BudgetUserTurn::class, 'batch_id');
    }

    public function creator()
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }
}
