<?php

namespace App\Models\Inventario;

use Illuminate\Database\Eloquent\Model;
use App\Models\Product;

class Supplier extends Model
{

protected $connection = 'budget';
    protected $fillable = [
        'name',
        'origin',
        'lead_time',
        'tipo'
    ];

    public function products()
    {
        return $this->hasMany(Product::class);
    }
}