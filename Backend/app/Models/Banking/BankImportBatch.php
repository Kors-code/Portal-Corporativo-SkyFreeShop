<?php

namespace App\Models\Banking;

use Illuminate\Database\Eloquent\Model;

class BankImportBatch extends Model
{
    protected $connection = 'budget';

    protected $fillable = [
        'bank',
        'source_type',
        'filename',
        'stored_path',
        'checksum',
        'status',
        'rows',
        'rows_imported',
        'rows_skipped',
        'from_date',
        'to_date',
        'total_sale_amount',
        'total_commission_amount',
        'total_withholding_amount',
        'total_income_amount',
        'total_debit_amount',
        'total_credit_amount',
        'metadata',
        'note',
        'created_by',
    ];

    protected $casts = [
        'from_date' => 'date',
        'to_date' => 'date',
        'metadata' => 'array',
        'total_sale_amount' => 'decimal:2',
        'total_commission_amount' => 'decimal:2',
        'total_withholding_amount' => 'decimal:2',
        'total_income_amount' => 'decimal:2',
        'total_debit_amount' => 'decimal:2',
        'total_credit_amount' => 'decimal:2',
    ];

    public function movements()
    {
        return $this->hasMany(BankMovement::class, 'batch_id');
    }
}
