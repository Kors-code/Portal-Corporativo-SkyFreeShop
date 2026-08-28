<?php

namespace App\Services\PassengerIntelligence;

use App\Models\PassengerIntelligence\PassengerCompositionProfile;
use App\Models\PassengerIntelligence\PassengerImportBatch;
use App\Models\PassengerIntelligence\PassengerMonthlyFact;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use RuntimeException;
use SplFileObject;

class PassengerMigrationMicrodataService
{
    private const SOURCE_NAME = 'Migracion Colombia OM3 - Microdatos Flujos Migratorios';
    private const SOURCE_URL = 'https://portal.migracioncolombia.gov.co/planeacion-y-estadistica/observatorio-om3/datos-abiertos/microdatos';
    private const METHOD = 'MIGRATION_MICRODATA_MONTHLY_PROFILE';

    private const FIELD_CANDIDATES = [
        'date' => ['fecha', 'fecha_movimiento', 'fecha_de_movimiento', 'fec_movimiento', 'fecha_viaje', 'fecha_registro'],
        'year' => ['a_o', 'ano', 'anio', 'year', 'vigencia'],
        'month' => ['mes', 'month', 'numero_de_mes', 'n_mero_de_mes', 'mes_numero'],
        'direction' => ['tipo_movimiento', 'movimiento', 'clase_movimiento', 'entrada_salida', 'tipo', 'tipo_de_flujo'],
        'nationality' => ['nacionalidad', 'pais_nacionalidad', 'nationality', 'pais_de_nacionalidad'],
        'checkpoint' => ['ubicacion_pcm', 'puesto_control', 'puesto_de_control', 'pcm', 'nombre_pcm', 'puesto_migratorio'],
        'count' => ['total', 'cantidad', 'conteo', 'movimientos', 'valor', 'registros'],
    ];

    public function importUploadedFile(UploadedFile $file, ?int $userId = null): array
    {
        return $this->importPath($file->getRealPath(), $file->getClientOriginalName(), [
            'imported_by' => $userId,
            'mime_type' => $file->getMimeType(),
        ]);
    }

    public function importPath(string $path, string $filename, array $options = []): array
    {
        if (!is_file($path)) {
            throw new RuntimeException('No se encontro el archivo de microdatos migratorios.');
        }

        $checksum = hash_file('sha256', $path);
        $existing = PassengerImportBatch::where('checksum', $checksum)->first();

        if ($existing) {
            return [
                'duplicate' => true,
                'batch_id' => $existing->id,
                'message' => 'Este archivo de microdatos ya fue importado previamente.',
                'period_start' => $existing->period_start?->toDateString(),
                'period_end' => $existing->period_end?->toDateString(),
                'rows_imported' => 0,
                'rows_skipped' => 0,
                'profiles' => [],
            ];
        }

        $stored = $this->storeOriginal($path, $filename);
        $scan = $this->scanRows($path, $filename);

        if (empty($scan['periods'])) {
            Storage::delete($stored);
            throw new RuntimeException('El archivo no produjo movimientos migratorios MDE validos. Revisa columnas de fecha/anio/mes, nacionalidad, tipo de movimiento y puesto de control.');
        }

        DB::connection('budget')->beginTransaction();

        try {
            $batch = PassengerImportBatch::create([
                'filename' => $filename,
                'checksum' => $checksum,
                'source_type' => 'migration_microdata',
                'observed_scope' => 'migration_flow',
                'source_path' => $stored,
                'source_url' => self::SOURCE_URL,
                'status' => 'processing',
                'rows_imported' => $scan['rows_matched'],
                'rows_skipped' => $scan['rows_skipped'],
                'total_pax' => $scan['total_movements'],
                'period_start' => $scan['period_start'],
                'period_end' => $scan['period_end'],
                'imported_by' => $options['imported_by'] ?? null,
                'notes' => [
                    'source' => self::SOURCE_NAME,
                    'mime_type' => $options['mime_type'] ?? null,
                    'column_mapping' => $scan['column_mapping'],
                    'warnings' => array_slice($scan['warnings'], 0, 100),
                    'method' => self::METHOD,
                    'audit_note' => 'Estos perfiles se calculan dentro del mismo universo migratorio: colombianos vs extranjeros observados en registros de Migracion para MDE. No usan resta contra Aerocivil.',
                ],
            ]);

            $profiles = $this->persistPeriods($scan['periods'], $batch, $scan['column_mapping']);

            $batch->update([
                'status' => empty($scan['warnings']) ? 'completed' : 'completed_with_warnings',
            ]);

            DB::connection('budget')->commit();

            return [
                'duplicate' => false,
                'batch_id' => $batch->id,
                'rows_imported' => $scan['rows_matched'],
                'rows_skipped' => $scan['rows_skipped'],
                'total_movements' => $scan['total_movements'],
                'period_start' => $scan['period_start'],
                'period_end' => $scan['period_end'],
                'column_mapping' => $scan['column_mapping'],
                'profiles' => $profiles,
            ];
        } catch (\Throwable $e) {
            DB::connection('budget')->rollBack();
            Storage::delete($stored);
            throw $e;
        }
    }

