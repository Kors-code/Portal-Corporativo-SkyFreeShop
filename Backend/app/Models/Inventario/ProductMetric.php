<?php

namespace App\Models\Inventario;

use Illuminate\Database\Eloquent\Model;
use App\Models\Product;

class ProductMetric extends Model
{

protected $connection = 'budget';
    protected $fillable = [
        'product_id',
        'maximo_mes',
        'maximo_dia',
        'promedio_diario',
        'rotacion',
        'total_ventas'
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}