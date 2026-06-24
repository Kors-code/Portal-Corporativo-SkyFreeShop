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
        return WhatsappReportJob::firstOrCreate(
            [
                'type' => $type,
                'report_date' => Carbon::parse($reportDate)->toDateString(),
                'status' => 'pending',
            ],
            [
                'payload' => $payload,
                'available_at' => now(),
            ]
        );
    }
}
