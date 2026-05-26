<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Entrega extends Model
{
    use HasFactory, SoftDeletes;

    protected $connection = 'mysql_personal';

    protected $table = 'entregas';

    protected $fillable = [
        'codigo_acta',
        'nombre_acta',
        'lider_entrega_id',
        'lider_recibe_id',
        'turno',
        'fecha_acta',
        'sede',
        'estado',
        'fecha_entrega',
        'fecha_recepcion',
        'observaciones',
        'razon_rechazo',
        'pdf_path',
        'correo_enviado',
    ];

    protected $casts = [
        'fecha_acta' => 'date',
        'fecha_entrega' => 'datetime',
        'fecha_recepcion' => 'datetime',
        'correo_enviado' => 'boolean',
    ];

    // Relaciones
    public function liderEntrega()
    {
        return $this->belongsTo(Empleado::class, 'lider_entrega_id');
    }

    public function liderRecibe()
    {
        return $this->belongsTo(Empleado::class, 'lider_recibe_id');
    }

    public function novedades()
    {
        return $this->hasMany(Novedad::class)->orderBy('orden')->orderBy('id');
    }

    public function firmas()
    {
        return $this->hasMany(FirmaDigital::class);
    }

    public function firmaEntrega()
    {
        return $this->hasOne(FirmaDigital::class)->where('tipo_firma', 'entrega');
    }

    public function firmaRecepcion()
    {
        return $this->hasOne(FirmaDigital::class)->where('tipo_firma', 'recepcion');
    }

    public function logs()
    {
        return $this->hasMany(EntregaLog::class)->orderBy('created_at', 'desc');
    }

    // Scopes
    public function scopeAbiertas($query)
    {
        return $query->where('estado', 'abierta');
    }

    public function scopeCompletadas($query)
    {
        return $query->where('estado', 'completada');
    }

    public function scopeParaLider($query, $empleadoId)
    {
        return $query->where(function ($q) use ($empleadoId) {
            $q->where('lider_entrega_id', $empleadoId)
              ->orWhere('lider_recibe_id', $empleadoId);
        });
    }

    public function scopeRecibidasPor($query, $empleadoId)
    {
        return $query->where('lider_recibe_id', $empleadoId);
    }

    public function scopeEntregadasPor($query, $empleadoId)
    {
        return $query->where('lider_entrega_id', $empleadoId);
    }

    // Helpers
    public function tieneFirmaEntrega(): bool
    {
        return $this->firmaEntrega()->exists();
    }

    public function tieneFirmaRecepcion(): bool
    {
        return $this->firmaRecepcion()->exists();
    }

    public function puedeFirmarComoEntrega(int $empleadoId): bool
    {
        return $this->lider_entrega_id === $empleadoId
            && !$this->tieneFirmaEntrega()
            && in_array($this->estado, ['abierta', 'entregada']);
    }

    public function puedeFirmarComoRecepcion(int $empleadoId): bool
    {
        return $this->lider_recibe_id === $empleadoId
            && !$this->tieneFirmaRecepcion()
            && $this->tieneFirmaEntrega()
            && in_array($this->estado, ['entregada', 'recibida']);
    }

    public function getEstadoLabelAttribute(): string
    {
        $labels = [
            'abierta' => 'Abierta',
            'entregada' => 'Firmada por quien entrega',
            'recibida' => 'Recibida',
            'completada' => 'Completada',
            'rechazada' => 'Rechazada',
        ];
        return $labels[$this->estado] ?? $this->estado;
    }
}
