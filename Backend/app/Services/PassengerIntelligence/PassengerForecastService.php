<?php

namespace App\Services\PassengerIntelligence;

use App\Models\PassengerIntelligence\PassengerForecastRun;
use Carbon\Carbon;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;

class PassengerForecastService
{
    private const MODEL_VERSION = 'ai_assisted_baseline_v1';
    private const DEFAULT_EMAIL = 'sebastian.cruz@dutyfreepartners.com';

    public function __construct(
        private PassengerFlightEstimationService $estimates,
        private PassengerIntelligenceOpenAiService $openAi,
        private PassengerExternalSignalService $externalSignals
    ) {
    }

    public function generate(array $options = []): array
    {
        $runDate = Carbon::parse($options['run_date'] ?? now('America/Bogota'), 'America/Bogota');
        [$targetYear, $targetMonth] = $this->targetPeriod($options, $runDate);
        $history = $this->monthlyTotalsUntil($targetYear, $targetMonth);
        $baseline = $this->baselineForecast($history, $targetYear, $targetMonth);
        $signals = $this->externalSignals->signalsForTargetMonth($targetYear, $targetMonth);
        $analysis = $this->aiAnalysis($history, $baseline, $signals, $targetYear, $targetMonth, $runDate);

        $forecast = PassengerForecastRun::create([
            'target_year' => $targetYear,
            'target_month' => $targetMonth,
            'airport_iata' => 'MDE',
            'run_date' => $runDate->toDateString(),
            'cutoff_date' => $options['cutoff_date'] ?? $runDate->toDateString(),
            'status' => 'generated',
            'method' => 'AI_ASSISTED_WEIGHTED_HISTORY',
            'model_version' => self::MODEL_VERSION,
            'actual_pax_to_date' => $baseline['actual_pax_to_date'],
            'predicted_remaining_pax' => $baseline['predicted_remaining_pax'],
            'predicted_total_pax' => $baseline['predicted_total_pax'],
            'predicted_colombian_pct' => $baseline['predicted_colombian_pct'],
            'predicted_foreign_pct' => $baseline['predicted_foreign_pct'],
            'confidence_level' => $baseline['confidence_level'],
            'input_sources' => [
                'monthly_estimates' => $history,
                'external_signals' => $signals,
                'calculation' => $baseline['calculation'],
                'openai_used' => $analysis['openai_used'],
            ],
            'explanation' => $analysis,
            'created_by' => $options['created_by'] ?? null,
        ]);

        $payload = $this->payload($forecast);

        if ($options['send_email'] ?? false) {
            $payload['email'] = $this->sendEmail($forecast, $options['email'] ?? self::DEFAULT_EMAIL);
        }

        return $payload;
    }

