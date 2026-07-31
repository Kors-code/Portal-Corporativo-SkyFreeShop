<?php

namespace App\Models\Banking;

use Illuminate\Database\Eloquent\Model;

class Bank extends Model
{
    protected $connection = 'budget';

    protected $fillable = [
        'code',
        'name',
        'is_active',
        'metadata',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];
}
