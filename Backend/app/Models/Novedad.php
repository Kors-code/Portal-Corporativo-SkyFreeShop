<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Novedad extends Model
{
    use HasFactory;

    protected $connection = 'mysql_personal';

    protected $table = 'novedades';

    protected $fillable = [
        'entrega_id',
        'categoria',
        'titulo',
        'descripcion',
        'prioridad',
        'requiere_seguimiento',
        'resuelto',
        'observaciones_receptor',
        'orden',
    ];

    protected $casts = [
        'requiere_seguimiento' => 'boolean',
        'resuelto' => 'boolean',
        'orden' => 'integer',
    ];

    public static array $categorias = [
        'precios_promociones' => [
            'label' => 'Precios y Promociones',
            'icon' => '🏷️',
            'color' => '#10b981',
            'opciones' => [
                'Nuevo descuento aplicado',
                'Cambio de precio en productos',
                'Promoción 2x1 activa',
                'Promoción combo',
                'Descuento por categoría',
                'Liquidación de stock',
                'Promoción fin de mes',
                'Black Friday / Cyber Monday',
            ],
        ],
        'logistica' => [
            'label' => 'Logística',
            'icon' => '📦',
            'color' => '#3b82f6',
            'opciones' => [
                'Recepción de mercancía pendiente',
                'Producto agotado',
                'Pedido especial cliente',
                'Devolución a proveedor',
                'Transferencia entre tiendas',
                'Inventario incompleto',
                'Mercancía dañada',
                'Falta de stock crítico',
            ],
        ],
        'cajas' => [
            'label' => 'Cajas',
            'icon' => '💰',
            'color' => '#f59e0b',
            'opciones' => [
                'Caja registradora con error',
                'Falta de cambio',
                'Diferencia de caja',
                'Datafono fuera de servicio',
                'Sistema POS con problemas',
                'Falla en lector de código',
                'Cierre de caja pendiente',
                'Discrepancia en arqueo',
            ],
        ],
        'personal' => [
            'label' => 'Personal',
            'icon' => '👥',
            'color' => '#8b5cf6',
            'opciones' => [
                'Nuevo colaborador en turno',
                'Incapacidad médica',
                'Permiso especial',
                'Cambio de turno solicitado',
                'Capacitación pendiente',
                'Llegada tarde',
                'Falta sin justificación',
                'Vacaciones programadas',
            ],
        ],
        'otros_temas' => [
            'label' => 'Otros Temas',
            'icon' => '📋',
            'color' => '#6b7280',
            'opciones' => [
                'Visita de cliente VIP',
                'Reclamo de cliente',
                'Visita de proveedor',
                'Auditoria interna',
                'Mantenimiento programado',
                'Visita gerencial',
                'Cambio en exhibición',
                'Tema misceláneo',
            ],
        ],
        'temas_pendientes' => [
            'label' => 'Temas Pendientes Atrás',
            'icon' => '⏰',
            'color' => '#ef4444',
            'opciones' => [
                'Reporte pendiente de envío',
                'Documento por firmar',
                'Pago a proveedor pendiente',
                'Limpieza pendiente',
                'Reparación pendiente',
                'Seguimiento a cliente',
                'Trámite administrativo',
                'Llamada de seguimiento',
            ],
        ],
    ];

    public static array $prioridades = [
        'baja' => ['label' => 'Baja', 'color' => '#6b7280'],
        'media' => ['label' => 'Media', 'color' => '#f59e0b'],
        'alta' => ['label' => 'Alta', 'color' => '#ef4444'],
        'urgente' => ['label' => 'Urgente', 'color' => '#dc2626'],
    ];

    public function entrega()
    {
        return $this->belongsTo(Entrega::class);
    }

    public function getCategoriaLabelAttribute(): string
    {
        return self::$categorias[$this->categoria]['label'] ?? $this->categoria;
    }

    public function getCategoriaIconAttribute(): string
    {
        return self::$categorias[$this->categoria]['icon'] ?? '📋';
    }

    public function getCategoriaColorAttribute(): string
    {
        return self::$categorias[$this->categoria]['color'] ?? '#6b7280';
    }
}
