<?php

namespace App\Models\Personal;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CatalogProduct extends Model
{
    protected $connection = 'mysql_personal';
    protected $table = 'catalog_products';

    protected $fillable = [
        'sku',
        'product',
        'category',
        'brand',
        'supplier',
        'cost_unit',
        'price_sale'
    ];
}

