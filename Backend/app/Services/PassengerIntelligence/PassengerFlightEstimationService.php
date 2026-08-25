<?php

namespace App\Services\PassengerIntelligence;

use App\Models\PassengerIntelligence\PassengerCommercialExposureRate;
use App\Models\PassengerIntelligence\PassengerCompositionProfile;
use App\Models\PassengerIntelligence\PassengerFlight;
use App\Models\PassengerIntelligence\PassengerFlightEstimate;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class PassengerFlightEstimationService
{
    private const MODEL_VERSION = 'baseline_v1';

    private array $compositionCache = [];
    private array $exposureCache = [];

    public function recalculate(array $filters = []): array
    {
        $query = $this->flightQuery($filters);
        $processed = 0;
        $created = 0;
        $updated = 0;
        $withoutComposition = 0;
        $withoutExposure = 0;
        $totalBasePax = 0.0;
        $totalCommercialPax = 0.0;
        $totalColombianPax = 0.0;
        $totalForeignPax = 0.0;

        $query->orderBy('id')->chunkById(500, function ($flights) use (
            &$processed,
            &$created,
            &$updated,
            &$withoutComposition,
            &$withoutExposure,
            &$totalBasePax,
            &$totalCommercialPax,
            &$totalColombianPax,
            &$totalForeignPax
        ) {
            $existingFlightIds = PassengerFlightEstimate::where('model_version', self::MODEL_VERSION)
                ->whereIn('flight_id', $flights->pluck('id'))
                ->pluck('flight_id')
                ->all();
            $existingFlightIds = array_flip(array_map('intval', $existingFlightIds));
            $rows = [];
            $now = now();

            foreach ($flights as $flight) {
                $profile = $this->resolveComposition($flight);
                $exposureRate = $this->resolveExposureRate($flight);
                $basePax = round((float) $flight->pax, 2);
                $commercialPax = $this->commercialExposedPax($flight, $basePax, $exposureRate);

                if (!$profile) {
                    $withoutComposition++;
                }

                if (!$exposureRate && !$this->isObservedCommercialFlow($flight)) {
                    $withoutExposure++;
                }

                [$colombianPax, $foreignPax] = $this->splitComposition($commercialPax, $profile);
                $rows[] = [
                    'flight_id' => $flight->id,
                    'composition_profile_id' => $profile?->id,
                    'exposure_rate_id' => $exposureRate?->id,
                    'base_pax' => $basePax,
                    'commercial_exposed_pax' => $commercialPax,
                    'colombian_pct' => $profile ? round((float) $profile->colombian_pct, 3) : null,
                    'foreign_pct' => $profile ? round((float) $profile->foreign_pct, 3) : null,
                    'colombian_pax' => $colombianPax,
                    'foreign_pax' => $foreignPax,
                    'estimation_method' => $this->method($flight, $profile, $exposureRate),
                    'confidence_level' => $this->confidence($flight, $profile, $exposureRate),
                    'model_version' => self::MODEL_VERSION,
                    'input_sources' => json_encode($this->inputSources($flight, $profile, $exposureRate), JSON_UNESCAPED_UNICODE),
                    'explanation' => json_encode($this->explanation($flight, $profile, $exposureRate), JSON_UNESCAPED_UNICODE),
                    'calculated_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                isset($existingFlightIds[(int) $flight->id]) ? $updated++ : $created++;
                $processed++;
                $totalBasePax += $basePax;
                $totalCommercialPax += (float) $commercialPax;
                $totalColombianPax += (float) ($colombianPax ?? 0);
                $totalForeignPax += (float) ($foreignPax ?? 0);
            }

            $this->upsertEstimateRows($rows);
        });

        return [
            'model_version' => self::MODEL_VERSION,
            'filters' => $filters,
            'processed' => $processed,
            'created' => $created,
            'updated' => $updated,
            'without_composition' => $withoutComposition,
            'without_exposure' => $withoutExposure,
            'totals' => [
                'base_pax' => round($totalBasePax, 2),
                'commercial_exposed_pax' => round($totalCommercialPax, 2),
                'colombian_pax' => round($totalColombianPax, 2),
                'foreign_pax' => round($totalForeignPax, 2),
            ],
        ];
    }

    public function latest(array $filters = [], int $limit = 50): array
    {
        $query = PassengerFlightEstimate::query()
            ->with('flight')
            ->where('model_version', self::MODEL_VERSION);

        if (!empty($filters['date_from'])) {
            $query->whereHas('flight', fn ($q) => $q->whereDate('flight_date', '>=', $filters['date_from']));
        }

        if (!empty($filters['date_to'])) {
            $query->whereHas('flight', fn ($q) => $q->whereDate('flight_date', '<=', $filters['date_to']));
        }

        if (!empty($filters['direction'])) {
            $query->whereHas('flight', fn ($q) => $q->where('direction', $filters['direction']));
        }

        return $query
            ->orderByDesc('calculated_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (PassengerFlightEstimate $estimate) => $this->payload($estimate))
            ->all();
    }

    public function monthlyAnalytics(array $filters = []): array
    {
        $query = DB::connection('budget')
            ->table('passenger_intelligence_flight_estimates as estimates')
            ->join('passenger_intelligence_flights as flights', 'flights.id', '=', 'estimates.flight_id')
            ->where('estimates.model_version', self::MODEL_VERSION);

        if (!empty($filters['year'])) {
            $query->whereYear('flights.flight_date', (int) $filters['year']);
        }

        if (!empty($filters['date_from'])) {
            $query->whereDate('flights.flight_date', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->whereDate('flights.flight_date', '<=', $filters['date_to']);
        }

        if (!empty($filters['direction'])) {
            $query->where('flights.direction', $filters['direction']);
        }

        return $query
            ->selectRaw('YEAR(flights.flight_date) as year_value')
            ->selectRaw('MONTH(flights.flight_date) as month_value')
            ->selectRaw('flights.direction')
            ->selectRaw('COUNT(*) as flights_count')
            ->selectRaw('SUM(estimates.base_pax) as base_pax')
            ->selectRaw('SUM(estimates.commercial_exposed_pax) as commercial_exposed_pax')
            ->selectRaw('SUM(estimates.colombian_pax) as colombian_pax')
            ->selectRaw('SUM(estimates.foreign_pax) as foreign_pax')
            ->selectRaw("SUM(CASE WHEN estimates.confidence_level = 'HIGH' THEN 1 ELSE 0 END) as high_confidence")
            ->selectRaw("SUM(CASE WHEN estimates.confidence_level = 'MEDIUM' THEN 1 ELSE 0 END) as medium_confidence")
            ->selectRaw("SUM(CASE WHEN estimates.confidence_level = 'LOW' THEN 1 ELSE 0 END) as low_confidence")
            ->groupByRaw('YEAR(flights.flight_date), MONTH(flights.flight_date), flights.direction')
            ->orderByRaw('YEAR(flights.flight_date), MONTH(flights.flight_date)')
            ->orderBy('flights.direction')
            ->get()
            ->map(function ($row) {
                $commercialPax = round((float) $row->commercial_exposed_pax, 2);
                $colombianPax = round((float) $row->colombian_pax, 2);
                $foreignPax = round((float) $row->foreign_pax, 2);

                return [
                    'year' => (int) $row->year_value,
                    'month' => (int) $row->month_value,
                    'period' => sprintf('%04d-%02d', (int) $row->year_value, (int) $row->month_value),
                    'direction' => $row->direction,
                    'flights' => (int) $row->flights_count,
                    'base_pax' => round((float) $row->base_pax, 2),
                    'commercial_exposed_pax' => $commercialPax,
                    'colombian_pax' => $colombianPax,
                    'foreign_pax' => $foreignPax,
                    'colombian_pct' => $commercialPax > 0 ? round(($colombianPax / $commercialPax) * 100, 3) : null,
                    'foreign_pct' => $commercialPax > 0 ? round(($foreignPax / $commercialPax) * 100, 3) : null,
                    'high_confidence' => (int) $row->high_confidence,
                    'medium_confidence' => (int) $row->medium_confidence,
                    'low_confidence' => (int) $row->low_confidence,
                ];
            })
            ->all();
    }

    private function flightQuery(array $filters)
    {
        $query = PassengerFlight::query();

        if (!empty($filters['date_from'])) {
            $query->whereDate('flight_date', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->whereDate('flight_date', '<=', $filters['date_to']);
        }

        if (!empty($filters['direction'])) {
            $query->where('direction', $filters['direction']);
        }

        if (!empty($filters['batch_id'])) {
            $query->where('batch_id', $filters['batch_id']);
        }

        return $query;
    }

    private function upsertEstimateRows(array $rows): void
    {
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::connection('budget')
                ->table('passenger_intelligence_flight_estimates')
                ->upsert(
                    $chunk,
                    ['flight_id', 'model_version'],
                    [
                        'composition_profile_id',
                        'exposure_rate_id',
                        'base_pax',
                        'commercial_exposed_pax',
                        'colombian_pct',
                        'foreign_pct',
                        'colombian_pax',
                        'foreign_pax',
                        'estimation_method',
                        'confidence_level',
                        'input_sources',
                        'explanation',
                        'calculated_at',
                        'updated_at',
                    ]
                );
        }
    }

    private function resolveComposition(PassengerFlight $flight): ?PassengerCompositionProfile
    {
        $date = $flight->flight_date ? Carbon::parse($flight->flight_date)->toDateString() : null;
        $period = $date ? substr($date, 0, 7) : 'undated';
        $cacheKey = ($flight->direction ?: 'total') . '|' . $period;

        if (array_key_exists($cacheKey, $this->compositionCache)) {
            return $this->compositionCache[$cacheKey];
        }

        $query = PassengerCompositionProfile::where('is_active', true)
            ->where(function ($q) use ($flight) {
                $q->whereNull('direction')->orWhere('direction', $flight->direction);
            });

        if ($date) {
            $datedProfile = (clone $query)
                ->where(function ($q) use ($date) {
                    $q->whereNull('valid_from')->orWhereDate('valid_from', '<=', $date);
                })
                ->where(function ($q) use ($date) {
                    $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', $date);
                })
                ->orderByRaw('CASE WHEN direction IS NULL THEN 1 ELSE 0 END')
                ->orderByDesc('valid_from')
                ->orderByDesc('created_at')
                ->first();

            if ($datedProfile) {
                return $this->compositionCache[$cacheKey] = $datedProfile;
            }
        }

        return $this->compositionCache[$cacheKey] = $query
            ->orderByRaw('CASE WHEN direction IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('valid_from')
            ->orderByDesc('created_at')
            ->first();
    }

    private function resolveExposureRate(PassengerFlight $flight): ?PassengerCommercialExposureRate
    {
        if (!$flight->flight_date) {
            return null;
        }

        $date = Carbon::parse($flight->flight_date);
        $cacheKey = ((int) $date->year) . '-' . ((int) $date->month) . '|' . ($flight->direction ?: 'total');

        if (array_key_exists($cacheKey, $this->exposureCache)) {
            return $this->exposureCache[$cacheKey];
        }

        return $this->exposureCache[$cacheKey] = PassengerCommercialExposureRate::where([
            'year' => (int) $date->year,
            'month' => (int) $date->month,
            'airport_iata' => 'MDE',
        ])
            ->where(function ($q) use ($flight) {
                $q->where('direction', $flight->direction)->orWhere('direction', 'total');
            })
            ->orderByRaw("CASE WHEN direction = ? THEN 0 ELSE 1 END", [$flight->direction])
            ->orderByDesc('calculated_at')
            ->first();
    }

    private function commercialExposedPax(PassengerFlight $flight, float $basePax, ?PassengerCommercialExposureRate $exposureRate): float
    {
        if ($this->isObservedCommercialFlow($flight)) {
            return $basePax;
        }

        if ($exposureRate && $exposureRate->exposure_pct !== null) {
            return round($basePax * ((float) $exposureRate->exposure_pct / 100), 2);
        }

        return $basePax;
    }

    private function splitComposition(float $commercialPax, ?PassengerCompositionProfile $profile): array
    {
        if (!$profile) {
            return [null, null];
        }

        $colombianPax = round($commercialPax * ((float) $profile->colombian_pct / 100), 2);
        $foreignPax = round($commercialPax - $colombianPax, 2);

        return [$colombianPax, $foreignPax];
    }

    private function method(PassengerFlight $flight, ?PassengerCompositionProfile $profile, ?PassengerCommercialExposureRate $exposureRate): string
    {
        if (!$profile) {
            return 'MISSING_COMPOSITION_PROFILE';
        }

        if ($this->isObservedCommercialFlow($flight)) {
            return 'OBSERVED_COMMERCIAL_PAX_WITH_MONTHLY_COMPOSITION';
        }

        if ($exposureRate) {
            return 'ESTIMATED_FLOW_WITH_EXPOSURE_AND_COMPOSITION';
        }

        return 'BASE_PAX_WITH_MONTHLY_COMPOSITION';
    }

    private function confidence(PassengerFlight $flight, ?PassengerCompositionProfile $profile, ?PassengerCommercialExposureRate $exposureRate): string
    {
        if (!$profile) {
            return 'LOW';
        }

        if ($this->isObservedCommercialFlow($flight) && $profile->confidence_level === 'HIGH') {
            return 'HIGH';
        }

        if ($profile->confidence_level === 'LOW') {
            return 'LOW';
        }

        if (!$exposureRate && !$this->isObservedCommercialFlow($flight)) {
            return 'MEDIUM';
        }

        return $profile->confidence_level ?: 'MEDIUM';
    }

    private function inputSources(PassengerFlight $flight, ?PassengerCompositionProfile $profile, ?PassengerCommercialExposureRate $exposureRate): array
    {
        return [
            'flight_source' => [
                'name' => $flight->source_name,
                'data_type' => $flight->data_type,
                'observed_scope' => $flight->observed_scope,
                'batch_id' => $flight->batch_id,
                'source_file_id' => $flight->source_file_id,
            ],
            'composition_profile' => $profile ? [
                'id' => $profile->id,
                'name' => $profile->name,
                'source_name' => $profile->source_name,
                'method' => $profile->method,
                'confidence_level' => $profile->confidence_level,
            ] : null,
            'commercial_exposure' => $exposureRate ? [
                'id' => $exposureRate->id,
                'method' => $exposureRate->method,
                'exposure_pct' => (float) $exposureRate->exposure_pct,
                'confidence_level' => $exposureRate->confidence_level,
            ] : null,
        ];
    }

    private function explanation(PassengerFlight $flight, ?PassengerCompositionProfile $profile, ?PassengerCommercialExposureRate $exposureRate): array
    {
        return [
            'summary' => $profile
                ? 'El vuelo se estima aplicando el perfil colombiano/extranjero vigente sobre el PAX comercial calculado.'
                : 'No se pudo estimar nacionalidad porque no existe perfil colombiano/extranjero activo.',
            'assumptions' => [
                'No existe nacionalidad publica por vuelo.',
                $this->isObservedCommercialFlow($flight)
                    ? 'El PAX base ya representa flujo comercial observado.'
                    : 'Si no existe tasa de exposicion, el PAX base se usa sin descuento comercial.',
            ],
            'composition_profile_used' => $profile?->name,
            'exposure_rate_used' => $exposureRate?->id,
        ];
    }

    private function isObservedCommercialFlow(PassengerFlight $flight): bool
    {
        return $flight->data_type === 'observed' && $flight->observed_scope === 'commercial_flow';
    }

    private function payload(PassengerFlightEstimate $estimate): array
    {
        $flight = $estimate->flight;

        return [
            'id' => $estimate->id,
            'flight_id' => $estimate->flight_id,
            'date' => $flight?->flight_date?->toDateString(),
            'time' => $flight?->scheduled_time ? substr((string) $flight->scheduled_time, 0, 5) : null,
            'direction' => $flight?->direction,
            'airline' => $flight?->airline,
            'flight_code' => $flight?->flight_code,
            'origin' => $flight?->origin,
            'destination' => $flight?->destination,
            'base_pax' => round((float) $estimate->base_pax, 2),
            'commercial_exposed_pax' => $estimate->commercial_exposed_pax === null ? null : round((float) $estimate->commercial_exposed_pax, 2),
            'colombian_pct' => $estimate->colombian_pct === null ? null : round((float) $estimate->colombian_pct, 3),
            'foreign_pct' => $estimate->foreign_pct === null ? null : round((float) $estimate->foreign_pct, 3),
            'colombian_pax' => $estimate->colombian_pax === null ? null : round((float) $estimate->colombian_pax, 2),
            'foreign_pax' => $estimate->foreign_pax === null ? null : round((float) $estimate->foreign_pax, 2),
            'estimation_method' => $estimate->estimation_method,
            'confidence_level' => $estimate->confidence_level,
            'model_version' => $estimate->model_version,
            'input_sources' => $estimate->input_sources,
            'explanation' => $estimate->explanation,
            'calculated_at' => $estimate->calculated_at?->toDateTimeString(),
        ];
    }
}
