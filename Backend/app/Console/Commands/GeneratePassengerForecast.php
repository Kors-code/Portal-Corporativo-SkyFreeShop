<?php

namespace App\Console\Commands;

use App\Services\PassengerIntelligence\PassengerForecastService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class GeneratePassengerForecast extends Command
{
    protected $signature = 'passenger-intelligence:forecast
        {--target_year=}
        {--target_month=}
        {--run_date=}
        {--email=sebastian.cruz@dutyfreepartners.com}
        {--send-email}
        {--force}';

    protected $description = 'Generate AI-assisted Passenger Intelligence monthly forecast';

    public function handle(PassengerForecastService $forecastService): int
    {
        $runDate = Carbon::parse($this->option('run_date') ?: now('America/Bogota'), 'America/Bogota');

        if (!$this->option('force') && !$forecastService->shouldRunAutomatically($runDate)) {
            $this->info('Forecast skipped. It only runs automatically on day 15 or 10 days before month end.');
            return self::SUCCESS;
        }

        $result = $forecastService->generate([
            'target_year' => $this->option('target_year') ?: null,
            'target_month' => $this->option('target_month') ?: null,
            'run_date' => $runDate->toDateString(),
            'send_email' => (bool) $this->option('send-email'),
            'email' => $this->option('email'),
        ]);

        $this->info('Forecast generated: ' . $result['target_period']);
        $this->line('Predicted PAX: ' . ($result['predicted_total_pax'] ?? 'n/a'));
        $this->line('Colombian %: ' . ($result['predicted_colombian_pct'] ?? 'n/a'));
        $this->line('Foreign %: ' . ($result['predicted_foreign_pct'] ?? 'n/a'));

        if (isset($result['email'])) {
            $this->line('Email: ' . json_encode($result['email'], JSON_UNESCAPED_UNICODE));
        }

        return self::SUCCESS;
    }
}
