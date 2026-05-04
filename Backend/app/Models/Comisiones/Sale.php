<?php

namespace App\Models\Comisiones;

use Illuminate\Database\Eloquent\Model;

class Sale extends Model
{
    protected $connection = 'budget';

    protected $fillable = [
    'sale_date',
    'sale_datetime',

    'folio',
    'pdv',

    'product_id',
    'passenger_id',
    'travel_itinerary_id',

    'customer_code',

    'quantity',
    'amount',
    'amount_cop',

    'discount',
    'total',

    'value_pesos',
    'value_usd',

    'cost',
    'currency',
    'exchange_rate',

    'status',
    'applied_promotion',
    'cancellation_date',

    'line_code',
    'seat',

    'passport_number',
    'passenger_name',
    'date_birth',
    'gender',

    'raw_payload',

    'hora',

    'seller_id',
    'store_id',

    'cashier',

    'import_batch_id',
];

    protected $dates = ['sale_date'];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function seller()
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function cashier()
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function commissions()
    {
        return $this->hasMany(Commission::class);
    }

    public function importBatch()
    {
        return $this->belongsTo(\App\Models\ImportBatch::class, 'import_batch_id');
    }

    public function batch()
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }
}