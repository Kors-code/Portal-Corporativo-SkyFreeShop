<?php

namespace App\Services\PassengerIntelligence;

use App\Models\PassengerIntelligence\PassengerFlight;
use App\Models\PassengerIntelligence\PassengerImportBatch;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use RuntimeException;

class PassengerExcelImportService
{
    public function importUploadedFile(UploadedFile $file, ?int $userId = null): array
    {
        return $this->importPath($file->getRealPath(), $file->getClientOriginalName(), [
            'imported_by' => $userId,
            'source_type' => 'excel',
            'source_name' => 'PAX Excel operativo',
            'data_type' => 'estimated',
            'fail_on_duplicate' => true,
        ]);
    }

    public function importPath(string $path, string $filename, array $options = []): array
    {
        if (!is_file($path)) {
            throw new RuntimeException('No se encontro el archivo de pasajeros para importar.');
        }

        $checksum = hash_file('sha256', $path);
        $existing = PassengerImportBatch::where('checksum', $checksum)->first();

        if ($existing) {
            if ($options['source_file_id'] ?? null) {
                $existing->update(['source_file_id' => $options['source_file_id']]);
            }

            if ($options['fail_on_duplicate'] ?? false) {
                throw new RuntimeException('Este archivo ya fue importado previamente.');
            }

            return [
                'duplicate' => true,
                'batch' => $existing,
                'batch_id' => $existing->id,
                'rows_imported' => 0,
                'rows_skipped' => 0,
                'total_pax' => round((float) $existing->total_pax, 2),
                'checksum' => $checksum,
            ];
        }

        $stored = $this->storeOriginal($path, $filename);

        DB::connection('budget')->beginTransaction();

        try {
            $batch = PassengerImportBatch::create([
                'source_file_id' => $options['source_file_id'] ?? null,
                'filename' => $filename,
                'checksum' => $checksum,
                'source_type' => $options['source_type'] ?? 'excel',
                'observed_scope' => $options['observed_scope'] ?? null,
                'source_path' => $options['source_path'] ?? null,
                'source_url' => $options['source_url'] ?? null,
                'status' => 'processing',
                'imported_by' => $options['imported_by'] ?? null,
                'notes' => [],
            ]);

            $workbook = Excel::toArray(null, $path);
            $sheetNames = $this->sheetNames($path);
            $rowsImported = 0;
            $rowsSkipped = 0;
            $errors = [];
            $dates = [];
            $totalPax = 0.0;
            $flightRows = [];
            $now = now();

            foreach ($workbook as $sheetIndex => $sheet) {
                $sheetName = $sheetNames[$sheetIndex] ?? ('Sheet ' . ($sheetIndex + 1));
                $direction = $this->directionFromSheet($sheetName);

                if (!$direction) {
                    $rowsSkipped += max(count($sheet) - 1, 0);
                    continue;
                }

                if (empty($sheet)) {
                    continue;
                }

                $headers = array_map(fn ($header) => $this->normalizeHeader((string) $header), $sheet[0]);

                for ($i = 1; $i < count($sheet); $i++) {
                    $line = $sheet[$i];
                    if (!$this->hasUsefulData($line)) {
                        continue;
                    }

                    $row = $this->assocRow($headers, $line);
                    $sourceRow = $i + 1;

                    try {
                        $flightDate = $this->parseDate($row['date'] ?? null);
                        $time = $this->parseTime($row['time'] ?? null);
                        $pax = $this->parseNumber($row['pax'] ?? null);
                        $airline = $this->nullableString($row['aer'] ?? null);
                        $flightCode = $this->nullableString($row['code'] ?? null);
                        $destination = $this->normalizeIata($row['destino'] ?? null);
                        $store = $this->nullableString($row['store'] ?? null);

                        if (!$flightDate || $pax === null || !$airline || !$flightCode) {
                            $rowsSkipped++;
                            $errors[] = [
                                'sheet' => $sheetName,
                                'row' => $sourceRow,
                                'error' => 'Fila sin fecha, PAX, aerolinea o codigo de vuelo valido.',
                            ];
                            continue;
                        }

                        $origin = $direction === 'arrival' ? null : 'MDE';
                        if ($direction === 'arrival') {
                            $destination = 'MDE';
                        }

                        $scheduledAt = $time ? Carbon::parse($flightDate . ' ' . $time, 'America/Bogota') : null;
                        $uid = sha1(implode('|', [
                            $checksum,
                            $sheetName,
                            $flightDate,
                            $time,
                            $airline,
                            $flightCode,
                            $origin,
                            $destination,
                        ]));

                        $flightRows[] = [
                            'batch_id' => $batch->id,
                            'source_file_id' => $options['source_file_id'] ?? null,
                            'flight_date' => $flightDate,
                            'scheduled_time' => $time,
                            'scheduled_at' => $scheduledAt,
                            'direction' => $direction,
                            'airline' => $airline,
                            'flight_code' => $flightCode,
                            'origin' => $origin,
                            'destination' => $destination,
                            'pax' => $pax,
                            'store' => $store,
                            'source_sheet' => $sheetName,
                            'source_row' => $sourceRow,
                            'source_row_uid' => $uid,
                            'data_type' => $options['data_type'] ?? 'estimated',
                            'observed_scope' => $options['observed_scope'] ?? null,
                            'source_name' => $options['source_name'] ?? 'PAX Excel operativo',
                            'retrieved_at' => $now,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];

                        $rowsImported++;
                        $totalPax += $pax;
                        $dates[] = $flightDate;
                    } catch (\Throwable $e) {
                        $rowsSkipped++;
                        $errors[] = [
                            'sheet' => $sheetName,
                            'row' => $sourceRow,
                            'error' => $e->getMessage(),
                        ];
                    }
                }
            }

            $this->upsertFlightRows($flightRows);

            $dates = array_values(array_filter($dates));

            $batch->update([
                'status' => empty($errors) ? 'completed' : 'completed_with_warnings',
                'rows_imported' => $rowsImported,
                'rows_skipped' => $rowsSkipped,
                'total_pax' => $totalPax,
                'period_start' => empty($dates) ? null : min($dates),
                'period_end' => empty($dates) ? null : max($dates),
                'notes' => [
                    'warnings' => array_slice($errors, 0, 100),
                    'ignored_sheets' => ['CARTAGENA', 'LDC MEDELLIN', 'LDC CALI'],
                    'source_file_id' => $options['source_file_id'] ?? null,
                    'observed_scope' => $options['observed_scope'] ?? null,
                    'reason' => 'El importador canonico importa DEPARTURES y ARRIVALS MDE. Las hojas LDC/otras plazas se conservan como referencia operativa, no como vuelos canonicos.',
                ],
            ]);

            DB::connection('budget')->commit();

            return [
                'duplicate' => false,
                'batch' => $batch->fresh(),
                'batch_id' => $batch->id,
                'rows_imported' => $rowsImported,
                'rows_skipped' => $rowsSkipped,
                'total_pax' => round($totalPax, 2),
                'checksum' => $checksum,
                'path' => $stored,
            ];
        } catch (\Throwable $e) {
            DB::connection('budget')->rollBack();
            Storage::delete($stored);
            throw $e;
        }
    }

