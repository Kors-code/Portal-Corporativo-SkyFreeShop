<?php

namespace App\Services\Banking;

use App\Models\Banking\BankImportBatch;
use DOMDocument;
use DOMXPath;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

class BankImportExportService
{
    private const DAVIBANK_HEADERS = [
        'NUMERO', 'NIT', 'CONSECUTIVO', 'CODIGO_RED', 'FECHA_ARCHIVO', 'ABONO_DEVOL', 'FECHA_ABONO', 'ESTABLECIMIENTO',
        'VALOR_ABONADO', 'VALOR_COMISION', 'VALOR_RETENCION', 'VALOR_IVA', 'VALOR_RETEIVA', 'NETO_ABONADO',
        'NUMERO_CUENTA', 'VALOR_PROPINA', 'NOMBRE_ALMACEN', 'NOMBREALMACEN', 'RETEICA', 'VALOR_COMPRA',
        'FECHA_COMPR', 'CODIGOAGENCIA', 'CIUDADAGENCIA', 'NOMBREAGENCIA', 'FRANQUICIA', 'ORIGEN', 'TIPO',
        'TIPO_ABONO', 'FECHA_CONSIG', 'NUM_TERMINAL', 'NUM_COMP', 'NUM_TRANSACC', 'NUM_AUTORIZACION', 'NUM_TARJETA',
        'TIPTARJ', 'UBICACION_TERM', 'CANAL_RECHAZO', 'RED_ADQUIRIENTE', 'FECHA_PROCESO', 'NUM_SEUDOCTA',
        'MATIPREG', 'MATIPRE', 'NOMBRE_TITULAR', 'ID_MOVIMIENTO',
    ];

    private const DAVIVIENDA_HEADERS = [
        'Fecha Vale', 'Fecha Proceso', 'Fecha de Abono', 'Bol. Ruta', 'Recap', 'Vale', 'Red', 'Terminal',
        'Número Autoriza', 'Valor Consumo', 'Valor Iva', 'Imp Al Consumo', 'Valor Propina', 'Valor Comisión',
        'Ret. Fuente', 'Ret. IVA', 'Ret. ICA', 'Valor Neto', 'Bases Dev. IVA', 'Hora Trans.', 'Tarjeta Socio', 'Tipo Tarjeta',
    ];

    private const BOGOTA_HEADERS = [
        'FEC_CONSI', 'FEC_TR', 'FEC_CANJE', 'TIPO', 'ORIGEN', 'CUENTA', 'TR', 'CODIGO', 'NOM_ESTAB', 'AUTORI',
        'TARJETA', 'T', 'MARCA', 'FRANQ', 'COMPRA', 'IVA', 'VAL_INC', 'PROPINA', 'TOTAL', 'DESCUENTO',
        'COMISION', 'IVACOM', 'RETERENTA', 'RETEIVA', 'RETEICA', 'NETO', 'OFI', 'CPBTE', 'SEC', 'RED', 'TERM',
    ];

    /**
     * @return array{path: string, filename: string, batch_id: int, rows: int, rows_imported: int, rows_skipped: int, sheets: int}
     */
    public function importAndExport(string $bankCode, UploadedFile $file, int $receiptStart, ?int $userId = null): array
    {
        $bankCode = strtolower(trim($bankCode));
        if (! in_array($bankCode, ['davibank', 'davivienda', 'bancolombia', 'bancodebogota'], true)) {
            throw new RuntimeException('Banco no soportado.');
        }

        if ($receiptStart <= 0) {
            throw new RuntimeException('El numero inicial del recibo debe ser mayor que 0.');
        }

        $parsed = match ($bankCode) {
            'davibank' => $this->parseDavibank($file->getRealPath()),
            'davivienda' => $this->parseDaviviendaHtml($file->getRealPath()),
            'bancolombia' => $this->parseBancolombiaCsv($file->getRealPath()),
            'bancodebogota' => $this->parseBancoBogotaCsv($file->getRealPath()),
        };

        if ($parsed['rows'] === []) {
            throw new RuntimeException('No hay filas validas para exportar con las reglas del banco seleccionado.');
        }

        [$newRows, $duplicateRows] = $this->partitionNewRows($bankCode, $parsed['rows']);
        $batch = $this->persistBatch($bankCode, $file, $parsed, $newRows, $duplicateRows, $receiptStart, $userId);
        $path = $this->writeFinalWorkbook($bankCode, $parsed['headers'], $parsed['rows'], $receiptStart);

        return [
            'path' => $path,
            'filename' => $this->finalFilename($bankCode, $batch->id),
            'batch_id' => $batch->id,
            'rows' => count($parsed['rows']),
            'rows_imported' => count($newRows),
            'rows_skipped' => (int) $parsed['rows_skipped'] + count($duplicateRows),
            'sheets' => count($this->groupRows($bankCode, $parsed['rows'])),
        ];
    }

    /**
     * @return array{path: string, filename: string, batch_id: int, rows: int, sheets: int}
     */
    public function exportBatch(int $batchId, ?int $receiptStart = null): array
    {
        $batch = BankImportBatch::findOrFail($batchId);
        $bankCode = strtolower((string) $batch->bank);
        if (! in_array($bankCode, ['davibank', 'davivienda', 'bancolombia', 'bancodebogota'], true)) {
            throw new RuntimeException('Este lote no corresponde a un banco exportable.');
        }

        $rows = $batch->movements()
            ->orderBy('deposit_date')
            ->orderBy('movement_date')
            ->orderBy('row_number')
            ->get()
            ->map(function ($movement): array {
                $payload = is_array($movement->raw_payload)
                    ? $movement->raw_payload
                    : json_decode((string) $movement->raw_payload, true);

                return is_array($payload) ? $payload : [];
            })
            ->filter(fn (array $row): bool => $row !== [])
            ->values()
            ->all();

        if ($rows === []) {
            throw new RuntimeException('El lote no tiene movimientos guardados para exportar.');
        }

        $metadata = is_array($batch->metadata) ? $batch->metadata : [];
        $receiptStart ??= (int) ($metadata['receipt_start'] ?? 1);
        $receiptStart = $receiptStart > 0 ? $receiptStart : 1;

        $headers = match ($bankCode) {
            'davibank' => self::DAVIBANK_HEADERS,
            'davivienda' => self::DAVIVIENDA_HEADERS,
            'bancolombia' => [],
            'bancodebogota' => self::BOGOTA_HEADERS,
            default => [],
        };

        $path = $this->writeFinalWorkbook($bankCode, $headers, $rows, $receiptStart);

        return [
            'path' => $path,
            'filename' => $this->finalFilename($bankCode, $batch->id),
            'batch_id' => $batch->id,
            'rows' => count($rows),
            'sheets' => count($this->groupRows($bankCode, $rows)),
        ];
    }

