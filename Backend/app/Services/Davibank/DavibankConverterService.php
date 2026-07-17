<?php

namespace App\Services\Davibank;

use DateTimeImmutable;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

class DavibankConverterService
{
    private const REQUIRED_COLUMNS = [
        'NUMERO',
        'NIT',
        'CONSECUTIVO',
        'CODIGO_RED',
        'FECHA_ARCHIVO',
        'ABONO_DEVOL',
        'FECHA_ABONO',
        'ESTABLECIMIENTO',
        'VALOR_ABONADO',
        'VALOR_COMISION',
        'VALOR_RETENCION',
        'VALOR_IVA',
        'VALOR_RETEIVA',
        'NETO_ABONADO',
        'NUMERO_CUENTA',
        'VALOR_PROPINA',
        'NOMBRE_ALMACEN',
        'NOMBREALMACEN',
        'RETEICA',
        'VALOR_COMPRA',
        'FECHA_COMPR',
        'CODIGOAGENCIA',
        'CIUDADAGENCIA',
        'NOMBREAGENCIA',
        'FRANQUICIA',
        'ORIGEN',
        'TIPO',
        'TIPO_ABONO',
        'FECHA_CONSIG',
        'NUM_TERMINAL',
        'NUM_COMP',
        'NUM_TRANSACC',
        'NUM_AUTORIZACION',
        'NUM_TARJETA',
        'TIPTARJ',
        'UBICACION_TERM',
        'CANAL_RECHAZO',
        'RED_ADQUIRIENTE',
        'FECHA_PROCESO',
        'NUM_SEUDOCTA',
        'MATIPREG',
        'MATIPRE',
        'NOMBRE_TITULAR',
        'ID_MOVIMIENTO',
    ];

    private const DATE_COLUMNS = ['FECHA_ARCHIVO', 'FECHA_ABONO', 'FECHA_COMPR', 'FECHA_CONSIG', 'FECHA_PROCESO'];

    private const MONEY_COLUMNS = [
        'VALOR_ABONADO',
        'VALOR_COMISION',
        'VALOR_RETENCION',
        'VALOR_IVA',
        'VALOR_RETEIVA',
        'NETO_ABONADO',
        'VALOR_PROPINA',
        'RETEICA',
        'VALOR_COMPRA',
    ];

    /**
     * @return array{path: string, filename: string, sheets: int, rows: int, excluded_zero_commission: int}
     */
    public function convert(UploadedFile $file, int $receiptStart): array
    {
        if ($receiptStart <= 0) {
            throw new RuntimeException('El numero inicial del recibo debe ser mayor que 0.');
        }

        [$headers, $rows, $excludedZeroCommission] = $this->readDavibankCsv($file->getRealPath());
        $groups = $this->groupRowsByAbonoDate($rows);

        if ($groups === []) {
            throw new RuntimeException('No hay filas validas para exportar despues de aplicar los filtros.');
        }

        ksort($groups);

        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0);

