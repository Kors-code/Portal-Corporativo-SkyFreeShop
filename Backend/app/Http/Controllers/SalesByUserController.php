<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Comisiones\Sale;
use App\Models\Comisiones\User;

class SalesByUserController extends Controller
{
public function getUsersWithSales()
{
    $users = \DB::table('sales')
        ->join('users', 'users.id', '=', 'sales.seller_id')
        ->select(
            'users.id as user_id',
            'users.name as user_name',
            \DB::raw('COUNT(sales.id) as sales_count')
        )
        ->groupBy('users.id', 'users.name')
        ->get();

    // Formato que React necesita
    $mapped = $users->map(function ($u) {
        return [
            'type' => 'seller',
            'key' => (string) $u->user_id,
            'label' => $u->user_name,
            'sales_count' => intval($u->sales_count)
        ];
    });

    return response()->json($mapped);
}
public function getSalesByUser(Request $r)
{
    $sellerId = $r->input('key');
    $dateFrom = $r->input('date_from');
    $dateTo = $r->input('date_to');

    $query = Sale::where('seller_id', $sellerId)
        ->with(['product:id,description'])
        ->select(
            'id',
            'sale_date',
            'folio',
            'pdv',
            'product_id',
            'quantity',
            'amount',
            'value_pesos',
            'value_usd',
            'currency',
            'status',
            'cashier'
        )
        ->orderBy('sale_date', 'desc');

    if ($dateFrom) $query->whereDate('sale_date', '>=', $dateFrom);
    if ($dateTo) $query->whereDate('sale_date', '<=', $dateTo);

    $sales = $query->get();

    return response()->json([
        'sales' => $sales
    ]);
}

}
