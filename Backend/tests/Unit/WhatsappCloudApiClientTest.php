<?php

namespace Tests\Unit;

use App\Services\WhatsappCloudApiClient;
use Tests\TestCase;

class WhatsappCloudApiClientTest extends TestCase
{
    public function test_it_rejects_group_destinations(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('solo soporta numeros individuales');

        app(WhatsappCloudApiClient::class)->sendImageToRecipients('png-bytes', 'Reporte', [
            '573116018431-1539828052@g.us',
        ]);
    }

    public function test_it_rejects_group_like_destinations_without_suffix(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('solo soporta numeros individuales');

        app(WhatsappCloudApiClient::class)->sendImageToRecipients('png-bytes', 'Reporte', [
            '573116018431-1539828052',
        ]);
    }
}
