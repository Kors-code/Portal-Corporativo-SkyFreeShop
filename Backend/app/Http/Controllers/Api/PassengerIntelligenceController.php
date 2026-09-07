<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PassengerIntelligence\PassengerCompositionProfile;
use App\Models\PassengerIntelligence\PassengerFlight;
use App\Models\PassengerIntelligence\PassengerImportBatch;
use App\Models\PassengerIntelligence\PassengerMonthlyFact;
use App\Models\PassengerIntelligence\PassengerSourceFile;
use App\Services\PassengerIntelligence\PassengerCommercialExposureService;
use App\Services\PassengerIntelligence\PassengerExcelImportService;
use App\Services\PassengerIntelligence\PassengerExternalSignalService;
use App\Services\PassengerIntelligence\PassengerFlightEstimationService;
use App\Services\PassengerIntelligence\PassengerForecastService;
use App\Services\PassengerIntelligence\PassengerMigrationMicrodataService;
use App\Services\PassengerIntelligence\PassengerOneDrivePaxService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;

class PassengerIntelligenceController extends Controller
{
    private const MDE_PCM_LOCATION = '(6.171601,-75.427454)';
    private const MIGRATION_FOREIGN_ENTRIES_DATASET = '96sh-4v8d';
    private const MIGRATION_COLOMBIAN_EXITS_DATASET = 'efw5-jiej';
    private const AEROCIVIL_TRAFFIC_DATASET = 'gb6w-ynu4';

    public function summary(
        Request $request,
        PassengerCommercialExposureService $exposureService,
        PassengerFlightEstimationService $estimator
    )
    {
        $filters = $this->validatedFilters($request);
        $query = $this->flightQuery($filters);

        $totalPax = (float) (clone $query)->sum('pax');
        $observedPax = (float) (clone $query)->where('data_type', 'observed')->sum('pax');
        $estimatedPax = (float) (clone $query)->where('data_type', 'estimated')->sum('pax');
        $totalFlights = (clone $query)->count();
        $dateCount = (clone $query)->distinct('flight_date')->count('flight_date');
        $monthlyObservedFactPax = $this->observedMonthlyFactTotalForFilters($filters);
        if ($monthlyObservedFactPax !== null) {
            $totalPax = $monthlyObservedFactPax;
            $observedPax = $monthlyObservedFactPax;
        }

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

        $estimateFilters = [
            'date_from' => $filters['date_from'],
            'date_to' => $filters['date_to'],
            'direction' => $filters['direction'],
        ];

        if (($filters['data_type'] ?? null) && $filters['data_type'] !== 'all') {
            $estimateFilters['data_type'] = $filters['data_type'];
        }

        $estimateRows = $estimator->monthlyAnalytics($estimateFilters);
        $estimateCommercialPax = round(array_sum(array_column($estimateRows, 'commercial_exposed_pax')), 2);
        $estimateColombianPax = round(array_sum(array_column($estimateRows, 'colombian_pax')), 2);
        $estimateForeignPax = round(array_sum(array_column($estimateRows, 'foreign_pax')), 2);
        $hasStoredCompositionEstimate = $estimateCommercialPax > 0 && ($estimateColombianPax > 0 || $estimateForeignPax > 0);

        $summaryColombianPax = $hasStoredCompositionEstimate
            ? ($monthlyObservedFactPax !== null && $estimateCommercialPax > 0
                ? round($monthlyObservedFactPax * ($estimateColombianPax / $estimateCommercialPax), 2)
                : $estimateColombianPax)
            : ($composition ? round($totalPax * ((float) $composition->colombian_pct / 100), 2) : null);
        $summaryForeignPax = $hasStoredCompositionEstimate
            ? ($monthlyObservedFactPax !== null && $summaryColombianPax !== null
                ? round($monthlyObservedFactPax - $summaryColombianPax, 2)
                : $estimateForeignPax)
            : ($composition ? round($totalPax * ((float) $composition->foreign_pct / 100), 2) : null);
        $summaryCompositionBasePax = $monthlyObservedFactPax ?? ($hasStoredCompositionEstimate ? $estimateCommercialPax : $totalPax);
        $summaryColombianPct = $summaryCompositionBasePax > 0 && $summaryColombianPax !== null
            ? round(($summaryColombianPax / $summaryCompositionBasePax) * 100, 3)
            : ($composition ? round((float) $composition->colombian_pct, 3) : null);
        $summaryForeignPct = $summaryCompositionBasePax > 0 && $summaryForeignPax !== null
            ? round(($summaryForeignPax / $summaryCompositionBasePax) * 100, 3)
            : ($composition ? round((float) $composition->foreign_pct, 3) : null);

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
                'observed_pax' => round($observedPax, 2),
                'estimated_pax' => round($estimatedPax, 2),
                'total_flights' => $totalFlights,
                'days' => $dateCount,
                'avg_pax_per_day' => $dateCount > 0 ? round($totalPax / $dateCount, 2) : 0,
                'avg_pax_per_flight' => $totalFlights > 0 ? round($totalPax / $totalFlights, 2) : 0,
                'composition_base_pax' => round($summaryCompositionBasePax, 2),
                'colombian_pax' => $summaryColombianPax,
                'foreign_pax' => $summaryForeignPax,
                'colombian_pct' => $summaryColombianPct,
                'foreign_pct' => $summaryForeignPct,
                'composition_source' => $hasStoredCompositionEstimate ? 'stored_flight_estimates' : 'profile_fallback',
            ],
            'composition' => $composition ? $this->profilePayload($composition) : null,
            'quality' => [
                'flow_data_type' => $observedPax > 0 ? 'observed_internal' : 'estimated',
                'flow_source' => $observedPax > 0 ? 'OneDrive/Excel Sky Free PAX observado' : 'Excel PAX operativo',
                'composition_status' => $composition ? 'estimated_from_profile' : 'missing_official_profile',
                'veracity_note' => $composition
                    ? 'El flujo viene de archivos PAX internos; colombiano/extranjero se estima con el perfil de composicion seleccionado y queda trazable.'
                    : 'El flujo viene del Excel importado. No se muestra porcentaje colombiano/extranjero porque el Excel no contiene nacionalidad.',
            ],
            'commercial_exposure' => $this->exposureForFilters($filters, $exposureService),
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
                'source_type' => $batch->source_type,
                'observed_scope' => $batch->observed_scope,
                'source_path' => $batch->source_path,
                'source_url' => $batch->source_url,
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

