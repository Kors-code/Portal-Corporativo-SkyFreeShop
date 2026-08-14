<?php

namespace App\Http\Controllers\ApiInventarios;

use App\Exports\InventoryStoreExport;
use App\Http\Controllers\Controller;
use App\Services\Inventario\InventoryReportService;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class InventoryController extends Controller
{
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

    public function export(Request $request, InventoryReportService $service)
    {
        $search = $request->get('search');
        $storeIds = $this->resolveStoreIds($request);
        $asOfDate = $request->input('as_of_date');
        $maxMonths = $this->resolveMaxMonths($request);
        $rows = $service->getReport($search, $storeIds, $asOfDate, $maxMonths);

        $storeSlug = 'todas-tiendas';
        if (count($storeIds) === 1) {
            $store = collect($service->getStores())->firstWhere('id', $storeIds[0]);
            $storeSlug = $this->slug((string) ($store->code ?? $store->name ?? 'tienda'));
        }

        $dateSlug = now()->format('Y-m-d');

        return Excel::download(
            new InventoryStoreExport($rows),
            "inventario-{$storeSlug}-{$dateSlug}.xlsx"
        );
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

    private function slug(string $value): string
    {
        $slug = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', trim($value)) ?? '');
        $slug = trim($slug, '-');

        return $slug !== '' ? $slug : 'tienda';
    }
}
