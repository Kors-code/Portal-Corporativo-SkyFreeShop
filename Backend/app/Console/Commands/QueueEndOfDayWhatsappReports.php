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
            'first_import_full_package' => true,
            'closed_day_report' => true,
            'executive_only' => true,
        ]);
        $stores = $jobs->enqueueUniqueDaily('store_sales', $date, [
            'first_import_full_package' => true,
            'closed_day_report' => true,
            'ignore_import_batch_id' => true,
        ]);

        $this->info("Reportes WhatsApp encolados para {$date}: daily ejecutivo #{$daily->id}, store_sales #{$stores->id}");

        $this->call('reports:process-whatsapp-jobs', ['--limit' => 10]);

        return self::SUCCESS;
    }
}
