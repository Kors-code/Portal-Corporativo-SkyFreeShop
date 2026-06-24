<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\VisualizationController;
use App\Services\AdvisorSalesWhatsappImageService;
use App\Services\WhatsappNumberReportSender;
use Illuminate\Console\Command;
use Illuminate\Http\Request;

class SendAdvisorSalesWhatsappReport extends Command
{
    protected $signature = 'reports:send-advisor-sales-whatsapp {--date=}';

    protected $description = 'Genera y envia por WhatsApp el reporte diario de ventas por asesor.';

    public function handle(
        VisualizationController $controller,
        AdvisorSalesWhatsappImageService $imageService,
        WhatsappNumberReportSender $sender
    ): int {
        $payload = [];
        if ($date = $this->option('date')) {
            $payload['date'] = $date;
        }

        $request = Request::create('/api/v1/visualizaciones/ventas-asesores/whatsapp/send', 'POST', $payload);
        $response = $controller->sendAdvisorSalesWhatsappReport($request, $imageService, $sender);
        $data = $response->getData(true);

        $this->info($data['message'] ?? 'Reporte de ventas por asesor enviado a WhatsApp.');

        return self::SUCCESS;
    }
}
