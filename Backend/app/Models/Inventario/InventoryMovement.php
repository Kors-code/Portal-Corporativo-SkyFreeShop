<?php

namespace App\Models\Inventario;

use Illuminate\Database\Eloquent\Model;
use App\Models\Product;

class InventoryMovement extends Model
{
    protected $connection = 'budget';
    protected $fillable = [
        'product_id',
        'type',
        'quantity',
        'reference_id',
        'note'
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}