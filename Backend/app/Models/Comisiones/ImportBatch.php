<?php
namespace App\Models\Comisiones;
use Illuminate\Database\Eloquent\Model;

class ImportBatch extends Model {
        protected $connection = 'budget';

    protected $fillable = [
        'filename',
        'checksum',
        'source_checksum',
        'status',
        'replace_existing',
        'rows',
        'note',
        'import_date',
        'published_at',
    ];

    public function sales() {
        return $this->hasMany(Sale::class, 'import_batch_id');
    }
}
