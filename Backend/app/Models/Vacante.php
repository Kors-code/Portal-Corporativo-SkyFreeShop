<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Vacante extends Model
{
    protected $fillable = ['titulo', 'descripcion', 'requisitos' , "requisito_ia" ,"salario" , "beneficios","criterios","localidad"];

    protected $casts = [
        'requisitos' => 'array',
        'beneficios' => 'array',
        'criterios' => 'array',
        'habilitado' => 'boolean',
    ]; 


protected static function booted()
{
    static::creating(function ($vacante) {
        $vacante->slug = Str::slug($vacante->titulo);
    });
}

    public function candidatos()
    {
        return $this->hasMany(Candidato::class);
    }
}
