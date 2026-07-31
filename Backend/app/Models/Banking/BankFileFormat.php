<?php

namespace App\Models\Banking;

use Illuminate\Database\Eloquent\Model;

class BankFileFormat extends Model
{
    protected $connection = 'budget';

    protected $fillable = [
        'bank_id',
        'code',
        'name',
        'source_type',
        'parser_class',
        'is_active',
        'metadata',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    public function bank()
    {
        return $this->belongsTo(Bank::class, 'bank_id');
    }
}
