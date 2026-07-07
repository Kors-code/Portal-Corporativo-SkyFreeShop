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
        'sku_mia',
        'upc',
        'upc2',
        'upc3',
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
        'status',
        'origin',
        'line',
        'ref',
        'cost_unit',
        'retail_price_cop',
        'precio_mx',
        'codigo_sat',
        'purchase_unit',
        'sales_unit',
        'product_use',
        'fraction',
        'brand_code',
        'color',
        'size',
        'origine',
        'prepak',
        'cumt',
        'umt',
        'aduana_description',
        'no_constancia',
        'tamano_etiqueta',
        'complete_classification',
        'factor_caja',
        'make_country',
        'iva_venta',
        'objeto_de_impuesto',
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
