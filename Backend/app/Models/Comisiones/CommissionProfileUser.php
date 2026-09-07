<?php

namespace App\Models\Comisiones;

use Illuminate\Database\Eloquent\Model;

class CommissionProfileUser extends Model
{
    protected $connection = 'budget';

    protected $fillable = [
        'commission_profile_id',
        'user_id',
        'valid_from',
        'valid_to',
        'note',
    ];

    protected $casts = [
        'valid_from' => 'date',
        'valid_to' => 'date',
    ];

    public function profile()
    {
        return $this->belongsTo(CommissionProfile::class, 'commission_profile_id');
    }
}