    private function storeOriginal(string $path, string $filename): string
    {
        $safeName = preg_replace('/[^A-Za-z0-9_.-]+/', '_', $filename) ?: 'passenger-pax.xlsx';
        $stored = 'imports/passenger-intelligence/' . now()->format('YmdHis') . '_' . $safeName;

        Storage::put($stored, file_get_contents($path));

        return $stored;
    }

    private function upsertFlightRows(array $rows): void
    {
        foreach (array_chunk($rows, 1000) as $chunk) {
            PassengerFlight::upsert(
                $chunk,
                ['source_row_uid'],
                [
                    'batch_id',
                    'source_file_id',
                    'flight_date',
                    'scheduled_time',
                    'scheduled_at',
                    'direction',
                    'airline',
                    'flight_code',
                    'origin',
                    'destination',
                    'pax',
                    'store',
                    'source_sheet',
                    'source_row',
                    'data_type',
                    'observed_scope',
                    'source_name',
                    'retrieved_at',
                    'updated_at',
                ]
            );
        }
    }

    private function directionFromSheet(string $sheetName): ?string
    {
        $name = strtoupper(trim($sheetName));

        return match ($name) {
            'DEPARTURES' => 'departure',
            'ARRIVALS' => 'arrival',
            default => null,
        };
    }

    private function normalizeHeader(string $header): string
    {
        return strtolower(trim(str_replace([' ', '-'], '_', $header)));
    }

    private function assocRow(array $headers, array $line): array
    {
        $row = [];
        foreach ($headers as $idx => $key) {
            if ($key === '') {
                continue;
            }
            $row[$key] = $line[$idx] ?? null;
        }

        return $row;
    }

    private function hasUsefulData(array $line): bool
    {
        foreach ($line as $value) {
            if ($value !== null && trim((string) $value) !== '') {
                return true;
            }
        }

        return false;
    }

    private function parseDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->toDateString();
        }

        if (is_numeric($value)) {
            return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value))->toDateString();
        }

        return Carbon::parse((string) $value, 'America/Bogota')->toDateString();
    }

    private function parseTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->format('H:i:s');
        }

        if (is_numeric($value)) {
            $seconds = (int) round(((float) $value) * 86400);
            $hours = intdiv($seconds, 3600) % 24;
            $minutes = intdiv($seconds % 3600, 60);
            $secs = $seconds % 60;
            return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
        }

        $raw = trim((string) $value);
        foreach (['H:i:s', 'H:i'] as $format) {
            $parsed = \DateTimeImmutable::createFromFormat('!' . $format, $raw);
            if ($parsed instanceof \DateTimeImmutable) {
                return $parsed->format('H:i:s');
            }
        }

        return Carbon::parse($raw, 'America/Bogota')->format('H:i:s');
    }

    private function parseNumber(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return round((float) $value, 2);
        }

        $normalized = str_replace(',', '.', trim((string) $value));
        return is_numeric($normalized) ? round((float) $normalized, 2) : null;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);
        if ($string === '' || strtolower($string) === 'null') {
            return null;
        }

        return $string;
    }

    private function normalizeIata(mixed $value): ?string
    {
        $string = $this->nullableString($value);
        return $string ? strtoupper(substr($string, 0, 8)) : null;
    }

    private function sheetNames(string $path): array
    {
        try {
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);
            return $reader->listWorksheetNames($path);
        } catch (\Throwable) {
            return [];
        }
    }
}
