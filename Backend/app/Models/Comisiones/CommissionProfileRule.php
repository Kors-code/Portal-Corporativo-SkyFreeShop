<?php

namespace App\Models\Comisiones;

use Illuminate\Database\Eloquent\Model;

class CommissionProfileRule extends Model
{
    protected $connection = 'budget';

    protected $fillable = [
        'commission_profile_id',
        'rule_type',
        'provider_name',
        'category_id',
        'category_code',
        'brand',
        'product_code',
        'commission_percentage',
        'commission_percentage100',
        'commission_percentage120',
    ];

    protected $casts = [
        'commission_percentage' => 'float',
        'commission_percentage100' => 'float',
        'commission_percentage120' => 'float',
    ];

    public function profile()
    {
        return $this->belongsTo(CommissionProfile::class, 'commission_profile_id');
    }
}
