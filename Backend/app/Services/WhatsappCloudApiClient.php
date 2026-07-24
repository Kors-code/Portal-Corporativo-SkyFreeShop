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

    public function sendImageTemplateToRecipients(
        string $pngBytes,
        array $bodyParameters = [],
        ?array $recipients = null,
        ?string $templateName = null,
        ?string $language = null
    ): array {
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
            $message = $this->sendImageTemplateMessage(
                $number,
                $mediaId,
                $bodyParameters,
                $templateName,
                $language
            );

            $results[] = [
                'to' => $number,
                'media' => $media,
                'message' => $message,
            ];
        }

        return $results;
    }

    public function sendMenuTemplateToRecipients(string $recipientLabel = 'Equipo Sky', ?array $recipients = null): array
    {
        $numbers = $recipients ? $this->normalizePhoneNumbers($recipients) : $this->recipientNumbers();

        if ($numbers === []) {
            throw new \RuntimeException('WHATSAPP_RECIPIENT_NUMBERS no esta configurado.');
        }

        $results = [];

        foreach ($numbers as $number) {
            $results[] = [
                'to' => $number,
                'message' => $this->sendMenuTemplateMessage($number, $recipientLabel),
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

    public function sendImageTemplateMessage(
        string $to,
        string $mediaId,
        array $bodyParameters = [],
        ?string $templateName = null,
        ?string $language = null
    ): array {
        if ($mediaId === '') {
            throw new \RuntimeException('WhatsApp Cloud API no devolvio media id.');
        }

        $components = [
            [
                'type' => 'header',
                'parameters' => [
                    [
                        'type' => 'image',
                        'image' => ['id' => $mediaId],
                    ],
                ],
            ],
        ];

        if ($bodyParameters !== []) {
            $components[] = [
                'type' => 'body',
                'parameters' => $this->templateBodyParameters($bodyParameters),
            ];
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $this->normalizePhoneNumber($to),
            'type' => 'template',
            'template' => [
                'name' => $templateName ?: (string) config('services.whatsapp_cloud.daily_report_template', 'reporte_diario_sky'),
                'language' => [
                    'code' => $language ?: (string) config('services.whatsapp_cloud.template_language', 'es'),
                ],
                'components' => $components,
            ],
        ];

        $response = Http::timeout(45)
            ->withToken($this->accessToken())
            ->acceptJson()
            ->post($this->endpoint('messages'), $payload);

        if (!$response->successful()) {
            throw new \RuntimeException('WhatsApp Cloud API template respondio ' . $response->status() . ': ' . $response->body());
        }

        return $response->json() ?? [];
    }

    public function sendMenuTemplateMessage(string $to, string $recipientLabel = 'Equipo Sky'): array
    {
        $lastResponse = null;

        foreach ($this->menuTemplateLanguages() as $language) {
            $payload = [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $this->normalizePhoneNumber($to),
                'type' => 'template',
                'template' => [
                    'name' => (string) config('services.whatsapp_cloud.menu_template', 'menu_reportes_sky'),
                    'language' => [
                        'code' => $language,
                    ],
                    'components' => [
                        [
                            'type' => 'body',
                            'parameters' => $this->templateBodyParameters(
                                [$recipientLabel],
                                'services.whatsapp_cloud.menu_template_body_param_names'
                            ),
                        ],
                    ],
                ],
            ];

            $response = Http::timeout(45)
                ->withToken($this->accessToken())
                ->acceptJson()
                ->post($this->endpoint('messages'), $payload);

            if ($response->successful()) {
                return $response->json() ?? [];
            }

            $lastResponse = $response;

            if (! str_contains($response->body(), '132001')) {
                break;
            }
        }

        throw new \RuntimeException(
            'WhatsApp Cloud API menu template respondio '
            . ($lastResponse?->status() ?? 0)
            . ': '
            . ($lastResponse?->body() ?? 'sin respuesta')
        );
    }

    public function sendTextMessage(string $to, string $message): array
    {
        $response = Http::timeout(45)
            ->withToken($this->accessToken())
            ->acceptJson()
            ->post($this->endpoint('messages'), [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $this->normalizePhoneNumber($to),
                'type' => 'text',
                'text' => [
                    'preview_url' => false,
                    'body' => $message,
                ],
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException('WhatsApp Cloud API text respondio ' . $response->status() . ': ' . $response->body());
        }

        return $response->json() ?? [];
    }

    public function sendButtonMenu(string $to): array
    {
        $response = Http::timeout(45)
            ->withToken($this->accessToken())
            ->acceptJson()
            ->post($this->endpoint('messages'), [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $this->normalizePhoneNumber($to),
                'type' => 'interactive',
                'interactive' => [
                    'type' => 'button',
                    'header' => [
                        'type' => 'text',
                        'text' => 'Portal Sky Free Shop',
                    ],
                    'body' => [
                        'text' => 'Elige el reporte que quieres recibir.',
                    ],
                    'footer' => [
                        'text' => 'Tambien puedes escribir una fecha YYYY-MM-DD.',
                    ],
                    'action' => [
                        'buttons' => [
                            [
                                'type' => 'reply',
                                'reply' => ['id' => 'daily_today', 'title' => 'Ventas hoy'],
                            ],
                            [
                                'type' => 'reply',
                                'reply' => ['id' => 'advisor_today', 'title' => 'Asesores hoy'],
                            ],
                            [
                                'type' => 'reply',
                                'reply' => ['id' => 'ask_date', 'title' => 'Otra fecha'],
                            ],
                        ],
                    ],
                ],
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException('WhatsApp Cloud API menu respondio ' . $response->status() . ': ' . $response->body());
        }

        return $response->json() ?? [];
    }

    public function recipientNumbers(): array
    {
        $raw = (string) config('services.whatsapp_cloud.recipient_numbers', '');

        return $this->normalizePhoneNumbers(preg_split('/[\s,;]+/', $raw) ?: []);
    }

    private function templateBodyParameters(
        array $parameters,
        string $configKey = 'services.whatsapp_cloud.daily_report_template_body_param_names'
    ): array
    {
        $names = collect(preg_split('/[\s,;]+/', (string) config($configKey, '')) ?: [])
            ->map(fn ($name) => trim((string) $name))
            ->filter()
            ->values()
            ->all();

        return collect(array_values($parameters))
            ->map(function ($value, $index) use ($names) {
                $parameter = [
                    'type' => 'text',
                    'text' => (string) $value,
                ];

                if (!empty($names[$index])) {
                    $parameter['parameter_name'] = $names[$index];
                }

                return $parameter;
            })
            ->values()
            ->all();
    }

    private function menuTemplateLanguages(): array
    {
        $configured = (string) (
            config('services.whatsapp_cloud.menu_template_language')
            ?: config('services.whatsapp_cloud.template_language', 'es')
        );
        $fallbacks = (string) config('services.whatsapp_cloud.menu_template_language_fallbacks', 'es_ES,es,es_CO');

        return collect(array_merge([$configured], preg_split('/[\s,;]+/', $fallbacks) ?: []))
            ->map(fn ($language) => trim((string) $language))
            ->filter()
            ->unique()
            ->values()
            ->all();
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
