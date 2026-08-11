<?php

namespace App\Console\Commands;

use App\Services\WhatsappReportJobService;
use Illuminate\Console\Command;

class QueueEndOfDayWhatsappReports extends Command
{
    protected $signature = 'reports:queue-end-of-day-whatsapp {--date=} {--force : Encola y envia manualmente aunque ya no sea el flujo automatico}';

    protected $description = 'Encola y procesa manualmente los reportes de WhatsApp de cierre del dia desde Laravel.';

    public function handle(WhatsappReportJobService $jobs): int
    {
        if (!$this->option('force')) {
            $this->warn('Este comando ya no envia reportes automaticamente. El envio diario se dispara solo al finalizar una importacion.');
            $this->warn('Para una prueba manual consciente usa: php artisan reports:queue-end-of-day-whatsapp --force');

            return self::SUCCESS;
        }

        $date = $this->option('date') ?: now('America/Bogota')->subDay()->toDateString();

        $daily = $jobs->enqueueUniqueDaily('daily', $date, [
            'pdvs' => ['COLS1', 'COLS2'],
            'first_import_full_package' => true,
            'closed_day_report' => true,
            'executive_only' => true,
        ]);
        $advisors = $jobs->enqueueUniqueDaily('advisor_sales', $date, [
            'first_import_full_package' => true,
            'closed_day_report' => true,
        ]);
        $stores = $jobs->enqueueUniqueDaily('store_sales', $date, [
            'first_import_full_package' => true,
            'closed_day_report' => true,
            'ignore_import_batch_id' => true,
        ]);

        $this->info("Reportes WhatsApp encolados para {$date}: daily ejecutivo #{$daily->id}, advisor_sales #{$advisors->id}, store_sales #{$stores->id}");

        $this->call('reports:process-whatsapp-jobs', ['--limit' => 10]);

        return self::SUCCESS;
    }
}
