<?php

namespace App\Http\Controllers\ApiInventarios;

use App\Http\Controllers\Controller;
use App\Imports\InventoryImport;
use App\Models\Inventario\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class InventoryImportController extends Controller
{
    public function import(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv'],
            'store_id' => ['required', 'integer', 'exists:budget.stores,id'],
        ]);

        $batchId = DB::connection('budget')->table('inventory_import_batches')->insertGetId([
            'filename' => $request->file('file')->getClientOriginalName(),
            'store_id' => (int) $request->store_id,
            'created_at' => now(),
        ]);

        Excel::import(
            new InventoryImport((int) $request->store_id, (int) $batchId),
            $request->file('file')
        );

        return response()->json([
            'message' => 'Inventario importado correctamente.',
            'batch_id' => $batchId,
        ]);
    }

    public function deleteBatch(int $batchId)
    {
        DB::connection('budget')->table('inventory')
            ->where('batch_id', $batchId)
            ->delete();

        DB::connection('budget')->table('inventory_import_batches')
            ->where('id', $batchId)
            ->delete();

        return response()->json([
            'message' => 'Batch eliminado correctamente.',
        ]);
    }

    public function stores()
    {
        return response()->json(
            Store::on('budget')->orderBy('name')->get()
        );
    }
}