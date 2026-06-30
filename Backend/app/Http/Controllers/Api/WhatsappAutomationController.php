<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WhatsappReportJob;
use App\Services\AdvisorSalesWhatsappImageService;
use App\Services\DailyWhatsappReportImageService;
use App\Services\StoreSalesWhatsappImageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WhatsappAutomationController extends Controller
{
    public function next(
        Request $request,
        VisualizationController $visualizations,
        DailyWhatsappReportImageService $dailyImages,
        StoreSalesWhatsappImageService $storeImages,
        AdvisorSalesWhatsappImageService $advisorImages
    ) {
        if (!$this->authorized($request)) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        WhatsappReportJob::query()
            ->where('status', 'processing')
            ->where('locked_at', '<=', now()->subMinutes(10))
            ->update([
                'status' => 'pending',
                'available_at' => now(),
                'locked_at' => null,
            ]);

        $job = DB::transaction(function () {
            $job = WhatsappReportJob::query()
                ->where('status', 'pending')
                ->where(function ($query) {
                    $query->whereNull('available_at')->orWhere('available_at', '<=', now());
                })
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if (!$job) {
                return null;
            }

            $job->update([
                'status' => 'processing',
                'attempts' => $job->attempts + 1,
                'locked_at' => now(),
                'last_error' => null,
            ]);

            return $job;
        });

        if (!$job) {
            return response()->json(['ok' => true, 'job' => null]);
        }

        try {
            $messages = $this->messagesForJob($job, $visualizations, $dailyImages, $storeImages, $advisorImages);
        } catch (\Throwable $error) {
            $job->update([
                'status' => $job->attempts >= 3 ? 'failed' : 'pending',
                'last_error' => $error->getMessage(),
                'available_at' => now()->addMinutes(2),
                'locked_at' => null,
            ]);

            return response()->json([
                'ok' => false,
                'message' => $error->getMessage(),
            ], 500);
        }

        return response()->json([
            'ok' => true,
            'job' => [
                'id' => $job->id,
                'type' => $job->type,
                'report_date' => optional($job->report_date)->toDateString(),
                'attempts' => $job->attempts,
            ],
            'messages' => $messages,
        ]);
    }

    public function complete(Request $request, WhatsappReportJob $job)
    {
        if (!$this->authorized($request)) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $data = $request->validate([
            'ok' => ['required', 'boolean'],
            'error' => ['nullable', 'string'],
        ]);

        if ($data['ok']) {
            $job->update([
                'status' => 'sent',
                'sent_at' => now(),
                'last_error' => null,
            ]);
        } else {
            $job->update([
                'status' => $job->attempts >= 3 ? 'failed' : 'pending',
                'last_error' => $data['error'] ?? 'Error desconocido enviando WhatsApp.',
                'available_at' => now()->addMinutes(2),
                'locked_at' => null,
            ]);
        }

        return response()->json(['ok' => true, 'status' => $job->fresh()->status]);
    }

    private function messagesForJob(
        WhatsappReportJob $job,
        VisualizationController $visualizations,
        DailyWhatsappReportImageService $dailyImages,
        StoreSalesWhatsappImageService $storeImages,
        AdvisorSalesWhatsappImageService $advisorImages
    ): array {
        $date = optional($job->report_date)->toDateString() ?: now('America/Bogota')->toDateString();
        $payload = is_array($job->payload) ? $job->payload : [];
        $request = Request::create('/', 'GET', array_merge($payload, ['date' => $date]));
        $destination = $this->destinationPayload($payload);

        if ($job->type === 'daily') {
            $report = $visualizations->dailyWhatsappReportData($request);

            return array_map(fn ($image) => array_merge([
                'caption' => (string) ($image['caption'] ?? ''),
                'mimeType' => 'image/png',
                'imageBase64' => base64_encode((string) ($image['bytes'] ?? '')),
            ], $destination), $dailyImages->makeImages($report));
        }

        if ($job->type === 'store_sales') {
            $report = $visualizations->storeSalesReportData($request);

            return [array_merge([
                'caption' => sprintf('Ventas por tiendas - %s', $report['date']),
                'mimeType' => 'image/png',
                'imageBase64' => base64_encode($storeImages->make($report)),
            ], $destination)];
        }

        if ($job->type === 'advisor_sales') {
            $report = $visualizations->advisorSalesReportData($request);

            return [array_merge([
                'caption' => sprintf('Ventas por asesor - %s', $report['date']),
                'mimeType' => 'image/png',
                'imageBase64' => base64_encode($advisorImages->make($report)),
            ], $destination)];
        }

        throw new \RuntimeException('Tipo de tarea WhatsApp no soportado: ' . $job->type);
    }

    private function destinationPayload(array $payload): array
    {
        if (!empty($payload['groupId']) && is_string($payload['groupId'])) {
            return ['groupId' => $payload['groupId']];
        }

        return [];
    }

    private function authorized(Request $request): bool
    {
        $token = (string) env('WHATSAPP_AUTOMATION_TOKEN', env('IMPORT_AUTOMATION_TOKEN'));

        return $token !== '' && hash_equals($token, (string) $request->header('X-Automation-Token'));
    }
}
