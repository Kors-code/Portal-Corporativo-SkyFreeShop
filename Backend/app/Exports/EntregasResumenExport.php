<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

class EntregasResumenExport implements FromCollection, WithHeadings, ShouldAutoSize
{
    public function __construct(private Collection $entregas)
    {
    }

    public function collection(): Collection
    {
        return $this->entregas->map(function ($entrega) {
            $novedades = $entrega->novedades ?? collect();
            $totalNovedades = $novedades->count();
            $novedadesResueltas = $novedades->where('resuelto', true)->count();
            $novedadesPendientes = $totalNovedades - $novedadesResueltas;
            $detalleNovedades = $novedades
                ->map(function ($novedad, $index) {
                    $numero = $index + 1;
                    $estado = $novedad->resuelto ? 'resuelta' : 'pendiente';
                    $titulo = $novedad->titulo ? " - {$novedad->titulo}" : '';

                    return "{$numero}. {$novedad->categoria}{$titulo} ({$novedad->prioridad}, {$estado}): {$novedad->descripcion}";
                })
                ->implode("\n");

            return [
                $entrega->codigo_acta,
                $entrega->nombre_acta,
                optional($entrega->fecha_acta)->format('Y-m-d'),
                $entrega->turno,
                $entrega->sede,
                $entrega->liderEntrega?->colaborador,
                $entrega->liderRecibe?->colaborador,
                $entrega->estado,
                $totalNovedades,
                $novedadesPendientes,
                $novedadesResueltas,
                $detalleNovedades,
                $entrega->observaciones,
                $entrega->razon_rechazo,
                optional($entrega->fecha_entrega)->format('Y-m-d H:i:s'),
                optional($entrega->fecha_recepcion)->format('Y-m-d H:i:s'),
                optional($entrega->created_at)->format('Y-m-d H:i:s'),
            ];
        });
    }

    public function headings(): array
    {
        return [
            'Codigo acta',
            'Nombre acta',
            'Fecha acta',
            'Turno',
            'Sede',
            'Lider entrega',
            'Lider recibe',
            'Estado',
            'Total novedades',
            'Novedades pendientes',
            'Novedades resueltas',
            'Detalle novedades',
            'Observaciones',
            'Razon rechazo',
            'Fecha entrega',
            'Fecha recepcion',
            'Fecha creacion',
        ];
    }
}
