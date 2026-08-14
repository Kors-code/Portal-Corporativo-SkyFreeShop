<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class InventoryStoreExport implements FromCollection, WithHeadings, ShouldAutoSize, WithTitle
{
    public function __construct(private array $rows)
    {
    }

    public function collection(): Collection
    {
        return collect($this->rows)->map(function (array $row) {
            $costUnit = (float) ($row['cost_unitario'] ?? $row['costo_unitario'] ?? 0);
            $stock = (float) ($row['stock_actual'] ?? 0);

            return [
                $row['product_code'] ?? '',
                $row['description'] ?? '',
                $row['classification_desc'] ?? '',
                $row['brand'] ?? '',
                $row['proveedor'] ?? $row['supplier'] ?? '',
                $costUnit,
                $stock,
                round($costUnit * $stock, 2),
            ];
        });
    }

    public function headings(): array
    {
        return [
            'SKU',
            'Descripcion',
            'Categoria',
            'Marca',
            'Proveedor',
            'Costo Unidad',
            'Stock',
            'Total',
        ];
    }

    public function title(): string
    {
        return 'Inventario';
    }
}
