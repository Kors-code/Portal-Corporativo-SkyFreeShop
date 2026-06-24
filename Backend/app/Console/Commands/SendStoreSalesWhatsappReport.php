<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\VisualizationController;
use App\Services\StoreSalesWhatsappImageService;
use App\Services\WhatsappNumberReportSender;
use Illuminate\Console\Command;
use Illuminate\Http\Request;

class SendStoreSalesWhatsappReport extends Command
{
    protected $signature = 'reports:send-store-sales-whatsapp {--date=}';

    protected $description = 'Genera y envia por WhatsApp el reporte de ventas por tiendas.';

    public function handle(
        VisualizationController $controller,
        StoreSalesWhatsappImageService $imageService,
        WhatsappNumberReportSender $sender
    ): int {
        $payload = [];
        if ($date = $this->option('date')) {
            $payload['date'] = $date;
        }

        $request = Request::create('/api/v1/visualizaciones/ventas-tiendas/whatsapp/send', 'POST', $payload);
        $response = $controller->sendStoreSalesWhatsappReport($request, $imageService, $sender);
        $data = $response->getData(true);

        $this->info($data['message'] ?? 'Reporte de ventas por tiendas enviado a WhatsApp.');

        return self::SUCCESS;
    }
}
