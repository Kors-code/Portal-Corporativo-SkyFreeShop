<?php

namespace App\Models\PassengerIntelligence;

use Illuminate\Database\Eloquent\Model;

class PassengerCompositionProfile extends Model
{
    protected $connection = 'budget';
    protected $table = 'passenger_intelligence_composition_profiles';

    protected $fillable = [
        'name',
        'valid_from',
        'valid_to',
        'direction',
        'colombian_pct',
        'foreign_pct',
        'source_name',
        'source_url',
        'method',
        'confidence_level',
        'is_active',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'valid_from' => 'date',
        'valid_to' => 'date',
        'colombian_pct' => 'decimal:3',
        'foreign_pct' => 'decimal:3',
        'is_active' => 'boolean',
    ];
}
