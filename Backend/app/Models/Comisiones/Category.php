<?php
namespace App\Models\Comisiones;
use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
        protected $connection = 'budget';

    protected $fillable = [
        'classification_code',
        'name',
        'description',
    ];
}

