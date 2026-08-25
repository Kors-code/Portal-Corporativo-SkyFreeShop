<?php

namespace App\Models\PassengerIntelligence;

use Illuminate\Database\Eloquent\Model;

class PassengerMonthlyFact extends Model
{
    protected $connection = 'budget';
    protected $table = 'passenger_intelligence_monthly_facts';

    protected $fillable = [
        'year',
        'month',
        'airport_iata',
        'direction',
        'fact_type',
        'source_type',
        'value',
        'records_count',
        'source_file_id',
        'import_batch_id',
        'source_name',
        'source_url',
        'source_period',
        'confidence_level',
        'metadata',
    ];

    protected $casts = [
        'year' => 'integer',
        'month' => 'integer',
        'value' => 'decimal:2',
        'records_count' => 'integer',
        'metadata' => 'array',
    ];
}
