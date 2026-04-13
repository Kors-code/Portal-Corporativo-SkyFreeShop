<?php

namespace App\Models\Inventario;

use Illuminate\Database\Eloquent\Model;

class Store extends Model
{
    protected $connection = 'budget';

    protected $fillable = [
        'name',
        'code',
        'type'
    ];

    public function inventories()
    {
        return $this->hasMany(Inventory::class);
    }
}