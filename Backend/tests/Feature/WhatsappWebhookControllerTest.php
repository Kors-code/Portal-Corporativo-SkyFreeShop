<?php

namespace Tests\Feature;

use Tests\TestCase;

class WhatsappWebhookControllerTest extends TestCase
{
    public function test_meta_webhook_verification_returns_challenge_for_valid_token(): void
    {
        config(['services.whatsapp_cloud.webhook_verify_token' => 'portal-token']);

        $response = $this->get('/api/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=portal-token&hub.challenge=abc123');

        $response->assertOk();
        $response->assertSeeText('abc123');
    }

    public function test_meta_webhook_verification_rejects_invalid_token(): void
    {
        config(['services.whatsapp_cloud.webhook_verify_token' => 'portal-token']);

        $this->get('/api/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=abc123')
            ->assertForbidden();
    }
}
