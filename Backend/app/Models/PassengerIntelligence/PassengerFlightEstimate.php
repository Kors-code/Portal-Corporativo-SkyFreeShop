<?php

namespace App\Models\PassengerIntelligence;

use Illuminate\Database\Eloquent\Model;

class PassengerFlightEstimate extends Model
{
    protected $connection = 'budget';
    protected $table = 'passenger_intelligence_flight_estimates';

    protected $fillable = [
        'flight_id',
        'composition_profile_id',
        'exposure_rate_id',
        'base_pax',
        'commercial_exposed_pax',
        'colombian_pct',
        'foreign_pct',
        'colombian_pax',
        'foreign_pax',
        'estimation_method',
        'confidence_level',
        'model_version',
        'input_sources',
        'explanation',
        'calculated_at',
    ];

    protected $casts = [
        'base_pax' => 'decimal:2',
        'commercial_exposed_pax' => 'decimal:2',
        'colombian_pct' => 'decimal:3',
        'foreign_pct' => 'decimal:3',
        'colombian_pax' => 'decimal:2',
        'foreign_pax' => 'decimal:2',
        'input_sources' => 'array',
        'explanation' => 'array',
        'calculated_at' => 'datetime',
    ];

    public function flight()
    {
        return $this->belongsTo(PassengerFlight::class, 'flight_id');
    }
}
