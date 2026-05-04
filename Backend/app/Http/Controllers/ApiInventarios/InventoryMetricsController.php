<?php

namespace App\Http\Controllers\ApiInventarios;

use App\Http\Controllers\Controller;
use App\Services\Inventario\InventoryReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

class InventoryMetricsController extends Controller
{
    public function stores(InventoryReportService $service)
    {
        return response()->json($service->getStores());
    }

    public function index(Request $request, InventoryReportService $service)
    {
        $search = $request->get('search');
        $storeIds = $this->resolveStoreIds($request);
        $asOfDate = $request->input('as_of_date');

        return response()->json(
            $service->getReport($search, $storeIds, $asOfDate)
        );
    }

    public function run(Request $request, InventoryReportService $service)
    {
        $storeIds = $this->resolveStoreIds($request);

        if (empty($storeIds)) {
            Artisan::call('inventory:metrics');
        } else {
            foreach ($storeIds as $storeId) {
                Artisan::call('inventory:metrics', ['--store_id' => $storeId]);
            }
        }

        $search = $request->get('search');
        $asOfDate = $request->input('as_of_date');
        $rows = $service->getReport($search, $storeIds, $asOfDate);

        return response()->json([
            'message' => 'Calculo ejecutado correctamente.',
            'executed_at' => now()->toDateTimeString(),
            'processed_products' => count($rows),
            'rows' => $rows,
        ]);
    }

    private function resolveStoreIds(Request $request): array
    {
        $storeIds = $request->input('store_ids', []);

        if (!is_array($storeIds)) {
            $storeIds = [$storeIds];
        }

        if ($request->filled('store_id')) {
            $storeIds[] = $request->input('store_id');
        }

        return collect($storeIds)
            ->filter(fn ($value) => $value !== null && $value !== '')
            ->map(fn ($value) => (int) $value)
            ->unique()
            ->values()
            ->all();
    }
}
