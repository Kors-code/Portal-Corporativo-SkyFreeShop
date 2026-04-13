<?php

namespace App\Http\Controllers\ApiInventarios;

use App\Http\Controllers\Controller;
use App\Services\Inventario\InventoryReportService;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    public function index(Request $request, InventoryReportService $service)
    {
        $search = $request->get('search');
        $storeId = $request->get('store_id');

        return response()->json(
            $service->getReport($search, $storeId ? (int) $storeId : null)
        );
    }
}