<?php

namespace App\Models\Comisiones;

use Illuminate\Database\Eloquent\Model;

class CategoryTierCommission extends Model
{
    protected $table = 'category_tier_commissions';
        protected $connection = 'budget';


    protected $fillable = [
        'tier_id', 'role_id', 'commission_percentage'
    ];

    public function tier()
    {
        return $this->belongsTo(CategoryCommissionTier::class, 'tier_id');
    }

    public function role()
    {
        return $this->belongsTo(\Spatie\Permission\Models\Role::class, 'role_id'); // or App\Models\Role depending on your implementation
    }
}
