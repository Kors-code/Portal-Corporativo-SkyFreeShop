<?php

namespace App\Models\Personal;

use Illuminate\Database\Eloquent\Model;

class WishItem extends Model
{
    protected $connection = 'mysql_personal';
    protected $table = 'wish_items';

    protected $fillable = [
        'product_text',
        'category',
        'catalog_product_id',
        'indicator',
        'status',
        'user_id',
        'meta',
        'count'
    ];

    protected $casts = [
        'meta' => 'array',
        'count' => 'integer'
    ];

    public function catalogProduct()
    {
        return $this->belongsTo(CatalogProduct::class, 'catalog_product_id');
    }
}
