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
        'source_channel',
        'source_email_message_id',
        'source_email_from',
        'source_email_subject',
        'source_email_received_at',
        'routing_method',
        'gmail_seen_at',
        'cv_summary',
        'vacancy_suggestions',
    ];

    protected $casts = [
        'source_email_received_at' => 'datetime',
        'gmail_seen_at' => 'datetime',
        'vacancy_suggestions' => 'array',
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
