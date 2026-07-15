<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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

    public function receive(Request $request)
    {
        if (! $this->hasValidSignature($request)) {
            Log::warning('WHATSAPP WEBHOOK SIGNATURE FAILED', [
                'signature_present' => $request->header('X-Hub-Signature-256') !== null,
                'app_secret_configured' => (string) config('services.whatsapp_cloud.webhook_app_secret') !== '',
            ]);

            return response()->json(['message' => 'Forbidden'], 403);
        }

        Log::info('WHATSAPP WEBHOOK RECEIVED', [
            'payload' => $request->all(),
        ]);

        return response()->json(['ok' => true]);
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
