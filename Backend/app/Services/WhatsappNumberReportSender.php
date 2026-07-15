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
}
