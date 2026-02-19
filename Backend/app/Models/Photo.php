<?php

// app/Models/Photo.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Photo extends Model
{
    protected $fillable = ['ruta'];
    public $timestamps = false; // según tu tabla
}
