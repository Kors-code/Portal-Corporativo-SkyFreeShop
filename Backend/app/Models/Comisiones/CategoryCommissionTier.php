<?php

namespace App\Models\Comisiones;

use Illuminate\Database\Eloquent\Model;

class CategoryCommissionTier extends Model
{
    protected $table = 'category_commission_tiers';
        protected $connection = 'budget';


    protected $fillable = [
        'category_id', 'label', 'min_pct', 'max_pct', 'sort_order'
    ];

    public function category()
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function commissions()
    {
        return $this->hasMany(CategoryTierCommission::class, 'tier_id');
    }
}
