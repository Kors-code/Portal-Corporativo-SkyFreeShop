<?php

namespace App\Console\Commands;

use App\Services\WhatsappCloudApiClient;
use Illuminate\Console\Command;

class SendWhatsappMenu extends Command
{
    protected $signature = 'reports:send-whatsapp-menu {--to=*} {--name=Equipo Sky}';

    protected $description = 'Envia la plantilla aprobada del menu de reportes por WhatsApp.';

    public function handle(WhatsappCloudApiClient $client): int
    {
        $recipients = $this->option('to') ?: null;
        $result = $client->sendMenuTemplateToRecipients((string) $this->option('name'), $recipients);

        $this->info('Menu de reportes enviado por WhatsApp.');
        $this->line('Destinatarios: ' . count($result));

        return self::SUCCESS;
    }
}
