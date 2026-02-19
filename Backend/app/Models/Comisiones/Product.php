<?php
namespace App\Models\Comisiones;
use Illuminate\Database\Eloquent\Model;

class Product extends Model {
        protected $connection = 'budget';

   protected $fillable = [
    'product_code',
    'upc',
    'description',
    'classification',
    'classification_desc',
    'brand',
    'currency',
    'provider_code',
    'provider_name',
    'regular_price',
    'avg_cost_usd',
    'cost_usd',
];

    public function sales() { return $this->hasMany(Sale::class); }
}