    public function audit(?int $year = null, ?int $month = null): array
    {
        $query = PassengerCompositionProfile::where('method', self::METHOD)
            ->orderByDesc('valid_from')
            ->orderByRaw('CASE WHEN direction IS NULL THEN 1 ELSE 0 END')
            ->orderBy('direction');

        if ($year) {
            $query->whereYear('valid_from', $year);
        }

        if ($month) {
            $query->whereMonth('valid_from', $month);
        }

        $profiles = $query->limit(60)->get();

        $factsQuery = PassengerMonthlyFact::where('source_type', 'migration_microdata')
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->orderBy('direction')
            ->orderBy('fact_type');

        if ($year) {
            $factsQuery->where('year', $year);
        }

        if ($month) {
            $factsQuery->where('month', $month);
        }

        $facts = $factsQuery->limit(300)->get();

        $fallbackProfiles = PassengerCompositionProfile::where('method', 'OFFICIAL_MONTHLY_RECONCILIATION')
            ->when($year, fn ($q) => $q->whereYear('valid_from', $year))
            ->when($month, fn ($q) => $q->whereMonth('valid_from', $month))
            ->orderByDesc('valid_from')
            ->limit(60)
            ->get();

        return [
            'source' => [
                'name' => self::SOURCE_NAME,
                'url' => self::SOURCE_URL,
                'method' => self::METHOD,
                'priority_note' => 'Cuando existe este perfil para el mes, debe usarse antes que OFFICIAL_MONTHLY_RECONCILIATION.',
            ],
            'filters' => ['year' => $year, 'month' => $month],
            'profiles' => $profiles->map(fn (PassengerCompositionProfile $profile) => $this->profilePayload($profile))->all(),
            'monthly_facts' => $facts->map(fn (PassengerMonthlyFact $fact) => [
                'year' => $fact->year,
                'month' => $fact->month,
                'direction' => $fact->direction,
                'fact_type' => $fact->fact_type,
                'value' => round((float) $fact->value, 2),
                'records_count' => $fact->records_count,
                'source_name' => $fact->source_name,
                'source_period' => $fact->source_period,
                'metadata' => $fact->metadata,
            ])->all(),
            'fallback_profiles_present' => $fallbackProfiles->map(fn (PassengerCompositionProfile $profile) => $this->profilePayload($profile))->all(),
            'warning' => $profiles->isEmpty()
                ? 'No hay perfiles de microdatos importados para este filtro; el sistema seguira usando perfiles manuales o el fallback agregado si existen.'
                : null,
        ];
    }

    private function scanRows(string $path, string $filename): array
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $rows = in_array($extension, ['csv', 'txt'], true)
            ? $this->csvRows($path)
            : $this->spreadsheetRows($path);

        $headers = null;
        $mapping = [];
        $stats = [];
        $warnings = [];
        $rowsRead = 0;
        $rowsMatched = 0;
        $rowsSkipped = 0;
        $totalMovements = 0.0;
        $periodStart = null;
        $periodEnd = null;

