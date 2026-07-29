<?php

namespace App\Models\Banking;

use Illuminate\Database\Eloquent\Model;

class BankCashReceipt extends Model
{
    protected $connection = 'budget';

    protected $fillable = [
        'batch_id',
        'bank',
        'receipt_date',
        'receipt_number',
        'sale_amount',
        'commission_amount',
        'withholding_amount',
        'income_amount',
        'generated_filename',
        'generated_path',
        'metadata',
    ];

    protected $casts = [
        'receipt_date' => 'date',
        'metadata' => 'array',
        'sale_amount' => 'decimal:2',
        'commission_amount' => 'decimal:2',
        'withholding_amount' => 'decimal:2',
        'income_amount' => 'decimal:2',
    ];
}
