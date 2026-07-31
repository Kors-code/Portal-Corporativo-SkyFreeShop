<?php

namespace App\Services\Davibank;

use App\Models\Banking\BankImportBatch;
use DateTimeImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
     * @return array{path: string, filename: string, sheets: int, rows: int, excluded_zero_commission: int, batch_id: int}
     */
    public function convert(UploadedFile $file, int $receiptStart, ?int $userId = null): array
    {
        if ($receiptStart <= 0) {
            throw new RuntimeException('El numero inicial del recibo debe ser mayor que 0.');
        }

        [$headers, $rows, $excludedZeroCommission, $skippedUnsupportedNetwork, $sourceRows] = $this->readDavibankCsv($file->getRealPath());
        $rows = $this->attachMovementUids($rows);
        $groups = $this->groupRowsByAbonoDate($rows);

        if ($groups === []) {
            throw new RuntimeException('No hay filas validas para exportar despues de aplicar los filtros.');
        }

        $xlsxPath = $this->writeWorkbook($headers, $groups, $receiptStart);
        $batch = $this->persistImport(
            $file,
            $rows,
            $groups,
            $receiptStart,
            $excludedZeroCommission,
            $skippedUnsupportedNetwork,
            $sourceRows,
            $userId
        );

        return [
            'path' => $xlsxPath,
            'filename' => 'davibank_convertido_' . now()->format('Ymd_His') . '.xlsx',
            'sheets' => count($groups),
            'rows' => array_sum(array_map('count', $groups)),
            'excluded_zero_commission' => $excludedZeroCommission,
            'batch_id' => $batch->id,
        ];
    }

    /**
     * @return array{path: string, filename: string, sheets: int, rows: int, batch_id: int}
     */
    public function exportBatch(int $batchId, ?int $receiptStart = null): array
    {
        $batch = BankImportBatch::findOrFail($batchId);
        if (! in_array($batch->source_type, ['davibank_converter', 'card_settlement'], true)) {
            throw new RuntimeException('Este lote no corresponde a un archivo Davibank exportable.');
        }

        $rows = $batch->movements()
            ->where('source_type', 'davibank_converter')
            ->orderBy('deposit_date')
            ->orderBy('row_number')
            ->get()
            ->map(function ($movement): array {
                $payload = is_array($movement->raw_payload)
                    ? $movement->raw_payload
                    : json_decode((string) $movement->raw_payload, true);

                if (! is_array($payload)) {
                    return [];
                }

                $date = $this->dateString($payload['FECHA_ABONO'] ?? null)
                    ?? optional($movement->deposit_date)->toDateString();

                if ($date) {
                    $payload['_FECHA_ABONO_KEY'] = $date;
                }

                return $payload;
            })
            ->filter(fn (array $row): bool => $row !== [] && isset($row['_FECHA_ABONO_KEY']))
            ->values()
            ->all();

        if ($rows === []) {
            throw new RuntimeException('El lote no tiene movimientos Davibank guardados para exportar.');
        }

        $metadata = is_array($batch->metadata) ? $batch->metadata : [];
        $receiptStart ??= (int) ($metadata['receipt_start'] ?? 1);
        if ($receiptStart <= 0) {
            $receiptStart = 1;
        }

        $groups = $this->groupRowsByAbonoDate($rows);
        $path = $this->writeWorkbook(self::REQUIRED_COLUMNS, $groups, $receiptStart);

        return [
            'path' => $path,
            'filename' => 'davibank_final_' . $batch->id . '_' . now()->format('Ymd_His') . '.xlsx',
            'sheets' => count($groups),
            'rows' => count($rows),
            'batch_id' => $batch->id,
        ];
    }

    /**
     * @return array{0: array<int, string>, 1: array<int, array<string, mixed>>, 2: int, 3: int, 4: int}
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
        $skippedUnsupportedNetwork = 0;
        $sourceRows = count($lines) - 1;

        foreach (array_slice($lines, 1) as $lineNumber => $line) {
            $values = $this->parseDavibankLine($line);
            $record = [];
            $csvRowNumber = $lineNumber + 2;

            foreach ($headers as $index => $header) {
                $record[$header] = $values[$index] ?? '';
            }

            $network = trim((string) $record['CODIGO_RED']);
            if (! in_array($network, ['VD', 'RD'], true)) {
                $skippedUnsupportedNetwork++;
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
                throw new RuntimeException('FECHA_ABONO invalida en fila CSV ' . $csvRowNumber);
            }

            $record['_FECHA_ABONO_KEY'] = $abonoDate->format('Y-m-d');
            $record['_CSV_ROW_NUMBER'] = $csvRowNumber;
            $records[] = $record;
        }

        return [$headers, $records, $excludedZeroCommission, $skippedUnsupportedNetwork, $sourceRows];
    }

    private function attachMovementUids(array $rows): array
    {
        return array_map(function (array $row): array {
            $row['_MOVEMENT_UID'] = $this->makeMovementUid($row);

            return $row;
        }, $rows);
    }

    private function makeMovementUid(array $row): string
    {
        $parts = [
            'davivienda',
            $row['CODIGO_RED'] ?? '',
            $row['ABONO_DEVOL'] ?? '',
            $row['NUM_AUTORIZACION'] ?? '',
            $row['NUM_TERMINAL'] ?? '',
            $row['NUM_COMP'] ?? '',
            $row['NUM_TARJETA'] ?? '',
            $this->dateString($row['FECHA_COMPR'] ?? null) ?? '',
            $this->dateString($row['FECHA_ABONO'] ?? null) ?? '',
            $this->decimalKey($row['VALOR_COMPRA'] ?? 0),
            $this->decimalKey($row['VALOR_ABONADO'] ?? 0),
            $this->decimalKey($row['NETO_ABONADO'] ?? 0),
        ];

        return hash('sha256', implode('|', array_map(fn (mixed $value): string => trim((string) $value), $parts)));
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

    private function persistImport(
        UploadedFile $file,
        array $rows,
        array $groups,
        int $receiptStart,
        int $excludedZeroCommission,
        int $skippedUnsupportedNetwork,
        int $sourceRows,
        ?int $userId
    ): BankImportBatch {
        $checksum = hash_file('sha256', $file->getRealPath()) ?: null;
        $storedPath = $this->storeSourceFile($file, $checksum);
        $now = now();
        $dateKeys = array_keys($groups);
        sort($dateKeys);
        $bankContext = $this->bankContext();

        return DB::connection('budget')->transaction(function () use (
            $file,
            $rows,
            $groups,
            $receiptStart,
            $excludedZeroCommission,
            $skippedUnsupportedNetwork,
            $sourceRows,
            $checksum,
            $storedPath,
            $now,
            $dateKeys,
            $userId,
            $bankContext
        ): BankImportBatch {
            [$newRows, $duplicateRows] = $this->partitionNewRows($rows);
            $newGroups = $this->groupRowsByAbonoDate($newRows);
            $newDateKeys = array_keys($newGroups);
            sort($newDateKeys);

            $batch = BankImportBatch::create([
                'bank_id' => $bankContext['bank_id'],
                'file_format_id' => $bankContext['file_format_id'],
                'bank_account_id' => $bankContext['bank_account_id'],
                'bank' => 'davibank',
                'source_type' => 'davibank_converter',
                'filename' => $file->getClientOriginalName() ?: 'davibank.csv',
                'stored_path' => $storedPath,
                'checksum' => $checksum,
                'status' => 'completed',
                'rows' => $sourceRows,
                'rows_imported' => count($newRows),
                'rows_skipped' => $excludedZeroCommission + $skippedUnsupportedNetwork + count($duplicateRows),
                'from_date' => $newDateKeys[0] ?? $dateKeys[0] ?? null,
                'to_date' => $newDateKeys[count($newDateKeys) - 1] ?? $dateKeys[count($dateKeys) - 1] ?? null,
                'total_sale_amount' => $this->sumColumn($newRows, 'VALOR_COMPRA'),
                'total_commission_amount' => $this->sumColumn($newRows, 'VALOR_COMISION'),
                'total_withholding_amount' => $this->sumWithholding($newRows),
                'total_income_amount' => $this->sumColumn($newRows, 'NETO_ABONADO'),
                'total_debit_amount' => 0,
                'total_credit_amount' => 0,
                'metadata' => [
                    'receipt_start' => $receiptStart,
                    'excluded_zero_commission' => $excludedZeroCommission,
                    'skipped_unsupported_network' => $skippedUnsupportedNetwork,
                    'skipped_duplicate_movements' => count($duplicateRows),
                    'generated_sheets' => count($groups),
                ],
                'created_by' => $userId,
            ]);

            foreach (array_chunk($this->makeMovementRows($batch->id, $newRows, $now, $bankContext), 500) as $chunk) {
                DB::connection('budget')->table('bank_movements')->insert($chunk);
            }

            if ($newGroups !== []) {
                DB::connection('budget')->table('bank_daily_summaries')->insert(
                    $this->makeDailySummaryRows($batch->id, $newGroups, $now, $bankContext)
                );

                DB::connection('budget')->table('bank_cash_receipts')->insert(
                    $this->makeCashReceiptRows($batch->id, $newGroups, $receiptStart, $now, $bankContext)
                );
            }

            return $batch;
        });
    }

    /**
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
     */
    private function partitionNewRows(array $rows): array
    {
        $uids = array_values(array_unique(array_filter(array_map(
            fn (array $row): ?string => $row['_MOVEMENT_UID'] ?? null,
            $rows
        ))));

        $existing = [];
        foreach (array_chunk($uids, 1000) as $chunk) {
            $found = DB::connection('budget')
                ->table('bank_movements')
                ->where('bank', 'davibank')
                ->whereIn('movement_uid', $chunk)
                ->pluck('movement_uid')
                ->all();

            foreach ($found as $uid) {
                $existing[(string) $uid] = true;
            }
        }

        $seen = [];
        $newRows = [];
        $duplicateRows = [];

        foreach ($rows as $row) {
            $uid = (string) ($row['_MOVEMENT_UID'] ?? '');

            if ($uid === '' || isset($existing[$uid]) || isset($seen[$uid])) {
                $duplicateRows[] = $row;
                continue;
            }

            $seen[$uid] = true;
            $newRows[] = $row;
        }

        return [$newRows, $duplicateRows];
    }

    private function storeSourceFile(UploadedFile $file, ?string $checksum): ?string
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: 'csv');
        $safeBaseName = preg_replace('/[^A-Za-z0-9_.-]+/', '_', pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME));
        $safeBaseName = trim((string) $safeBaseName, '._-') ?: 'davibank';
        $filename = now()->format('Ymd_His') . '_' . substr((string) $checksum, 0, 12) . '_' . $safeBaseName . '.' . $extension;

        $storedPath = Storage::putFileAs('imports/banks/davivienda', $file, $filename);

        return $storedPath ?: null;
    }

    private function makeMovementRows(int $batchId, array $rows, mixed $timestamp, array $bankContext): array
    {
        return array_map(function (array $row) use ($batchId, $timestamp, $bankContext): array {
            return [
                'batch_id' => $batchId,
                'bank_id' => $bankContext['bank_id'],
                'bank_account_id' => $bankContext['bank_account_id'],
                'bank' => 'davibank',
                'source_type' => 'davibank_converter',
                'movement_uid' => $row['_MOVEMENT_UID'] ?? null,
                'row_number' => $row['_CSV_ROW_NUMBER'] ?? null,
                'movement_date' => $this->dateString($row['FECHA_COMPR'] ?? null),
                'process_date' => $this->dateString($row['FECHA_PROCESO'] ?? null),
                'deposit_date' => $this->dateString($row['FECHA_ABONO'] ?? null),
                'account_number' => $this->blankToNull($row['NUMERO_CUENTA'] ?? null),
                'branch_code' => $this->blankToNull($row['CODIGOAGENCIA'] ?? null),
                'transaction_code' => $this->blankToNull($row['NUM_TRANSACC'] ?? null),
                'reference' => $this->blankToNull($row['ID_MOVIMIENTO'] ?? $row['NUM_COMP'] ?? null),
                'receipt_number' => $this->blankToNull($row['CONSECUTIVO'] ?? null),
                'authorization_number' => $this->blankToNull($row['NUM_AUTORIZACION'] ?? null),
                'terminal' => $this->blankToNull($row['NUM_TERMINAL'] ?? null),
                'network' => $this->blankToNull($row['CODIGO_RED'] ?? null),
                'card_type' => $this->blankToNull($row['FRANQUICIA'] ?? $row['TIPTARJ'] ?? null),
                'card_last_digits' => $this->cardLastDigits($row['NUM_TARJETA'] ?? null),
                'counterparty' => $this->blankToNull($row['NOMBRE_TITULAR'] ?? null),
                'description' => $this->blankToNull($row['NOMBRE_ALMACEN'] ?? $row['NOMBREALMACEN'] ?? $row['UBICACION_TERM'] ?? null),
                'movement_type' => $this->blankToNull($row['TIPO_ABONO'] ?? $row['TIPO'] ?? null),
                'category' => 'card_sale',
                'currency' => 'COP',
                'sale_amount' => $row['VALOR_COMPRA'] ?? 0,
                'commission_amount' => $row['VALOR_COMISION'] ?? 0,
                'withholding_amount' => $this->rowWithholding($row),
                'withholding_source_amount' => $row['VALOR_RETENCION'] ?? 0,
                'withholding_vat_amount' => $row['VALOR_RETEIVA'] ?? 0,
                'withholding_ica_amount' => $row['RETEICA'] ?? 0,
                'vat_amount' => $row['VALOR_IVA'] ?? 0,
                'tip_amount' => $row['VALOR_PROPINA'] ?? 0,
                'income_amount' => $row['NETO_ABONADO'] ?? 0,
                'net_amount' => $row['NETO_ABONADO'] ?? 0,
                'is_sale' => true,
                'is_income' => true,
                'is_expense' => false,
                'is_excluded' => false,
                'raw_payload' => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }, $rows);
    }

    private function makeDailySummaryRows(int $batchId, array $groups, mixed $timestamp, array $bankContext): array
    {
        $summaryRows = [];

        foreach ($groups as $date => $dayRows) {
            $summaryRows[] = [
                'batch_id' => $batchId,
                'bank_id' => $bankContext['bank_id'],
                'bank' => 'davibank',
                'summary_date' => $date,
                'movements_count' => count($dayRows),
                'sale_amount' => $this->sumColumn($dayRows, 'VALOR_COMPRA'),
                'commission_amount' => $this->sumColumn($dayRows, 'VALOR_COMISION'),
                'withholding_amount' => $this->sumWithholding($dayRows),
                'income_amount' => $this->sumColumn($dayRows, 'NETO_ABONADO'),
                'debit_amount' => 0,
                'credit_amount' => 0,
                'metadata' => json_encode([
                    'visa_abonado' => $this->sumWhereNetwork($dayRows, 'VD', 'VALOR_ABONADO'),
                    'redeban_abonado' => $this->sumWhereNetwork($dayRows, 'RD', 'VALOR_ABONADO'),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }

        return $summaryRows;
    }

    private function makeCashReceiptRows(int $batchId, array $groups, int $receiptStart, mixed $timestamp, array $bankContext): array
    {
        $receiptRows = [];
        $receiptNumber = $receiptStart;

        foreach ($groups as $date => $dayRows) {
            $receiptRows[] = [
                'batch_id' => $batchId,
                'bank_id' => $bankContext['bank_id'],
                'bank' => 'davibank',
                'receipt_date' => $date,
                'receipt_number' => $receiptNumber,
                'sale_amount' => $this->sumColumn($dayRows, 'VALOR_COMPRA'),
                'commission_amount' => $this->sumColumn($dayRows, 'VALOR_COMISION'),
                'withholding_amount' => $this->sumWithholding($dayRows),
                'income_amount' => $this->sumColumn($dayRows, 'NETO_ABONADO'),
                'metadata' => json_encode([
                    'receipt_numbers' => range($receiptNumber, $receiptNumber + 6),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
            $receiptNumber += 7;
        }

        return $receiptRows;
    }

    private function sumColumn(array $rows, string $column): float
    {
        return array_reduce($rows, fn (float $carry, array $row): float => $carry + (float) ($row[$column] ?? 0), 0.0);
    }

    private function sumWithholding(array $rows): float
    {
        return array_reduce($rows, fn (float $carry, array $row): float => $carry + $this->rowWithholding($row), 0.0);
    }

    private function rowWithholding(array $row): float
    {
        return (float) ($row['VALOR_RETENCION'] ?? 0)
            + (float) ($row['VALOR_RETEIVA'] ?? 0)
            + (float) ($row['RETEICA'] ?? 0);
    }

    private function dateString(mixed $value): ?string
    {
        $date = $this->parseDateValue((string) $value);

        return $date?->format('Y-m-d');
    }

    private function blankToNull(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function cardLastDigits(mixed $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $value);

        return $digits ? substr($digits, -4) : null;
    }

    private function decimalKey(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    /**
     * @return array{bank_id: ?int, file_format_id: ?int, bank_account_id: ?int}
     */
    private function bankContext(): array
    {
        $bankId = DB::connection('budget')->table('banks')->where('code', 'davibank')->value('id');
        $fileFormatId = DB::connection('budget')->table('bank_file_formats')->where('code', 'davibank_sales_csv')->value('id');
        $bankAccountId = DB::connection('budget')->table('bank_accounts')->where('account_number', '6841002235')->value('id');

        return [
            'bank_id' => $bankId ? (int) $bankId : null,
            'file_format_id' => $fileFormatId ? (int) $fileFormatId : null,
            'bank_account_id' => $bankAccountId ? (int) $bankAccountId : null,
        ];
    }

    private function writeWorkbook(array $headers, array $groups, int $receiptStart): string
    {
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

        return $xlsxPath;
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
