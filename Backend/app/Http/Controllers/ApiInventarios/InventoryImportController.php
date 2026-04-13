<?php

namespace App\Http\Controllers\ApiInventarios;

use App\Http\Controllers\Controller;
use App\Imports\InventoryImport;
use App\Models\Inventario\Store;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
class InventoryImportController extends Controller
{
    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv',
            'store_id' => 'required|integer|exists:budget.stores,id',
        ]);

        Excel::import(new InventoryImport((int) $request->store_id), $request->file('file'));

        return response()->json([
            'message' => 'Inventario importado correctamente'
        ]);
    }
    public function importCatalog(Request $request)
{
    $request->validate([
        'file' => 'required|file|mimes:xlsx,xls,csv',
    ]);

    Excel::import(new ProductCatalogImport(), $request->file('file'));

    return response()->json([
        'message' => 'Catálogo importado correctamente'
    ]);
}

    public function stores()
    {
        return response()->json(
            Store::orderBy('name')->get()
        );
    }
}