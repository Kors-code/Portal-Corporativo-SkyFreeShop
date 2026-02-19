<?php
namespace App\Exports\Sheets;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;

class CategoriesSheet implements FromArray, WithHeadings, WithTitle, WithEvents
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
            'Classification',
            'Participación %',
            'Budget (USD)',
            'Ventas (USD)',
            'Ventas (COP)',
            '% de categoría',
            'Califica',
            'Pct comisión aplicada',
            'Comisión proyectada USD',
            'Comisión COP',
        ];
    }

    public function title(): string
    {
        return 'Categories';
    }
    public function registerEvents(): array
{
    return [
        AfterSheet::class => function (AfterSheet $event) {

            $lastColumn = 'J';

            $budget = $this->meta['budget']['name'] ?? 'Presupuesto';
            $start  = $this->meta['budget']['start_date'] ?? '';
            $end    = $this->meta['budget']['end_date'] ?? '';

            $event->sheet->insertNewRowBefore(1, 3);

            $event->sheet->mergeCells("A1:{$lastColumn}1");
            $event->sheet->setCellValue('A1', 'RESUMEN DE COMISIONES POR CATEGORÍA');

            $event->sheet->mergeCells("A2:{$lastColumn}2");
            $event->sheet->setCellValue('A2', "Presupuesto: {$budget} | Periodo: {$start} → {$end}");

            $event->sheet->getStyle("A1")->applyFromArray([
                'font' => ['bold' => true, 'size' => 14],
                'alignment' => ['horizontal' => 'center'],
            ]);

            $event->sheet->getStyle("A2")->applyFromArray([
                'font' => ['italic' => true, 'size' => 10],
                'alignment' => ['horizontal' => 'center'],
            ]);

            $event->sheet->getStyle("A4:J4")->applyFromArray([
                'font' => ['bold' => true],
                'fill' => [
                    'fillType' => 'solid',
                    'startColor' => ['argb' => 'FFEFEFEF'],
                ],
            ]);

            foreach (range('A','J') as $col) {
                $event->sheet->getDelegate()->getColumnDimension($col)->setAutoSize(true);
            }
        },
    ];
}

}
