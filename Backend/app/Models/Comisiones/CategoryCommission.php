<?php

namespace App\Models\Comisiones;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CategoryCommission extends Model
{
    use HasFactory;

    protected $connection = 'budget';

protected $fillable = [
    'category_id',
    'role_id',
    'commission_percentage',
    'commission_percentage100',
    'commission_percentage120',
    'participation_pct',
    'budget_id',
];


    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }
}
