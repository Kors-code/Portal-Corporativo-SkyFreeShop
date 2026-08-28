<?php

namespace App\Services\PassengerIntelligence;

use App\Models\PassengerIntelligence\PassengerExternalSignal;
use Carbon\Carbon;

class PassengerExternalSignalService
{
    public function __construct(
        private PassengerFlightEstimationService $estimates
    ) {
    }

    public function syncVerifiableSignals(?int $year = null): array
    {
        $years = $year ? [$year] : $this->yearsFromHistory();
        $created = 0;
        $updated = 0;

        foreach ($this->curatedCityEvents($years) as $signal) {
            $result = $this->upsertSignal($signal);
            $created += $result['created'];
            $updated += $result['updated'];
        }

        foreach ($years as $signalYear) {
            foreach ($this->colombianHolidaySignals($signalYear) as $signal) {
                $result = $this->upsertSignal($signal);
                $created += $result['created'];
                $updated += $result['updated'];
            }
        }

        return [
            'years' => $years,
            'created' => $created,
            'updated' => $updated,
            'total' => PassengerExternalSignal::count(),
        ];
    }

    public function latest(array $filters = []): array
    {
        $query = PassengerExternalSignal::query();

        if (!empty($filters['year'])) {
            $query->where(function ($q) use ($filters) {
                $q->whereYear('date_from', (int) $filters['year'])
                    ->orWhereYear('date_to', (int) $filters['year']);
            });
        }

        if (!empty($filters['date_from'])) {
            $query->whereDate('date_to', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->whereDate('date_from', '<=', $filters['date_to']);
        }

        if (!empty($filters['signal_type'])) {
            $query->where('signal_type', $filters['signal_type']);
        }

        return $query
            ->orderBy('date_from')
            ->orderByDesc('impact_score')
            ->limit($filters['limit'] ?? 200)
            ->get()
            ->map(fn (PassengerExternalSignal $signal) => $this->payload($signal))
            ->all();
    }

    public function monthlyImpact(array $filters = []): array
    {
        $rows = $this->monthlyHistory($filters);
        $paxValues = array_column($rows, 'pax');
        $signalMonths = [];
        $quietMonths = [];

        foreach ($rows as $index => $row) {
            $monthStart = Carbon::create((int) $row['year'], (int) $row['month'], 1, 0, 0, 0, 'America/Bogota')->startOfMonth();
            $monthEnd = $monthStart->copy()->endOfMonth();
            $signals = $this->signalsForPeriod($monthStart, $monthEnd);
            $previousThree = array_slice($paxValues, max(0, $index - 3), min(3, $index));
            $previousThreeAvg = count($previousThree) > 0 ? round(array_sum($previousThree) / count($previousThree), 2) : null;
            $signalScore = array_sum(array_column($signals, 'impact_score'));

            $row['signals_count'] = count($signals);
            $row['signal_score'] = $signalScore;
            $row['top_signals'] = array_slice($signals, 0, 5);
            $row['previous_3_month_avg'] = $previousThreeAvg;
            $row['lift_vs_previous_3_pct'] = $previousThreeAvg && $previousThreeAvg > 0
                ? round((($row['pax'] - $previousThreeAvg) / $previousThreeAvg) * 100, 2)
                : null;
            $row['signal_intensity'] = $this->signalIntensity($signalScore);
            $row['analysis'] = $this->monthAnalysis($row);

            if ($row['signals_count'] > 0) {
                $signalMonths[] = $row['pax'];
            } else {
                $quietMonths[] = $row['pax'];
            }

            $rows[$index] = $row;
        }

        $signalAvg = count($signalMonths) > 0 ? round(array_sum($signalMonths) / count($signalMonths), 2) : null;
        $quietAvg = count($quietMonths) > 0 ? round(array_sum($quietMonths) / count($quietMonths), 2) : null;

        return [
            'summary' => [
                'months_analyzed' => count($rows),
                'months_with_signals' => count($signalMonths),
                'months_without_signals' => count($quietMonths),
                'avg_pax_with_signals' => $signalAvg,
                'avg_pax_without_signals' => $quietAvg,
                'difference_pct' => $signalAvg && $quietAvg && $quietAvg > 0
                    ? round((($signalAvg - $quietAvg) / $quietAvg) * 100, 2)
                    : null,
                'note' => 'Correlacion descriptiva: no prueba causalidad. Sirve para detectar meses con festivos/eventos y revisar si deben ajustar el forecast.',
            ],
            'months' => $rows,
        ];
    }

    public function signalsForTargetMonth(int $year, int $month): array
    {
        $start = Carbon::create($year, $month, 1, 0, 0, 0, 'America/Bogota')->startOfMonth();
        $end = $start->copy()->endOfMonth();

        return $this->signalsForPeriod($start, $end);
    }

    private function upsertSignal(array $signal): array
    {
        $model = PassengerExternalSignal::updateOrCreate(
            [
                'date_from' => $signal['date_from'],
                'date_to' => $signal['date_to'],
                'signal_type' => $signal['signal_type'],
                'name' => $signal['name'],
            ],
            $signal
        );

        return [
            'created' => $model->wasRecentlyCreated ? 1 : 0,
            'updated' => $model->wasRecentlyCreated ? 0 : 1,
        ];
    }

    private function yearsFromHistory(): array
    {
        $years = collect($this->estimates->monthlyAnalytics(['data_type' => 'observed']))
            ->pluck('year')
            ->unique()
            ->sort()
            ->values()
            ->all();

        return empty($years) ? [(int) now('America/Bogota')->year] : $years;
    }

    private function monthlyHistory(array $filters): array
    {
        $history = [];

        foreach ($this->estimates->monthlyAnalytics([
            'data_type' => 'observed',
            'year' => $filters['year'] ?? null,
        ]) as $row) {
            $current = $history[$row['period']] ?? [
                'period' => $row['period'],
                'year' => $row['year'],
                'month' => $row['month'],
                'pax' => 0.0,
                'flights' => 0,
                'colombian_pax' => 0.0,
                'foreign_pax' => 0.0,
            ];

            $current['pax'] += (float) $row['commercial_exposed_pax'];
            $current['flights'] += (int) $row['flights'];
            $current['colombian_pax'] += (float) $row['colombian_pax'];
            $current['foreign_pax'] += (float) $row['foreign_pax'];
            $current['colombian_pct'] = $current['pax'] > 0 ? round(($current['colombian_pax'] / $current['pax']) * 100, 3) : null;
            $current['foreign_pct'] = $current['pax'] > 0 ? round(($current['foreign_pax'] / $current['pax']) * 100, 3) : null;
            $history[$row['period']] = $current;
        }

        return array_values($history);
    }

    private function signalsForPeriod(Carbon $start, Carbon $end): array
    {
        return PassengerExternalSignal::query()
            ->whereDate('date_from', '<=', $end->toDateString())
            ->whereDate('date_to', '>=', $start->toDateString())
            ->orderByDesc('impact_score')
            ->orderBy('date_from')
            ->get()
            ->map(fn (PassengerExternalSignal $signal) => $this->payload($signal))
            ->all();
    }

    private function payload(PassengerExternalSignal $signal): array
    {
        return [
            'id' => $signal->id,
            'date_from' => $signal->date_from?->toDateString(),
            'date_to' => $signal->date_to?->toDateString(),
            'signal_type' => $signal->signal_type,
            'name' => $signal->name,
            'location' => $signal->location,
            'source_name' => $signal->source_name,
            'source_url' => $signal->source_url,
            'source_published_at' => $signal->source_published_at?->toDateString(),
            'expected_impact' => $signal->expected_impact,
            'impact_direction' => $signal->impact_direction,
            'impact_score' => $signal->impact_score,
            'verification_status' => $signal->verification_status,
            'notes' => $signal->notes,
            'metadata' => $signal->metadata,
        ];
    }

    private function curatedCityEvents(array $years): array
    {
        $signals = [
            [
                'date_from' => '2025-07-27',
                'date_to' => '2025-07-31',
                'signal_type' => 'business_fair',
                'name' => 'Colombiamoda 2025',
                'location' => 'Medellin',
                'source_name' => 'INEXMODA',
                'source_url' => 'https://inexmoda.org.co/colombiamoda-2025-un-circuito-creativo-que-conecta-al-pais-y-que-enaltece-la-propuesta-de-valor-del-sistema-moda-latinoamericano/',
                'source_published_at' => '2025-07-01',
                'expected_impact' => 'high',
                'impact_direction' => 'increase_business_and_foreign_flow',
                'impact_score' => 82,
                'verification_status' => 'verified',
                'notes' => 'Evento de moda/negocios con compradores nacionales e internacionales; se usa como señal de posible aumento de flujo internacional.',
            ],
            [
                'date_from' => '2025-08-01',
                'date_to' => '2025-08-10',
                'signal_type' => 'city_event',
                'name' => 'Feria de las Flores 2025',
                'location' => 'Medellin',
                'source_name' => 'Alcaldia de Medellin',
                'source_url' => 'https://www.medellin.gov.co/es/sala-de-prensa/noticias/la-feria-de-las-flores-de-medellin-enaltece-la-cultura-silletera-alcalde-federico-gutierrez-anuncio-mas-de-200-eventos-durante-10-dias/',
                'source_published_at' => '2025-07-03',
                'expected_impact' => 'high',
                'impact_direction' => 'increase_tourism_flow',
                'impact_score' => 92,
                'verification_status' => 'verified',
                'notes' => 'Evento insignia de ciudad durante 10 dias; probable punto caliente de visitantes nacionales e internacionales.',
            ],
            [
                'date_from' => '2026-07-10',
                'date_to' => '2026-07-12',
                'signal_type' => 'city_event',
                'name' => 'Conciertos Ciudad Altavoz 2026',
                'location' => 'Medellin',
                'source_name' => 'Alcaldia de Medellin',
                'source_url' => 'https://www.medellin.gov.co/es/sala-de-prensa/noticias/la-alcaldia-de-medellin-presenta-las-fechas-de-los-principales-eventos-de-ciudad-para-2026/',
                'source_published_at' => '2026-01-01',
                'expected_impact' => 'medium',
                'impact_direction' => 'increase_city_event_flow',
                'impact_score' => 52,
                'verification_status' => 'verified',
                'notes' => 'Evento cultural de ciudad; señal contextual de actividad turistica/cultural.',
            ],
            [
                'date_from' => '2026-07-31',
                'date_to' => '2026-08-09',
                'signal_type' => 'city_event',
                'name' => 'Feria de las Flores 2026',
                'location' => 'Medellin',
                'source_name' => 'Alcaldia de Medellin',
                'source_url' => 'https://www.medellin.gov.co/es/feria-de-flores/',
                'source_published_at' => '2026-07-01',
                'expected_impact' => 'high',
                'impact_direction' => 'increase_tourism_flow',
                'impact_score' => 94,
                'verification_status' => 'verified',
                'notes' => 'Evento oficial de ciudad del 31 de julio al 9 de agosto de 2026.',
            ],
            [
                'date_from' => '2026-09-11',
                'date_to' => '2026-09-20',
                'signal_type' => 'city_event',
                'name' => 'Fiesta del Libro y la Cultura 2026',
                'location' => 'Medellin',
                'source_name' => 'Eventos del Libro de Medellin',
                'source_url' => 'https://fiestadellibroylacultura.com/fiesta-del-libro-y-la-cultura/fiesta-2026/info-y-ubicacion/',
                'source_published_at' => '2026-08-20',
                'expected_impact' => 'medium',
                'impact_direction' => 'increase_cultural_tourism_flow',
                'impact_score' => 62,
                'verification_status' => 'verified',
                'notes' => 'Evento cultural con invitados nacionales e internacionales; impacto potencial moderado sobre visitantes.',
            ],
            [
                'date_from' => '2026-09-22',
                'date_to' => '2026-09-24',
                'signal_type' => 'business_fair',
                'name' => 'Colombiamoda 2026',
                'location' => 'Medellin',
                'source_name' => 'ProColombia - Colombia Travel',
                'source_url' => 'https://colombia.travel/es/ferias-y-fiestas/colombiamoda',
                'source_published_at' => null,
                'expected_impact' => 'medium',
                'impact_direction' => 'increase_business_and_foreign_flow',
                'impact_score' => 72,
                'verification_status' => 'verified',
                'notes' => 'Fuente turistica nacional lista Colombiamoda en Medellin para septiembre de 2026.',
            ],
            [
                'date_from' => '2026-11-27',
                'date_to' => '2026-12-13',
                'signal_type' => 'city_event',
                'name' => 'Festival de Navidad Medellin 2026',
                'location' => 'Medellin',
                'source_name' => 'Alcaldia de Medellin',
                'source_url' => 'https://www.medellin.gov.co/es/sala-de-prensa/noticias/la-alcaldia-de-medellin-presenta-las-fechas-de-los-principales-eventos-de-ciudad-para-2026/',
                'source_published_at' => '2026-01-01',
                'expected_impact' => 'high',
                'impact_direction' => 'increase_tourism_flow',
                'impact_score' => 76,
                'verification_status' => 'verified',
                'notes' => 'Temporada de Navidad con multiples actividades de ciudad.',
            ],
        ];

        return array_values(array_filter($signals, fn ($signal) => in_array((int) substr($signal['date_from'], 0, 4), $years, true)));
    }

    private function colombianHolidaySignals(int $year): array
    {
        $easter = Carbon::createFromTimestamp($this->easterTimestamp($year), 'America/Bogota')->startOfDay();
        $sourceUrl = 'https://sedeelectronica.minhacienda.gov.co/SedeElectronica/info/inicio.do?formAction=btDiasInhabiles&target=print';
        $holidays = [
            ['Año Nuevo', Carbon::create($year, 1, 1, 0, 0, 0, 'America/Bogota'), 35],
            ['Reyes Magos', $this->mondayAfter(Carbon::create($year, 1, 6, 0, 0, 0, 'America/Bogota')), 35],
            ['Dia de San Jose', $this->mondayAfter(Carbon::create($year, 3, 19, 0, 0, 0, 'America/Bogota')), 35],
            ['Semana Santa', $easter->copy()->subDays(3), 70, $easter->copy()],
            ['Dia del Trabajo', Carbon::create($year, 5, 1, 0, 0, 0, 'America/Bogota'), 35],
            ['Ascension', $this->mondayAfter($easter->copy()->addDays(39)), 35],
            ['Corpus Christi', $this->mondayAfter($easter->copy()->addDays(60)), 35],
            ['Sagrado Corazon', $this->mondayAfter($easter->copy()->addDays(68)), 35],
            ['San Pedro y San Pablo', $this->mondayAfter(Carbon::create($year, 6, 29, 0, 0, 0, 'America/Bogota')), 35],
            ['Independencia de Colombia', Carbon::create($year, 7, 20, 0, 0, 0, 'America/Bogota'), 35],
            ['Batalla de Boyaca', Carbon::create($year, 8, 7, 0, 0, 0, 'America/Bogota'), 35],
            ['Asuncion de la Virgen', $this->mondayAfter(Carbon::create($year, 8, 15, 0, 0, 0, 'America/Bogota')), 35],
            ['Dia de la Raza', $this->mondayAfter(Carbon::create($year, 10, 12, 0, 0, 0, 'America/Bogota')), 35],
            ['Todos los Santos', $this->mondayAfter(Carbon::create($year, 11, 1, 0, 0, 0, 'America/Bogota')), 35],
            ['Independencia de Cartagena', $this->mondayAfter(Carbon::create($year, 11, 11, 0, 0, 0, 'America/Bogota')), 35],
            ['Inmaculada Concepcion', Carbon::create($year, 12, 8, 0, 0, 0, 'America/Bogota'), 35],
            ['Navidad', Carbon::create($year, 12, 25, 0, 0, 0, 'America/Bogota'), 55],
        ];

        return array_map(function (array $holiday) use ($sourceUrl) {
            $date = $holiday[1];
            $end = $holiday[3] ?? $date->copy()->addDay();

            return [
                'date_from' => $date->copy()->subDay()->toDateString(),
                'date_to' => $end->copy()->addDay()->toDateString(),
                'signal_type' => 'holiday',
                'name' => $holiday[0],
                'location' => 'Colombia',
                'source_name' => 'Calendario oficial de dias festivos Colombia',
                'source_url' => $sourceUrl,
                'source_published_at' => null,
                'expected_impact' => $holiday[2] >= 55 ? 'high' : 'medium',
                'impact_direction' => 'increase_holiday_travel_flow',
                'impact_score' => $holiday[2],
                'verification_status' => 'calculated_from_official_calendar_rules',
                'notes' => 'Ventana ampliada alrededor del festivo para capturar posible movimiento de viaje.',
                'metadata' => [
                    'holiday_date' => $date->toDateString(),
                    'unit' => 'date_window',
                ],
            ];
        }, $holidays);
    }

    private function mondayAfter(Carbon $date): Carbon
    {
        return $date->isMonday() ? $date : $date->copy()->next(Carbon::MONDAY);
    }

    private function easterTimestamp(int $year): int
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return Carbon::create($year, $month, $day, 0, 0, 0, 'America/Bogota')->timestamp;
    }

    private function signalIntensity(int $score): string
    {
        return match (true) {
            $score >= 120 => 'high',
            $score >= 60 => 'medium',
            $score > 0 => 'low',
            default => 'none',
        };
    }

    private function monthAnalysis(array $row): string
    {
        if ($row['signals_count'] === 0) {
            return 'Mes sin festivos/eventos guardados; usar como posible comparación base.';
        }

        if ($row['lift_vs_previous_3_pct'] === null) {
            return 'Mes con festivos/eventos, pero no hay suficiente historial previo para medir variacion.';
        }

        if ($row['lift_vs_previous_3_pct'] > 8) {
            return 'Mes caliente: el PAX supera claramente el promedio de los 3 meses anteriores y coincide con festivos/eventos.';
        }

        if ($row['lift_vs_previous_3_pct'] < -8) {
            return 'Mes con festivos/eventos pero PAX por debajo del promedio reciente; revisar datos incompletos, mezcla de rutas o evento con bajo impacto real.';
        }

        return 'Mes con festivos/eventos y variacion moderada frente al promedio reciente.';
    }
}
