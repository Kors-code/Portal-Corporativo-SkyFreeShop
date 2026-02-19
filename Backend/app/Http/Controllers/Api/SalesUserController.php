<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

class SalesUserController extends Controller
{
    // Usuarios que tienen ventas registradas
    public function users()
    {
        $users = DB::table('users')
            ->select('users.id', 'users.name')
            ->join('sales', 'sales.user_id', '=', 'users.id')
            ->groupBy('users.id', 'users.name')
            ->orderBy('users.name')
            ->get();

        return response()->json($users);
    }

    // Ventas por usuario
    public function salesByUser($id)
    {
        $sales = DB::table('sales')
            ->select('id', 'amount', 'date')
            ->where('user_id', $id)
            ->orderBy('date', 'desc')
            ->get();

        return response()->json([
            "user_id" => $id,
            "sales"   => $sales
        ]);
    }
}
