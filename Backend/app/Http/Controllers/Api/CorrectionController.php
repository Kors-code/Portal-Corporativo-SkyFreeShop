<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Comisiones\Sale;
use Illuminate\Http\Request;


class CorrectionController extends Controller
{
    public function updateSaleAssignment(Request $request, $id)
    {
        $validated = $request->validate([
            'assigned_to' => 'required|exists:users,id'
        ]);

        $sale = Sale::findOrFail($id);
        $sale->update(['assigned_to' => $validated['assigned_to']]);

        return response()->json([
            'message' => 'Venta asignada correctamente',
            'sale' => $sale
        ], 200);
    }
}