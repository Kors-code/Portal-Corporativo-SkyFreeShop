<?php

namespace App\Exports\Sheets;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;

class SellerSalesSheet implements FromArray, WithHeadings, WithTitle, WithEvents
{
    protected array $rows;
    protected array $meta;

    public function __construct(array $rows, array $meta = [])
    {
        $this->rows = $rows;
        $this->meta = $meta;
    }

    public function array(): array
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return [
            'Fecha',
            'Seller Code',
            'Folio',
            'Producto',
            'Categoría',
            'USD',
            'COP',
            'Comisión',
        ];
    }

    public function title(): string
    {
        return 'Ventas';
    }

    public function registerEvents(): array
{
    return [
        AfterSheet::class => function (AfterSheet $event) {

            $lastColumn = 'H';

            $seller = $this->meta['user']['name'] ?? 'Vendedor';
            $budget = $this->meta['budget']['name'] ?? '';
            $start  = $this->meta['budget']['start_date'] ?? '';
            $end    = $this->meta['budget']['end_date'] ?? '';

            // Insertar espacio arriba
            $event->sheet->insertNewRowBefore(1, 3);

            // TITULO
            $event->sheet->mergeCells("A1:{$lastColumn}1");
            $event->sheet->setCellValue('A1', strtoupper($seller) . ' — DETALLE DE VENTAS');

            // SUBTITULO
            $event->sheet->mergeCells("A2:{$lastColumn}2");
            $event->sheet->setCellValue('A2', "Presupuesto: {$budget} | Periodo: {$start} → {$end}");

            // ESTILOS
            $event->sheet->getStyle("A1")->applyFromArray([
                'font' => ['bold' => true, 'size' => 14],
                'alignment' => ['horizontal' => 'center'],
            ]);

            $event->sheet->getStyle("A2")->applyFromArray([
                'font' => ['italic' => true, 'size' => 10],
                'alignment' => ['horizontal' => 'center'],
            ]);

            // Encabezados (fila 4)
            $event->sheet->getStyle("A4:H4")->applyFromArray([
                'font' => ['bold' => true],
                'fill' => [
                    'fillType' => 'solid',
                    'startColor' => ['argb' => 'FFEFEFEF'],
                ],
            ]);

            // Auto ancho columnas
            foreach (range('A','H') as $col) {
                $event->sheet->getDelegate()->getColumnDimension($col)->setAutoSize(true);
            }
        },
    ];
}

}
