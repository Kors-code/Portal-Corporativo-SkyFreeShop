<?php

namespace App\Models\PassengerIntelligence;

use Illuminate\Database\Eloquent\Model;

class PassengerImportBatch extends Model
{
    protected $connection = 'budget';
    protected $table = 'passenger_intelligence_import_batches';

    protected $fillable = [
        'source_file_id',
        'filename',
        'checksum',
        'source_type',
        'observed_scope',
        'source_path',
        'source_url',
        'status',
        'period_start',
        'period_end',
        'rows_imported',
        'rows_skipped',
        'total_pax',
        'notes',
        'imported_by',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'total_pax' => 'decimal:2',
        'notes' => 'array',
    ];

    public function flights()
    {
        return $this->hasMany(PassengerFlight::class, 'batch_id');
    }

    public function sourceFile()
    {
        return $this->belongsTo(PassengerSourceFile::class, 'source_file_id');
    }
}
