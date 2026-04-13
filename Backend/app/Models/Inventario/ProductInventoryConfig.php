<?php

namespace App\Models\Inventario;

use Illuminate\Database\Eloquent\Model;
use App\Models\Product;

class ProductInventoryConfig extends Model
{

protected $connection = 'budget';
    protected $fillable = [
        'product_id',
        'factor_caja',
        'lead_time',
        'tipo_abastecimiento',
        'stock_seguridad',
        'multiplo_compra',
        'minimo_compra'
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}