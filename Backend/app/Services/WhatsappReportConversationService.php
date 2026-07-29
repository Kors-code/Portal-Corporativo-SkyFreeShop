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
        private readonly AdvisorSalesWhatsappImageService $advisorImages,
        private readonly StoreSalesWhatsappImageService $storeImages
    ) {
    }

    public function handle(array $payload): void
    {
        foreach ($this->incomingMessages($payload) as $message) {
            $from = (string) ($message['from'] ?? '');
            if ($from === '') {
                continue;
            }

            if (! $this->allowsSender($from)) {
                Log::info('WHATSAPP REPORT CONVERSATION SKIPPED UNAUTHORIZED NUMBER', [
                    'from' => $from,
                ]);

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

        if ($command === 'store_today') {
            $this->sendStoreReport($from, now('America/Bogota')->toDateString());
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

        $this->client->sendMenuTemplateMessage($from);
    }

    private function sendDailyReport(string $to, string $date): void
    {
        $request = Request::create('/', 'GET', [
            'date' => $date,
            'pdvs' => ['COLS1', 'COLS2'],
        ]);
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

    private function sendStoreReport(string $to, string $date): void
    {
        $request = Request::create('/', 'GET', ['date' => $date]);
        $report = $this->visualizations->storeSalesReportData($request);

        $this->sender->sendImageToNumbers(
            $this->storeImages->make($report),
            sprintf('Ventas por tienda - %s', $report['date']),
            [$to]
        );
    }

    private function commandFromMessage(array $message): string
    {
        $interactive = $message['interactive'] ?? [];

        if (($interactive['type'] ?? '') === 'button_reply') {
            return $this->normalizeCommand(
                (string) ($interactive['button_reply']['id'] ?? $interactive['button_reply']['title'] ?? '')
            );
        }

        if (($interactive['type'] ?? '') === 'list_reply') {
            return $this->normalizeCommand(
                (string) ($interactive['list_reply']['id'] ?? $interactive['list_reply']['title'] ?? '')
            );
        }

        if (($message['type'] ?? '') === 'button') {
            return $this->normalizeCommand((string) ($message['button']['payload'] ?? $message['button']['text'] ?? ''));
        }

        return $this->normalizeCommand((string) ($message['text']['body'] ?? ''));
    }

    private function normalizeCommand(string $value): string
    {
        $command = strtolower(trim($value));

        return match ($command) {
            'ventas de hoy', 'ventas hoy', 'daily', 'reporte diario', 'daily_today' => 'daily_today',
            'ventas asesores', 'asesores', 'asesores hoy', 'advisor_today' => 'advisor_today',
            'ventas por tienda', 'ventas tienda', 'tiendas', 'store_today', 'store_sales' => 'store_today',
            'otra fecha', 'fecha', 'ask_date' => 'ask_date',
            default => $command,
        };
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

    private function allowsSender(string $from): bool
    {
        if ((bool) config('services.whatsapp_cloud.allow_any_report_sender')) {
            return true;
        }

        return in_array($this->normalizePhoneNumber($from), $this->client->recipientNumbers(), true);
    }

    private function normalizePhoneNumber(string $number): string
    {
        return preg_replace('/\D+/', '', $number) ?? '';
    }
}
