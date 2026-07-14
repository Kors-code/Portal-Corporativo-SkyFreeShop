<?php

namespace App\Console\Commands;

use App\Services\Inventario\InventoryAlertService;
use Illuminate\Console\Command;

class WarmInventoryAlertTopCache extends Command
{
    protected $signature = 'inventory:alerts-cache-top {--force}';
    protected $description = 'Warm daily top product cache for inventory alert lists';

    public function handle(InventoryAlertService $service): int
    {
        $results = $service->warmTopCaches((bool) $this->option('force'));

        $this->info('Inventory alert top caches processed: ' . count($results));
        foreach ($results as $result) {
            $line = 'List ' . ($result['list_id'] ?? '-') . ': ' . ($result['status'] ?? '-');
            if (isset($result['rows'])) {
                $line .= ' - ' . $result['rows'] . ' products';
            }
            if (isset($result['message'])) {
                $line .= ' - ' . $result['message'];
            }
            $this->line($line);
        }

        return self::SUCCESS;
    }
}