    public function sourceAudit(Request $request, PassengerCommercialExposureService $exposureService)
    {
        $data = $request->validate([
            'year' => 'nullable|integer|min:2012|max:2100',
            'month' => 'nullable|integer|min:1|max:12',
        ]);

        if (!empty($data['year']) || !empty($data['month'])) {
            $exposureService->refreshObservedFacts($data['year'] ?? null, $data['month'] ?? null);
        }

        $period = $this->auditPeriod($data['year'] ?? null, $data['month'] ?? null);
        $monthlyRows = $this->sourceAuditMonthlyRows($period);
        $batchRows = $this->sourceAuditBatchRows($period, isset($data['year'], $data['month']));

        return response()->json([
            'filters' => [
                'year' => $data['year'] ?? null,
                'month' => $data['month'] ?? null,
                'period_start' => $period['start']?->toDateString(),
                'period_end' => $period['end']?->toDateString(),
            ],
            'summary' => [
                'months' => count($monthlyRows),
                'batches' => count($batchRows),
                'onedrive_batches' => collect($batchRows)->where('is_onedrive', true)->count(),
                'manual_batches' => collect($batchRows)->where('is_onedrive', false)->count(),
                'audited_pax' => round((float) collect($monthlyRows)->sum('monthly_fact_pax'), 2),
                'warning' => collect($batchRows)->contains(fn ($row) => $row['status'] !== 'OK')
                    ? 'Hay diferencias o fuentes no OneDrive en el periodo. Revisa el detalle por archivo.'
                    : null,
            ],
            'monthly' => $monthlyRows,
            'batches' => $batchRows,
            'formulas' => [
                'monthly_fact_pax' => 'passenger_intelligence_monthly_facts.value donde fact_type = skyfree_commercial_observed_pax y source_type = skyfree_onedrive_pax',
                'batch_pax' => 'passenger_intelligence_import_batches.total_pax del archivo importado',
                'flight_rows_pax' => 'SUM(passenger_intelligence_flights.pax) por batch_id o por mes',
                'difference' => 'monthly_fact_pax - flight_rows_pax o batch_pax - flight_rows_pax',
            ],
        ]);
    }

