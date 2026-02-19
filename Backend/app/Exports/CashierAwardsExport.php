<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class CashierAwardsExport implements FromArray, WithHeadings, WithTitle
{
    protected array $rows;

    public function __construct(array $rows)
    {
        $this->rows = $rows;
    }

    public function array(): array
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return [
            'User ID',
            'Cajero',
            'Ventas USD',
            '% Participación',
            'Premiación',
        ];
    }

    public function title(): string
    {
        return 'Premios Cajeros';
    }
}