        $receiptNumber = $receiptStart;
        foreach ($groups as $dateKey => $dayRows) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dateKey);
            if (! $date) {
                continue;
            }

            $sheet = new Worksheet($spreadsheet, $this->makeSheetName($date));
            $spreadsheet->addSheet($sheet);
            $receiptNumber = $this->fillDaySheet($sheet, $headers, $dayRows, $date, $receiptNumber);
        }

        $spreadsheet->setActiveSheetIndex(0);

        $path = tempnam(storage_path('app'), 'davibank_');
        if ($path === false) {
            throw new RuntimeException('No se pudo crear el archivo temporal.');
        }

        $xlsxPath = $path . '.xlsx';
        @rename($path, $xlsxPath);

        (new Xlsx($spreadsheet))->save($xlsxPath);

        return [
            'path' => $xlsxPath,
            'filename' => 'davibank_convertido_' . now()->format('Ymd_His') . '.xlsx',
            'sheets' => count($groups),
            'rows' => array_sum(array_map('count', $groups)),
            'excluded_zero_commission' => $excludedZeroCommission,
        ];
    }

    /**
     * @return array{0: array<int, string>, 1: array<int, array<string, mixed>>, 2: int}
     */
    private function readDavibankCsv(string $path): array
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false || $lines === []) {
            throw new RuntimeException('El CSV esta vacio o no se pudo leer.');
        }

        $headers = $this->parseDavibankLine($lines[0]);
        $missing = array_values(array_diff(self::REQUIRED_COLUMNS, $headers));
        if ($missing !== []) {
            throw new RuntimeException('Faltan columnas requeridas: ' . implode(', ', $missing));
        }

        $records = [];
        $excludedZeroCommission = 0;

        foreach (array_slice($lines, 1) as $lineNumber => $line) {
            $values = $this->parseDavibankLine($line);
            $record = [];

            foreach ($headers as $index => $header) {
                $record[$header] = $values[$index] ?? '';
            }

            $network = trim((string) $record['CODIGO_RED']);
            if (! in_array($network, ['VD', 'RD'], true)) {
                continue;
            }

            foreach (self::MONEY_COLUMNS as $column) {
                $record[$column] = $this->parseMoney($record[$column]);
            }

            if ((float) $record['VALOR_COMISION'] == 0.0) {
                $excludedZeroCommission++;
                continue;
            }

            $abonoDate = $this->parseDateValue((string) $record['FECHA_ABONO']);
            if (! $abonoDate) {
                throw new RuntimeException('FECHA_ABONO invalida en fila CSV ' . ($lineNumber + 2));
            }

            $record['_FECHA_ABONO_KEY'] = $abonoDate->format('Y-m-d');
            $records[] = $record;
        }

        return [$headers, $records, $excludedZeroCommission];
    }

    private function parseDavibankLine(string $line): array
    {
        $line = preg_replace('/^\xEF\xBB\xBF/', '', trim($line));

        if (str_starts_with($line, '"') && str_ends_with($line, '"')) {
            $line = substr($line, 1, -1);
        }

        return explode(';', $line);
    }

    private function parseMoney(mixed $value): float
    {
        $text = trim((string) $value);
        if ($text === '') {
            return 0.0;
        }

        return round((float) str_replace(',', '.', str_replace('.', '', $text)), 2);
    }

    private function parseDateValue(string $value): ?DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '' || $value === '0000/00/00') {
            return null;
        }

        foreach (['!Y/m/d', '!Y-m-d'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value);
            if ($date instanceof DateTimeImmutable) {
                return $date;
            }
        }

        return null;
    }

    private function groupRowsByAbonoDate(array $rows): array
    {
        $groups = [];

        foreach ($rows as $row) {
            $groups[$row['_FECHA_ABONO_KEY']][] = $row;
        }

        return $groups;
    }

    private function makeSheetName(DateTimeImmutable $date): string
    {
        return $date->format('d') . ' ' . $this->monthName((int) $date->format('n'));
    }

    private function fillDaySheet(Worksheet $sheet, array $headers, array $rows, DateTimeImmutable $date, int $receiptNumber): int
    {
        $visaTotal = $this->sumWhereNetwork($rows, 'VD', 'VALOR_ABONADO');
        $redebanTotal = $this->sumWhereNetwork($rows, 'RD', 'VALOR_ABONADO');

        $sheet->setCellValue('C2', 'Visa');
        $sheet->setCellValue('D2', 'REDEBAN');
        $sheet->setCellValue('C4', $visaTotal);
        $sheet->setCellValue('D4', $redebanTotal);
        $sheet->setCellValue('F4', '=SUM(C4:D4)');
        $sheet->setCellValue('G4', 'BANCO');
        $sheet->setCellValue('F5', '=SUM(C5:D5)');
        $sheet->setCellValue('G5', 'VENTAS');
        $sheet->setCellValue('C6', '=+C4');
        $sheet->setCellValue('D6', '=+D4');
        $sheet->setCellValue('F6', '=+F4-F5');
        $this->styleTopSummary($sheet);

        $headerRow = 8;
        foreach ($headers as $index => $header) {
            $sheet->setCellValue([$index + 1, $headerRow], $header);
        }

        $dataStartRow = $headerRow + 1;
        foreach ($rows as $rowOffset => $row) {
            $excelRow = $dataStartRow + $rowOffset;

            foreach ($headers as $index => $header) {
                $columnIndex = $index + 1;
                $value = $row[$header] ?? '';

                if (in_array($header, self::DATE_COLUMNS, true)) {
                    $dateValue = $this->parseDateValue((string) $value);
                    if ($dateValue) {
                        $sheet->setCellValue([$columnIndex, $excelRow], ExcelDate::PHPToExcel($dateValue));
                        $sheet->getStyle([$columnIndex, $excelRow])->getNumberFormat()->setFormatCode('yyyy/mm/dd');
                        continue;
                    }
                }

                $sheet->setCellValue([$columnIndex, $excelRow], $value);
            }
        }

        $dataEndRow = $dataStartRow + count($rows) - 1;
        $subtotalRow = $dataEndRow + 2;

        foreach (range('I', 'T') as $column) {
            $sheet->setCellValue("{$column}{$subtotalRow}", "=SUBTOTAL(9,{$column}{$dataStartRow}:{$column}{$dataEndRow})");
        }

        $this->styleRawTable($sheet, $headers, $headerRow, $dataStartRow, $subtotalRow);

        $receiptStartRow = $subtotalRow + 4;
        $this->fillReceiptBlock($sheet, $date, $receiptStartRow, $receiptNumber, $subtotalRow);

        $this->autosizeColumns($sheet, count($headers));
        $sheet->freezePane('A9');

        return $receiptNumber + 7;
    }

    private function sumWhereNetwork(array $rows, string $network, string $column): float
    {
        return array_reduce(
            $rows,
            fn (float $carry, array $row): float => $carry + (((string) $row['CODIGO_RED'] === $network) ? (float) $row[$column] : 0.0),
            0.0
        );
    }

    private function fillReceiptBlock(Worksheet $sheet, DateTimeImmutable $date, int $startRow, int $receiptNumber, int $subtotalRow): void
    {
        $dayText = $this->dayText($date);

        $rows = [
            ['13551808', 'Ica 0.6%', '860034594', 'Colpatria', "=+S{$subtotalRow}", null, "RC VTAS PAGO {$dayText} TARJETA COLPATRIA ICA"],
            ['13551508', 'Rtefte 1,5%', '860034594', 'Colpatria', "=+K{$subtotalRow}", null, "RC VTAS PAGO {$dayText} TARJETACOLPATRIARETENCION"],
            ['53051501', 'Comisiones no gravadas', '860034594', 'Colpatria', "=+J{$subtotalRow}", null, "RC VTAS PAGO {$dayText} TARJETA COLPATRIA COMISION"],
            ['11102006', 'Cuenta corriente colpatria 6841002235-pesos', '860034594', 'Colpatria', '=+C6', null, "RC VTAS PAGO {$dayText} PAGO DE VISA"],
            ['11102006', 'Cuenta corriente colpatria 6841002235-pesos', '860034594', 'Colpatria', '=+D6', null, "RC VTAS PAGO {$dayText} PAGO REDEBAN"],
            ['11102006', 'Cuenta corriente colpatria 6841002235-pesos', '860034594', 'Colpatria', null, '=+F' . ($startRow + 2) . '+F' . ($startRow + 1) . '+F' . $startRow, "RC VTAS PAGO {$dayText} PAGO"],
            ['13050503', 'Clientes Nacionales', '222222222', 'Vtas Mostrador', null, '=SUM(F' . $startRow . ':F' . ($startRow + 6) . ')-SUM(G' . $startRow . ':G' . ($startRow + 5) . ')', "RC VTAS PAGO {$dayText} PAGO TARJETA COLPATRIA"],
        ];

        $sheet->setCellValue("A" . ($startRow - 2), 'RECIBO DE CAJA');
        $sheet->mergeCells("A" . ($startRow - 2) . ':H' . ($startRow - 2));

        foreach (['NUMERO', 'CUENTA', 'NOMBRE CUENTA', 'NIT', 'NOMBRE NIT', 'DEBITO', 'CREDITO', 'CONCEPTO'] as $index => $header) {
            $sheet->setCellValue([$index + 1, $startRow - 1], $header);
        }

        foreach ($rows as $index => $row) {
            $excelRow = $startRow + $index;
            $sheet->setCellValue("A{$excelRow}", $receiptNumber + $index);
            $sheet->setCellValue("B{$excelRow}", $row[0]);
            $sheet->setCellValue("C{$excelRow}", $row[1]);
            $sheet->setCellValue("D{$excelRow}", $row[2]);
            $sheet->setCellValue("E{$excelRow}", $row[3]);
            $sheet->setCellValue("F{$excelRow}", $row[4]);
            $sheet->setCellValue("G{$excelRow}", $row[5]);
            $sheet->setCellValue("H{$excelRow}", $row[6]);
        }

        $totalRow = $startRow + count($rows);
        $sheet->setCellValue("F{$totalRow}", "=SUM(F{$startRow}:F" . ($totalRow - 1) . ')');
        $sheet->setCellValue("G{$totalRow}", "=SUM(G{$startRow}:G" . ($totalRow - 1) . ')');
        $sheet->setCellValue("H{$totalRow}", "=+G{$totalRow}-F{$totalRow}");

        $this->styleReceiptBlock($sheet, $startRow, $totalRow);
    }

    private function dayText(DateTimeImmutable $date): string
    {
        return ((int) $date->format('d')) . ' ' . $this->monthName((int) $date->format('n'));
    }

    private function monthName(int $month): string
    {
        return [
            1 => 'ENERO',
            2 => 'FEBRERO',
            3 => 'MARZO',
            4 => 'ABRIL',
            5 => 'MAYO',
            6 => 'JUNIO',
            7 => 'JULIO',
            8 => 'AGOSTO',
            9 => 'SEPTIEMBRE',
            10 => 'OCTUBRE',
            11 => 'NOVIEMBRE',
            12 => 'DICIEMBRE',
        ][$month];
    }

    private function styleTopSummary(Worksheet $sheet): void
    {
        foreach (['C2:D2', 'C4:D4', 'F4:G6'] as $range) {
            $sheet->getStyle($range)->getFont()->setBold(true);
            $sheet->getStyle($range)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        }

        $sheet->getStyle('C2:D2')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFD9EAF7');
        $sheet->getStyle('G4:G5')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFF2CC');
        $sheet->getStyle('C4:F6')->getNumberFormat()->setFormatCode('#,##0');
    }

    private function styleRawTable(Worksheet $sheet, array $headers, int $headerRow, int $dataStartRow, int $subtotalRow): void
    {
        $lastColumn = Coordinate::stringFromColumnIndex(count($headers));

        $sheet->getStyle("A{$headerRow}:{$lastColumn}{$headerRow}")->getFont()->setBold(true);
        $sheet->getStyle("A{$headerRow}:{$lastColumn}{$headerRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFD9EAF7');
        $sheet->getStyle("A{$headerRow}:{$lastColumn}{$subtotalRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle("A{$headerRow}:{$lastColumn}{$headerRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("I{$dataStartRow}:T{$subtotalRow}")->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle("I{$subtotalRow}:T{$subtotalRow}")->getFont()->setBold(true);
        $sheet->getStyle("I{$subtotalRow}:T{$subtotalRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFF2CC');
    }

    private function styleReceiptBlock(Worksheet $sheet, int $startRow, int $totalRow): void
    {
        $titleRow = $startRow - 2;
        $headerRow = $startRow - 1;

        $sheet->getStyle("A{$titleRow}:H{$titleRow}")->getFont()->setBold(true)->setSize(12);
        $sheet->getStyle("A{$titleRow}:H{$titleRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("A{$titleRow}:H{$titleRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFBDD7EE');
        $sheet->getStyle("A{$headerRow}:H{$headerRow}")->getFont()->setBold(true);
        $sheet->getStyle("A{$headerRow}:H{$headerRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFD9EAF7');
        $sheet->getStyle("A{$titleRow}:H{$totalRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle("F{$startRow}:G{$totalRow}")->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle("F{$totalRow}:H{$totalRow}")->getFont()->setBold(true);
        $sheet->getStyle("H{$startRow}:H{$totalRow}")->getAlignment()->setWrapText(true);
    }

    private function autosizeColumns(Worksheet $sheet, int $columnCount): void
    {
        for ($index = 1; $index <= $columnCount; $index++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($index))->setAutoSize(true);
        }

        $sheet->getColumnDimension('C')->setWidth(35);
        $sheet->getColumnDimension('H')->setWidth(58);
    }
}
