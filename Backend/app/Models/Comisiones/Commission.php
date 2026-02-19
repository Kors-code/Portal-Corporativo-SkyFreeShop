<?php

namespace App\Models\Comisiones;


use Illuminate\Database\Eloquent\Model;

class Commission extends Model
{
        protected $connection = 'budget';

    protected $fillable = [
        'sale_id',
        'user_id',
        'rule_id',
        'commission_amount',
        'is_provisional',
        'calculated_as',
    ];

    protected $casts = [
        'is_provisional' => 'boolean',
        'commission_amount' => 'decimal:2',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function rule()
    {
        return $this->belongsTo(CategoryCommission::class, 'rule_id');
    }
}
