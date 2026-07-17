<?php

namespace App\Services;

class WhatsappNumberReportSender
{
    public function __construct(private readonly WhatsappCloudApiClient $client)
    {
    }

    public function sendImage(string $pngBytes, string $caption): array
    {
        return $this->client->sendImageToRecipients($pngBytes, $caption);
    }

    public function sendImageToNumbers(string $pngBytes, string $caption, array $numbers): array
    {
        return $this->client->sendImageToRecipients($pngBytes, $caption, $numbers);
    }

    public function sendDailyTemplateImage(string $pngBytes, string $recipientLabel, string $reportDate, ?array $numbers = null): array
    {
        return $this->client->sendImageTemplateToRecipients(
            $pngBytes,
            $this->dailyTemplateBodyParameters($recipientLabel, $reportDate),
            $numbers
        );
    }

    public function sendImages(array $images): array
    {
        $results = [];

        foreach ($images as $image) {
            $results[] = $this->sendImage(
                (string) ($image['bytes'] ?? ''),
                (string) ($image['caption'] ?? '')
            );
        }

        return $results;
    }

    public function sendImagesToNumbers(array $images, array $numbers): array
    {
        $results = [];

        foreach ($images as $image) {
            $results[] = $this->sendImageToNumbers(
                (string) ($image['bytes'] ?? ''),
                (string) ($image['caption'] ?? ''),
                $numbers
            );
        }

        return $results;
    }

    public function sendDailyTemplateImages(array $images, string $recipientLabel, string $reportDate, ?array $numbers = null): array
    {
        $results = [];

        foreach ($images as $image) {
            $results[] = $this->sendDailyTemplateImage(
                (string) ($image['bytes'] ?? ''),
                $recipientLabel,
                $reportDate,
                $numbers
            );
        }

        return $results;
    }

    private function dailyTemplateBodyParameters(string $recipientLabel, string $reportDate): array
    {
        $parameters = [$recipientLabel, $reportDate];
        $count = max(0, min(count($parameters), (int) config('services.whatsapp_cloud.daily_report_template_body_params', 2)));

        return array_slice($parameters, 0, $count);
    }
}
