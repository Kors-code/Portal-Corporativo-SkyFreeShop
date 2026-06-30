<?php

namespace App\Console\Commands;

use App\Services\WhatsappReportJobService;
use Illuminate\Console\Command;

class QueueEndOfDayWhatsappReports extends Command
{
    protected $signature = 'reports:queue-end-of-day-whatsapp {--date=}';

    protected $description = 'Encola los reportes de WhatsApp de cierre del dia para el worker.';

    public function handle(WhatsappReportJobService $jobs): int
    {
        $date = $this->option('date') ?: now('America/Bogota')->subDay()->toDateString();

        $daily = $jobs->enqueueUniqueDaily('daily', $date);
        $advisors = $jobs->enqueueUniqueDaily('advisor_sales', $date);

        $this->info("Reportes WhatsApp encolados para {$date}: daily #{$daily->id}, advisor_sales #{$advisors->id}");

        return self::SUCCESS;
    }
}
