<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsappReportJob extends Model
{
    protected $fillable = [
        'type',
        'status',
        'report_date',
        'payload',
        'attempts',
        'last_error',
        'available_at',
        'locked_at',
        'sent_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'report_date' => 'date:Y-m-d',
        'available_at' => 'datetime',
        'locked_at' => 'datetime',
        'sent_at' => 'datetime',
    ];
}
