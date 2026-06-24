<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\VisualizationController;
use App\Services\DailyWhatsappReportImageService;
use App\Services\WhatsappNumberReportSender;
use Illuminate\Console\Command;
use Illuminate\Http\Request;

class SendDailyWhatsappNumberReport extends Command
{
    protected $signature = 'reports:send-daily-whatsapp-numbers {--date=}';

    protected $description = 'Genera y envia por WhatsApp a numeros el reporte diario original.';

    public function handle(
        VisualizationController $controller,
        DailyWhatsappReportImageService $imageService,
        WhatsappNumberReportSender $sender
    ): int {
        $payload = [];
        if ($date = $this->option('date')) {
            $payload['date'] = $date;
        }

        $request = Request::create('/api/v1/visualizaciones/daily-whatsapp/send-to-recipients', 'POST', $payload);
        $response = $controller->sendWhatsappDailyNumberReport($request, $imageService, $sender);
        $data = $response->getData(true);

        $this->info($data['message'] ?? 'Reporte diario enviado a numeros de WhatsApp.');

        return self::SUCCESS;
    }
}
