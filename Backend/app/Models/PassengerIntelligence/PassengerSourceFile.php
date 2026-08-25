<?php

namespace App\Models\PassengerIntelligence;

use Illuminate\Database\Eloquent\Model;

class PassengerSourceFile extends Model
{
    protected $connection = 'budget';
    protected $table = 'passenger_intelligence_source_files';

    protected $fillable = [
        'provider',
        'drive_item_id',
        'drive_id',
        'name',
        'extension',
        'mime_type',
        'size',
        'web_url',
        'parent_path',
        'e_tag',
        'c_tag',
        'source_last_modified_at',
        'discovered_at',
        'downloaded_at',
        'checksum',
        'status',
        'notes',
    ];

    protected $casts = [
        'size' => 'integer',
        'source_last_modified_at' => 'datetime',
        'discovered_at' => 'datetime',
        'downloaded_at' => 'datetime',
        'notes' => 'array',
    ];

    public function importBatch()
    {
        return $this->hasOne(PassengerImportBatch::class, 'source_file_id');
    }
}
