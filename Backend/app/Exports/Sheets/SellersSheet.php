<?php
namespace App\Exports\Sheets;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;

class SellersSheet implements FromArray, WithHeadings, WithTitle, WithEvents
{

    protected $rows;
    protected $meta;

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
            'Seller ID',
            'Vendedor',
            'Turnos asignados',
            'Ventas COP',
            'Ventas USD',
            'Comisión COP',
            'Avg TRM',
            'Tickets count',
            'Avg ticket USD',
            'Avg ticket COP',
        ];
    }
    public function registerEvents(): array
{
    return [
        AfterSheet::class => function (AfterSheet $event) {

            $lastColumn = 'J'; // 10 columnas

            $budget = $this->meta['budget']['name'] ?? 'Presupuesto';
            $start  = $this->meta['budget']['start_date'] ?? '';
            $end    = $this->meta['budget']['end_date'] ?? '';

            // Espacio arriba
            $event->sheet->insertNewRowBefore(1, 3);

            // TITULO
            $event->sheet->mergeCells("A1:{$lastColumn}1");
            $event->sheet->setCellValue('A1', 'REPORTE GENERAL DE VENDEDORES');

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

            // Encabezados tabla (fila 4)
            $event->sheet->getStyle("A4:J4")->applyFromArray([
                'font' => ['bold' => true],
                'fill' => [
                    'fillType' => 'solid',
                    'startColor' => ['argb' => 'FFEFEFEF'],
                ],
            ]);

            // Auto ancho
            foreach (range('A','J') as $col) {
                $event->sheet->getDelegate()->getColumnDimension($col)->setAutoSize(true);
            }
        },
    ];
}


    public function title(): string
    {
        return 'Sellers';
    }
}
