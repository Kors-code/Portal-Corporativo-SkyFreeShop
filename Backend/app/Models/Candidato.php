<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Facades\Crypt;


class Candidato extends Model
{
    protected $fillable = [
        'nombre',
        'email',
        'cv',
        'vacante_id',
        'cv_text',
        'razon_ia',
        'estado',
        'puntaje',
        'celular',
        'estado_correo',
        'autorizacion',
    ];
     // Cifrar automáticamente algunos campos
    protected function email(): Attribute
{
    return Attribute::make(
        get: fn ($value) => $this->safeDecrypt($value),
        set: fn ($value) => $value ? Crypt::encryptString($value) : null,
    );
}

protected function celular(): Attribute
{
    return Attribute::make(
        get: fn ($value) => $this->safeDecrypt($value),
        set: fn ($value) => $value ? Crypt::encryptString($value) : null,
    );
}

protected function cvText(): Attribute
{
    return Attribute::make(
        get: fn ($value) => $this->safeDecrypt($value),
        set: fn ($value) => $value ? Crypt::encryptString($value) : null,
    );
}

private function safeDecrypt($value)
{
    if (!$value) {
        return null;
    }

    try {
        return Crypt::decryptString($value);
    } catch (\Exception $e) {
        // Si el valor no estaba cifrado (ejemplo: registros antiguos), lo retorna tal cual
        return $value;
    }
}
 public function vacante()
{
    return $this->belongsTo(Vacante::class);
}

}
