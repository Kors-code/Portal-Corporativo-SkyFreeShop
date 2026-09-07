<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class CommissionProfileEarnersExport implements FromArray, WithHeadings, ShouldAutoSize, WithTitle
{
    public function __construct(private array $rows)
    {
    }

    public function array(): array
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return [
            'Perfil',
            'Tipo',
            'Usuario',
            'Codigo vendedor',
            'Ventas',
            'Unidades',
            'Ventas USD',
            'Ventas COP',
            'Cumplimiento %',
            '% Aplicado',
            'Comision USD',
            'Estado',
        ];
    }

    public function title(): string
    {
        return 'Perfiles comisionables';
    }
}
