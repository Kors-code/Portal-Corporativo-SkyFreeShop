<?php

namespace App\Models\Comisiones;

use Illuminate\Database\Eloquent\Model;

class CommissionProfile extends Model
{
    protected $connection = 'budget';

    protected $fillable = [
        'budget_id',
        'name',
        'profile_type',
        'commission_mode',
        'category_role_id',
        'commission_percentage',
        'commission_percentage100',
        'commission_percentage120',
        'minimum_fulfillment_pct',
        'target_amount_usd',
        'is_active',
        'valid_from',
        'valid_to',
        'note',
        'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'category_role_id' => 'integer',
        'commission_percentage' => 'float',
        'commission_percentage100' => 'float',
        'commission_percentage120' => 'float',
        'minimum_fulfillment_pct' => 'float',
        'target_amount_usd' => 'float',
        'valid_from' => 'date',
        'valid_to' => 'date',
    ];

    public function rules()
    {
        return $this->hasMany(CommissionProfileRule::class);
    }

    public function assignments()
    {
        return $this->hasMany(CommissionProfileUser::class);
    }
}