    public function latest(int $limit = 12): array
    {
        return PassengerForecastRun::query()
            ->orderByDesc('run_date')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (PassengerForecastRun $run) => $this->payload($run))
            ->all();
    }

    public function payload(PassengerForecastRun $run): array
    {
        return [
            'id' => $run->id,
            'target_year' => $run->target_year,
            'target_month' => $run->target_month,
            'target_period' => sprintf('%04d-%02d', $run->target_year, $run->target_month),
            'airport_iata' => $run->airport_iata,
            'run_date' => $run->run_date?->toDateString(),
            'cutoff_date' => $run->cutoff_date?->toDateString(),
            'status' => $run->status,
            'method' => $run->method,
            'model_version' => $run->model_version,
            'actual_pax_to_date' => $run->actual_pax_to_date === null ? null : round((float) $run->actual_pax_to_date, 2),
            'predicted_remaining_pax' => $run->predicted_remaining_pax === null ? null : round((float) $run->predicted_remaining_pax, 2),
            'predicted_total_pax' => $run->predicted_total_pax === null ? null : round((float) $run->predicted_total_pax, 2),
            'predicted_colombian_pct' => $run->predicted_colombian_pct === null ? null : round((float) $run->predicted_colombian_pct, 3),
            'predicted_foreign_pct' => $run->predicted_foreign_pct === null ? null : round((float) $run->predicted_foreign_pct, 3),
            'confidence_level' => $run->confidence_level,
            'input_sources' => $run->input_sources,
            'explanation' => $run->explanation,
            'created_at' => $run->created_at?->toDateTimeString(),
        ];
    }

    public function shouldRunAutomatically(Carbon $date): bool
    {
        return $date->day === $date->copy()->endOfMonth()->subDays(10)->day
            || $date->day === 15;
    }

    private function targetPeriod(array $options, Carbon $runDate): array
    {
        if (!empty($options['target_year']) && !empty($options['target_month'])) {
            return [(int) $options['target_year'], (int) $options['target_month']];
        }

        $target = $runDate->day === 15
            ? $runDate->copy()
            : $runDate->copy()->addMonthNoOverflow();

        return [(int) $target->year, (int) $target->month];
    }

    private function monthlyTotalsUntil(int $targetYear, int $targetMonth): array
    {
        $targetPeriod = sprintf('%04d-%02d', $targetYear, $targetMonth);
        $rows = $this->estimates->monthlyAnalytics([]);
        $totals = [];

        foreach ($rows as $row) {
            if ($row['period'] >= $targetPeriod) {
                continue;
            }

            $current = $totals[$row['period']] ?? [
                'period' => $row['period'],
                'year' => $row['year'],
                'month' => $row['month'],
                'pax' => 0.0,
                'colombian_pax' => 0.0,
                'foreign_pax' => 0.0,
                'flights' => 0,
            ];

            $current['pax'] += (float) $row['commercial_exposed_pax'];
            $current['colombian_pax'] += (float) $row['colombian_pax'];
            $current['foreign_pax'] += (float) $row['foreign_pax'];
            $current['flights'] += (int) $row['flights'];
            $current['colombian_pct'] = $current['pax'] > 0 ? round(($current['colombian_pax'] / $current['pax']) * 100, 3) : null;
            $current['foreign_pct'] = $current['pax'] > 0 ? round(($current['foreign_pax'] / $current['pax']) * 100, 3) : null;
            $totals[$row['period']] = $current;
        }

        return array_values($totals);
    }

    private function baselineForecast(array $history, int $targetYear, int $targetMonth): array
    {
        $lastThree = $this->recentWindow($history, $targetYear, $targetMonth, 3);
        if (count($lastThree) < 3) {
            $lastThree = array_slice($history, -3);
        }

        $lastYearWindow = $this->sameWindowLastYear($history, $targetYear, $targetMonth, 3);
        $sameMonthLastYear = collect($history)->firstWhere('period', sprintf('%04d-%02d', $targetYear - 1, $targetMonth));
        $overall = $history;

        $lastThreeAvg = $this->average($lastThree, 'pax');
        $lastYearWindowAvg = $this->average($lastYearWindow, 'pax');
        $sameMonthPax = $sameMonthLastYear['pax'] ?? null;
        $overallAvg = $this->average($overall, 'pax');
        $growthFactor = ($lastThreeAvg && $lastYearWindowAvg && $lastYearWindowAvg > 0)
            ? round($lastThreeAvg / $lastYearWindowAvg, 6)
            : null;
        $seasonalAdjustedPax = ($sameMonthPax && $growthFactor)
            ? round($sameMonthPax * $growthFactor, 2)
            : null;

        $weights = [
            'same_month_last_year_adjusted_by_recent_trend' => $seasonalAdjustedPax ? 0.60 : 0,
            'last_three_months' => $lastThreeAvg ? 0.30 : 0,
            'overall_average' => $overallAvg ? 0.10 : 0,
        ];
        $weightTotal = array_sum($weights) ?: 1;
        $predictedTotal = (
            (($seasonalAdjustedPax ?? 0) * $weights['same_month_last_year_adjusted_by_recent_trend']) +
            (($lastThreeAvg ?? 0) * $weights['last_three_months']) +
            (($overallAvg ?? 0) * $weights['overall_average'])
        ) / $weightTotal;

        $colombianPct = $this->weightedPct($lastThree, $overall);
        $hasSeasonalTrend = $seasonalAdjustedPax !== null && count($lastThree) >= 3 && count($lastYearWindow) >= 3;

        return [
            'actual_pax_to_date' => null,
            'predicted_remaining_pax' => round($predictedTotal, 2),
            'predicted_total_pax' => round($predictedTotal, 2),
            'predicted_colombian_pct' => $colombianPct,
            'predicted_foreign_pct' => round(100 - $colombianPct, 3),
            'confidence_level' => $hasSeasonalTrend && count($history) >= 12 ? 'MEDIUM' : 'LOW',
            'calculation' => [
                'formula' => 'forecast = 60% * (same_month_last_year * recent_3_month_avg / same_3_month_avg_last_year) + 30% * recent_3_month_avg + 10% * overall_avg',
                'weights' => $weights,
                'recent_window_periods' => array_column($lastThree, 'period'),
                'last_year_window_periods' => array_column($lastYearWindow, 'period'),
                'last_three_months_avg' => $lastThreeAvg,
                'last_year_same_three_months_avg' => $lastYearWindowAvg,
                'year_over_year_recent_growth_factor' => $growthFactor,
                'same_month_last_year_pax' => $sameMonthPax,
                'same_month_last_year_adjusted_by_recent_trend' => $seasonalAdjustedPax,
                'overall_avg' => $overallAvg,
                'history_months' => count($history),
                'fallback_note' => $hasSeasonalTrend
                    ? null
                    : 'No habia ventana completa de 3 meses actuales y 3 meses del año pasado; se recalibraron pesos con los datos disponibles.',
            ],
        ];
    }

    private function aiAnalysis(array $history, array $baseline, array $signals, int $targetYear, int $targetMonth, Carbon $runDate): array
    {
        $response = $this->openAi->forecastAnalysis([
            'target_period' => sprintf('%04d-%02d', $targetYear, $targetMonth),
            'run_date' => $runDate->toDateString(),
            'baseline_forecast' => $baseline,
            'external_signals' => $signals,
            'history' => array_slice($history, -16),
        ]);

        if ($response['ok'] ?? false) {
            return [
                'openai_used' => true,
                ...$response['data'],
            ];
        }

        return [
            'openai_used' => false,
            'openai_error' => $response['error'] ?? 'No disponible',
            'executive_summary' => 'Forecast generado con baseline estadistico. La IA no estuvo disponible para analisis narrativo.',
            'forecast_drivers' => array_filter([
                'Mismo mes del año anterior ajustado por tendencia: ultimos 3 meses vs los mismos 3 meses del año pasado.',
                !empty($signals) ? 'Festivos/eventos del mes objetivo: ' . implode(', ', array_column(array_slice($signals, 0, 4), 'name')) : null,
            ]),
            'risks' => ['Historico incompleto antes de mayo 2025 hasta importar el archivo grande por chunks.', 'La composicion colombiano/extranjero sigue siendo estimada, no observada por vuelo.'],
            'accuracy_monitoring_plan' => ['Comparar contra PAX real importado al cierre del mes.', 'Recalcular el dia 15 cuando exista PAX parcial.'],
            'failure_modes' => ['Eventos no reflejados en historico.', 'Cambios de rutas/aerolineas.', 'Excel incompleto o duplicado.'],
            'recommendations' => ['Importar historico 2019-2025 con lector streaming.', 'Guardar error porcentual por cada forecast cerrado.'],
        ];
    }

    private function sendEmail(PassengerForecastRun $run, string $email): array
    {
        try {
            Mail::mailer('smtp')->send([], [], function (Message $message) use ($run, $email) {
                $message
                    ->from(config('mail.from.address'), 'Sky Free Passenger Intelligence')
                    ->to($email)
                    ->subject('Forecast Passenger Intelligence ' . sprintf('%04d-%02d', $run->target_year, $run->target_month))
                    ->html($this->emailHtml($run));
            });

            return ['sent' => true, 'to' => $email];
        } catch (\Throwable $e) {
            return ['sent' => false, 'to' => $email, 'error' => $e->getMessage()];
        }
    }

    private function emailHtml(PassengerForecastRun $run): string
    {
        $summary = e($run->explanation['executive_summary'] ?? 'Forecast generado.');

        return <<<HTML
<h2>Passenger Intelligence Forecast {$run->target_year}-{$run->target_month}</h2>
<p>{$summary}</p>
<ul>
  <li><strong>PAX predicho:</strong> {$run->predicted_total_pax}</li>
  <li><strong>% colombianos:</strong> {$run->predicted_colombian_pct}%</li>
  <li><strong>% extranjeros:</strong> {$run->predicted_foreign_pct}%</li>
  <li><strong>Confianza:</strong> {$run->confidence_level}</li>
</ul>
HTML;
    }

    private function average(array $rows, string $key): ?float
    {
        if (empty($rows)) {
            return null;
        }

        return round(array_sum(array_column($rows, $key)) / count($rows), 2);
    }

    private function recentWindow(array $history, int $targetYear, int $targetMonth, int $months): array
    {
        $target = Carbon::create($targetYear, $targetMonth, 1, 0, 0, 0, 'America/Bogota');
        $periods = [];

        for ($i = $months; $i >= 1; $i--) {
            $periods[] = $target->copy()->subMonthsNoOverflow($i)->format('Y-m');
        }

        return $this->historyRowsForPeriods($history, $periods);
    }

    private function sameWindowLastYear(array $history, int $targetYear, int $targetMonth, int $months): array
    {
        $target = Carbon::create($targetYear - 1, $targetMonth, 1, 0, 0, 0, 'America/Bogota');
        $periods = [];

        for ($i = $months; $i >= 1; $i--) {
            $periods[] = $target->copy()->subMonthsNoOverflow($i)->format('Y-m');
        }

        return $this->historyRowsForPeriods($history, $periods);
    }

    private function historyRowsForPeriods(array $history, array $periods): array
    {
        $indexed = [];
        foreach ($history as $row) {
            $indexed[$row['period']] = $row;
        }

        $rows = [];
        foreach ($periods as $period) {
            if (isset($indexed[$period])) {
                $rows[] = $indexed[$period];
            }
        }

        return $rows;
    }

    private function weightedPct(array $preferredRows, array $fallbackRows): float
    {
        $rows = !empty($preferredRows) ? $preferredRows : $fallbackRows;
        $pax = array_sum(array_column($rows, 'pax'));
        $colombian = array_sum(array_column($rows, 'colombian_pax'));

        return $pax > 0 ? round(($colombian / $pax) * 100, 3) : 50.0;
    }
}
