<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class WhatsappCloudApiClient
{
    public function sendImageToRecipients(string $pngBytes, string $caption = '', ?array $recipients = null): array
    {
        $numbers = $recipients ? $this->normalizePhoneNumbers($recipients) : $this->recipientNumbers();

        if ($numbers === []) {
            throw new \RuntimeException('WHATSAPP_RECIPIENT_NUMBERS no esta configurado.');
        }

        $media = $this->uploadMedia($pngBytes);
        $mediaId = (string) ($media['id'] ?? '');

        if ($mediaId === '') {
            throw new \RuntimeException('WhatsApp Cloud API no devolvio media id.');
        }

        $results = [];

        foreach ($numbers as $number) {
            $message = $this->sendImageMessage($number, $mediaId, $caption);

            $results[] = [
                'to' => $number,
                'media' => $media,
                'message' => $message,
            ];
        }

        return $results;
    }

    public function uploadMedia(string $bytes, string $mimeType = 'image/png', string $filename = 'whatsapp-report.png'): array
    {
        if ($bytes === '') {
            throw new \InvalidArgumentException('La imagen de WhatsApp esta vacia.');
        }

        $response = Http::timeout(60)
            ->withToken($this->accessToken())
            ->acceptJson()
            ->attach('file', $bytes, $filename, ['Content-Type' => $mimeType])
            ->post($this->endpoint('media'), [
                'messaging_product' => 'whatsapp',
                'type' => $mimeType,
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException('WhatsApp Cloud API media respondio ' . $response->status() . ': ' . $response->body());
        }

        return $response->json() ?? [];
    }

    public function sendImageMessage(string $to, string $mediaId, string $caption = ''): array
    {
        if ($mediaId === '') {
            throw new \RuntimeException('WhatsApp Cloud API no devolvio media id.');
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $this->normalizePhoneNumber($to),
            'type' => 'image',
            'image' => [
                'id' => $mediaId,
            ],
        ];

        if ($caption !== '') {
            $payload['image']['caption'] = $caption;
        }

        $response = Http::timeout(45)
            ->withToken($this->accessToken())
            ->acceptJson()
            ->post($this->endpoint('messages'), $payload);

        if (!$response->successful()) {
            throw new \RuntimeException('WhatsApp Cloud API messages respondio ' . $response->status() . ': ' . $response->body());
        }

        return $response->json() ?? [];
    }

    public function recipientNumbers(): array
    {
        $raw = (string) config('services.whatsapp_cloud.recipient_numbers', '');

        return $this->normalizePhoneNumbers(preg_split('/[\s,;]+/', $raw) ?: []);
    }

    private function normalizePhoneNumbers(array $numbers): array
    {
        return collect($numbers)
            ->map(fn ($number) => $this->normalizePhoneNumber($number))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function endpoint(string $path): string
    {
        $version = trim((string) config('services.whatsapp_cloud.api_version', 'v23.0'), '/');
        $phoneNumberId = (string) config('services.whatsapp_cloud.phone_number_id');

        if ($phoneNumberId === '') {
            throw new \RuntimeException('WHATSAPP_CLOUD_PHONE_NUMBER_ID no esta configurado.');
        }

        return "https://graph.facebook.com/{$version}/{$phoneNumberId}/{$path}";
    }

    private function accessToken(): string
    {
        $token = (string) config('services.whatsapp_cloud.access_token');

        if ($token === '') {
            throw new \RuntimeException('WHATSAPP_CLOUD_ACCESS_TOKEN no esta configurado.');
        }

        return $token;
    }

    private function normalizePhoneNumber(string $number): string
    {
        $number = trim($number);

        if (str_contains(strtolower($number), '@g.us') || preg_match('/^\d+\-\d+$/', $number)) {
            throw new \InvalidArgumentException('WhatsApp Cloud API solo soporta numeros individuales; no se permiten grupos.');
        }

        $digits = preg_replace('/\D+/', '', $number) ?? '';

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        $countryCode = preg_replace('/\D+/', '', (string) config('services.whatsapp_cloud.default_country_code', '57')) ?? '';

        if ($digits !== '' && strlen($digits) <= 10 && $countryCode !== '') {
            $digits = $countryCode . $digits;
        }

        return $digits;
    }
}
