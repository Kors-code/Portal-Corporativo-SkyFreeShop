<?php

namespace App\Models\Banking;

use Illuminate\Database\Eloquent\Model;

class BankAccount extends Model
{
    protected $connection = 'budget';

    protected $fillable = [
        'bank_id',
        'account_number',
        'account_type',
        'name',
        'accounting_account',
        'accounting_name',
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
