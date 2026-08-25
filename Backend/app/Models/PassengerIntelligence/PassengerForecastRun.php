<?php

namespace App\Models\PassengerIntelligence;

use Illuminate\Database\Eloquent\Model;

class PassengerForecastRun extends Model
{
    protected $connection = 'budget';
    protected $table = 'passenger_intelligence_forecast_runs';

    protected $fillable = [
        'target_year',
        'target_month',
        'airport_iata',
        'run_date',
        'cutoff_date',
        'status',
        'method',
        'model_version',
        'actual_pax_to_date',
        'predicted_remaining_pax',
        'predicted_total_pax',
        'predicted_colombian_pct',
        'predicted_foreign_pct',
        'confidence_level',
        'input_sources',
        'explanation',
        'created_by',
    ];

    protected $casts = [
        'target_year' => 'integer',
        'target_month' => 'integer',
        'run_date' => 'date',
        'cutoff_date' => 'date',
        'actual_pax_to_date' => 'decimal:2',
        'predicted_remaining_pax' => 'decimal:2',
        'predicted_total_pax' => 'decimal:2',
        'predicted_colombian_pct' => 'decimal:3',
        'predicted_foreign_pct' => 'decimal:3',
        'input_sources' => 'array',
        'explanation' => 'array',
    ];
}
