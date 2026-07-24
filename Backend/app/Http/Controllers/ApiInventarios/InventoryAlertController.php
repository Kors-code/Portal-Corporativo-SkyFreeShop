<?php

namespace App\Http\Controllers\ApiInventarios;

use App\Http\Controllers\Controller;
use App\Services\Inventario\InventoryAlertService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryAlertController extends Controller
{
    public function index(InventoryAlertService $service): JsonResponse
    {
        return response()->json($service->lists());
    }

    public function show(int $id, InventoryAlertService $service): JsonResponse
    {
        return response()->json($service->getList($id));
    }

    public function current(int $id, InventoryAlertService $service): JsonResponse
    {
        return response()->json($service->currentAlerts($id));
    }

    public function store(Request $request, InventoryAlertService $service): JsonResponse
    {
        $data = $this->validatedList($request);

        return response()->json($service->saveList($data, null, $request->user()?->id), 201);
    }

    public function update(int $id, Request $request, InventoryAlertService $service): JsonResponse
    {
        $data = $this->validatedList($request);

        return response()->json($service->saveList($data, $id, $request->user()?->id));
    }

    public function destroy(int $id, InventoryAlertService $service): JsonResponse
    {
        $service->deleteList($id);

        return response()->json(['ok' => true]);
    }

    public function products(Request $request, InventoryAlertService $service): JsonResponse
    {
        return response()->json($service->searchProducts(
            $request->query('search'),
            (int) $request->query('limit', 20)
        ));
    }

    public function top(Request $request, InventoryAlertService $service): JsonResponse
    {
        $data = $request->validate([
            'store_ids' => ['required', 'array', 'min:1'],
            'store_ids.*' => ['integer'],
            'months' => ['nullable', 'integer', 'min:1', 'max:12'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        return response()->json($service->topProducts(
            $data['store_ids'],
            (int) ($data['months'] ?? 3),
            (int) ($data['limit'] ?? 50)
        ));
    }

    public function addTop(int $id, Request $request, InventoryAlertService $service): JsonResponse
    {
        $data = $request->validate([
            'months' => ['nullable', 'integer', 'min:1', 'max:12'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        return response()->json($service->addTopToList(
            $id,
            (int) ($data['months'] ?? 3),
            (int) ($data['limit'] ?? 50)
        ));
    }

    public function addProduct(int $id, Request $request, InventoryAlertService $service): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
        ]);

        return response()->json($service->addProduct($id, (int) $data['product_id']));
    }

    public function removeProduct(int $id, int $productId, InventoryAlertService $service): JsonResponse
    {
        return response()->json($service->removeProduct($id, $productId));
    }

    public function send(int $id, InventoryAlertService $service): JsonResponse
    {
        return response()->json($service->sendList($id, 'manual', true, false));
    }

    public function test(int $id, InventoryAlertService $service): JsonResponse
    {
        return response()->json($service->sendList($id, 'test', true, true));
    }

    public function history(Request $request, InventoryAlertService $service): JsonResponse
    {
        $listId = $request->query('list_id') ? (int) $request->query('list_id') : null;

        return response()->json($service->history($listId, (int) $request->query('limit', 30)));
    }

    private function validatedList(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'is_active' => ['nullable', 'boolean'],
            'auto_send' => ['nullable', 'boolean'],
            'frequency_days' => ['nullable', 'integer', 'min:1', 'max:30'],
            'top_months' => ['nullable', 'integer', 'min:1', 'max:12'],
            'top_limit' => ['nullable', 'integer', 'min:1', 'max:200'],
            'store_ids' => ['required', 'array', 'min:1'],
            'store_ids.*' => ['integer'],
            'product_ids' => ['nullable', 'array'],
            'product_ids.*' => ['integer'],
            'recipients' => ['nullable', 'array'],
            'recipients.*.name' => ['nullable', 'string', 'max:160'],
            'recipients.*.email' => ['required_with:recipients', 'email', 'max:190'],
            'recipients.*.is_active' => ['nullable', 'boolean'],
        ]);
    }
}
