<?php

namespace App\Models\Banking;

use Illuminate\Database\Eloquent\Model;

class BankImportBatch extends Model
{
    protected $connection = 'budget';

    protected $fillable = [
        'bank_id',
        'file_format_id',
        'bank_account_id',
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

    public function bankCatalog()
    {
        return $this->belongsTo(Bank::class, 'bank_id');
    }

    public function fileFormat()
    {
        return $this->belongsTo(BankFileFormat::class, 'file_format_id');
    }

    public function bankAccount()
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }
}
