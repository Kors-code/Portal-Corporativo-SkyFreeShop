<?php

namespace App\Services\PassengerIntelligence;

use Exception;
use GuzzleHttp\Client as GuzzleClient;
use OpenAI;

class PassengerIntelligenceOpenAiService
{
    private mixed $client;

    public function __construct()
    {
        $factory = OpenAI::factory()
            ->withApiKey((string) config('services.openai.api_key'));

        $caBundle = $this->resolveCaBundle();

        if ($caBundle) {
            $factory = $factory->withHttpClient(new GuzzleClient([
                'verify' => $caBundle,
                'timeout' => 60,
                'connect_timeout' => 15,
            ]));
        }

        $this->client = $factory->make();
    }

    public function forecastAnalysis(array $payload): array
    {
        return $this->chatJson(
            'Eres analista senior de Passenger Intelligence para Sky Free en el aeropuerto MDE. Responde solo JSON valido. No inventes fuentes. Diferencia datos observados, datos estimados y prediccion.',
            json_encode([
                'task' => 'Analizar forecast mensual de pasajeros y composicion colombiano/extranjero.',
                'context' => 'Sky Free mide PAX internos observados por Excel/OneDrive. La composicion colombiano/extranjero es estimada por perfiles oficiales disponibles. Los festivos/eventos son datos guardados con fuente y URL; usalos solo como contexto trazable, no inventes otras fuentes. La prediccion debe ser explicable y auditable.',
                'payload' => $payload,
                'required_json_schema' => [
                    'executive_summary' => 'string',
                    'forecast_drivers' => ['string'],
                    'risks' => ['string'],
                    'accuracy_monitoring_plan' => ['string'],
                    'failure_modes' => ['string'],
                    'recommendations' => ['string'],
                ],
            ], JSON_UNESCAPED_UNICODE),
            [
                'executive_summary',
                'forecast_drivers',
                'risks',
                'accuracy_monitoring_plan',
                'failure_modes',
                'recommendations',
            ]
        );
    }

    private function chatJson(string $systemPrompt, string $userPrompt, array $requiredKeys): array
    {
        try {
            $response = $this->client->chat()->create([
                'model' => config('services.openai.passenger_intelligence_model', 'gpt-4o'),
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                'temperature' => 0.2,
                'max_tokens' => 2500,
                'top_p' => 1.0,
                'n' => 1,
            ]);
        } catch (Exception $e) {
            return [
                'ok' => false,
                'error' => 'Error al solicitar IA: ' . $e->getMessage(),
                'raw' => null,
            ];
        }

        $content = $response->choices[0]->message->content ?? '';
        $json = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $extracted = $this->extractJsonBlock($content);
            $json = $extracted ? json_decode($extracted, true) : null;
        }

        if (!is_array($json)) {
            return [
                'ok' => false,
                'error' => 'Respuesta IA invalida',
                'raw' => $content,
            ];
        }

        foreach ($requiredKeys as $key) {
            if (!array_key_exists($key, $json)) {
                return [
                    'ok' => false,
                    'error' => "Respuesta IA sin clave requerida: {$key}",
                    'raw' => $json,
                ];
            }
        }

        return [
            'ok' => true,
            'data' => $json,
        ];
    }

    private function extractJsonBlock(string $text): ?string
    {
        $start = strpos($text, '{');
        $end = strrpos($text, '}');

        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        $candidate = substr($text, $start, $end - $start + 1);

        return json_decode($candidate, true) === null && json_last_error() !== JSON_ERROR_NONE
            ? null
            : $candidate;
    }

    private function resolveCaBundle(): ?string
    {
        $candidates = [
            config('services.openai.ca_bundle'),
            ini_get('curl.cainfo') ?: null,
            'C:\\laragon\\etc\\ssl\\cacert.pem',
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '' && is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
