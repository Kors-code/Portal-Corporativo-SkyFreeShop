<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\VisualizationController;
use App\Models\WhatsappReportJob;
use App\Services\AdvisorSalesWhatsappImageService;
use App\Services\DailyWhatsappReportImageService;
use App\Services\StoreSalesWhatsappImageService;
use App\Services\WhatsappNumberReportSender;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProcessWhatsappReportJobs extends Command
{
    protected $signature = 'reports:process-whatsapp-jobs {--limit=10} {--batch-id=} {--include-legacy-store-sales}';

    protected $description = 'Procesa y envia desde Laravel los reportes WhatsApp pendientes usando Cloud API.';

    public function handle(
        VisualizationController $visualizations,
        DailyWhatsappReportImageService $dailyImages,
        StoreSalesWhatsappImageService $storeImages,
        AdvisorSalesWhatsappImageService $advisorImages,
        WhatsappNumberReportSender $sender
    ): int {
        $limit = max(1, (int) $this->option('limit'));
        $batchId = $this->option('batch-id') !== null ? (int) $this->option('batch-id') : null;
        $includeLegacyStoreSales = (bool) $this->option('include-legacy-store-sales');
        $processed = 0;

        while ($processed < $limit) {
            $job = $this->lockNextJob($batchId, $includeLegacyStoreSales);

            if (!$job) {
                break;
            }

            try {
                $images = $this->imagesForJob($job, $visualizations, $dailyImages, $storeImages, $advisorImages);
                $result = in_array($job->type, ['daily', 'advisor_sales', 'store_sales'], true)
                    ? $sender->sendDailyTemplateImages($images, 'Equipo Sky', optional($job->report_date)->toDateString() ?: now('America/Bogota')->toDateString())
                    : $sender->sendImages($images);

                $job->update([
                    'status' => 'sent',
                    'sent_at' => now(),
                    'last_error' => null,
                    'locked_at' => null,
                    'payload' => array_merge(is_array($job->payload) ? $job->payload : [], [
                        'cloud_api_result' => $result,
                    ]),
                ]);

                $this->info("WhatsApp job #{$job->id} enviado.");
            } catch (\Throwable $error) {
                $job->update([
                    'status' => $job->attempts >= 3 ? 'failed' : 'pending',
                    'last_error' => $error->getMessage(),
                    'available_at' => now()->addMinutes(2),
                    'locked_at' => null,
                ]);

                $this->error("WhatsApp job #{$job->id} fallo: {$error->getMessage()}");
            }

            $processed++;
        }

        $this->info("Trabajos WhatsApp procesados: {$processed}");

        return self::SUCCESS;
    }

    private function lockNextJob(?int $batchId = null, bool $includeLegacyStoreSales = false): ?WhatsappReportJob
    {
        $staleProcessing = WhatsappReportJob::query()
            ->where('status', 'processing')
            ->where('locked_at', '<=', now()->subMinutes(10));

        if ($batchId !== null) {
            $staleProcessing->where('payload->import_batch_id', $batchId);
        } elseif (!$includeLegacyStoreSales) {
            $staleProcessing->where(function ($query) {
                $query->where('type', '!=', 'store_sales')
                    ->orWhereNotNull('payload->import_batch_id');
            });
        }

        $staleProcessing->update([
            'status' => 'pending',
            'available_at' => now(),
            'locked_at' => null,
        ]);

        return DB::transaction(function () use ($batchId, $includeLegacyStoreSales) {
            $job = WhatsappReportJob::query()
                ->where('status', 'pending')
                ->where(function ($query) {
                    $query->whereNull('available_at')->orWhere('available_at', '<=', now());
                });

            if ($batchId !== null) {
                $job->where('payload->import_batch_id', $batchId);
            } elseif (!$includeLegacyStoreSales) {
                $job->where(function ($query) {
                    $query->where('type', '!=', 'store_sales')
                        ->orWhereNotNull('payload->import_batch_id');
                });
            }

            $job = $job
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

            return $job->fresh();
        });
    }

    private function imagesForJob(
        WhatsappReportJob $job,
        VisualizationController $visualizations,
        DailyWhatsappReportImageService $dailyImages,
        StoreSalesWhatsappImageService $storeImages,
        AdvisorSalesWhatsappImageService $advisorImages
    ): array {
        $date = optional($job->report_date)->toDateString() ?: now('America/Bogota')->toDateString();
        $payload = is_array($job->payload) ? $job->payload : [];
        $request = Request::create('/', 'GET', array_merge($payload, ['date' => $date]));

        if ($job->type === 'daily') {
            $report = $visualizations->dailyWhatsappReportData($request);

            return $dailyImages->makeImages($report);
        }

        if ($job->type === 'store_sales') {
            $report = $visualizations->storeSalesReportData($request);

            return [[
                'bytes' => $storeImages->make($report),
                'caption' => sprintf('Ventas por tiendas - %s', $report['date']),
            ]];
        }

        if ($job->type === 'advisor_sales') {
            $report = $visualizations->advisorSalesReportData($request);

            return [[
                'bytes' => $advisorImages->make($report),
                'caption' => sprintf('Ventas por asesor - %s', $report['date']),
            ]];
        }

        throw new \RuntimeException('Tipo de tarea WhatsApp no soportado: ' . $job->type);
    }
}
