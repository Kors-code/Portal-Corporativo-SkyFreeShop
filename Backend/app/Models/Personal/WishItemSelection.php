<?php

namespace App\Models\Personal;

use Illuminate\Database\Eloquent\Model;

class WishItemSelection extends Model
{
    protected $connection = 'mysql_personal';
    protected $table = 'wish_item_selections';

    protected $fillable = [
        'wish_item_id',
        'catalog_product_id',
        'user_id',
        'meta'
    ];

    protected $casts = [
        'meta' => 'array'
    ];

    public function wishItem()
    {
        return $this->belongsTo(WishItem::class, 'wish_item_id');
    }

    public function catalogProduct()
    {
        return $this->belongsTo(CatalogProduct::class, 'catalog_product_id');
    }
}
