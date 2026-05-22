<?php

namespace App\Models\Inventario;

use Illuminate\Database\Eloquent\Model;

class InventoryImportBatch extends Model
{
    protected $connection = 'budget';
    protected $table = 'inventory_import_batches';

    protected $fillable = [
        'filename',
        'store_id',
        'to_date',
        'rows_imported',
        'status',
        'checksum',
        'notes',
    ];

    protected $casts = [
        'to_date' => 'date',
        'rows_imported' => 'integer',
        'store_id' => 'integer',
    ];

    public function items()
    {
        return $this->hasMany(Inventory::class, 'batch_id');
    }
}