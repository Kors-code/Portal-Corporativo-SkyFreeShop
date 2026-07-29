<?php

namespace App\Models\Banking;

use Illuminate\Database\Eloquent\Model;

class BankMovement extends Model
{
    protected $connection = 'budget';

    protected $fillable = [
        'batch_id',
        'bank',
        'source_type',
        'row_number',
        'movement_date',
        'process_date',
        'deposit_date',
        'movement_time',
        'account_number',
        'branch_code',
        'transaction_code',
        'reference',
        'receipt_number',
        'authorization_number',
        'terminal',
        'network',
        'card_type',
        'card_last_digits',
        'counterparty',
        'description',
        'movement_type',
        'category',
        'currency',
        'sale_amount',
        'commission_amount',
        'withholding_amount',
        'withholding_source_amount',
        'withholding_vat_amount',
        'withholding_ica_amount',
        'vat_amount',
        'consumption_tax_amount',
        'tip_amount',
        'income_amount',
        'debit_amount',
        'credit_amount',
        'net_amount',
        'is_sale',
        'is_income',
        'is_expense',
        'is_excluded',
        'exclude_reason',
        'raw_payload',
    ];

    protected $casts = [
        'movement_date' => 'date',
        'process_date' => 'date',
        'deposit_date' => 'date',
        'raw_payload' => 'array',
        'is_sale' => 'boolean',
        'is_income' => 'boolean',
        'is_expense' => 'boolean',
        'is_excluded' => 'boolean',
        'sale_amount' => 'decimal:2',
        'commission_amount' => 'decimal:2',
        'withholding_amount' => 'decimal:2',
        'withholding_source_amount' => 'decimal:2',
        'withholding_vat_amount' => 'decimal:2',
        'withholding_ica_amount' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'consumption_tax_amount' => 'decimal:2',
        'tip_amount' => 'decimal:2',
        'income_amount' => 'decimal:2',
        'debit_amount' => 'decimal:2',
        'credit_amount' => 'decimal:2',
        'net_amount' => 'decimal:2',
    ];

    public function batch()
    {
        return $this->belongsTo(BankImportBatch::class, 'batch_id');
    }
}
