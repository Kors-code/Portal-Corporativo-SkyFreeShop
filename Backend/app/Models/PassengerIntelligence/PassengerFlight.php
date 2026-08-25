<?php

namespace App\Models\PassengerIntelligence;

use Illuminate\Database\Eloquent\Model;

class PassengerFlight extends Model
{
    protected $connection = 'budget';
    protected $table = 'passenger_intelligence_flights';

    protected $fillable = [
        'batch_id',
        'source_file_id',
        'flight_date',
        'scheduled_time',
        'scheduled_at',
        'direction',
        'airline',
        'flight_code',
        'origin',
        'destination',
        'pax',
        'store',
        'source_sheet',
        'source_row',
        'source_row_uid',
        'data_type',
        'observed_scope',
        'source_name',
        'retrieved_at',
    ];

    protected $casts = [
        'flight_date' => 'date',
        'scheduled_at' => 'datetime',
        'retrieved_at' => 'datetime',
        'pax' => 'decimal:2',
    ];

    public function batch()
    {
        return $this->belongsTo(PassengerImportBatch::class, 'batch_id');
    }

    public function sourceFile()
    {
        return $this->belongsTo(PassengerSourceFile::class, 'source_file_id');
    }

    public function estimates()
    {
        return $this->hasMany(PassengerFlightEstimate::class, 'flight_id');
    }
}