        foreach ($rows as $line) {
            $rowsRead++;

            if (!$this->hasUsefulData($line)) {
                continue;
            }

            if ($headers === null) {
                $headers = array_map(fn ($header) => $this->normalizeHeader((string) $header), $line);
                $mapping = $this->resolveMapping($headers);
                $missing = array_diff(['nationality', 'direction', 'checkpoint'], array_keys(array_filter($mapping)));

                if ($missing) {
                    throw new RuntimeException('No se reconocieron columnas obligatorias de microdatos: ' . implode(', ', $missing));
                }

                if (!$mapping['date'] && (!$mapping['year'] || !$mapping['month'])) {
                    throw new RuntimeException('No se reconocio fecha o combinacion anio/mes en los microdatos.');
                }

                continue;
            }

            $row = $this->assocRow($headers, $line);
            $period = $this->periodFromRow($row, $mapping);
            $direction = $this->directionFromValue($this->value($row, $mapping['direction']));
            $nationality = $this->normalizeText((string) $this->value($row, $mapping['nationality']));
            $checkpoint = $this->normalizeText((string) $this->value($row, $mapping['checkpoint']));
            $count = $mapping['count'] ? $this->number($this->value($row, $mapping['count'])) : 1.0;

            if (!$period || !$direction || !$nationality || !$this->isMdeCheckpoint($checkpoint) || $count <= 0) {
                $rowsSkipped++;
                continue;
            }

            $group = $this->isColombianNationality($nationality) ? 'colombian' : 'foreign';
            $key = sprintf('%04d-%02d|%s', $period['year'], $period['month'], $direction);

            $stats[$key] ??= [
                'year' => $period['year'],
                'month' => $period['month'],
                'direction' => $direction,
                'colombian' => 0.0,
                'foreign' => 0.0,
                'records_count' => 0,
            ];

            $stats[$key][$group] += $count;
            $stats[$key]['records_count']++;
            $rowsMatched++;
            $totalMovements += $count;

            $date = Carbon::create($period['year'], $period['month'], 1, 0, 0, 0, 'America/Bogota');
            $periodStart = $periodStart ? min($periodStart, $date->toDateString()) : $date->toDateString();
            $periodEndValue = $date->copy()->endOfMonth()->toDateString();
            $periodEnd = $periodEnd ? max($periodEnd, $periodEndValue) : $periodEndValue;
        }

        if ($rowsRead <= 1) {
            $warnings[] = 'El archivo parecia tener solo encabezados o estaba vacio.';
        }

