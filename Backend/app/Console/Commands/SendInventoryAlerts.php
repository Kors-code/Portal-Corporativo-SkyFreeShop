<?php

namespace App\Console\Commands;

use App\Services\Inventario\InventoryAlertService;
use Illuminate\Console\Command;

class SendInventoryAlerts extends Command
{
    protected $signature = 'inventory:alerts-send {--list_id=} {--force}';
    protected $description = 'Send configured inventory alert summaries';

    public function handle(InventoryAlertService $service): int
    {
        $listId = $this->option('list_id');

        if ($listId !== null && $listId !== '') {
            $result = $service->sendList((int) $listId, 'manual', (bool) $this->option('force'), false);
            $this->info(($result['status'] ?? 'ok') . ': ' . ($result['message'] ?? 'Procesado.'));
            return self::SUCCESS;
        }

        $results = $service->sendAutomatic();
        $this->info('Inventory alert lists processed: ' . count($results));

        foreach ($results as $result) {
            $this->line('List ' . $result['list_id'] . ': ' . ($result['status'] ?? '-') . ' - ' . ($result['message'] ?? ''));
        }

        return self::SUCCESS;
    }
}
