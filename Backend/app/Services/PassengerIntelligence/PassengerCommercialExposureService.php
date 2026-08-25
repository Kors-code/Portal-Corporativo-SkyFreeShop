<?php

namespace App\Services\PassengerIntelligence;

use App\Models\PassengerIntelligence\PassengerCommercialExposureRate;
use App\Models\PassengerIntelligence\PassengerFlight;
use App\Models\PassengerIntelligence\PassengerMonthlyFact;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class PassengerCommercialExposureService
{
    private const AEROCIVIL_TRAFFIC_DATASET = 'gb6w-ynu4';
    private const AIRPORT = 'MDE';

    public function refreshObservedFacts(?int $year = null, ?int $month = null): array
    {
        $query = PassengerFlight::query()
            ->where('data_type', 'observed')
            ->where('observed_scope', 'commercial_flow');

        if ($year) {
            $query->whereYear('flight_date', $year);
        }

        if ($month) {
            $query->whereMonth('flight_date', $month);
        }

        $directionRows = (clone $query)
            ->select(
                DB::raw('YEAR(flight_date) as year_value'),
                DB::raw('MONTH(flight_date) as month_value'),
                'direction',
                DB::raw('SUM(pax) as pax_value'),
                DB::raw('COUNT(*) as records_count')
            )
            ->groupBy(DB::raw('YEAR(flight_date)'), DB::raw('MONTH(flight_date)'), 'direction')
            ->get();

        $totalRows = (clone $query)
            ->select(
                DB::raw('YEAR(flight_date) as year_value'),
                DB::raw('MONTH(flight_date) as month_value'),
                DB::raw("'total' as direction"),
                DB::raw('SUM(pax) as pax_value'),
                DB::raw('COUNT(*) as records_count')
            )
            ->groupBy(DB::raw('YEAR(flight_date)'), DB::raw('MONTH(flight_date)'))
            ->get();

        $facts = [];

        foreach ($directionRows->merge($totalRows) as $row) {
            $facts[] = $this->upsertObservedFact(
                (int) $row->year_value,
                (int) $row->month_value,
                (string) $row->direction,
                round((float) $row->pax_value, 2),
                (int) $row->records_count
            );
        }

        return $facts;
    }

    public function calculateForPeriod(int $year, int $month): array
    {
        $this->refreshObservedFacts($year, $month);

        $results = [];
        foreach (['arrival', 'departure', 'total'] as $direction) {
            $commercialFact = $this->commercialFact($year, $month, $direction);
            if (!$commercialFact) {
                continue;
            }

            $officialFact = $this->syncAerocivilFact($year, $month, $direction);
            $officialPax = $officialFact ? (float) $officialFact->value : null;
            $commercialPax = (float) $commercialFact->value;
            $exposurePct = $officialPax && $officialPax > 0 ? round(($commercialPax / $officialPax) * 100, 3) : null;

            $results[] = PassengerCommercialExposureRate::updateOrCreate(
                [
                    'year' => $year,
                    'month' => $month,
                    'airport_iata' => self::AIRPORT,
                    'direction' => $direction,
                    'method' => 'SKYFREE_OBSERVED_VS_AEROCIVIL',
                ],
                [
                    'commercial_pax' => $commercialPax,
                    'official_airport_pax' => $officialPax,
                    'exposure_pct' => $exposurePct,
                    'commercial_fact_id' => $commercialFact->id,
                    'official_fact_id' => $officialFact?->id,
                    'confidence_level' => $this->confidenceForExposure($commercialPax, $officialPax, $exposurePct),
                    'notes' => [
                        'formula' => 'commercial_pax / official_airport_pax * 100',
                        'commercial_source' => 'OneDrive Sky Free PAX observado',
                        'official_source' => 'Aerocivil Trafico Origen-Destino gb6w-ynu4',
                        'limitation' => 'Aerocivil mide pasajeros transportados; OneDrive Sky Free debe representar pasajeros expuestos o que pasan por el flujo comercial.',
                    ],
                    'calculated_at' => now(),
                ]
            );
        }

        return array_map(fn (PassengerCommercialExposureRate $rate) => $this->ratePayload($rate), $results);
    }

    public function calculateAvailablePeriods(?int $year = null): array
    {
        $periods = $this->observedPeriods($year);
        $results = [];
        $errors = [];

        foreach ($periods as $period) {
            try {
                $results[] = [
                    'period' => $period,
                    'rates' => $this->calculateForPeriod((int) $period['year'], (int) $period['month']),
                ];
            } catch (Throwable $e) {
                $errors[] = [
                    'period' => $period,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return [
            'periods_found' => count($periods),
            'periods_calculated' => count($results),
            'periods_failed' => count($errors),
            'results' => $results,
            'errors' => $errors,
        ];
    }

    public function observedPeriods(?int $year = null): array
    {
        $query = PassengerFlight::query()
            ->where('data_type', 'observed')
            ->where('observed_scope', 'commercial_flow')
            ->whereNotNull('flight_date');

        if ($year) {
            $query->whereYear('flight_date', $year);
        }

        return $query
            ->select(
                DB::raw('YEAR(flight_date) as year_value'),
                DB::raw('MONTH(flight_date) as month_value'),
                DB::raw('COUNT(*) as records_count'),
                DB::raw('SUM(pax) as pax_value')
            )
            ->groupBy(DB::raw('YEAR(flight_date)'), DB::raw('MONTH(flight_date)'))
            ->orderBy(DB::raw('YEAR(flight_date)'))
            ->orderBy(DB::raw('MONTH(flight_date)'))
            ->get()
            ->map(fn ($row) => [
                'year' => (int) $row->year_value,
                'month' => (int) $row->month_value,
                'records_count' => (int) $row->records_count,
                'pax' => round((float) $row->pax_value, 2),
            ])
            ->all();
    }

    public function latestRates(?int $year = null, ?int $month = null): array
    {
        $query = PassengerCommercialExposureRate::query();

        if ($year) {
            $query->where('year', $year);
        }

        if ($month) {
            $query->where('month', $month);
        }

        return $query
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->orderByRaw("FIELD(direction, 'total', 'departure', 'arrival')")
            ->limit(12)
            ->get()
            ->map(fn (PassengerCommercialExposureRate $rate) => $this->ratePayload($rate))
            ->all();
    }

    public function monthlyFacts(?int $year = null, ?int $month = null): array
    {
        $query = PassengerMonthlyFact::query();

        if ($year) {
            $query->where('year', $year);
        }

        if ($month) {
            $query->where('month', $month);
        }

        return $query
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->orderBy('fact_type')
            ->orderBy('direction')
            ->limit(500)
            ->get()
            ->map(fn (PassengerMonthlyFact $fact) => [
                'id' => $fact->id,
                'year' => $fact->year,
                'month' => $fact->month,
                'airport_iata' => $fact->airport_iata,
                'direction' => $fact->direction,
                'fact_type' => $fact->fact_type,
                'source_type' => $fact->source_type,
                'value' => round((float) $fact->value, 2),
                'records_count' => $fact->records_count,
                'source_name' => $fact->source_name,
                'source_period' => $fact->source_period,
                'confidence_level' => $fact->confidence_level,
                'metadata' => $fact->metadata,
            ])
            ->all();
    }

    private function upsertObservedFact(int $year, int $month, string $direction, float $pax, int $recordsCount): PassengerMonthlyFact
    {
        return PassengerMonthlyFact::updateOrCreate(
            [
                'year' => $year,
                'month' => $month,
                'airport_iata' => self::AIRPORT,
                'direction' => $direction,
                'fact_type' => 'skyfree_commercial_observed_pax',
                'source_type' => 'skyfree_onedrive_pax',
            ],
            [
                'value' => $pax,
                'records_count' => $recordsCount,
                'source_name' => 'OneDrive Sky Free PAX Col',
                'source_period' => sprintf('%04d-%02d', $year, $month),
                'confidence_level' => 'HIGH',
                'metadata' => [
                    'unit' => 'passengers',
                    'scope' => 'commercial_flow',
                    'generated_from' => 'passenger_intelligence_flights',
                ],
            ]
        );
    }

    private function syncAerocivilFact(int $year, int $month, string $direction): ?PassengerMonthlyFact
    {
        $pax = match ($direction) {
            'arrival' => $this->aerocivilPax($year, $month, "destino='MDE'"),
            'departure' => $this->aerocivilPax($year, $month, "origen='MDE'"),
            default => $this->aerocivilPax($year, $month, "destino='MDE'") + $this->aerocivilPax($year, $month, "origen='MDE'"),
        };

        if ($pax <= 0) {
            return null;
        }

        return PassengerMonthlyFact::updateOrCreate(
            [
                'year' => $year,
                'month' => $month,
                'airport_iata' => self::AIRPORT,
                'direction' => $direction,
                'fact_type' => 'airport_official_international_pax',
                'source_type' => 'aerocivil_gb6w_ynu4',
            ],
            [
                'value' => round($pax, 2),
                'records_count' => 1,
                'source_name' => 'Aerocivil Transporte Aereo Comercial - Trafico Origen-Destino',
                'source_url' => 'https://www.datos.gov.co/resource/gb6w-ynu4.json',
                'source_period' => sprintf('%04d-%02d', $year, $month),
                'confidence_level' => 'HIGH',
                'metadata' => [
                    'unit' => 'passengers',
                    'traffic_type' => 'international',
                    'dataset' => self::AEROCIVIL_TRAFFIC_DATASET,
                ],
            ]
        );
    }

    private function commercialFact(int $year, int $month, string $direction): ?PassengerMonthlyFact
    {
        return PassengerMonthlyFact::where([
            'year' => $year,
            'month' => $month,
            'airport_iata' => self::AIRPORT,
            'direction' => $direction,
            'fact_type' => 'skyfree_commercial_observed_pax',
            'source_type' => 'skyfree_onedrive_pax',
        ])->first();
    }

    private function aerocivilPax(int $year, int $month, string $directionWhere): float
    {
        $where = sprintf(
            "a_o=%d AND n_mero_de_mes=%d AND %s AND tr_fico_n_i='I'",
            $year,
            $month,
            $directionWhere
        );

        $rows = $this->socrataGet(self::AEROCIVIL_TRAFFIC_DATASET, [
            '$select' => 'sum(pasajeros)',
            '$where' => $where,
        ]);

        return round((float) ($rows[0]['sum_pasajeros'] ?? 0), 2);
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
            throw new RuntimeException("Datos Abiertos API error {$response->status()} for {$dataset}");
        }

        return $response->json() ?: [];
    }

    private function confidenceForExposure(float $commercialPax, ?float $officialPax, ?float $exposurePct): string
    {
        if (!$officialPax || $officialPax <= 0 || $commercialPax <= 0) {
            return 'LOW';
        }

        if ($exposurePct !== null && ($exposurePct <= 0 || $exposurePct > 100)) {
            return 'LOW';
        }

        return 'HIGH';
    }

    private function ratePayload(PassengerCommercialExposureRate $rate): array
    {
        return [
            'id' => $rate->id,
            'year' => $rate->year,
            'month' => $rate->month,
            'airport_iata' => $rate->airport_iata,
            'direction' => $rate->direction,
            'commercial_pax' => round((float) $rate->commercial_pax, 2),
            'official_airport_pax' => $rate->official_airport_pax === null ? null : round((float) $rate->official_airport_pax, 2),
            'exposure_pct' => $rate->exposure_pct === null ? null : round((float) $rate->exposure_pct, 3),
            'method' => $rate->method,
            'confidence_level' => $rate->confidence_level,
            'notes' => $rate->notes,
            'calculated_at' => $rate->calculated_at?->toDateTimeString(),
        ];
    }
}