        return [
            'periods' => array_values($stats),
            'rows_matched' => $rowsMatched,
            'rows_skipped' => $rowsSkipped,
            'total_movements' => round($totalMovements, 2),
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'column_mapping' => $mapping,
            'warnings' => $warnings,
        ];
    }

    private function persistPeriods(array $periods, PassengerImportBatch $batch, array $mapping): array
    {
        $profiles = [];
        $totals = [];

        foreach ($periods as $period) {
            $profiles[] = $this->persistDirectionPeriod($period, $batch, $mapping);
            $totalKey = sprintf('%04d-%02d', $period['year'], $period['month']);
            $totals[$totalKey] ??= [
                'year' => $period['year'],
                'month' => $period['month'],
                'direction' => null,
                'colombian' => 0.0,
                'foreign' => 0.0,
                'records_count' => 0,
            ];
            $totals[$totalKey]['colombian'] += $period['colombian'];
            $totals[$totalKey]['foreign'] += $period['foreign'];
            $totals[$totalKey]['records_count'] += $period['records_count'];
        }

        foreach ($totals as $period) {
            $profiles[] = $this->persistDirectionPeriod($period, $batch, $mapping);
        }

        return $profiles;
    }

    private function persistDirectionPeriod(array $period, PassengerImportBatch $batch, array $mapping): array
    {
        $total = round($period['colombian'] + $period['foreign'], 2);
        if ($total <= 0) {
            throw new RuntimeException('Periodo migratorio sin movimientos para calcular perfil.');
        }

        $colombianPct = round(($period['colombian'] / $total) * 100, 3);
        $direction = $period['direction'];
        $validFrom = Carbon::create($period['year'], $period['month'], 1, 0, 0, 0, 'America/Bogota');
        $validTo = $validFrom->copy()->endOfMonth();
        $directionLabel = $direction === 'arrival' ? 'llegadas' : ($direction === 'departure' ? 'salidas' : 'total');

        $this->upsertMonthlyFact($period, 'migration_colombian_movements', $period['colombian'], $batch, $mapping);
        $this->upsertMonthlyFact($period, 'migration_foreign_movements', $period['foreign'], $batch, $mapping);
        $this->upsertMonthlyFact($period, 'migration_total_movements', $total, $batch, $mapping);

        $profile = PassengerCompositionProfile::updateOrCreate(
            [
                'name' => sprintf('Perfil Migracion microdatos MDE %s %s %d', $directionLabel, $this->monthNameEs($period['month']), $period['year']),
                'direction' => $direction,
                'valid_from' => $validFrom->toDateString(),
                'valid_to' => $validTo->toDateString(),
            ],
            [
                'colombian_pct' => $colombianPct,
                'foreign_pct' => round(100 - $colombianPct, 3),
                'source_name' => self::SOURCE_NAME,
                'source_url' => self::SOURCE_URL,
                'method' => self::METHOD,
                'confidence_level' => $this->confidenceForTotal($total),
                'is_active' => true,
                'notes' => json_encode([
                    'batch_id' => $batch->id,
                    'source_file' => $batch->filename,
                    'colombian_movements' => round($period['colombian'], 2),
                    'foreign_movements' => round($period['foreign'], 2),
                    'total_movements' => $total,
                    'records_count' => $period['records_count'],
                    'column_mapping' => $mapping,
                    'formula' => 'colombian_pct = colombian_movements / total_movements * 100; foreign_pct = 100 - colombian_pct',
                    'limitation' => 'Perfil mensual por puesto migratorio MDE; no identifica vuelo individual.',
                ], JSON_UNESCAPED_UNICODE),
                'created_by' => $batch->imported_by,
            ]
        );

        return $this->profilePayload($profile);
    }

    private function upsertMonthlyFact(array $period, string $factType, float $value, PassengerImportBatch $batch, array $mapping): void
    {
        PassengerMonthlyFact::updateOrCreate(
            [
                'year' => $period['year'],
                'month' => $period['month'],
                'airport_iata' => 'MDE',
                'direction' => $period['direction'] ?: 'total',
                'fact_type' => $factType,
                'source_type' => 'migration_microdata',
            ],
            [
                'value' => round($value, 2),
                'records_count' => $period['records_count'],
                'import_batch_id' => $batch->id,
                'source_name' => self::SOURCE_NAME,
                'source_url' => self::SOURCE_URL,
                'source_period' => sprintf('%04d-%02d', $period['year'], $period['month']),
                'confidence_level' => $this->confidenceForTotal($period['colombian'] + $period['foreign']),
                'metadata' => [
                    'source_file' => $batch->filename,
                    'column_mapping' => $mapping,
                    'method' => self::METHOD,
                ],
            ]
        );
    }

    private function csvRows(string $path): iterable
    {
        $file = new SplFileObject($path);
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE);
        $file->setCsvControl($this->detectDelimiter($path));

        foreach ($file as $row) {
            if ($row === [null] || $row === false) {
                continue;
            }

            yield $row;
        }
    }

    private function spreadsheetRows(string $path): iterable
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $workbook = $reader->load($path);

        foreach ($workbook->getWorksheetIterator() as $sheet) {
            foreach ($sheet->toArray(null, true, true, false) as $row) {
                yield $row;
            }
        }
    }

    private function detectDelimiter(string $path): string
    {
        $sample = file_get_contents($path, false, null, 0, 4096) ?: '';
        $candidates = [',' => substr_count($sample, ','), ';' => substr_count($sample, ';'), "\t" => substr_count($sample, "\t")];
        arsort($candidates);

        return (string) array_key_first($candidates);
    }

    private function resolveMapping(array $headers): array
    {
        $mapping = [];

        foreach (self::FIELD_CANDIDATES as $field => $candidates) {
            $mapping[$field] = null;
            foreach ($candidates as $candidate) {
                if (in_array($candidate, $headers, true)) {
                    $mapping[$field] = $candidate;
                    break;
                }
            }
        }

        return $mapping;
    }

    private function periodFromRow(array $row, array $mapping): ?array
    {
        if ($mapping['date']) {
            $date = $this->date($this->value($row, $mapping['date']));
            if ($date) {
                return ['year' => (int) $date->year, 'month' => (int) $date->month];
            }
        }

        $year = (int) preg_replace('/\D+/', '', (string) $this->value($row, $mapping['year']));
        $month = $this->monthNumber($this->value($row, $mapping['month']));

        if ($year < 2012 || $month < 1 || $month > 12) {
            return null;
        }

        return ['year' => $year, 'month' => $month];
    }

    private function directionFromValue(mixed $value): ?string
    {
        $text = $this->normalizeText((string) $value);

        if (str_contains($text, 'entrada') || str_contains($text, 'ingreso') || $text === 'e') {
            return 'arrival';
        }

        if (str_contains($text, 'salida') || str_contains($text, 'egreso') || $text === 's') {
            return 'departure';
        }

        return null;
    }

    private function isMdeCheckpoint(string $checkpoint): bool
    {
        foreach (['mde', 'jose maria cordova', 'jmc', 'rionegro', 'medellin', '6 171601 75 427454'] as $needle) {
            if (str_contains($checkpoint, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function isColombianNationality(string $nationality): bool
    {
        return str_contains($nationality, 'colombia') || str_contains($nationality, 'colombian') || $nationality === 'co' || $nationality === 'col';
    }

    private function normalizeHeader(string $header): string
    {
        return trim(preg_replace('/_+/', '_', preg_replace('/[^a-z0-9]+/', '_', $this->normalizeText($header))), '_');
    }

    private function normalizeText(string $text): string
    {
        $text = trim(mb_strtolower($text, 'UTF-8'));
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);

        return trim(preg_replace('/\s+/', ' ', $ascii !== false ? $ascii : $text));
    }

    private function assocRow(array $headers, array $line): array
    {
        $row = [];
        foreach ($headers as $idx => $key) {
            if ($key !== '') {
                $row[$key] = $line[$idx] ?? null;
            }
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

    private function value(array $row, ?string $key): mixed
    {
        return $key ? ($row[$key] ?? null) : null;
    }

    private function date(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }

        if (is_numeric($value)) {
            return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value));
        }

        try {
            return Carbon::parse((string) $value, 'America/Bogota');
        } catch (\Throwable) {
            return null;
        }
    }

    private function monthNumber(mixed $value): int
    {
        if (is_numeric($value)) {
            return (int) $value;
        }

        $month = $this->normalizeText((string) $value);
        $months = [
            'enero' => 1,
            'febrero' => 2,
            'marzo' => 3,
            'abril' => 4,
            'mayo' => 5,
            'junio' => 6,
            'julio' => 7,
            'agosto' => 8,
            'septiembre' => 9,
            'setiembre' => 9,
            'octubre' => 10,
            'noviembre' => 11,
            'diciembre' => 12,
        ];

        return $months[$month] ?? 0;
    }

    private function number(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        $normalized = str_replace(',', '.', trim((string) $value));

        return is_numeric($normalized) ? (float) $normalized : 0.0;
    }

    private function confidenceForTotal(float $total): string
    {
        if ($total >= 5000) {
            return 'HIGH';
        }

        return $total >= 1000 ? 'MEDIUM' : 'LOW';
    }

    private function storeOriginal(string $path, string $filename): string
    {
        $safeName = preg_replace('/[^A-Za-z0-9_.-]+/', '_', $filename) ?: 'migration-microdata.csv';
        $stored = 'imports/passenger-intelligence/migration/' . now()->format('YmdHis') . '_' . $safeName;

        Storage::put($stored, file_get_contents($path));

        return $stored;
    }

    private function profilePayload(PassengerCompositionProfile $profile): array
    {
        return [
            'id' => $profile->id,
            'name' => $profile->name,
            'valid_from' => $profile->valid_from?->toDateString(),
            'valid_to' => $profile->valid_to?->toDateString(),
            'direction' => $profile->direction,
            'colombian_pct' => round((float) $profile->colombian_pct, 3),
            'foreign_pct' => round((float) $profile->foreign_pct, 3),
            'source_name' => $profile->source_name,
            'source_url' => $profile->source_url,
            'method' => $profile->method,
            'confidence_level' => $profile->confidence_level,
            'notes' => $profile->notes,
            'created_at' => $profile->created_at?->toDateTimeString(),
        ];
    }

    private function monthNameEs(int $month): string
    {
        return [
            1 => 'Enero',
            2 => 'Febrero',
            3 => 'Marzo',
            4 => 'Abril',
            5 => 'Mayo',
            6 => 'Junio',
            7 => 'Julio',
            8 => 'Agosto',
            9 => 'Septiembre',
            10 => 'Octubre',
            11 => 'Noviembre',
            12 => 'Diciembre',
        ][$month] ?? 'Mes';
    }
}
