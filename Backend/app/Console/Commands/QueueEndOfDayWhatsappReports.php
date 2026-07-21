<?php

namespace App\Console\Commands;

use App\Services\WhatsappReportJobService;
use Illuminate\Console\Command;

class QueueEndOfDayWhatsappReports extends Command
{
    protected $signature = 'reports:queue-end-of-day-whatsapp {--date=}';

    protected $description = 'Encola y procesa los reportes de WhatsApp de cierre del dia desde Laravel.';

    public function handle(WhatsappReportJobService $jobs): int
    {
        $date = $this->option('date') ?: now('America/Bogota')->subDay()->toDateString();

        $daily = $jobs->enqueueUniqueDaily('daily', $date, [
            'pdvs' => ['COLS1', 'COLS2'],
        ]);
        $advisors = $jobs->enqueueUniqueDaily('advisor_sales', $date);

        $this->info("Reportes WhatsApp encolados para {$date}: daily #{$daily->id}, advisor_sales #{$advisors->id}");

        $this->call('reports:process-whatsapp-jobs', ['--limit' => 10]);

        return self::SUCCESS;
    }
}
