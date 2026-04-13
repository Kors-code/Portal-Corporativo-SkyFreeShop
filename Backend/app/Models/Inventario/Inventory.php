<?php

namespace App\Models\Inventario;

use Illuminate\Database\Eloquent\Model;
use App\Models\Product;

class Inventory extends Model
{
    protected $connection = 'budget';
    protected $table = 'inventory';

    protected $fillable = [
        'product_id',
        'store_id',
        'existencia_anterior',
        'compras',
        'ventas',
        'entrada',
        'salida',
        'existencia_final',
        'factor_caja',
        'costo_unitario',
        'total_inv_final',
        'costo_unitario_usd',
        'valor_final_usd',
        't_cambio',
        'cogs',
        'proveedor',
        'supplier',
        'brand',
        'upc1',
        'upc2',
        'upc3',
        'retail',
        'pct_costo',
        'pct_margen',
        'toDate',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }
}