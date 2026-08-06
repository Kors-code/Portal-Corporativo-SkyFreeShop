<?php

namespace App\Services;

use App\Http\Controllers\Api\VisualizationController;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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

        if ($command === 'store_today') {
            $this->sendStoreReport($from, now('America/Bogota')->toDateString());
            return;
        }

        if ($command === 'advisor_today') {
            $this->sendAdvisorReport($from, now('America/Bogota')->toDateString());
            return;
        }

        if ($command === 'daily_executive') {
            $this->sendDailyExecutiveReport($from, now('America/Bogota')->subDay()->toDateString());
            return;
        }

        if ($command === 'daily_breakdown') {
            $this->sendDailyBreakdownReport($from, now('America/Bogota')->toDateString());
            return;
        }

        if ($command === 'ask_date') {
            $this->client->sendTextMessage($from, 'Escribe la fecha para el reporte bonito en formato YYYY-MM-DD. Ejemplo: 2026-07-20. Para un rango usa YYYY-MM-DD hasta YYYY-MM-DD. Ejemplo: 2026-07-01 hasta 2026-07-20. Tambien puedes escribir hoy o ayer.');
            return;
        }

        if (preg_match('/^range:(\d{4}-\d{2}-\d{2}):(\d{4}-\d{2}-\d{2})$/', $command, $matches)) {
            $this->sendDailyExecutiveReport($from, $matches[1], $matches[2]);
            return;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $command)) {
            $this->sendDailyExecutiveReport($from, $command);
            return;
        }

        $this->client->sendMenuTemplateMessage($from);
    }

    private function sendDailyExecutiveReport(string $to, string $date, ?string $endDate = null): void
    {
        $payload = $endDate !== null
            ? ['start_date' => $date, 'end_date' => $endDate]
            : ['date' => $date];
        $payload['pdvs'] = ['COLS1', 'COLS2'];

        $request = Request::create('/', 'GET', $payload);
        $report = $this->visualizations->dailyWhatsappReportData($request);

        $this->sender->sendImageToNumbers(
            $this->dailyImages->make($report),
            sprintf('Ventas acumuladas mes - %s', $report['budget']['period']['end'] ?? $report['date'] ?? $endDate ?? $date),
            [$to]
        );
    }

    private function sendDailyBreakdownReport(string $to, string $date): void
    {
        $request = Request::create('/', 'GET', [
            'date' => $date,
            'pdvs' => ['COLS1', 'COLS2'],
        ]);
        $report = $this->visualizations->dailyWhatsappReportData($request);
        $images = array_slice($this->dailyImages->makeImages($report), 1);

        if ($images === []) {
            $this->client->sendTextMessage($to, 'No hay desglose de ventas disponible para esa fecha.');
            return;
        }

        $this->sender->sendImagesToNumbers($images, [$to]);
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

    private function sendStoreReport(string $to, string $date, ?string $endDate = null): void
    {
        $payload = $endDate !== null
            ? ['start_date' => $date, 'end_date' => $endDate]
            : ['date' => $date];
        $request = Request::create('/', 'GET', $payload);
        $report = $this->visualizations->storeSalesReportData($request);

        $this->sender->sendImageToNumbers(
            $this->storeImages->make($report),
            sprintf('Ventas Daily - %s', $report['date_label'] ?? $report['date']),
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
        $range = $this->dateRangeCommand($command);

        if ($range !== null) {
            return $range;
        }

        $date = $this->dateCommand($command);

        if ($date !== null) {
            return $date;
        }

        $command = strtr($command, ['_' => ' ', '-' => ' ']);
        $command = preg_replace('/\s+/', ' ', $command) ?: $command;

        return match ($command) {
            'ventas de hoy', 'ventas hoy', 'daily today', 'ventas daily', 'store today', 'store sales' => 'store_today',
            'ventas de asesores', 'ventas asesores', 'asesores', 'asesores hoy', 'advisor today' => 'advisor_today',
            'ventas acumuladas mes', 'ventas acumuladas', 'acumuladas mes', 'daily', 'reporte diario', 'daily executive' => 'daily_executive',
            'desglose de ventas', 'desglose ventas', 'detalle diario', 'daily breakdown' => 'daily_breakdown',
            'ventas por tienda', 'ventas tienda', 'tiendas' => 'store_today',
            'otra fecha', 'fecha', 'ask date' => 'ask_date',
            default => $command,
        };
    }

    private function dateRangeCommand(string $command): ?string
    {
        $command = trim($command);
        $separator = '(?:\s+(?:hasta el|hasta|al|a|to)\s+|\s+[-–—]\s+)';

        if (!preg_match('/^(.+?)' . $separator . '(.+)$/i', $command, $matches)) {
            return null;
        }

        $start = $this->dateCommand(trim($matches[1]));
        $end = $this->dateCommand(trim($matches[2]));

        if ($start !== null && $end === null && preg_match('/^(?:el\s+)?(\d{1,2})$/', trim($matches[2]), $dayMatch)) {
            $startDate = Carbon::parse($start, 'America/Bogota');
            $end = $this->validDate((int) $startDate->format('Y'), (int) $startDate->format('m'), (int) $dayMatch[1]);
        }

        if ($start === null || $end === null) {
            return null;
        }

        if ($start > $end) {
            [$start, $end] = [$end, $start];
        }

        return "range:{$start}:{$end}";
    }

    private function dateCommand(string $command): ?string
    {
        $command = trim($command);

        if ($command === 'hoy') {
            return now('America/Bogota')->toDateString();
        }

        if ($command === 'ayer') {
            return now('America/Bogota')->subDay()->toDateString();
        }

        if (preg_match('/^(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})$/', $command, $matches)) {
            return $this->validDate((int) $matches[1], (int) $matches[2], (int) $matches[3]);
        }

        if (preg_match('/^(\d{1,2})[-\/](\d{1,2})(?:[-\/](\d{2,4}))?$/', $command, $matches)) {
            $year = isset($matches[3]) && $matches[3] !== ''
                ? (int) $matches[3]
                : (int) now('America/Bogota')->format('Y');

            if ($year < 100) {
                $year += 2000;
            }

            return $this->validDate($year, (int) $matches[2], (int) $matches[1]);
        }

        return null;
    }

    private function validDate(int $year, int $month, int $day): ?string
    {
        if (!checkdate($month, $day, $year)) {
            return null;
        }

        return Carbon::create($year, $month, $day, 0, 0, 0, 'America/Bogota')->toDateString();
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