    public function import(
        Request $request,
        PassengerExcelImportService $importer,
        PassengerCommercialExposureService $exposureService
    )
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls|max:20480',
        ]);

        $file = $request->file('file');

        try {
            $result = $importer->importUploadedFile($file, optional($request->user())->id);
            $this->refreshExposureFactsForBatch($result['batch'] ?? null, $exposureService);

            return response()->json([
                'message' => 'Importacion de pasajeros completada',
                'batch_id' => $result['batch_id'],
                'rows_imported' => $result['rows_imported'],
                'rows_skipped' => $result['rows_skipped'],
                'total_pax' => $result['total_pax'],
                'path' => $result['path'] ?? null,
            ]);
        } catch (\Throwable $e) {
            Log::error('Passenger Intelligence import failed: ' . $e->getMessage());

            $status = str_contains($e->getMessage(), 'ya fue importado') ? 409 : 500;

            return response()->json([
                'message' => 'No se pudo importar el archivo de pasajeros',
                'error' => $e->getMessage(),
            ], $status);
        }
    }

    public function sourceFiles(PassengerOneDrivePaxService $oneDrive)
    {
        $files = PassengerSourceFile::where('provider', 'onedrive')
            ->orderByDesc('source_last_modified_at')
            ->limit(50)
            ->get()
            ->map(fn (PassengerSourceFile $file) => $oneDrive->filePayload($file));

        return response()->json($files);
    }

    public function syncOneDriveFiles(Request $request, PassengerOneDrivePaxService $oneDrive)
    {
        $data = $request->validate([
            'recursive' => 'nullable|boolean',
        ]);

        try {
            $files = $oneDrive->discoverFiles((bool) ($data['recursive'] ?? true));
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'No se pudo sincronizar la carpeta PAX Col de OneDrive.',
                'error' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'message' => 'Archivos PAX encontrados en OneDrive.',
            'files' => $files,
        ]);
    }

    public function importOneDriveFile(
        Request $request,
        PassengerOneDrivePaxService $oneDrive,
        PassengerExcelImportService $importer,
        PassengerCommercialExposureService $exposureService
    ) {
        $data = $request->validate([
            'source_file_id' => 'nullable|integer',
            'limit' => 'nullable|integer|min:1|max:20',
        ]);

        $files = isset($data['source_file_id'])
            ? PassengerSourceFile::where('provider', 'onedrive')->whereKey($data['source_file_id'])->get()
            : PassengerSourceFile::where('provider', 'onedrive')
                ->whereIn('status', ['discovered', 'import_failed'])
                ->orderByDesc('source_last_modified_at')
                ->limit($data['limit'] ?? 5)
                ->get();

        if ($files->isEmpty()) {
            return response()->json([
                'message' => 'No hay archivos OneDrive pendientes para importar. Sincroniza la carpeta primero.',
            ], 404);
        }

        $results = [];
        $errors = [];

        foreach ($files as $file) {
            try {
                $result = $oneDrive->importFile($file, $importer, optional($request->user())->id);
                $this->refreshExposureFactsForBatch($result['batch'] ?? null, $exposureService);
                $results[] = [
                    'source_file' => $result['source_file'] ?? $oneDrive->filePayload($file->fresh()),
                    'batch_id' => $result['batch_id'] ?? null,
                    'duplicate' => (bool) ($result['duplicate'] ?? false),
                    'rows_imported' => $result['rows_imported'] ?? 0,
                    'total_pax' => $result['total_pax'] ?? 0,
                ];
            } catch (\Throwable $e) {
                $errors[] = [
                    'source_file_id' => $file->id,
                    'filename' => $file->name,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'message' => empty($errors) ? 'Importacion OneDrive completada.' : 'Importacion OneDrive completada con advertencias.',
            'results' => $results,
            'errors' => $errors,
        ], empty($results) ? 422 : 200);
    }

    public function reloadOneDrivePax(
        Request $request,
        PassengerOneDrivePaxService $oneDrive,
        PassengerExcelImportService $importer,
        PassengerCommercialExposureService $exposureService,
        PassengerFlightEstimationService $estimator
    ) {
        $data = $request->validate([
            'limit' => 'nullable|integer|min:1|max:100',
            'rediscover' => 'nullable|boolean',
        ]);

        $discoverError = null;
        $discoveredFiles = [];

        if ($data['rediscover'] ?? true) {
            try {
                $discoveredFiles = $oneDrive->discoverFiles(true);
            } catch (\Throwable $e) {
                $discoverError = $e->getMessage();
            }
        }

        $reload = $oneDrive->reloadImportedFiles(
            $importer,
            optional($request->user())->id,
            $data['limit'] ?? null
        );

        $facts = $exposureService->refreshObservedFacts();
        $estimates = $estimator->recalculate(['data_type' => 'observed']);

        return response()->json([
            'message' => empty($reload['errors'])
                ? 'OneDrive PAX recargado y recalculado.'
                : 'OneDrive PAX recargado con advertencias.',
            'discover_error' => $discoverError,
            'discovered_files' => count($discoveredFiles),
            'facts_refreshed' => count($facts),
            'estimates' => $estimates,
            ...$reload,
        ], empty($reload['results']) ? 422 : 200);
    }

    public function monthlyFacts(Request $request, PassengerCommercialExposureService $exposureService)
    {
        $data = $request->validate([
            'year' => 'nullable|integer|min:2012|max:2100',
            'month' => 'nullable|integer|min:1|max:12',
        ]);

        return response()->json($exposureService->monthlyFacts($data['year'] ?? null, $data['month'] ?? null));
    }

    public function flightEstimates(Request $request, PassengerFlightEstimationService $estimator)
    {
        $data = $request->validate([
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'direction' => 'nullable|in:arrival,departure',
            'data_type' => 'nullable|in:observed,estimated',
            'limit' => 'nullable|integer|min:1|max:500',
        ]);

        $data['data_type'] = $data['data_type'] ?? 'observed';

        return response()->json($estimator->latest($data, $data['limit'] ?? 50));
    }

    public function monthlyEstimateAnalytics(Request $request, PassengerFlightEstimationService $estimator)
    {
        $data = $request->validate([
            'year' => 'nullable|integer|min:2012|max:2100',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'direction' => 'nullable|in:arrival,departure',
            'data_type' => 'nullable|in:observed,estimated',
        ]);

        $data['data_type'] = $data['data_type'] ?? 'observed';

        return response()->json($estimator->monthlyAnalytics($data));
    }

    public function forecasts(Request $request, PassengerForecastService $forecastService)
    {
        $data = $request->validate([
            'limit' => 'nullable|integer|min:1|max:50',
        ]);

        return response()->json($forecastService->latest($data['limit'] ?? 12));
    }

    public function externalSignals(Request $request, PassengerExternalSignalService $signals)
    {
        $data = $request->validate([
            'year' => 'nullable|integer|min:2012|max:2100',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'signal_type' => 'nullable|string|max:60',
            'limit' => 'nullable|integer|min:1|max:500',
        ]);

        return response()->json($signals->latest($data));
    }

    public function externalSignalImpact(Request $request, PassengerExternalSignalService $signals)
    {
        $data = $request->validate([
            'year' => 'nullable|integer|min:2012|max:2100',
        ]);

        return response()->json($signals->monthlyImpact($data));
    }

    public function syncExternalSignals(Request $request, PassengerExternalSignalService $signals)
    {
        $data = $request->validate([
            'year' => 'nullable|integer|min:2012|max:2100',
        ]);

        return response()->json([
            'message' => 'Festivos y eventos verificables sincronizados.',
            ...$signals->syncVerifiableSignals($data['year'] ?? null),
        ]);
    }

    public function generateForecast(Request $request, PassengerForecastService $forecastService)
    {
        $data = $request->validate([
            'target_year' => 'nullable|integer|min:2012|max:2100',
            'target_month' => 'nullable|integer|min:1|max:12',
            'run_date' => 'nullable|date',
            'cutoff_date' => 'nullable|date',
            'send_email' => 'nullable|boolean',
            'email' => 'nullable|email',
        ]);

        $result = $forecastService->generate([
            ...$data,
            'created_by' => $request->user()?->id,
        ]);

        return response()->json([
            'message' => 'Forecast Passenger Intelligence generado.',
            'forecast' => $result,
        ], 201);
    }

    public function recalculateFlightEstimates(Request $request, PassengerFlightEstimationService $estimator)
    {
        $data = $request->validate([
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'direction' => 'nullable|in:arrival,departure',
            'data_type' => 'nullable|in:observed,estimated',
            'batch_id' => 'nullable|integer',
        ]);

        $result = $estimator->recalculate($data);

        return response()->json([
            'message' => 'Estimaciones por vuelo recalculadas y guardadas.',
            ...$result,
        ]);
    }

    public function recalculateAll(
        Request $request,
        PassengerCommercialExposureService $exposureService,
        PassengerFlightEstimationService $estimator
    ) {
        $data = $request->validate([
            'year' => 'nullable|integer|min:2012|max:2100',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'direction' => 'nullable|in:arrival,departure',
            'batch_id' => 'nullable|integer',
        ]);

        $estimateFilters = array_filter([
            'date_from' => $data['date_from'] ?? null,
            'date_to' => $data['date_to'] ?? null,
            'direction' => $data['direction'] ?? null,
            'data_type' => $data['data_type'] ?? 'observed',
            'batch_id' => $data['batch_id'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');

        if (!isset($estimateFilters['date_from'], $estimateFilters['date_to']) && !empty($data['year'])) {
            $estimateFilters['date_from'] = sprintf('%d-01-01', (int) $data['year']);
            $estimateFilters['date_to'] = sprintf('%d-12-31', (int) $data['year']);
        }

        $exposure = $exposureService->calculateAvailablePeriods($data['year'] ?? null);
        $estimates = $estimator->recalculate($estimateFilters);

        return response()->json([
            'message' => 'Exposicion comercial y estimaciones por vuelo recalculadas.',
            'exposure' => $exposure,
            'estimates' => $estimates,
        ]);
    }

    public function recalculateExposure(Request $request, PassengerCommercialExposureService $exposureService)
    {
        $data = $request->validate([
            'year' => 'nullable|integer|min:2012|max:2100',
            'month' => 'nullable|integer|min:1|max:12',
            'all' => 'nullable|boolean',
        ]);

        if ($data['all'] ?? false) {
            return response()->json([
                'message' => 'Exposicion comercial recalculada para todos los meses observados.',
                ...$exposureService->calculateAvailablePeriods($data['year'] ?? null),
            ]);
        }

        [$year, $month] = $this->periodForExposure($data['year'] ?? null, $data['month'] ?? null);

        try {
            $rates = $exposureService->calculateForPeriod($year, $month);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'No se pudo recalcular la exposicion comercial.',
                'error' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'message' => 'Exposicion comercial recalculada.',
            'period' => ['year' => $year, 'month' => $month],
            'rates' => $rates,
        ]);
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

    public function migrationMicrodataAudit(Request $request, PassengerMigrationMicrodataService $migrationMicrodata)
    {
        $data = $request->validate([
            'year' => 'nullable|integer|min:2012|max:2100',
            'month' => 'nullable|integer|min:1|max:12',
        ]);

        return response()->json($migrationMicrodata->audit($data['year'] ?? null, $data['month'] ?? null));
    }

    public function importMigrationMicrodata(
        Request $request,
        PassengerMigrationMicrodataService $migrationMicrodata,
        PassengerFlightEstimationService $estimator
    ) {
        $data = $request->validate([
            'file' => 'required|file|mimes:csv,txt,xlsx,xls|max:204800',
            'recalculate_estimates' => 'nullable|boolean',
        ]);

        try {
            $result = $migrationMicrodata->importUploadedFile($request->file('file'), optional($request->user())->id);

            if (($data['recalculate_estimates'] ?? true) && !($result['duplicate'] ?? false) && !empty($result['period_start']) && !empty($result['period_end'])) {
                $result['estimates'] = $estimator->recalculate([
                    'date_from' => $result['period_start'],
                    'date_to' => $result['period_end'],
                    'data_type' => 'observed',
                ]);
            }

            return response()->json([
                'message' => ($result['duplicate'] ?? false)
                    ? 'Microdatos de Migracion ya importados previamente.'
                    : 'Microdatos de Migracion importados y perfiles mensuales creados.',
                ...$result,
            ], ($result['duplicate'] ?? false) ? 200 : 201);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'No se pudieron importar los microdatos de Migracion.',
                'error' => $e->getMessage(),
            ], 422);
        }
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

    private function exposureForFilters(array $filters, PassengerCommercialExposureService $exposureService): array
    {
        [$year, $month] = $this->periodForExposure(
            isset($filters['date_to']) && $filters['date_to'] ? (int) Carbon::parse($filters['date_to'])->year : null,
            isset($filters['date_to']) && $filters['date_to'] ? (int) Carbon::parse($filters['date_to'])->month : null
        );

        return [
            'period' => ['year' => $year, 'month' => $month],
            'rates' => $exposureService->latestRates($year, $month),
        ];
    }

    private function refreshExposureFactsForBatch(mixed $batch, PassengerCommercialExposureService $exposureService): void
    {
        if (!$batch instanceof PassengerImportBatch || !$batch->period_start || !$batch->period_end) {
            return;
        }

        $cursor = $batch->period_start->copy()->startOfMonth();
        $end = $batch->period_end->copy()->startOfMonth();

        while ($cursor->lessThanOrEqualTo($end)) {
            $exposureService->refreshObservedFacts((int) $cursor->year, (int) $cursor->month);
            $cursor->addMonth();
        }
    }

    private function auditPeriod(?int $year, ?int $month): array
    {
        if (!$year && !$month) {
            return ['start' => null, 'end' => null];
        }

        $year = $year ?: (int) now('America/Bogota')->year;

        if (!$month) {
            return [
                'start' => Carbon::create($year, 1, 1, 0, 0, 0, 'America/Bogota'),
                'end' => Carbon::create($year, 12, 1, 0, 0, 0, 'America/Bogota')->endOfMonth(),
            ];
        }

        $start = Carbon::create($year, $month, 1, 0, 0, 0, 'America/Bogota');

        return ['start' => $start, 'end' => $start->copy()->endOfMonth()];
    }

    private function sourceAuditMonthlyRows(array $period): array
    {
        $facts = PassengerMonthlyFact::where([
            'airport_iata' => 'MDE',
            'direction' => 'total',
            'fact_type' => 'skyfree_commercial_observed_pax',
            'source_type' => 'skyfree_onedrive_pax',
        ]);

        if ($period['start'] && $period['end']) {
            $facts->where(function ($q) use ($period) {
                $q->where('year', '>', (int) $period['start']->year)
                    ->orWhere(fn ($sameYear) => $sameYear->where('year', (int) $period['start']->year)->where('month', '>=', (int) $period['start']->month));
            })->where(function ($q) use ($period) {
                $q->where('year', '<', (int) $period['end']->year)
                    ->orWhere(fn ($sameYear) => $sameYear->where('year', (int) $period['end']->year)->where('month', '<=', (int) $period['end']->month));
            });
        }

        return $facts
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->limit(36)
            ->get()
            ->map(function (PassengerMonthlyFact $fact) {
                $flightAgg = PassengerFlight::where('data_type', 'observed')
                    ->where('observed_scope', 'commercial_flow')
                    ->whereYear('flight_date', $fact->year)
                    ->whereMonth('flight_date', $fact->month)
                    ->selectRaw('COUNT(*) as rows_count, COALESCE(SUM(pax), 0) as pax_value')
                    ->first();

                $singleMonthBatches = PassengerImportBatch::where('source_type', 'onedrive_skyfree_pax')
                    ->where('observed_scope', 'commercial_flow')
                    ->whereYear('period_start', $fact->year)
                    ->whereMonth('period_start', $fact->month)
                    ->whereRaw('YEAR(period_start) = YEAR(period_end)')
                    ->whereRaw('MONTH(period_start) = MONTH(period_end)')
                    ->get();

                $factPax = round((float) $fact->value, 2);
                $flightPax = round((float) ($flightAgg?->pax_value ?? 0), 2);

                $difference = round($factPax - $flightPax, 2);

                return [
                    'period' => sprintf('%04d-%02d', $fact->year, $fact->month),
                    'year' => $fact->year,
                    'month' => $fact->month,
                    'monthly_fact_pax' => $factPax,
                    'monthly_fact_rows' => $fact->records_count,
                    'flight_rows_pax' => $flightPax,
                    'flight_rows_count' => (int) ($flightAgg?->rows_count ?? 0),
                    'batch_total_pax' => round((float) $singleMonthBatches->sum('total_pax'), 2),
                    'batch_rows' => (int) $singleMonthBatches->sum('rows_imported'),
                    'batch_count' => $singleMonthBatches->count(),
                    'source_mode' => 'onedrive_flight_rows',
                    'difference_vs_flight_rows' => $difference,
                    'status' => $fact->source_name === 'OneDrive Sky Free PAX Col' && abs($difference) <= 0.01 ? 'OK' : 'REVISAR',
                    'source_name' => $fact->source_name,
                    'source_period' => $fact->source_period,
                    'explanation' => 'El total mensual se reconstruye desde las filas de vuelos guardadas desde OneDrive para que OneDrive y BD cuadren linea a linea.',
                ];
            })
            ->all();
    }

    private function sourceAuditBatchRows(array $period, bool $includeRawExcel = false): array
    {
        $batches = PassengerImportBatch::with('sourceFile')
            ->whereIn('source_type', ['onedrive_skyfree_pax', 'excel'])
            ->whereNotNull('period_start')
            ->whereNotNull('period_end');

        if ($period['start'] && $period['end']) {
            $batches
                ->whereDate('period_start', '<=', $period['end']->toDateString())
                ->whereDate('period_end', '>=', $period['start']->toDateString());
        }

        return $batches
            ->orderByDesc('period_start')
            ->orderByDesc('id')
            ->limit(60)
            ->get()
            ->map(function (PassengerImportBatch $batch) use ($includeRawExcel) {
                $flightAgg = PassengerFlight::where('batch_id', $batch->id)
                    ->selectRaw('COUNT(*) as rows_count, COALESCE(SUM(pax), 0) as pax_value, MIN(flight_date) as min_date, MAX(flight_date) as max_date')
                    ->first();

                $directions = PassengerFlight::where('batch_id', $batch->id)
                    ->select('direction', DB::raw('COUNT(*) as rows_count'), DB::raw('COALESCE(SUM(pax), 0) as pax_value'))
                    ->groupBy('direction')
                    ->orderBy('direction')
                    ->get()
                    ->map(fn ($row) => [
                        'direction' => $row->direction,
                        'rows' => (int) $row->rows_count,
                        'pax' => round((float) $row->pax_value, 2),
                    ])
                    ->all();
                $rawDirections = $includeRawExcel
                    ? $this->rawExcelDirectionsForBatch($batch)
                    : ['path_found' => false, 'directions' => []];

                $sourceFile = $batch->sourceFile;
                $batchPax = round((float) $batch->total_pax, 2);
                $flightPax = round((float) ($flightAgg?->pax_value ?? 0), 2);
                $isOneDrive = $batch->source_type === 'onedrive_skyfree_pax'
                    && $batch->observed_scope === 'commercial_flow'
                    && ($sourceFile || $batch->source_url);
                $difference = round($batchPax - $flightPax, 2);

                return [
                    'batch_id' => $batch->id,
                    'filename' => $batch->filename,
                    'period_start' => $batch->period_start?->toDateString(),
                    'period_end' => $batch->period_end?->toDateString(),
                    'source_type' => $batch->source_type,
                    'observed_scope' => $batch->observed_scope,
                    'is_onedrive' => $isOneDrive,
                    'status' => $isOneDrive && abs($difference) <= 0.01 ? 'OK' : 'REVISAR',
                    'batch_pax' => $batchPax,
                    'batch_rows' => $batch->rows_imported,
                    'flight_rows_pax' => $flightPax,
                    'flight_rows_count' => (int) ($flightAgg?->rows_count ?? 0),
                    'difference_vs_flight_rows' => $difference,
                    'flight_min_date' => $flightAgg?->min_date,
                    'flight_max_date' => $flightAgg?->max_date,
                    'directions' => $directions,
                    'raw_excel_directions' => $rawDirections['directions'],
                    'raw_excel_path_found' => $rawDirections['path_found'],
                    'source_file' => $sourceFile ? [
                        'id' => $sourceFile->id,
                        'provider' => $sourceFile->provider,
                        'drive_item_id' => $sourceFile->drive_item_id,
                        'drive_id' => $sourceFile->drive_id,
                        'name' => $sourceFile->name,
                        'web_url' => $sourceFile->web_url,
                        'parent_path' => $sourceFile->parent_path,
                        'status' => $sourceFile->status,
                        'checksum' => $sourceFile->checksum,
                        'source_last_modified_at' => $sourceFile->source_last_modified_at?->toDateTimeString(),
                        'downloaded_at' => $sourceFile->downloaded_at?->toDateTimeString(),
                    ] : null,
                    'source_url' => $batch->source_url,
                    'source_path' => $batch->source_path,
                    'notes' => $batch->notes,
                    'explanation' => $isOneDrive
                        ? 'Este batch viene de Microsoft Graph / OneDrive PAX Col y representa PAX operativo Sky Free.'
                        : 'Este batch no esta completamente trazado a OneDrive; puede ser carga manual o fuente anterior.',
                ];
            })
            ->all();
    }

    private function rawExcelDirectionsForBatch(PassengerImportBatch $batch): array
    {
        $path = $this->storedOriginalPathForBatch($batch);

        if (!$path) {
            return ['path_found' => false, 'directions' => []];
        }

        try {
            $reader = IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);
            $workbook = $reader->load($path);
            $directions = [];

            foreach ($workbook->getWorksheetIterator() as $sheet) {
                $direction = match (strtoupper(trim($sheet->getTitle()))) {
                    'ARRIVALS' => 'arrival',
                    'DEPARTURES' => 'departure',
                    default => null,
                };

                if (!$direction) {
                    continue;
                }

                $rows = $sheet->toArray(null, true, true, false);
                if (empty($rows)) {
                    continue;
                }

                $headers = array_map(fn ($header) => strtolower(trim(str_replace([' ', '-'], '_', (string) $header))), $rows[0]);
                $pax = 0.0;
                $validRows = 0;

                for ($i = 1; $i < count($rows); $i++) {
                    $row = [];
                    foreach ($headers as $idx => $key) {
                        if ($key !== '') {
                            $row[$key] = $rows[$i][$idx] ?? null;
                        }
                    }

                    if (!$this->rawExcelRowLooksImportable($row)) {
                        continue;
                    }

                    $pax += $this->rawExcelNumber($row['pax'] ?? null);
                    $validRows++;
                }

                $directions[] = [
                    'direction' => $direction,
                    'rows' => $validRows,
                    'pax' => round($pax, 2),
                    'source' => 'stored_original_excel',
                ];
            }

            return ['path_found' => true, 'directions' => $directions];
        } catch (\Throwable $e) {
            return [
                'path_found' => true,
                'directions' => [],
                'error' => $e->getMessage(),
            ];
        }
    }

    private function storedOriginalPathForBatch(PassengerImportBatch $batch): ?string
    {
        $directory = storage_path('app/private/imports/passenger-intelligence');
        if (!is_dir($directory)) {
            return null;
        }

        $candidates = glob($directory . DIRECTORY_SEPARATOR . '*.xlsx') ?: [];
        foreach ($candidates as $candidate) {
            if (is_file($candidate) && hash_file('sha256', $candidate) === $batch->checksum) {
                return $candidate;
            }
        }

        return null;
    }

    private function rawExcelRowLooksImportable(array $row): bool
    {
        return trim((string) ($row['date'] ?? '')) !== ''
            && trim((string) ($row['aer'] ?? '')) !== ''
            && trim((string) ($row['code'] ?? '')) !== ''
            && $this->rawExcelNumber($row['pax'] ?? null) > 0;
    }

    private function rawExcelNumber(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        if (is_numeric($value)) {
            return round((float) $value, 2);
        }

        $normalized = str_replace(',', '.', trim((string) $value));

        return is_numeric($normalized) ? round((float) $normalized, 2) : 0.0;
    }

    private function periodForExposure(?int $year, ?int $month): array
    {
        if ($year && $month) {
            return [$year, $month];
        }

        $latestObserved = PassengerFlight::where('data_type', 'observed')
            ->where('observed_scope', 'commercial_flow')
            ->max('flight_date');

        $date = $latestObserved
            ? Carbon::parse($latestObserved, 'America/Bogota')
            : Carbon::parse(PassengerFlight::max('flight_date') ?: now('America/Bogota'), 'America/Bogota');

        return [(int) $date->year, (int) $date->month];
    }

    private function validatedFilters(Request $request): array
    {
        $data = $request->validate([
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'direction' => 'nullable|in:arrival,departure',
            'data_type' => 'nullable|in:observed,estimated,all',
            'airline' => 'nullable|string|max:120',
            'destination' => 'nullable|string|max:8',
        ]);

        return [
            'date_from' => $data['date_from'] ?? null,
            'date_to' => $data['date_to'] ?? null,
            'direction' => $data['direction'] ?? null,
            'data_type' => $data['data_type'] ?? 'observed',
            'airline' => $data['airline'] ?? null,
            'destination' => isset($data['destination']) ? strtoupper($data['destination']) : null,
        ];
    }

    private function observedMonthlyFactTotalForFilters(array $filters): ?float
    {
        if (($filters['data_type'] ?? 'observed') !== 'observed' || !empty($filters['direction']) || !empty($filters['airline']) || !empty($filters['destination'])) {
            return null;
        }

        if (empty($filters['date_from']) || empty($filters['date_to'])) {
            return null;
        }

        $from = Carbon::parse($filters['date_from'], 'America/Bogota')->startOfDay();
        $to = Carbon::parse($filters['date_to'], 'America/Bogota')->startOfDay();

        if (!$from->isSameDay($from->copy()->startOfMonth()) || !$to->isSameDay($to->copy()->endOfMonth()->startOfDay())) {
            return null;
        }

        $cursor = $from->copy()->startOfMonth();
        $end = $to->copy()->startOfMonth();
        $periods = [];

        while ($cursor->lessThanOrEqualTo($end)) {
            $periods[] = [(int) $cursor->year, (int) $cursor->month];
            $cursor->addMonth();
        }

        if (empty($periods)) {
            return null;
        }

        $query = PassengerMonthlyFact::where([
            'airport_iata' => 'MDE',
            'direction' => 'total',
            'fact_type' => 'skyfree_commercial_observed_pax',
            'source_type' => 'skyfree_onedrive_pax',
        ]);

        $query->where(function ($q) use ($periods) {
            foreach ($periods as [$year, $month]) {
                $q->orWhere(fn ($periodQuery) => $periodQuery->where('year', $year)->where('month', $month));
            }
        });

        $facts = $query->get();

        return $facts->count() === count($periods) ? round((float) $facts->sum('value'), 2) : null;
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

        if (($filters['data_type'] ?? null) && $filters['data_type'] !== 'all') {
            $query->where('data_type', $filters['data_type']);
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
            ->orderByRaw("CASE WHEN method = 'MIGRATION_MICRODATA_MONTHLY_PROFILE' THEN 0 WHEN method = 'OFFICIAL_MONTHLY_RECONCILIATION' THEN 1 ELSE 2 END")
            ->orderByDesc('valid_from')
            ->orderByDesc('created_at')
            ->first();

        if ($profile || !$date) {
            return $profile;
        }

        $fallbackQuery = PassengerCompositionProfile::where('is_active', true)
            ->where(function ($q) use ($filters) {
                $q->whereNull('direction');
                if ($filters['direction']) {
                    $q->orWhere('direction', $filters['direction']);
                }
            });

        $flightDate = Carbon::parse($date, 'America/Bogota');
        $sameMonthProfile = (clone $fallbackQuery)
            ->whereNotNull('valid_from')
            ->whereMonth('valid_from', (int) $flightDate->month)
            ->whereYear('valid_from', '<', (int) $flightDate->year)
            ->orderByRaw('CASE WHEN direction IS NULL THEN 1 ELSE 0 END')
            ->orderByRaw("CASE WHEN method = 'MIGRATION_MICRODATA_MONTHLY_PROFILE' THEN 0 WHEN method = 'OFFICIAL_MONTHLY_RECONCILIATION' THEN 1 ELSE 2 END")
            ->orderByDesc('valid_from')
            ->orderByDesc('created_at')
            ->first();

        if ($sameMonthProfile) {
            return $sameMonthProfile;
        }

        return $fallbackQuery
            ->where(function ($q) use ($date) {
                $q->whereNull('valid_from')->orWhereDate('valid_from', '<=', $date);
            })
            ->orderByRaw('CASE WHEN direction IS NULL THEN 1 ELSE 0 END')
            ->orderByRaw('CASE WHEN valid_from IS NULL THEN 1 ELSE 0 END')
            ->orderByRaw("CASE WHEN method = 'MIGRATION_MICRODATA_MONTHLY_PROFILE' THEN 0 WHEN method = 'OFFICIAL_MONTHLY_RECONCILIATION' THEN 1 ELSE 2 END")
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

}
