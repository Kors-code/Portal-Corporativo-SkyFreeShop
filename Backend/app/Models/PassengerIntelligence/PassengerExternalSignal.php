<?php

namespace App\Models\PassengerIntelligence;

use Illuminate\Database\Eloquent\Model;

class PassengerExternalSignal extends Model
{
    protected $connection = 'budget';
    protected $table = 'passenger_intelligence_external_signals';

    protected $fillable = [
        'date_from',
        'date_to',
        'signal_type',
        'name',
        'location',
        'source_name',
        'source_url',
        'source_published_at',
        'expected_impact',
        'impact_direction',
        'impact_score',
        'verification_status',
        'notes',
        'metadata',
    ];

    protected $casts = [
        'date_from' => 'date',
        'date_to' => 'date',
        'source_published_at' => 'date',
        'impact_score' => 'integer',
        'metadata' => 'array',
    ];
}
