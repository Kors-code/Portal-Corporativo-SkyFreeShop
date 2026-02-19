<?php
namespace App\Models\Comisiones;
use Illuminate\Database\Eloquent\Model;

class Sale extends Model {
        protected $connection = 'budget';

    protected $fillable = [
        'seller_id','cashier_id','product_id','amount','value_usd','sale_date',
        'folio','pdv','quantity','value_pesos','value_usd','currency','cost','status','cashier','import_batch_id','exchange_rate',
        'amount_cop',
    ];
    protected $dates = ['sale_date'];
    public function product() { return $this->belongsTo(Product::class); }
    public function seller() { return $this->belongsTo(User::class, 'seller_id'); }
    public function cashier() { return $this->belongsTo(User::class, 'cashier_id'); }
    public function commissions() { return $this->hasMany(Commission::class); }
    public function importBatch() { return $this->belongsTo(\App\Models\ImportBatch::class, 'import_batch_id'); }
public function batch()
{
    return $this->belongsTo(ImportBatch::class, 'import_batch_id');
}

}
