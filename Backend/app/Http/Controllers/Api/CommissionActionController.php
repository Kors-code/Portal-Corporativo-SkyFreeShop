<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CommissionService;

class CommissionActionController extends Controller
{
    public function generate()
    {
        $result = app(CommissionService::class)->generateForActiveBudget();
        return response()->json($result);
    }
}
