<?php

namespace App\Models\Comisiones;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserCategoryCommission extends Model
{
    use HasFactory;
        protected $connection = 'budget';


    protected $fillable = [
        'user_id',
        'category_id',
        'commission_percentage',
        'active',
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
