<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PassengerIntelligence\PassengerCompositionProfile;
use App\Models\PassengerIntelligence\PassengerFlight;
use App\Models\PassengerIntelligence\PassengerImportBatch;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class PassengerIntelligenceController extends Controller
{
    private const MDE_PCM_LOCATION = '(6.171601,-75.427454)';
    private const MIGRATION_FOREIGN_ENTRIES_DATASET = '96sh-4v8d';
    private const MIGRATION_COLOMBIAN_EXITS_DATASET = 'efw5-jiej';
    private const AEROCIVIL_TRAFFIC_DATASET = 'gb6w-ynu4';

    public function summary(Request $request)
    {
        $filters = $this->validatedFilters($request);
        $query = $this->flightQuery($filters);

        $totalPax = (float) (clone $query)->sum('pax');
        $totalFlights = (clone $query)->count();
        $dateCount = (clone $query)->distinct('flight_date')->count('flight_date');
        $composition = $this->resolveComposition($filters);
        if (!$composition) {
            try {
                $this->syncOfficialProfileForPeriod(
                    $filters['date_to'] ? (int) Carbon::parse($filters['date_to'])->year : $this->latestFlightYear(),
                    $filters['date_to'] ? (int) Carbon::parse($filters['date_to'])->month : $this->latestFlightMonth(),
                    $request
                );
                $composition = $this->resolveComposition($filters);
            } catch (\Throwable $e) {
                Log::warning('No se pudo sincronizar automaticamente Passenger Intelligence: ' . $e->getMessage());
            }
        }

        $byDirection = (clone $query)
            ->select('direction', DB::raw('COUNT(*) as flights'), DB::raw('SUM(pax) as pax'))
            ->groupBy('direction')
            ->orderBy('direction')
            ->get()
            ->map(fn ($row) => [
                'direction' => $row->direction,
                'flights' => (int) $row->flights,
                'pax' => round((float) $row->pax, 2),
            ]);

        $hourly = (clone $query)
            ->select(DB::raw('HOUR(scheduled_time) as hour'), DB::raw('SUM(pax) as pax'), DB::raw('COUNT(*) as flights'))
            ->whereNotNull('scheduled_time')
            ->groupBy(DB::raw('HOUR(scheduled_time)'))
            ->orderBy(DB::raw('HOUR(scheduled_time)'))
            ->get()
            ->map(fn ($row) => [
                'hour' => str_pad((string) $row->hour, 2, '0', STR_PAD_LEFT) . ':00',
                'pax' => round((float) $row->pax, 2),
                'flights' => (int) $row->flights,
            ]);

        $daily = (clone $query)
            ->select('flight_date', DB::raw('SUM(pax) as pax'), DB::raw('COUNT(*) as flights'))
            ->groupBy('flight_date')
            ->orderBy('flight_date')
            ->get()
            ->map(fn ($row) => [
                'date' => Carbon::parse($row->flight_date)->toDateString(),
                'pax' => round((float) $row->pax, 2),
                'flights' => (int) $row->flights,
            ]);

        $airlines = (clone $query)
            ->select('airline', DB::raw('SUM(pax) as pax'), DB::raw('COUNT(*) as flights'))
            ->whereNotNull('airline')
            ->groupBy('airline')
            ->orderByDesc(DB::raw('SUM(pax)'))
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'airline' => $row->airline,
                'pax' => round((float) $row->pax, 2),
                'flights' => (int) $row->flights,
            ]);

        $routes = (clone $query)
            ->select('origin', 'destination', 'direction', DB::raw('SUM(pax) as pax'), DB::raw('COUNT(*) as flights'))
            ->groupBy('origin', 'destination', 'direction')
            ->orderByDesc(DB::raw('SUM(pax)'))
            ->limit(12)
            ->get()
            ->map(fn ($row) => [
                'route' => trim(($row->origin ?: 'MDE') . ' - ' . ($row->destination ?: 'MDE')),
                'direction' => $row->direction,
                'origin' => $row->origin,
                'destination' => $row->destination,
                'pax' => round((float) $row->pax, 2),
                'flights' => (int) $row->flights,
            ]);

        $latestFlights = (clone $query)
            ->orderByDesc('flight_date')
            ->orderByDesc('scheduled_time')
            ->limit(20)
            ->get(['flight_date', 'scheduled_time', 'direction', 'airline', 'flight_code', 'origin', 'destination', 'pax', 'data_type'])
            ->map(fn ($row) => [
                'date' => $row->flight_date?->toDateString(),
                'time' => $row->scheduled_time ? substr((string) $row->scheduled_time, 0, 5) : null,
                'direction' => $row->direction,
                'airline' => $row->airline,
                'flight_code' => $row->flight_code,
                'origin' => $row->origin,
                'destination' => $row->destination,
                'pax' => round((float) $row->pax, 2),
                'data_type' => $row->data_type,
            ]);

        return response()->json([
            'filters' => $filters,
            'summary' => [
                'total_pax' => round($totalPax, 2),
                'total_flights' => $totalFlights,
                'days' => $dateCount,
                'avg_pax_per_day' => $dateCount > 0 ? round($totalPax / $dateCount, 2) : 0,
                'avg_pax_per_flight' => $totalFlights > 0 ? round($totalPax / $totalFlights, 2) : 0,
                'colombian_pax' => $composition ? round($totalPax * ((float) $composition->colombian_pct / 100), 2) : null,
                'foreign_pax' => $composition ? round($totalPax * ((float) $composition->foreign_pct / 100), 2) : null,
                'colombian_pct' => $composition ? round((float) $composition->colombian_pct, 3) : null,
                'foreign_pct' => $composition ? round((float) $composition->foreign_pct, 3) : null,
            ],
            'composition' => $composition ? $this->profilePayload($composition) : null,
            'quality' => [
                'flow_data_type' => 'estimated',
                'flow_source' => 'Excel PAX operativo',
                'composition_status' => $composition ? 'estimated_from_profile' : 'missing_official_profile',
                'veracity_note' => $composition
                    ? 'El flujo viene del Excel importado; colombiano/extranjero se estima con el perfil de composicion seleccionado y queda trazable.'
                    : 'El flujo viene del Excel importado. No se muestra porcentaje colombiano/extranjero porque el Excel no contiene nacionalidad.',
            ],
            'by_direction' => $byDirection,
            'hourly' => $hourly,
            'daily' => $daily,
            'airlines' => $airlines,
            'routes' => $routes,
            'latest_flights' => $latestFlights,
        ]);
    }

    public function batches()
    {
        $batches = PassengerImportBatch::orderByDesc('created_at')
            ->limit(30)
            ->get()
            ->map(fn ($batch) => [
                'id' => $batch->id,
                'filename' => $batch->filename,
                'status' => $batch->status,
                'period_start' => $batch->period_start?->toDateString(),
                'period_end' => $batch->period_end?->toDateString(),
                'rows_imported' => $batch->rows_imported,
                'rows_skipped' => $batch->rows_skipped,
                'total_pax' => round((float) $batch->total_pax, 2),
                'notes' => $batch->notes,
                'created_at' => $batch->created_at?->toDateTimeString(),
            ]);

        return response()->json($batches);
    }

    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls|max:20480',
        ]);

        $file = $request->file('file');
        $checksum = hash_file('sha256', $file->getRealPath());

        $existing = PassengerImportBatch::where('checksum', $checksum)->first();
        if ($existing) {
            return response()->json([
                'message' => 'Este archivo ya fue importado previamente.',
                'batch_id' => $existing->id,
            ], 409);
        }

        $stored = $file->storeAs(
            'imports/passenger-intelligence',
            now()->format('YmdHis') . '_' . $file->getClientOriginalName()
        );

        DB::connection('budget')->beginTransaction();

        try {
            $batch = PassengerImportBatch::create([
                'filename' => $file->getClientOriginalName(),
                'checksum' => $checksum,
                'source_type' => 'excel',
                'status' => 'processing',
                'imported_by' => optional($request->user())->id,
                'notes' => [],
            ]);

            $workbook = Excel::toArray(null, $file);
            $sheetNames = $this->sheetNames($file->getRealPath());
            $rowsImported = 0;
            $rowsSkipped = 0;
            $errors = [];
            $dates = [];
            $totalPax = 0.0;

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

                $headers = array_map(fn ($h) => $this->normalizeHeader((string) $h), $sheet[0]);

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

                        PassengerFlight::updateOrCreate(
                            ['source_row_uid' => $uid],
                            [
                                'batch_id' => $batch->id,
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
                                'data_type' => 'estimated',
                                'source_name' => 'PAX Excel operativo',
                                'retrieved_at' => now(),
                            ]
                        );

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
                    'reason' => 'El MVP canonico importa DEPARTURES y ARRIVALS MDE. Las hojas LDC/otras plazas se conservan como referencia operativa, no como vuelos canonicos.',
                ],
            ]);

            DB::connection('budget')->commit();

            return response()->json([
                'message' => 'Importacion de pasajeros completada',
                'batch_id' => $batch->id,
                'rows_imported' => $rowsImported,
                'rows_skipped' => $rowsSkipped,
                'total_pax' => round($totalPax, 2),
                'path' => $stored,
            ]);
        } catch (\Throwable $e) {
            DB::connection('budget')->rollBack();
            Storage::delete($stored);
            Log::error('Passenger Intelligence import failed: ' . $e->getMessage());

            return response()->json([
                'message' => 'No se pudo importar el archivo de pasajeros',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function profiles()
    {
        $profiles = PassengerCompositionProfile::orderByDesc('created_at')
            ->limit(30)
            ->get()
            ->map(fn ($profile) => $this->profilePayload($profile));

        return response()->json($profiles);
    }

    public function storeProfile(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:160',
            'valid_from' => 'nullable|date',
            'valid_to' => 'nullable|date|after_or_equal:valid_from',
            'direction' => 'nullable|in:arrival,departure',
            'colombian_pct' => 'required|numeric|min:0|max:100',
            'source_name' => 'required|string|max:160',
            'source_url' => 'nullable|string|max:500',
            'method' => 'nullable|string|max:80',
            'confidence_level' => 'nullable|in:HIGH,MEDIUM,LOW',
            'notes' => 'nullable|string|max:2000',
        ]);

        $colombianPct = round((float) $data['colombian_pct'], 3);

        $profile = PassengerCompositionProfile::create([
            'name' => $data['name'],
            'valid_from' => $data['valid_from'] ?? null,
            'valid_to' => $data['valid_to'] ?? null,
            'direction' => $data['direction'] ?? null,
            'colombian_pct' => $colombianPct,
            'foreign_pct' => round(100 - $colombianPct, 3),
            'source_name' => $data['source_name'],
            'source_url' => $data['source_url'] ?? null,
            'method' => $data['method'] ?? 'manual_official_profile',
            'confidence_level' => $data['confidence_level'] ?? 'MEDIUM',
            'is_active' => true,
            'notes' => $data['notes'] ?? null,
            'created_by' => optional($request->user())->id,
        ]);

        return response()->json($this->profilePayload($profile), 201);
    }

    public function syncOfficialSources(Request $request)
    {
        $data = $request->validate([
            'year' => 'nullable|integer|min:2012|max:2100',
            'month' => 'nullable|integer|min:1|max:12',
        ]);

        $targetYear = $data['year'] ?? $this->latestFlightYear();
        $targetMonth = $data['month'] ?? $this->latestFlightMonth();
        try {
            $result = $this->syncOfficialProfileForPeriod($targetYear, $targetMonth, $request);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'No hay datos oficiales suficientes para calcular el perfil.',
                'error' => $e->getMessage(),
            ], 422);
        }

        return response()->json($result);
    }

    private function syncOfficialProfileForPeriod(int $targetYear, int $targetMonth, Request $request): array
    {
        $migrationYear = min($targetYear, $this->latestMigrationYear());

        $foreignEntries = $this->socrataSum(
            self::MIGRATION_FOREIGN_ENTRIES_DATASET,
            sprintf(
                "a_o='%s' AND mes='%s' AND ubicacion_pcm='%s'",
                $migrationYear,
                $this->monthNameEs($targetMonth),
                self::MDE_PCM_LOCATION
            ),
            'total'
        );

        $colombianExits = $this->socrataSum(
            self::MIGRATION_COLOMBIAN_EXITS_DATASET,
            sprintf(
                "a_o='%s' AND mes='%s' AND ubicacion_pcm='%s'",
                $migrationYear,
                $this->monthNameEs($targetMonth),
                self::MDE_PCM_LOCATION
            ),
            'total'
        );

        $officialArrivals = $this->socrataSum(
            self::AEROCIVIL_TRAFFIC_DATASET,
            sprintf(
                "a_o=%d AND n_mero_de_mes=%d AND destino='MDE' AND tr_fico_n_i='I'",
                $migrationYear,
                $targetMonth
            ),
            'pasajeros'
        );

        $officialDepartures = $this->socrataSum(
            self::AEROCIVIL_TRAFFIC_DATASET,
            sprintf(
                "a_o=%d AND n_mero_de_mes=%d AND origen='MDE' AND tr_fico_n_i='I'",
                $migrationYear,
                $targetMonth
            ),
            'pasajeros'
        );

        if ($foreignEntries <= 0 || $colombianExits <= 0 || $officialArrivals <= 0 || $officialDepartures <= 0) {
            throw new \RuntimeException(json_encode([
                'message' => 'No hay datos oficiales suficientes para calcular el perfil.',
                'inputs' => compact('targetYear', 'targetMonth', 'migrationYear', 'foreignEntries', 'colombianExits', 'officialArrivals', 'officialDepartures'),
            ], JSON_UNESCAPED_UNICODE));
        }

        $arrivalForeignPct = min(100, round(($foreignEntries / $officialArrivals) * 100, 3));
        $arrivalColombianPct = round(100 - $arrivalForeignPct, 3);
        $departureColombianPct = min(100, round(($colombianExits / $officialDepartures) * 100, 3));
        $departureForeignPct = round(100 - $departureColombianPct, 3);

        $globalOfficialTotal = $officialArrivals + $officialDepartures;
        $globalColombian = (($arrivalColombianPct / 100) * $officialArrivals) + $colombianExits;
        $globalColombianPct = round(($globalColombian / $globalOfficialTotal) * 100, 3);

        $profiles = [
            $this->upsertOfficialProfile(
                'Perfil oficial MDE llegadas ' . $this->monthNameEs($targetMonth) . ' ' . $migrationYear,
                'arrival',
                $arrivalColombianPct,
                $migrationYear,
                $targetMonth,
                [
                    'foreign_entries_migration' => $foreignEntries,
                    'official_arrivals_aerocivil' => $officialArrivals,
                    'limitation' => 'Migracion publica entradas de extranjeros; colombianos en llegada se infieren como remanente contra total Aerocivil.',
                ],
                $request
            ),
            $this->upsertOfficialProfile(
                'Perfil oficial MDE salidas ' . $this->monthNameEs($targetMonth) . ' ' . $migrationYear,
                'departure',
                $departureColombianPct,
                $migrationYear,
                $targetMonth,
                [
                    'colombian_exits_migration' => $colombianExits,
                    'official_departures_aerocivil' => $officialDepartures,
                    'limitation' => 'Migracion publica salidas de colombianos; extranjeros en salida se infieren como remanente contra total Aerocivil.',
                ],
                $request
            ),
            $this->upsertOfficialProfile(
                'Perfil oficial MDE total ' . $this->monthNameEs($targetMonth) . ' ' . $migrationYear,
                null,
                $globalColombianPct,
                $migrationYear,
                $targetMonth,
                [
                    'official_arrivals_aerocivil' => $officialArrivals,
                    'official_departures_aerocivil' => $officialDepartures,
                    'foreign_entries_migration' => $foreignEntries,
                    'colombian_exits_migration' => $colombianExits,
                    'limitation' => 'Perfil global ponderado a partir de perfiles direccionales y totales Aerocivil.',
                ],
                $request
            ),
        ];

        return [
            'message' => 'Perfil oficial sincronizado.',
            'target_period' => [
                'requested_year' => $targetYear,
                'official_year_used' => $migrationYear,
                'month' => $targetMonth,
                'month_name' => $this->monthNameEs($targetMonth),
            ],
            'inputs' => [
                'foreign_entries_migration' => $foreignEntries,
                'colombian_exits_migration' => $colombianExits,
                'official_arrivals_aerocivil' => $officialArrivals,
                'official_departures_aerocivil' => $officialDepartures,
            ],
            'profiles' => array_map(fn ($profile) => $this->profilePayload($profile), $profiles),
        ];
    }

    private function validatedFilters(Request $request): array
    {
        $data = $request->validate([
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'direction' => 'nullable|in:arrival,departure',
            'airline' => 'nullable|string|max:120',
            'destination' => 'nullable|string|max:8',
        ]);

        return [
            'date_from' => $data['date_from'] ?? null,
            'date_to' => $data['date_to'] ?? null,
            'direction' => $data['direction'] ?? null,
            'airline' => $data['airline'] ?? null,
            'destination' => isset($data['destination']) ? strtoupper($data['destination']) : null,
        ];
    }

    private function flightQuery(array $filters)
    {
        $query = PassengerFlight::query();

        if ($filters['date_from']) {
            $query->whereDate('flight_date', '>=', $filters['date_from']);
        }

        if ($filters['date_to']) {
            $query->whereDate('flight_date', '<=', $filters['date_to']);
        }

        if ($filters['direction']) {
            $query->where('direction', $filters['direction']);
        }

        if ($filters['airline']) {
            $query->where('airline', 'like', '%' . $filters['airline'] . '%');
        }

        if ($filters['destination']) {
            $query->where('destination', $filters['destination']);
        }

        return $query;
    }

    private function resolveComposition(array $filters): ?PassengerCompositionProfile
    {
        $date = $filters['date_to'] ?: $filters['date_from'];

        $query = PassengerCompositionProfile::where('is_active', true)
            ->where(function ($q) use ($filters) {
                $q->whereNull('direction');
                if ($filters['direction']) {
                    $q->orWhere('direction', $filters['direction']);
                }
            });

        if ($date) {
            $query->where(function ($q) use ($date) {
                $q->whereNull('valid_from')->orWhereDate('valid_from', '<=', $date);
            })->where(function ($q) use ($date) {
                $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', $date);
            });
        }

        $profile = (clone $query)
            ->orderByRaw('CASE WHEN direction IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('valid_from')
            ->orderByDesc('created_at')
            ->first();

        if ($profile || !$date) {
            return $profile;
        }

        return PassengerCompositionProfile::where('is_active', true)
            ->where(function ($q) use ($filters) {
                $q->whereNull('direction');
                if ($filters['direction']) {
                    $q->orWhere('direction', $filters['direction']);
                }
            })
            ->orderByRaw('CASE WHEN direction IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('valid_from')
            ->orderByDesc('created_at')
            ->first();
    }

    private function upsertOfficialProfile(
        string $name,
        ?string $direction,
        float $colombianPct,
        int $year,
        int $month,
        array $notes,
        Request $request
    ): PassengerCompositionProfile {
        $validFrom = Carbon::create($year, $month, 1, 0, 0, 0, 'America/Bogota')->toDateString();
        $validTo = Carbon::create($year, $month, 1, 0, 0, 0, 'America/Bogota')->endOfMonth()->toDateString();

        return PassengerCompositionProfile::updateOrCreate(
            [
                'name' => $name,
                'direction' => $direction,
                'valid_from' => $validFrom,
                'valid_to' => $validTo,
            ],
            [
                'colombian_pct' => $colombianPct,
                'foreign_pct' => round(100 - $colombianPct, 3),
                'source_name' => 'Migracion Colombia + Aerocivil Datos Abiertos',
                'source_url' => 'https://www.datos.gov.co/',
                'method' => 'OFFICIAL_MONTHLY_RECONCILIATION',
                'confidence_level' => 'MEDIUM',
                'is_active' => true,
                'notes' => json_encode($notes, JSON_UNESCAPED_UNICODE),
                'created_by' => optional($request->user())->id,
            ]
        );
    }

    private function latestFlightYear(): int
    {
        $date = PassengerFlight::max('flight_date');
        return $date ? (int) Carbon::parse($date)->year : (int) now('America/Bogota')->year;
    }

    private function latestFlightMonth(): int
    {
        $date = PassengerFlight::max('flight_date');
        return $date ? (int) Carbon::parse($date)->month : (int) now('America/Bogota')->month;
    }

    private function latestMigrationYear(): int
    {
        try {
            $rows = $this->socrataGet(self::MIGRATION_FOREIGN_ENTRIES_DATASET, [
                '$select' => 'max(a_o)',
            ]);

            return (int) ($rows[0]['max_a_o'] ?? 2025);
        } catch (\Throwable) {
            return 2025;
        }
    }

    private function socrataSum(string $dataset, string $where, string $field): float
    {
        $rows = $this->socrataGet($dataset, [
            '$select' => 'sum(' . $field . ')',
            '$where' => $where,
        ]);

        return round((float) ($rows[0]['sum_' . $field] ?? 0), 2);
    }

    private function socrataGet(string $dataset, array $query): array
    {
        $request = Http::timeout(20)
            ->retry(2, 300)
            ->acceptJson();

        if (!config('services.datos_gov.verify_ssl', false)) {
            $request = $request->withoutVerifying();
        }

        $response = $request->get("https://www.datos.gov.co/resource/{$dataset}.json", $query);

        if (!$response->successful()) {
            throw new \RuntimeException("Datos Abiertos API error {$response->status()} for {$dataset}");
        }

        return $response->json() ?: [];
    }

    private function monthNameEs(int $month): string
    {
        $months = [
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
        ];

        return $months[$month] ?? 'Enero';
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
            'is_active' => (bool) $profile->is_active,
            'notes' => $profile->notes,
            'created_at' => $profile->created_at?->toDateTimeString(),
        ];
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
