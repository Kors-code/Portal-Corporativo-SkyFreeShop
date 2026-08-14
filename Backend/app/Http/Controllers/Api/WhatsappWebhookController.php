<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\WhatsappReportConversationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WhatsappWebhookController extends Controller
{
    public function verify(Request $request)
    {
        $mode = (string) $request->query('hub_mode', $request->query('hub.mode', ''));
        $token = (string) $request->query('hub_verify_token', $request->query('hub.verify_token', ''));
        $challenge = (string) $request->query('hub_challenge', $request->query('hub.challenge', ''));
        $expectedToken = (string) config('services.whatsapp_cloud.webhook_verify_token');

        if ($mode === 'subscribe' && $expectedToken !== '' && hash_equals($expectedToken, $token)) {
            return response($challenge, 200)->header('Content-Type', 'text/plain');
        }

        Log::warning('WHATSAPP WEBHOOK VERIFY FAILED', [
            'mode' => $mode,
            'token_present' => $token !== '',
            'expected_token_configured' => $expectedToken !== '',
        ]);

        return response('Forbidden', 403);
    }

    public function receive(Request $request, WhatsappReportConversationService $conversation)
    {
        if (! $this->hasValidSignature($request)) {
            Log::warning('WHATSAPP WEBHOOK SIGNATURE FAILED', [
                'signature_present' => $request->header('X-Hub-Signature-256') !== null,
                'app_secret_configured' => (string) config('services.whatsapp_cloud.webhook_app_secret') !== '',
            ]);

            return response()->json(['message' => 'Forbidden'], 403);
        }

        $this->logStatusSummaries($request->all());

        Log::info('WHATSAPP WEBHOOK RECEIVED', [
            'payload' => $request->all(),
        ]);

        $conversation->handle($request->all());

        return response()->json(['ok' => true]);
    }

    private function logStatusSummaries(array $payload): void
    {
        foreach (($payload['entry'] ?? []) as $entry) {
            foreach (($entry['changes'] ?? []) as $change) {
                $value = $change['value'] ?? [];

                foreach (($value['statuses'] ?? []) as $status) {
                    $errors = collect($status['errors'] ?? [])
                        ->map(fn ($error) => [
                            'code' => $error['code'] ?? null,
                            'title' => $error['title'] ?? null,
                            'message' => $error['message'] ?? null,
                            'details' => $error['error_data']['details'] ?? null,
                        ])
                        ->values()
                        ->all();

                    $level = $errors === [] ? 'info' : 'warning';

                    Log::{$level}('WHATSAPP STATUS SUMMARY', [
                        'message_id' => $status['id'] ?? null,
                        'status' => $status['status'] ?? null,
                        'timestamp' => $status['timestamp'] ?? null,
                        'recipient' => $this->maskPhone((string) ($status['recipient_id'] ?? '')),
                        'conversation_id' => $status['conversation']['id'] ?? null,
                        'pricing_category' => $status['pricing']['category'] ?? null,
                        'errors' => $errors,
                    ]);
                }
            }
        }
    }

    private function maskPhone(string $number): ?string
    {
        $digits = preg_replace('/\D+/', '', $number) ?? '';

        if ($digits === '') {
            return null;
        }

        return substr($digits, 0, 4) . '***' . substr($digits, -2);
    }

    private function hasValidSignature(Request $request): bool
    {
        $appSecret = (string) config('services.whatsapp_cloud.webhook_app_secret');

        if ($appSecret === '') {
            return ! app()->environment('production');
        }

        $signature = (string) $request->header('X-Hub-Signature-256', '');
        if (! str_starts_with($signature, 'sha256=')) {
            return false;
        }

        $expected = 'sha256=' . hash_hmac('sha256', $request->getContent(), $appSecret);

        return hash_equals($expected, $signature);
    }
}
