<?php
namespace App\Models\Comisiones;
use Illuminate\Database\Eloquent\Model;

class ImportBatch extends Model {
        protected $connection = 'budget';

    protected $fillable = ['filename','checksum','status','rows','note','import_date'];

    public function sales() {
        return $this->hasMany(Sale::class, 'import_batch_id');
    }
}
