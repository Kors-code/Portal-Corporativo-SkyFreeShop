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
        $maxMonths = $this->resolveMaxMonths($request);

        return response()->json(
            $service->getReport($search, $storeIds, $asOfDate, $maxMonths)
        );
    }

    public function run(Request $request, InventoryReportService $service)
    {
        $storeIds = $this->resolveStoreIds($request);
        $maxMonths = $this->resolveMaxMonths($request);

        if (empty($storeIds)) {
            Artisan::call('inventory:metrics', ['--max_months' => $maxMonths]);
        } else {
            foreach ($storeIds as $storeId) {
                Artisan::call('inventory:metrics', [
                    '--store_id' => $storeId,
                    '--max_months' => $maxMonths,
                ]);
            }
        }

        $search = $request->get('search');
        $asOfDate = $request->input('as_of_date');
        $rows = $service->getReport($search, $storeIds, $asOfDate, $maxMonths);

        return response()->json([
            'message' => 'Calculo ejecutado correctamente.',
            'executed_at' => now()->toDateTimeString(),
            'max_months' => $maxMonths,
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

    private function resolveMaxMonths(Request $request): int
    {
        $value = filter_var($request->input('max_months', 12), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        return min($value ?: 12, 20);
    }
}
