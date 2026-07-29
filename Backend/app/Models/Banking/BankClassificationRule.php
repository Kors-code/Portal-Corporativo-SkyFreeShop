<?php

namespace App\Models\Banking;

use Illuminate\Database\Eloquent\Model;

class BankClassificationRule extends Model
{
    protected $connection = 'budget';

    protected $fillable = [
        'bank',
        'transaction_code',
        'description_contains',
        'category',
        'counts_as_sale',
        'counts_as_income',
        'counts_as_expense',
        'amount_target',
        'is_active',
        'priority',
        'notes',
    ];

    protected $casts = [
        'counts_as_sale' => 'boolean',
        'counts_as_income' => 'boolean',
        'counts_as_expense' => 'boolean',
        'is_active' => 'boolean',
    ];
}
