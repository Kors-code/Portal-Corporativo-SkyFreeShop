<?php

namespace App\Models\PassengerIntelligence;

use Illuminate\Database\Eloquent\Model;

class PassengerCommercialExposureRate extends Model
{
    protected $connection = 'budget';
    protected $table = 'passenger_intelligence_commercial_exposure_rates';

    protected $fillable = [
        'year',
        'month',
        'airport_iata',
        'direction',
        'commercial_pax',
        'official_airport_pax',
        'exposure_pct',
        'method',
        'commercial_fact_id',
        'official_fact_id',
        'confidence_level',
        'notes',
        'calculated_at',
    ];

    protected $casts = [
        'year' => 'integer',
        'month' => 'integer',
        'commercial_pax' => 'decimal:2',
        'official_airport_pax' => 'decimal:2',
        'exposure_pct' => 'decimal:3',
        'notes' => 'array',
        'calculated_at' => 'datetime',
    ];
}
