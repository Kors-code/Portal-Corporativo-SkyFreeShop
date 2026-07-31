<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class InventoryAlertSummaryExport implements FromArray, ShouldAutoSize, WithHeadings, WithTitle
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
            'SKU',
            'Producto',
            'Marca',
            'Proveedor',
            'Tienda',
            'Inventario',
            'Dias disponible',
            'Estado',
        ];
    }

    public function title(): string
    {
        return 'Alertas inventario';
    }
}
