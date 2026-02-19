<?php

namespace App\Models\Personal;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TableWish extends Model
{
    protected $connection = 'mysql_personal';
    protected $table = 'table_wish';

    protected $fillable = [
        'product',
        'category'
    ];
}