    /**
     * @return array{headers: array<int, string>, rows: array<int, array<string, mixed>>, rows_total: int, rows_skipped: int, source_type: string, file_format_code: string}
     */
    private function parseDavibank(string $path): array
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false || $lines === []) {
            throw new RuntimeException('El archivo Davibank esta vacio.');
        }

        $headers = $this->parseDavibankLine($lines[0]);
        $missing = array_values(array_diff(self::DAVIBANK_HEADERS, $headers));
        if ($missing !== []) {
            throw new RuntimeException('Faltan columnas Davibank: ' . implode(', ', $missing));
        }

        $rows = [];
        $skipped = 0;
        foreach (array_slice($lines, 1) as $offset => $line) {
            $values = $this->parseDavibankLine($line);
            $row = [];
            foreach ($headers as $index => $header) {
                $row[$header] = $values[$index] ?? '';
            }

            if (! in_array(trim((string) $row['CODIGO_RED']), ['VD', 'RD'], true)) {
                $skipped++;
                continue;
            }

            foreach (['VALOR_ABONADO', 'VALOR_COMISION', 'VALOR_RETENCION', 'VALOR_IVA', 'VALOR_RETEIVA', 'NETO_ABONADO', 'VALOR_PROPINA', 'RETEICA', 'VALOR_COMPRA'] as $column) {
                $row[$column] = $this->parseMoney($row[$column]);
            }

            if ((float) $row['VALOR_COMISION'] === 0.0 && (float) $row['VALOR_RETENCION'] === 0.0 && (float) $row['NETO_ABONADO'] === 0.0) {
                $skipped++;
                continue;
            }

            $row['_row_number'] = $offset + 2;
            $row['_date_key'] = $this->dateString($row['FECHA_ABONO'] ?? null) ?? '';
            $row['_movement_uid'] = $this->hashParts([
                'davibank', $row['CODIGO_RED'], $row['ABONO_DEVOL'], $row['NUM_AUTORIZACION'], $row['NUM_TERMINAL'],
                $row['NUM_COMP'], $row['NUM_TARJETA'], $row['FECHA_COMPR'], $row['FECHA_ABONO'], $row['VALOR_COMPRA'],
                $row['VALOR_ABONADO'], $row['NETO_ABONADO'],
            ]);
            $rows[] = $row;
        }

        return [
            'headers' => self::DAVIBANK_HEADERS,
            'rows' => $rows,
            'rows_total' => count($lines) - 1,
            'rows_skipped' => $skipped,
            'source_type' => 'davibank_converter',
            'file_format_code' => 'davibank_sales_csv',
        ];
    }

    /**
     * @return array{headers: array<int, string>, rows: array<int, array<string, mixed>>, rows_total: int, rows_skipped: int, source_type: string, file_format_code: string}
     */
    private function parseDaviviendaHtml(string $path): array
    {
        $html = file_get_contents($path);
        if ($html === false || trim($html) === '') {
            throw new RuntimeException('El archivo Davivienda esta vacio.');
        }

        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($dom);
        $tableRows = $xpath->query('//tr');
        if (!$tableRows) {
            throw new RuntimeException('No se encontro tabla en el archivo Davivienda.');
        }

        $headers = [];
        $rows = [];
        $rowNumber = 0;
        foreach ($tableRows as $tr) {
            $cells = [];
            foreach ($xpath->query('./td|./th', $tr) as $cell) {
                $cells[] = $this->cleanText($cell->textContent);
            }

            if ($cells === []) {
                continue;
            }

            if (in_array('Fecha Vale', $cells, true)) {
                $headers = array_map(fn (string $header): string => str_replace('Imp. Al Consumo', 'Imp Al Consumo', $header), $cells);
                continue;
            }

            if ($headers === [] || count($cells) < 10) {
                continue;
            }

            $rowNumber++;
            $row = [];
            foreach ($headers as $index => $header) {
                $row[$header] = $cells[$index] ?? '';
            }

            foreach (['Valor Consumo', 'Valor Iva', 'Imp Al Consumo', 'Valor Propina', 'Valor Comisión', 'Ret. Fuente', 'Ret. IVA', 'Ret. ICA', 'Valor Neto', 'Bases Dev. IVA'] as $column) {
                $row[$column] = $this->parseMoney($row[$column] ?? 0);
            }

            $row['_row_number'] = $rowNumber;
            $row['_date_key'] = $this->dateString($row['Fecha de Abono'] ?? null) ?? '';
            $row['_movement_uid'] = $this->hashParts([
                'davivienda', $row['Red'] ?? '', $row['Terminal'] ?? '', $row['Vale'] ?? '', $row['Número Autoriza'] ?? '',
                $row['Tarjeta Socio'] ?? '', $row['Fecha Vale'] ?? '', $row['Fecha de Abono'] ?? '', $row['Valor Consumo'] ?? 0,
                $row['Valor Neto'] ?? 0,
            ]);
            $rows[] = $row;
        }

        return [
            'headers' => self::DAVIVIENDA_HEADERS,
            'rows' => $rows,
            'rows_total' => count($rows),
            'rows_skipped' => 0,
            'source_type' => 'davivienda_card_detail',
            'file_format_code' => 'davivienda_card_detail_html',
        ];
    }

    /**
     * @return array{headers: array<int, string>, rows: array<int, array<string, mixed>>, rows_total: int, rows_skipped: int, source_type: string, file_format_code: string}
     */
    private function parseBancolombiaCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if (!$handle) {
            throw new RuntimeException('No se pudo leer el archivo Bancolombia.');
        }

        $rows = [];
        $skipped = 0;
        $rowNumber = 0;
        while (($values = fgetcsv($handle)) !== false) {
            $rowNumber++;
            $values = array_pad($values, 10, '');
            $amount = $this->parseMoney($values[5] ?? 0);
            $code = $this->cleanText($values[6] ?? '');
            $description = $this->cleanText($values[7] ?? '');

            if ($amount <= 0 || ! in_array($code, ['4027', '4065'], true)) {
                $skipped++;
                continue;
            }

            $row = [
                'account_number' => $this->cleanText($values[0] ?? ''),
                'document' => $this->cleanText($values[1] ?? ''),
                'movement_date' => $this->dateString($values[3] ?? null),
                'amount' => $amount,
                'transaction_code' => $code,
                'description' => $description,
                'raw_columns' => array_values($values),
                '_row_number' => $rowNumber,
            ];
            $row['_date_key'] = $row['movement_date'] ?? '';
            $row['_movement_uid'] = $this->hashParts(['bancolombia', $row['account_number'], $row['movement_date'], $amount, $code, $description]);
            $rows[] = $row;
        }
        fclose($handle);

        return [
            'headers' => [],
            'rows' => $rows,
            'rows_total' => $rowNumber,
            'rows_skipped' => $skipped,
            'source_type' => 'bancolombia_account_movement',
            'file_format_code' => 'bancolombia_movements_csv',
        ];
    }

    /**
     * @return array{headers: array<int, string>, rows: array<int, array<string, mixed>>, rows_total: int, rows_skipped: int, source_type: string, file_format_code: string}
     */
    private function parseBancoBogotaCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if (!$handle) {
            throw new RuntimeException('No se pudo leer el archivo Banco de Bogota.');
        }

        $headers = fgetcsv($handle);
        if (! is_array($headers)) {
            fclose($handle);
            throw new RuntimeException('El archivo Banco de Bogota esta vacio.');
        }

        $headers = array_map(fn (string $header): string => trim($header, " \t\n\r\0\x0B\""), $headers);
        $missing = array_values(array_diff(self::BOGOTA_HEADERS, $headers));
        if ($missing !== []) {
            fclose($handle);
            throw new RuntimeException('Faltan columnas Banco de Bogota: ' . implode(', ', $missing));
        }

        $rows = [];
        $skipped = 0;
        $rowNumber = 1;
        while (($values = fgetcsv($handle)) !== false) {
            $rowNumber++;
            $row = [];
            foreach ($headers as $index => $header) {
                $row[$header] = $values[$index] ?? '';
            }

            foreach (['COMPRA', 'IVA', 'VAL_INC', 'PROPINA', 'TOTAL', 'DESCUENTO', 'COMISION', 'IVACOM', 'RETERENTA', 'RETEIVA', 'RETEICA', 'NETO'] as $column) {
                $row[$column] = $this->parseMoney($row[$column] ?? 0);
            }

            if ((float) ($row['NETO'] ?? 0) <= 0 && (float) ($row['TOTAL'] ?? 0) <= 0) {
                $skipped++;
                continue;
            }

            $row['_row_number'] = $rowNumber;
            $row['_date_key'] = $this->dateString($row['FEC_TR'] ?? null) ?? $this->dateString($row['FEC_CANJE'] ?? null) ?? $this->dateString($row['FEC_CONSI'] ?? null) ?? '';
            $row['_movement_uid'] = $this->hashParts([
                'bancodebogota', $row['FEC_CONSI'] ?? '', $row['FEC_TR'] ?? '', $row['FEC_CANJE'] ?? '',
                $row['CUENTA'] ?? '', $row['AUTORI'] ?? '', $row['TARJETA'] ?? '', $row['SEC'] ?? '',
                $row['TOTAL'] ?? 0, $row['NETO'] ?? 0,
            ]);
            $rows[] = $row;
        }
        fclose($handle);

        return [
            'headers' => self::BOGOTA_HEADERS,
            'rows' => $rows,
            'rows_total' => $rowNumber - 1,
            'rows_skipped' => $skipped,
            'source_type' => 'bancodebogota_card_settlement',
            'file_format_code' => 'bancodebogota_sales_csv',
        ];
    }

    private function persistBatch(string $bankCode, UploadedFile $file, array $parsed, array $newRows, array $duplicateRows, int $receiptStart, ?int $userId): BankImportBatch
    {
        $context = $this->bankContext($bankCode, $parsed['file_format_code']);
        $checksum = hash_file('sha256', $file->getRealPath()) ?: null;
        $storedPath = $this->storeSourceFile($bankCode, $file, $checksum);
        $dateKeys = array_values(array_filter(array_map(fn (array $row): string => (string) ($row['_date_key'] ?? ''), $newRows)));
        sort($dateKeys);

        return DB::connection('budget')->transaction(function () use ($bankCode, $file, $parsed, $newRows, $duplicateRows, $receiptStart, $userId, $context, $checksum, $storedPath, $dateKeys): BankImportBatch {
            $batch = BankImportBatch::create([
                'bank_id' => $context['bank_id'],
                'file_format_id' => $context['file_format_id'],
                'bank_account_id' => $context['bank_account_id'],
                'bank' => $bankCode,
                'source_type' => $parsed['source_type'],
                'filename' => $file->getClientOriginalName() ?: $bankCode . '.csv',
                'stored_path' => $storedPath,
                'checksum' => $checksum,
                'status' => count($newRows) > 0 ? 'completed' : 'duplicate',
                'rows' => (int) $parsed['rows_total'],
                'rows_imported' => count($newRows),
                'rows_skipped' => (int) $parsed['rows_skipped'] + count($duplicateRows),
                'from_date' => $dateKeys[0] ?? null,
                'to_date' => $dateKeys[count($dateKeys) - 1] ?? null,
                'total_sale_amount' => $this->sumRows($bankCode, $newRows, 'sale'),
                'total_commission_amount' => $this->sumRows($bankCode, $newRows, 'commission'),
                'total_withholding_amount' => $this->sumRows($bankCode, $newRows, 'withholding'),
                'total_income_amount' => $this->sumRows($bankCode, $newRows, 'income'),
                'total_debit_amount' => $this->sumRows($bankCode, $newRows, 'debit'),
                'total_credit_amount' => $this->sumRows($bankCode, $newRows, 'credit'),
                'metadata' => [
                    'receipt_start' => $receiptStart,
                    'source_rows_for_export' => count($parsed['rows']),
                    'skipped_duplicate_movements' => count($duplicateRows),
                    'file_format_code' => $parsed['file_format_code'],
                ],
                'created_by' => $userId,
            ]);

            foreach (array_chunk($this->movementRows($bankCode, $batch->id, $newRows, $context), 500) as $chunk) {
                DB::connection('budget')->table('bank_movements')->insert($chunk);
            }

            return $batch;
        });
    }

    private function writeFinalWorkbook(string $bankCode, array $headers, array $rows, int $receiptStart): string
    {
        return match ($bankCode) {
            'davibank' => $this->writeDavibankWorkbook($headers, $rows, $receiptStart),
            'davivienda' => $this->writeDaviviendaWorkbook($headers, $rows, $receiptStart),
            'bancolombia' => $this->writeBancolombiaWorkbook($rows, $receiptStart),
            'bancodebogota' => $this->writeBancoBogotaWorkbook($headers, $rows, $receiptStart),
            default => throw new RuntimeException('Banco no soportado para exportar.'),
        };
    }

    private function writeDavibankWorkbook(array $headers, array $rows, int $receiptStart): string
    {
        $spreadsheet = $this->newSpreadsheet();
        $receipt = $receiptStart;
        foreach ($this->groupRows('davibank', $rows) as $dateKey => $dayRows) {
            $date = $this->dateObject($dateKey);
            $sheet = new Worksheet($spreadsheet, $this->sheetName($date));
            $spreadsheet->addSheet($sheet);
            $receipt = $this->fillDavibankSheet($sheet, $headers, $dayRows, $date, $receipt);
        }

        return $this->saveSpreadsheet($spreadsheet, 'davibank_final_');
    }

    private function fillDavibankSheet(Worksheet $sheet, array $headers, array $rows, \DateTimeImmutable $date, int $receipt): int
    {
        $visa = $this->sumWhere($rows, 'CODIGO_RED', 'VD', 'VALOR_ABONADO');
        $redeban = $this->sumWhere($rows, 'CODIGO_RED', 'RD', 'VALOR_ABONADO');
        $sheet->setCellValue('C2', 'Visa');
        $sheet->setCellValue('D2', 'REDEBAN');
        $sheet->setCellValue('C4', $visa);
        $sheet->setCellValue('D4', $redeban);
        $sheet->setCellValue('F4', '=SUM(C4:D4)');
        $sheet->setCellValue('G4', 'BANCO');
        $sheet->setCellValue('F5', '=SUM(C5:D5)');
        $sheet->setCellValue('G5', 'VENTAS');
        $sheet->setCellValue('C6', '=+C4');
        $sheet->setCellValue('D6', '=+D4');
        $sheet->setCellValue('F6', '=+F4-F5');
        $this->writeTable($sheet, $headers, $rows, 8);
        $subtotalRow = count($rows) + 10;
        foreach (range('I', 'T') as $column) {
            $sheet->setCellValue("{$column}{$subtotalRow}", "=SUBTOTAL(9,{$column}9:{$column}" . ($subtotalRow - 2) . ')');
        }
        $this->styleSheet($sheet, count($headers), $subtotalRow);
        $receiptRow = $subtotalRow + 4;
        $this->fillDavibankReceipt($sheet, $date, $receiptRow, $receipt, $subtotalRow);
        if ($this->hasDavibankReturnRows($rows)) {
            $this->fillDavibankRefundReceipt($sheet, $date, $receiptRow + 11, $receipt + 1, $rows);
        }
        $this->autosize($sheet, count($headers));

        return $receipt + ($this->hasDavibankReturnRows($rows) ? 2 : 1);
    }

    private function fillDavibankReceipt(Worksheet $sheet, \DateTimeImmutable $date, int $startRow, int $receipt, int $subtotalRow): void
    {
        $dayText = $this->dayText($date);
        $rows = [
            ['13551808', 'Ica 0.6%', '860034594', 'Colpatria', "=+S{$subtotalRow}", null, "RC VTAS PAGO {$dayText} TARJETA COLPATRIA ICA"],
            ['13551508', 'Rtefte 1,5%', '860034594', 'Colpatria', "=+K{$subtotalRow}", null, "RC VTAS PAGO {$dayText} TARJETACOLPATRIARETENCION"],
            ['53051501', 'Comisiones no gravadas', '860034594', 'Colpatria', "=+J{$subtotalRow}", null, "RC VTAS PAGO {$dayText} TARJETA COLPATRIA COMISION"],
            ['11102006', 'Cuenta corriente Colpatria 6841002235-pesos', '860034594', 'Colpatria', '=+C6', null, "RC VTAS PAGO {$dayText} PAGO DE VISA"],
            ['11102006', 'Cuenta corriente Colpatria 6841002235-pesos', '860034594', 'Colpatria', '=+D6', null, "RC VTAS PAGO {$dayText} PAGO REDEBAN"],
            ['11102006', 'Cuenta corriente Colpatria 6841002235-pesos', '860034594', 'Colpatria', null, '=+F' . ($startRow + 2) . '+F' . ($startRow + 1) . '+F' . $startRow, "RC VTAS PAGO {$dayText} PAGO"],
            ['13050503', 'Clientes Nacionales', '222222222', 'Vtas Mostrador', null, '=SUM(F' . $startRow . ':F' . ($startRow + 6) . ')-SUM(G' . $startRow . ':G' . ($startRow + 5) . ')', "RC VTAS PAGO {$dayText} PAGO TARJETA COLPATRIA"],
        ];
        $this->writeReceiptBlock($sheet, $startRow, $receipt, ['RECIBO DE CAJA', 'CUENTA', 'NOMBRE CUENTA', 'NIT', 'NOMBRE NIT', 'DEBITO', 'CREDITO', 'DETALLE'], $rows, 'F', 'G', 'H');
    }

    private function fillDavibankRefundReceipt(Worksheet $sheet, \DateTimeImmutable $date, int $startRow, int $receipt, array $dayRows): void
    {
        $dayText = $this->dayText($date);
        $refundRows = array_values(array_filter($dayRows, fn (array $row): bool => strtoupper((string) ($row['ABONO_DEVOL'] ?? '')) === 'D'));
        $refundSale = $this->sumNumeric($refundRows, 'VALOR_COMPRA');
        $refundNet = $this->sumNumeric($refundRows, 'NETO_ABONADO');
        $refundCommission = $this->sumNumeric($refundRows, 'VALOR_COMISION');
        $refundRetention = $this->sumNumeric($refundRows, 'VALOR_RETENCION');
        $refundIca = $this->sumNumeric($refundRows, 'RETEICA');

        $rows = [
            ['13551808', 'Ica 0.6%', '860034594', 'Colpatria', null, $refundIca ?: null, "DEVOLUCION VTAS PAGO {$dayText} TARJETA COLPATRIA ICA"],
            ['13551508', 'Rtefte 1,5%', '860034594', 'Colpatria', null, $refundRetention ?: null, "DEVOLUCION VTAS PAGO {$dayText} TARJETA COLPATRIA RETENCION"],
            ['53051501', 'Comisiones no gravadas', '860034594', 'Colpatria', null, $refundCommission ?: null, "DEVOLUCION VTAS PAGO {$dayText} TARJETA COLPATRIA COMISION"],
            ['11102006', 'Cuenta corriente Colpatria 6841002235-pesos', '860034594', 'Colpatria', null, $refundNet ?: null, "DEVOLUCION VTAS PAGO {$dayText} BANCO"],
            ['13050503', 'Clientes Nacionales', '222222222', 'Vtas Mostrador', $refundSale ?: null, null, "DEVOLUCION VTAS PAGO {$dayText} TARJETA COLPATRIA"],
        ];
        $this->writeReceiptBlock($sheet, $startRow, $receipt, ['RECIBO DE CAJA', 'CUENTA', 'NOMBRE CUENTA', 'NIT', 'NOMBRE NIT', 'DEBITO', 'CREDITO', 'DETALLE'], $rows, 'F', 'G', 'H');
    }

    private function hasDavibankReturnRows(array $rows): bool
    {
        foreach ($rows as $row) {
            if (strtoupper((string) ($row['ABONO_DEVOL'] ?? '')) === 'D') {
                return true;
            }
        }

        return false;
    }

    private function writeDaviviendaWorkbook(array $headers, array $rows, int $receiptStart): string
    {
        $spreadsheet = $this->newSpreadsheet();
        $receipt = $receiptStart;
        foreach ($this->groupRows('davivienda', $rows) as $dateKey => $dayRows) {
            $date = $this->dateObject($dateKey);
            $sheet = new Worksheet($spreadsheet, $this->sheetName($date));
            $spreadsheet->addSheet($sheet);
            $this->writeTable($sheet, $headers, $dayRows, 1);
            $totalRow = count($dayRows) + 2;
            foreach (range('J', 'S') as $column) {
                $sheet->setCellValue("{$column}{$totalRow}", "=SUM({$column}2:{$column}" . ($totalRow - 1) . ')');
            }
            $receiptRow = $totalRow + 6;
            $this->fillDaviviendaReceipt($sheet, $date, $receiptRow, $receipt, $totalRow);
            if ($this->hasDaviviendaReturnRows($dayRows)) {
                $this->fillDaviviendaRefundReceipt($sheet, $date, $receiptRow + 8, $receipt + 1, $dayRows);
            }
            $this->styleSheet($sheet, count($headers), $totalRow);
            $this->autosize($sheet, count($headers));
            $receipt += $this->hasDaviviendaReturnRows($dayRows) ? 2 : 1;
        }

        return $this->saveSpreadsheet($spreadsheet, 'davivienda_final_');
    }

    private function fillDaviviendaReceipt(Worksheet $sheet, \DateTimeImmutable $date, int $startRow, int $receipt, int $totalRow): void
    {
        $day = ((int) $date->format('d'));
        $rows = [
            ['11200501', 'Cta ahorro Davivienda 475670049406', '860034313', "=+R{$totalRow}", null, "RC VTAS PAGO TARJETA DAVIVIENDA {$this->monthName((int) $date->format('n'))} {$day}"],
            ['13551508', 'Retención en la fuente 1.5%', '860034313', "=+O{$totalRow}", null, "RC VTAS PAGO TARJETA DAVIVIENDA RETEFUENTE DAVIVIENDA {$this->monthName((int) $date->format('n'))} {$day}"],
            ['53051501', 'Comisiones bancarias no gravadas', '860034313', "=+N{$totalRow}", null, "RC VTAS PAGO TARJETA DAVIVIENDA COMISION DAVIVIENDA {$this->monthName((int) $date->format('n'))} {$day}"],
            ['13050501', 'Clientes nacionales', '222222222', null, "=+J{$totalRow}", "RC VTAS PAGO TARJETA DAVIVIENDA {$this->monthName((int) $date->format('n'))} {$day}"],
        ];
        $this->writeReceiptBlock($sheet, $startRow, $receipt, ['COMPROBANTE', 'CUENTA', 'NOMBRE CUENTA', 'NIT', 'DEBITO', 'CREDITO', 'DETALLE'], $rows, 'E', 'F', 'G');
    }

    private function fillDaviviendaRefundReceipt(Worksheet $sheet, \DateTimeImmutable $date, int $startRow, int $receipt, array $dayRows): void
    {
        $day = ((int) $date->format('d'));
        $month = $this->monthName((int) $date->format('n'));
        $returnRows = array_values(array_filter($dayRows, fn (array $row): bool => $this->isDaviviendaReturnRow($row)));
        $refundSale = abs($this->sumNumeric($returnRows, 'Valor Consumo'));
        $refundNet = abs($this->sumNumeric($returnRows, 'Valor Neto'));
        $refundCommission = abs($this->sumNumeric($returnRows, 'Valor Comisión'));
        $refundRetention = abs(
            $this->sumNumeric($returnRows, 'Ret. Fuente')
            + $this->sumNumeric($returnRows, 'Ret. IVA')
            + $this->sumNumeric($returnRows, 'Ret. ICA')
        );
        $refundBase = abs($this->sumNumeric($returnRows, 'Bases Dev. IVA'));
        if ($refundSale === 0.0 && $refundBase > 0.0) {
            $refundSale = $refundBase;
        }

        $rows = [
            ['11200501', 'Cta ahorro Davivienda 475670049406', '860034313', null, $refundNet ?: null, "DEVOLUCION VTAS PAGO TARJETA DAVIVIENDA {$month} {$day}"],
            ['13551508', 'RetenciÃ³n en la fuente 1.5%', '860034313', null, $refundRetention ?: null, "DEVOLUCION VTAS PAGO TARJETA DAVIVIENDA RETEFUENTE {$month} {$day}"],
            ['53051501', 'Comisiones bancarias no gravadas', '860034313', null, $refundCommission ?: null, "DEVOLUCION VTAS PAGO TARJETA DAVIVIENDA COMISION {$month} {$day}"],
            ['13050501', 'Clientes nacionales', '222222222', $refundSale ?: null, null, "DEVOLUCION VTAS PAGO TARJETA DAVIVIENDA {$month} {$day}"],
        ];
        $this->writeReceiptBlock($sheet, $startRow, $receipt, ['COMPROBANTE', 'CUENTA', 'NOMBRE CUENTA', 'NIT', 'DEBITO', 'CREDITO', 'DETALLE'], $rows, 'E', 'F', 'G');
    }

    private function hasDaviviendaReturnRows(array $rows): bool
    {
        foreach ($rows as $row) {
            if ($this->isDaviviendaReturnRow($row)) {
                return true;
            }
        }

        return false;
    }

    private function isDaviviendaReturnRow(array $row): bool
    {
        return (float) ($row['Valor Consumo'] ?? 0) < 0
            || (float) ($row['Valor Neto'] ?? 0) < 0
            || (float) ($row['Valor Comisión'] ?? 0) < 0
            || (float) ($row['Ret. Fuente'] ?? 0) < 0
            || (float) ($row['Ret. IVA'] ?? 0) < 0
            || (float) ($row['Ret. ICA'] ?? 0) < 0
            || (float) ($row['Bases Dev. IVA'] ?? 0) !== 0.0;
    }

    private function writeBancoBogotaWorkbook(array $headers, array $rows, int $receiptStart): string
    {
        $spreadsheet = $this->newSpreadsheet();
        $receipt = $receiptStart;
        foreach ($this->groupRows('bancodebogota', $rows) as $dateKey => $dayRows) {
            $date = $this->dateObject($dateKey);
            $sheet = new Worksheet($spreadsheet, $this->sheetName($date));
            $spreadsheet->addSheet($sheet);
            $this->fillBancoBogotaSheet($sheet, $headers, $dayRows, $date, $receipt);
            $receipt++;
        }

        return $this->saveSpreadsheet($spreadsheet, 'bancodebogota_final_');
    }

    private function fillBancoBogotaSheet(Worksheet $sheet, array $headers, array $rows, \DateTimeImmutable $date, int $receipt): void
    {
        foreach (['Fecha', 'Ciudad', 'Debitos', 'Creditos', 'Marca', 'Franquicia', 'Descripcion'] as $index => $header) {
            $sheet->setCellValue([$index + 1, 3], $header);
        }

        $franchises = [
            ['AmericanExpress', '4', 6133, 'Depositos Electronicos AmericanExpress'],
            ['MasterCard', '2', 6050, 'Deposito Electronico MasterCard'],
            ['Visa', '1', 6040, 'Deposito Electronico Visa'],
        ];
        foreach ($franchises as $index => [$name, $marca, $franquicia, $description]) {
            $rowNumber = 4 + $index;
            $netAmount = $this->sumWhere($rows, 'MARCA', $marca, 'NETO');
            $sheet->setCellValue("A{$rowNumber}", ExcelDate::PHPToExcel($date));
            $sheet->setCellValue("B{$rowNumber}", 'Cartagena');
            $sheet->setCellValue("D{$rowNumber}", $netAmount ?: null);
            $sheet->setCellValue("E{$rowNumber}", $marca);
            $sheet->setCellValue("F{$rowNumber}", $franquicia);
            $sheet->setCellValue("G{$rowNumber}", $description);
        }
        $sheet->setCellValue('D7', '=SUM(D4:D6)');
        $sheet->getStyle('A4:A6')->getNumberFormat()->setFormatCode('yyyy-mm-dd');
        $this->fillBancoBogotaControl($sheet, $rows);

        $this->writeTable($sheet, $headers, $rows, 10);
        $totalRow = count($rows) + 11;
        foreach (range('O', 'Z') as $column) {
            $sheet->setCellValue("{$column}{$totalRow}", "=SUM({$column}11:{$column}" . ($totalRow - 1) . ')');
        }
        $this->styleSheet($sheet, count($headers), $totalRow);
        $this->fillBancoBogotaReceipt($sheet, $date, $totalRow + 4, $receipt, $totalRow);
        $this->autosize($sheet, count($headers));
    }

    private function fillBancoBogotaControl(Worksheet $sheet, array $rows): void
    {
        $sheet->fromArray(['Visa', 'MasterCard', 'AmericanExpress'], null, 'N3');
        $sheet->fromArray([1, 2, 4], null, 'N4');
        $sheet->fromArray(['=+D6', '=+D5', '=+D4', '=SUM(N5:P5)'], null, 'N5');
        $sheet->fromArray([
            $this->sumWhere($rows, 'MARCA', '1', 'TOTAL') ?: null,
            $this->sumWhere($rows, 'MARCA', '2', 'TOTAL') ?: null,
            $this->sumWhere($rows, 'MARCA', '4', 'TOTAL') ?: null,
            '=SUM(N6:P6)',
        ], null, 'N6');
        $sheet->fromArray(['=+N5-N6', '=+O5-O6', '=+P5-P6', '=+Q5-Q6'], null, 'N7');
        $sheet->getStyle('N3:Q7')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle('N3:P3')->getFont()->setBold(true);
        $sheet->getStyle('N3:P3')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFD9EAF7');
    }

    private function fillBancoBogotaReceipt(Worksheet $sheet, \DateTimeImmutable $date, int $startRow, int $receipt, int $totalRow): void
    {
        $dayText = $this->dayText($date);
        $rows = [
            ['13551817', 'ICA 0.4%', '860002963', 'Banco de Bogota', "=+Y{$totalRow}", null, "RC VTAS PAGO {$dayText} TARJETA BANCO DE BOGOTA"],
            ['13551508', 'Rtefte 1,5%', '860002964', 'Banco de Bogota', "=+W{$totalRow}", null, "RC VTAS PAGO {$dayText} TARJETA BANCO DE BOGOTA"],
            ['53051501', 'Comisiones no gravadas', '860002964', 'Banco de Bogota', "=+U{$totalRow}", null, "RC VTAS PAGO {$dayText} PAGO TARJETA BANCO DE BOGOTA"],
            ['11102004', 'Banco de Bogota Ahorros', '860002964', 'Banco de Bogota', '=+D4', null, "RC VTAS PAGO {$dayText} PAGO DE AMERICANEXPRESS"],
            ['11102004', 'Banco de Bogota Ahorros', '860002964', 'Banco de Bogota', '=+D6', null, "RC VTAS PAGO {$dayText} PAGO DE VISA"],
            ['11102004', 'Banco de Bogota Ahorros', '860002964', 'Banco de Bogota', '=+D5', null, "RC VTAS PAGO {$dayText} PAGO DE MASTERCARD"],
            ['13050501', 'Clientes Nacionales', '222222222', 'Vtas Mostrador', null, "=+S{$totalRow}", "RC VTAS PAGO {$dayText} TARJETA BANCO DE BOGOTA"],
        ];
        $this->writeReceiptBlock($sheet, $startRow, $receipt, ['RECIBO DE CAJA', 'CUENTA', 'NOMBRE CUENTA', 'NIT', 'NOMBRE NIT', 'DEBITO', 'CREDITO', 'DETALLE'], $rows, 'F', 'G', 'H');
    }

    private function writeBancolombiaWorkbook(array $rows, int $receiptStart): string
    {
        $spreadsheet = $this->newSpreadsheet();
        $raw = new Worksheet($spreadsheet, 'MOVIMIENTOS');
        $spreadsheet->addSheet($raw);
        foreach ($rows as $r => $row) {
            foreach (($row['raw_columns'] ?? []) as $c => $value) {
                $raw->setCellValue([$c + 1, $r + 1], $this->numericOrText($value));
            }
        }
        $this->autosize($raw, 9);

        $receipts = new Worksheet($spreadsheet, 'RECIBOS');
        $spreadsheet->addSheet($receipts);
        $currentRow = 1;
        $receipt = $receiptStart;
        foreach ($this->groupRows('bancolombia', $rows) as $dateKey => $dayRows) {
            $date = $this->dateObject($dateKey);
            foreach ($dayRows as $row) {
                $concept = 'RC VENTAS BANCOLOMBIA ' . $date->format('d') . ' ' . $this->monthName((int) $date->format('n')) . ' ' . $date->format('Y');
                $receiptCode = 'CC-15-' . $receipt;
                $this->writeBancolombiaReceipt($receipts, $currentRow, $receiptCode, $date, (float) $row['amount'], $concept);
                $currentRow += 4;
            }
            $receipt++;
        }
        $this->autosize($receipts, 9);

        return $this->saveSpreadsheet($spreadsheet, 'bancolombia_final_');
    }

    private function writeBancolombiaReceipt(Worksheet $sheet, int $row, string $receiptCode, \DateTimeImmutable $date, float $amount, string $concept): void
    {
        foreach (['RECIBO DE CAJA', 'FECHA', 'CUENTA', 'NOMBRE CUENTA', 'NIT', 'NOMBRE NIT', 'DEBITO', 'CREDITO', 'CONCEPTO'] as $i => $header) {
            $sheet->setCellValue([$i + 1, $row], $header);
        }
        $sheet->fromArray([
            [$receiptCode, ExcelDate::PHPToExcel($date), '11200502', 'Banc ahorro 4694', '890903938', 'Bancolombia', $amount, null, $concept],
            [$receiptCode, ExcelDate::PHPToExcel($date), '13050503', 'Clientes Nacionales', '222222222', 'Vtas Mostrador', null, $amount, $concept],
        ], null, "A" . ($row + 1));
        $sheet->getStyle("B" . ($row + 1) . ':B' . ($row + 2))->getNumberFormat()->setFormatCode('yyyy-mm-dd');
        $sheet->getStyle("G" . ($row + 1) . ':H' . ($row + 2))->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle("A{$row}:I{$row}")->getFont()->setBold(true);
        $sheet->getStyle("A{$row}:I{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFD9EAF7');
        $sheet->getStyle("A{$row}:I" . ($row + 2))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    }

    private function movementRows(string $bankCode, int $batchId, array $rows, array $context): array
    {
        $now = now();
        return array_map(function (array $row) use ($bankCode, $batchId, $context, $now): array {
            return [
                'bank_id' => $context['bank_id'],
                'bank_account_id' => $context['bank_account_id'],
                'batch_id' => $batchId,
                'bank' => $bankCode,
                'source_type' => match ($bankCode) {
                    'davibank' => 'davibank_converter',
                    'davivienda' => 'davivienda_card_detail',
                    'bancolombia' => 'bancolombia_account_movement',
                    'bancodebogota' => 'bancodebogota_card_settlement',
                    default => 'bank_movement',
                },
                'movement_uid' => $row['_movement_uid'] ?? null,
                'row_number' => $row['_row_number'] ?? null,
                'movement_date' => $this->movementDate($bankCode, $row),
                'process_date' => $this->processDate($bankCode, $row),
                'deposit_date' => $this->depositDate($bankCode, $row),
                'account_number' => $this->accountNumber($bankCode, $row),
                'transaction_code' => $this->transactionCode($bankCode, $row),
                'reference' => $this->reference($bankCode, $row),
                'receipt_number' => $this->receiptNumber($bankCode, $row),
                'authorization_number' => $this->authorization($bankCode, $row),
                'terminal' => $this->terminal($bankCode, $row),
                'network' => $this->network($bankCode, $row),
                'card_type' => $this->cardType($bankCode, $row),
                'card_last_digits' => $this->cardLastDigits($bankCode, $row),
                'description' => $this->description($bankCode, $row),
                'movement_type' => $this->movementType($bankCode, $row),
                'category' => $bankCode === 'bancolombia' ? 'account_sale' : 'card_sale',
                'currency' => 'COP',
                'sale_amount' => $this->sumRows($bankCode, [$row], 'sale'),
                'commission_amount' => $this->sumRows($bankCode, [$row], 'commission'),
                'withholding_amount' => $this->sumRows($bankCode, [$row], 'withholding'),
                'withholding_source_amount' => match ($bankCode) {
                    'davibank' => $row['VALOR_RETENCION'] ?? 0,
                    'bancodebogota' => $row['RETERENTA'] ?? 0,
                    default => $row['Ret. Fuente'] ?? 0,
                },
                'withholding_vat_amount' => match ($bankCode) {
                    'davibank' => $row['VALOR_RETEIVA'] ?? 0,
                    'bancodebogota' => $row['RETEIVA'] ?? 0,
                    default => $row['Ret. IVA'] ?? 0,
                },
                'withholding_ica_amount' => match ($bankCode) {
                    'davibank' => $row['RETEICA'] ?? 0,
                    'bancodebogota' => $row['RETEICA'] ?? 0,
                    default => $row['Ret. ICA'] ?? 0,
                },
                'vat_amount' => match ($bankCode) {
                    'davibank' => $row['VALOR_IVA'] ?? 0,
                    'bancodebogota' => $row['IVA'] ?? 0,
                    default => $row['Valor Iva'] ?? 0,
                },
                'consumption_tax_amount' => $row['Imp Al Consumo'] ?? 0,
                'tip_amount' => match ($bankCode) {
                    'davibank' => $row['VALOR_PROPINA'] ?? 0,
                    'bancodebogota' => $row['PROPINA'] ?? 0,
                    default => $row['Valor Propina'] ?? 0,
                },
                'income_amount' => $this->sumRows($bankCode, [$row], 'income'),
                'debit_amount' => $this->sumRows($bankCode, [$row], 'debit'),
                'credit_amount' => $this->sumRows($bankCode, [$row], 'credit'),
                'net_amount' => $this->sumRows($bankCode, [$row], 'income'),
                'is_sale' => true,
                'is_income' => true,
                'is_expense' => false,
                'is_excluded' => false,
                'raw_payload' => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }, $rows);
    }

    private function partitionNewRows(string $bankCode, array $rows): array
    {
        $uids = array_values(array_unique(array_filter(array_map(fn (array $row): ?string => $row['_movement_uid'] ?? null, $rows))));
        $existing = [];
        foreach (array_chunk($uids, 1000) as $chunk) {
            $found = DB::connection('budget')->table('bank_movements')->where('bank', $bankCode)->whereIn('movement_uid', $chunk)->pluck('movement_uid')->all();
            foreach ($found as $uid) {
                $existing[(string) $uid] = true;
            }
        }

        $seen = [];
        $newRows = [];
        $duplicates = [];
        foreach ($rows as $row) {
            $uid = (string) ($row['_movement_uid'] ?? '');
            if ($uid === '' || isset($existing[$uid]) || isset($seen[$uid])) {
                $duplicates[] = $row;
                continue;
            }
            $seen[$uid] = true;
            $newRows[] = $row;
        }

        return [$newRows, $duplicates];
    }

    private function groupRows(string $bankCode, array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $key = (string) ($row['_date_key'] ?? '');
            if ($key === '') {
                $key = $this->depositDate($bankCode, $row) ?? $this->movementDate($bankCode, $row) ?? 'sin-fecha';
            }
            $groups[$key][] = $row;
        }
        ksort($groups);

        return $groups;
    }

    private function writeTable(Worksheet $sheet, array $headers, array $rows, int $headerRow): void
    {
        foreach ($headers as $index => $header) {
            $sheet->setCellValue([$index + 1, $headerRow], $header);
        }
        foreach ($rows as $rowIndex => $row) {
            foreach ($headers as $colIndex => $header) {
                $value = $row[$header] ?? '';
                if ($this->looksDate($value)) {
                    $date = $this->dateObject($this->dateString($value) ?: '');
                    $sheet->setCellValue([$colIndex + 1, $headerRow + 1 + $rowIndex], ExcelDate::PHPToExcel($date));
                    $sheet->getStyle([$colIndex + 1, $headerRow + 1 + $rowIndex])->getNumberFormat()->setFormatCode('yyyy-mm-dd');
                } else {
                    $sheet->setCellValue([$colIndex + 1, $headerRow + 1 + $rowIndex], $this->numericOrText($value));
                }
            }
        }
    }

    private function writeReceiptBlock(Worksheet $sheet, int $startRow, int $receipt, array $headers, array $rows, string $debitColumn, string $creditColumn, string $lastColumn): void
    {
        foreach ($headers as $index => $header) {
            $sheet->setCellValue([$index + 1, $startRow - 1], $header);
        }
        foreach ($rows as $index => $row) {
            $excelRow = $startRow + $index;
            $sheet->setCellValue("A{$excelRow}", $index === 0 ? $receipt : '=+A' . ($excelRow - 1));
            foreach ($row as $column => $value) {
                $sheet->setCellValue([$column + 2, $excelRow], $value);
            }
        }
        $totalRow = $startRow + count($rows);
        $sheet->setCellValue("{$debitColumn}{$totalRow}", "=SUM({$debitColumn}{$startRow}:{$debitColumn}" . ($totalRow - 1) . ')');
        $sheet->setCellValue("{$creditColumn}{$totalRow}", "=SUM({$creditColumn}{$startRow}:{$creditColumn}" . ($totalRow - 1) . ')');
        $sheet->getStyle("A" . ($startRow - 1) . ":{$lastColumn}" . $totalRow)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle("A" . ($startRow - 1) . ":{$lastColumn}" . ($startRow - 1))->getFont()->setBold(true);
        $sheet->getStyle("A" . ($startRow - 1) . ":{$lastColumn}" . ($startRow - 1))->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFD9EAF7');
    }

    private function styleSheet(Worksheet $sheet, int $columns, int $totalRow): void
    {
        $last = Coordinate::stringFromColumnIndex($columns);
        $sheet->getStyle("A1:{$last}1")->getFont()->setBold(true);
        $sheet->getStyle("A1:{$last}1")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFD9EAF7');
        if ($totalRow > 1) {
            $sheet->getStyle("A1:{$last}{$totalRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
            $sheet->getStyle("A{$totalRow}:{$last}{$totalRow}")->getFont()->setBold(true);
        }
    }

    private function newSpreadsheet(): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0);
        return $spreadsheet;
    }

    private function saveSpreadsheet(Spreadsheet $spreadsheet, string $prefix): string
    {
        $spreadsheet->setActiveSheetIndex(0);
        $path = tempnam(storage_path('app'), $prefix);
        if ($path === false) {
            throw new RuntimeException('No se pudo crear archivo temporal.');
        }
        $xlsxPath = $path . '.xlsx';
        @rename($path, $xlsxPath);
        (new Xlsx($spreadsheet))->save($xlsxPath);
        return $xlsxPath;
    }

    private function bankContext(string $bankCode, string $formatCode): array
    {
        $bankId = DB::connection('budget')->table('banks')->where('code', $bankCode)->value('id');
        $formatId = DB::connection('budget')->table('bank_file_formats')->where('code', $formatCode)->value('id');
        $account = match ($bankCode) {
            'davibank' => '6841002235',
            'davivienda' => '475670049406',
            'bancolombia' => '024-000046-94',
            'bancodebogota' => '532444098',
            default => null,
        };
        $accountId = $account ? DB::connection('budget')->table('bank_accounts')->where('account_number', $account)->value('id') : null;

        return ['bank_id' => $bankId ? (int) $bankId : null, 'file_format_id' => $formatId ? (int) $formatId : null, 'bank_account_id' => $accountId ? (int) $accountId : null];
    }

    private function storeSourceFile(string $bankCode, UploadedFile $file, ?string $checksum): ?string
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: 'dat');
        $base = preg_replace('/[^A-Za-z0-9_.-]+/', '_', pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) ?: $bankCode;
        $filename = now()->format('Ymd_His') . '_' . substr((string) $checksum, 0, 12) . '_' . trim($base, '._-') . '.' . $extension;
        $stored = Storage::putFileAs('imports/banks/' . $bankCode, $file, $filename);
        return $stored ?: null;
    }

    private function finalFilename(string $bankCode, int $batchId): string
    {
        return $bankCode . '_final_' . $batchId . '_' . now()->format('Ymd_His') . '.xlsx';
    }

    private function parseDavibankLine(string $line): array
    {
        $line = preg_replace('/^\xEF\xBB\xBF/', '', trim($line));
        if (str_starts_with($line, '"') && str_ends_with($line, '"')) {
            $line = substr($line, 1, -1);
        }
        return array_map(fn (string $value): string => trim($value, "\" \t\n\r\0\x0B"), explode(';', $line));
    }

    private function parseMoney(mixed $value): float
    {
        $text = trim((string) $value);
        if ($text === '') {
            return 0.0;
        }
        $text = str_replace(['$', ' '], '', $text);
        if (str_contains($text, ',')) {
            $text = str_replace(',', '.', str_replace('.', '', $text));
        }
        if (str_starts_with($text, '.')) {
            $text = '0' . $text;
        }
        return round((float) $text, 2);
    }

    private function dateString(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '' || $value === '0000/00/00') {
            return null;
        }
        if (preg_match('/^\d{8}$/', $value)) {
            return substr($value, 0, 4) . '-' . substr($value, 4, 2) . '-' . substr($value, 6, 2);
        }
        $value = str_replace('//', '/', $value);
        foreach (['Y/m/d', 'Y-m-d', 'd/m/Y', 'd/m/y'] as $format) {
            $date = \DateTimeImmutable::createFromFormat('!' . $format, $value);
            if ($date) {
                return $date->format('Y-m-d');
            }
        }
        return null;
    }

    private function dateObject(string $date): \DateTimeImmutable
    {
        $object = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$object) {
            throw new RuntimeException('Fecha invalida: ' . $date);
        }
        return $object;
    }

    private function looksDate(mixed $value): bool
    {
        return $this->dateString($value) !== null && !is_numeric($value);
    }

    private function numericOrText(mixed $value): mixed
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }
        $text = trim((string) $value);
        return is_numeric($text) ? (float) $text : $text;
    }

    private function cleanText(mixed $value): string
    {
        return trim(preg_replace('/\s+/', ' ', html_entity_decode((string) $value)) ?? '');
    }

    private function hashParts(array $parts): string
    {
        return hash('sha256', implode('|', array_map(fn (mixed $part): string => trim((string) $part), $parts)));
    }

    private function sumRows(string $bankCode, array $rows, string $kind): float
    {
        return array_reduce($rows, function (float $carry, array $row) use ($bankCode, $kind): float {
            return $carry + match ($kind) {
                'sale' => match ($bankCode) {
                    'davivienda' => (float) ($row['Valor Consumo'] ?? 0),
                    'bancolombia' => (float) ($row['amount'] ?? 0),
                    'bancodebogota' => (float) ($row['TOTAL'] ?? 0),
                    default => (float) ($row['VALOR_COMPRA'] ?? 0),
                },
                'commission' => match ($bankCode) {
                    'davivienda' => (float) ($row['Valor Comisión'] ?? 0),
                    'davibank' => (float) ($row['VALOR_COMISION'] ?? 0),
                    'bancodebogota' => (float) ($row['COMISION'] ?? 0),
                    default => 0.0,
                },
                'withholding' => match ($bankCode) {
                    'davivienda' => (float) ($row['Ret. Fuente'] ?? 0) + (float) ($row['Ret. IVA'] ?? 0) + (float) ($row['Ret. ICA'] ?? 0),
                    'davibank' => (float) ($row['VALOR_RETENCION'] ?? 0) + (float) ($row['VALOR_RETEIVA'] ?? 0) + (float) ($row['RETEICA'] ?? 0),
                    'bancodebogota' => (float) ($row['RETERENTA'] ?? 0) + (float) ($row['RETEIVA'] ?? 0) + (float) ($row['RETEICA'] ?? 0),
                    default => 0.0,
                },
                'income' => match ($bankCode) {
                    'davivienda' => (float) ($row['Valor Neto'] ?? 0),
                    'bancolombia' => (float) ($row['amount'] ?? 0),
                    'bancodebogota' => (float) ($row['NETO'] ?? 0),
                    default => (float) ($row['NETO_ABONADO'] ?? 0),
                },
                'debit' => $bankCode === 'bancolombia' ? max(0, (float) ($row['amount'] ?? 0)) : 0.0,
                'credit' => 0.0,
                default => 0.0,
            };
        }, 0.0);
    }

    private function sumWhere(array $rows, string $field, string $value, string $sumField): float
    {
        return array_reduce($rows, fn (float $carry, array $row): float => $carry + (((string) ($row[$field] ?? '') === $value) ? (float) ($row[$sumField] ?? 0) : 0), 0.0);
    }

    private function sumNumeric(array $rows, string $field): float
    {
        return array_reduce($rows, fn (float $carry, array $row): float => $carry + (float) ($row[$field] ?? 0), 0.0);
    }

    private function movementDate(string $bankCode, array $row): ?string { return match ($bankCode) { 'davibank' => $this->dateString($row['FECHA_COMPR'] ?? null), 'davivienda' => $this->dateString($row['Fecha Vale'] ?? null), 'bancolombia' => $row['movement_date'] ?? null, 'bancodebogota' => $this->dateString($row['FEC_TR'] ?? null), default => null }; }
    private function processDate(string $bankCode, array $row): ?string { return match ($bankCode) { 'davibank' => $this->dateString($row['FECHA_PROCESO'] ?? null), 'davivienda' => $this->dateString($row['Fecha Proceso'] ?? null), 'bancodebogota' => $this->dateString($row['FEC_CONSI'] ?? null), default => null }; }
    private function depositDate(string $bankCode, array $row): ?string { return match ($bankCode) { 'davibank' => $this->dateString($row['FECHA_ABONO'] ?? null), 'davivienda' => $this->dateString($row['Fecha de Abono'] ?? null), 'bancolombia' => $row['movement_date'] ?? null, 'bancodebogota' => $this->dateString($row['FEC_CANJE'] ?? null), default => null }; }
    private function accountNumber(string $bankCode, array $row): ?string { return match ($bankCode) { 'davibank' => $row['NUMERO_CUENTA'] ?? null, 'davivienda' => '475670049406', 'bancolombia' => $row['account_number'] ?? null, 'bancodebogota' => ltrim((string) ($row['CUENTA'] ?? ''), '0'), default => null }; }
    private function transactionCode(string $bankCode, array $row): ?string { return match ($bankCode) { 'davibank' => $row['NUM_TRANSACC'] ?? null, 'bancolombia' => $row['transaction_code'] ?? null, 'bancodebogota' => $row['TR'] ?? null, default => null }; }
    private function reference(string $bankCode, array $row): ?string { return match ($bankCode) { 'davibank' => $row['ID_MOVIMIENTO'] ?? $row['NUM_COMP'] ?? null, 'davivienda' => $row['Vale'] ?? null, 'bancodebogota' => $row['SEC'] ?? $row['CPBTE'] ?? null, default => null }; }
    private function receiptNumber(string $bankCode, array $row): ?string { return match ($bankCode) { 'davibank' => $row['CONSECUTIVO'] ?? null, 'davivienda' => $row['Vale'] ?? null, 'bancodebogota' => $row['CPBTE'] ?? null, default => null }; }
    private function authorization(string $bankCode, array $row): ?string { return match ($bankCode) { 'davibank' => $row['NUM_AUTORIZACION'] ?? null, 'davivienda' => $row['Número Autoriza'] ?? null, 'bancodebogota' => $row['AUTORI'] ?? null, default => null }; }
    private function terminal(string $bankCode, array $row): ?string { return match ($bankCode) { 'davibank' => $row['NUM_TERMINAL'] ?? null, 'davivienda' => $row['Terminal'] ?? null, 'bancodebogota' => $row['TERM'] ?? null, default => null }; }
    private function network(string $bankCode, array $row): ?string { return match ($bankCode) { 'davibank' => $row['CODIGO_RED'] ?? null, 'davivienda' => $row['Red'] ?? null, 'bancodebogota' => $row['RED'] ?? null, default => null }; }
    private function cardType(string $bankCode, array $row): ?string { return match ($bankCode) { 'davibank' => $row['TIPTARJ'] ?? null, 'davivienda' => $row['Tipo Tarjeta'] ?? null, 'bancodebogota' => $row['FRANQ'] ?? $row['T'] ?? null, default => null }; }
    private function cardLastDigits(string $bankCode, array $row): ?string { $raw = match ($bankCode) { 'davibank' => $row['NUM_TARJETA'] ?? '', 'davivienda' => $row['Tarjeta Socio'] ?? '', 'bancodebogota' => $row['TARJETA'] ?? '', default => '' }; $digits = preg_replace('/\D+/', '', (string) $raw); return $digits ? substr($digits, -4) : null; }
    private function description(string $bankCode, array $row): ?string { return match ($bankCode) { 'davibank' => $row['NOMBREALMACEN'] ?? $row['UBICACION_TERM'] ?? null, 'davivienda' => $row['Tipo Tarjeta'] ?? null, 'bancolombia' => $row['description'] ?? null, 'bancodebogota' => $row['NOM_ESTAB'] ?? $row['FRANQ'] ?? null, default => null }; }
    private function movementType(string $bankCode, array $row): ?string { return match ($bankCode) { 'davibank' => $row['ABONO_DEVOL'] ?? null, 'davivienda' => 'A', 'bancolombia' => 'credit', 'bancodebogota' => $row['T'] ?? null, default => null }; }

    private function autosize(Worksheet $sheet, int $columns): void
    {
        for ($i = 1; $i <= $columns; $i++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setAutoSize(true);
        }
    }

    private function sheetName(\DateTimeImmutable $date): string
    {
        return $date->format('d') . ' ' . $this->monthName((int) $date->format('n'));
    }

    private function dayText(\DateTimeImmutable $date): string
    {
        return ((int) $date->format('d')) . ' ' . $this->monthName((int) $date->format('n'));
    }

    private function monthName(int $month): string
    {
        return [1 => 'ENERO', 2 => 'FEBRERO', 3 => 'MARZO', 4 => 'ABRIL', 5 => 'MAYO', 6 => 'JUNIO', 7 => 'JULIO', 8 => 'AGOSTO', 9 => 'SEPTIEMBRE', 10 => 'OCTUBRE', 11 => 'NOVIEMBRE', 12 => 'DICIEMBRE'][$month];
    }
}
