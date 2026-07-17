<?php

namespace App\Services;

use App\Http\Controllers\Api\VisualizationController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WhatsappReportConversationService
{
    public function __construct(
        private readonly WhatsappCloudApiClient $client,
        private readonly WhatsappNumberReportSender $sender,
        private readonly VisualizationController $visualizations,
        private readonly DailyWhatsappReportImageService $dailyImages,
        private readonly AdvisorSalesWhatsappImageService $advisorImages
    ) {
    }

    public function handle(array $payload): void
    {
        foreach ($this->incomingMessages($payload) as $message) {
            $from = (string) ($message['from'] ?? '');
            if ($from === '') {
                continue;
            }

            try {
                $this->handleMessage($from, $message);
            } catch (\Throwable $error) {
                Log::error('WHATSAPP REPORT CONVERSATION FAILED', [
                    'from' => $from,
                    'error' => $error->getMessage(),
                ]);

                $this->client->sendTextMessage($from, 'No pude generar ese reporte en este momento. Intenta de nuevo en unos minutos.');
            }
        }
    }

    private function handleMessage(string $from, array $message): void
    {
        $command = $this->commandFromMessage($message);

        if ($command === 'daily_today') {
            $this->sendDailyReport($from, now('America/Bogota')->toDateString());
            return;
        }

        if ($command === 'advisor_today') {
            $this->sendAdvisorReport($from, now('America/Bogota')->toDateString());
            return;
        }

        if ($command === 'ask_date') {
            $this->client->sendTextMessage($from, 'Escribe la fecha que quieres consultar en formato YYYY-MM-DD. Ejemplo: 2026-07-16');
            return;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $command)) {
            $this->sendDailyReport($from, $command);
            return;
        }

        $this->client->sendButtonMenu($from);
    }

    private function sendDailyReport(string $to, string $date): void
    {
        $request = Request::create('/', 'GET', ['date' => $date]);
        $report = $this->visualizations->dailyWhatsappReportData($request);

        $this->sender->sendImagesToNumbers($this->dailyImages->makeImages($report), [$to]);
    }

    private function sendAdvisorReport(string $to, string $date): void
    {
        $request = Request::create('/', 'GET', ['date' => $date]);
        $report = $this->visualizations->advisorSalesReportData($request);

        $this->sender->sendImageToNumbers(
            $this->advisorImages->make($report),
            sprintf('Ventas por asesor - %s', $report['date']),
            [$to]
        );
    }

    private function commandFromMessage(array $message): string
    {
        $interactive = $message['interactive'] ?? [];

        if (($interactive['type'] ?? '') === 'button_reply') {
            return strtolower(trim((string) ($interactive['button_reply']['id'] ?? '')));
        }

        if (($interactive['type'] ?? '') === 'list_reply') {
            return strtolower(trim((string) ($interactive['list_reply']['id'] ?? '')));
        }

        $text = strtolower(trim((string) ($message['text']['body'] ?? '')));
        $text = str_replace(['ventas de hoy', 'ventas hoy', 'daily', 'reporte diario'], 'daily_today', $text);
        $text = str_replace(['ventas asesores', 'asesores', 'asesores hoy'], 'advisor_today', $text);
        $text = str_replace(['otra fecha', 'fecha'], 'ask_date', $text);

        return $text;
    }

    private function incomingMessages(array $payload): array
    {
        $messages = [];

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                foreach ($change['value']['messages'] ?? [] as $message) {
                    $messages[] = $message;
                }
            }
        }

        return $messages;
    }
}
