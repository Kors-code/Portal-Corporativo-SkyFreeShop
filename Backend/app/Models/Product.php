<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Inventario\Inventory;
use App\Models\Inventario\ProductInventoryConfig;
use App\Models\Inventario\ProductMetric;
use App\Models\Inventario\Supplier;

class Product extends Model
{
    protected $connection = 'budget';

    protected $fillable = [
        'product_code',
        'upc',
        'description',
        'brand',
        'classification',
        'classification_desc',
        'provider_code',
        'provider_name',
        'regular_price',
        'cost_usd',
        'avg_cost_usd',
        'currency',
        'type',
        'supplier_id'
    ];

    public function inventories()
    {
        return $this->hasMany(Inventory::class);
    }

    public function config()
    {
        return $this->hasOne(ProductInventoryConfig::class);
    }

    public function metrics()
    {
        return $this->hasOne(ProductMetric::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }
}