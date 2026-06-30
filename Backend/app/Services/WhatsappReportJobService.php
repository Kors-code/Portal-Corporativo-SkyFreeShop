<?php

namespace App\Services;

use App\Models\WhatsappReportJob;
use Illuminate\Support\Carbon;

class WhatsappReportJobService
{
    public function enqueue(string $type, ?string $reportDate = null, array $payload = []): WhatsappReportJob
    {
        return WhatsappReportJob::create([
            'type' => $type,
            'status' => 'pending',
            'report_date' => $reportDate,
            'payload' => $payload,
            'available_at' => now(),
        ]);
    }

    public function enqueueUniqueDaily(string $type, string $reportDate, array $payload = []): WhatsappReportJob
    {
        $date = Carbon::parse($reportDate)->toDateString();

        $job = WhatsappReportJob::query()
            ->where('type', $type)
            ->whereDate('report_date', $date)
            ->first();

        if (!$job) {
            return WhatsappReportJob::create([
                'type' => $type,
                'status' => 'pending',
                'report_date' => $date,
                'payload' => $payload,
                'available_at' => now(),
            ]);
        }

        if ($job->status === 'failed') {
            $job->update([
                'status' => 'pending',
                'attempts' => 0,
                'payload' => $payload,
                'last_error' => null,
                'available_at' => now(),
                'locked_at' => null,
                'sent_at' => null,
            ]);
        }

        return $job;
    }
}
