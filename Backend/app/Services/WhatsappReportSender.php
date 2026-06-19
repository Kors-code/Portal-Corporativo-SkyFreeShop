<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class WhatsappReportSender
{
    public function sendImage(string $pngBytes, string $caption): array
    {
        $url = rtrim((string) config('services.whatsapp.url'), '/');
        $token = (string) config('services.whatsapp.token');
        $groupId = (string) config('services.whatsapp.group_id');

        if ($url === '') {
            throw new \RuntimeException('WHATSAPP_SERVICE_URL no esta configurado.');
        }

        if ($token === '') {
            throw new \RuntimeException('WHATSAPP_SERVICE_TOKEN no esta configurado.');
        }

        if ($groupId === '') {
            throw new \RuntimeException('WHATSAPP_GROUP_ID no esta configurado.');
        }

        $response = Http::timeout(45)
            ->withHeaders([
                'x-api-token' => $token,
                'Accept' => 'application/json',
            ])
            ->post($url . '/send-image', [
                'groupId' => $groupId,
                'caption' => $caption,
                'imageBase64' => base64_encode($pngBytes),
                'mimeType' => 'image/png',
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException('WhatsApp service respondio ' . $response->status() . ': ' . $response->body());
        }

        return $response->json() ?? [];
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
