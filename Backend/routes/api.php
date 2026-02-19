<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ImportSalesController;
use App\Http\Controllers\Api\CommissionController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\CommissionReportController;
use App\Http\Controllers\Api\CategoryCommissionController;
use App\Http\Controllers\Api\ImportBatchController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\BudgetController;
use App\Http\Controllers\SalesByUserController;
use App\Http\Controllers\Api\BudgetProgressController;
use App\Http\Controllers\Api\CommissionActionController;
use App\Http\Controllers\Api\TurnsImportController;






Route::get('/v1/ping', function () {
    return response()->json([
        'status' => 'ok',
        'message' => 'API funcionando correctamente',
        ]);
        });
        
        Route::prefix('v1')->group(function () {
    // TURNOS (poner primero para evitar conflicto con imports/{id})
    Route::post('import-turns', [TurnsImportController::class, 'import']);
    Route::get('imports/turns', [TurnsImportController::class, 'index']);
    Route::get('imports/turns/{id}', [TurnsImportController::class, 'show']);
    Route::delete('imports/turns/{id}', [TurnsImportController::class, 'deleteBatch']);
    Route::delete('imports/turns', [TurnsImportController::class, 'bulkDelete']);

            
            // USERS & ROLES
    Route::get('users', [UserController::class, 'index']);
    Route::post('users/{id}/assign-role', [UserController::class, 'assignRole']);
    Route::get('roles', [RoleController::class, 'index']);
    
    // IMPORTS (EXCEL)
    Route::post('import-sales', [ImportSalesController::class, 'import']);
    Route::get('imports', [ImportBatchController::class, 'index']);
    Route::get('imports/{id}', [ImportBatchController::class, 'show']);
    Route::delete('imports/{id}', [ImportBatchController::class, 'destroy']);
    Route::post('imports/bulk-delete', [ImportBatchController::class, 'bulkDestroy']);

    
    // SALES
    Route::get('sales/users', [SalesByUserController::class, 'getUsersWithSales']);
    Route::get('sales/by-user', [SalesByUserController::class, 'getSalesByUser']);
    
    // COMMISSIONS – LOGIC
    Route::post('commissions/recalc-sale/{id}', [CommissionController::class,'recalcSale']);
    Route::post('commissions/recalc-user/{userId}/{month}', [CommissionController::class,'recalcUserMonth']);
    Route::get('commissions/summary', [CommissionController::class,'userSummary']);
    Route::post('commissions/finalize', [CommissionController::class,'finalize']);
    
    // COMMISSIONS – REPORTS (👈 TU CONTROLADOR)
    
    Route::get('/commissions/by-seller', [CommissionReportController::class, 'bySeller']);
    Route::get('/commissions/by-seller/{userId}', [CommissionReportController::class, 'bySellerDetail']);
    Route::put('commissions/assign-turns/{userId}/{budget_id}', [CommissionReportController::class, 'assignTurns']);
    Route::post('commissions/assign-turns/{userId}/{budget_id}', [CommissionReportController::class, 'assignTurns']);
    

    // REPORTS Cajeros
    Route::get('reports/cashier-awards', [ReportController::class, 'cashierAwards']);
    Route::get('reports/cashier/{userId}/categories', [ReportController::class, 'cashierCategories']);    
    Route::post('/cashier-adjustments', [ReportController::class,'storeCashierAdjustment']);

    // COMMISSION CONFIG
    Route::get('commissions/categories', [CategoryCommissionController::class, 'index']);
    Route::post('commissions/categories', [CategoryCommissionController::class, 'upsert']);
    Route::delete('commissions/categories/{id}', [CategoryCommissionController::class, 'destroy']);
    Route::post('commissions/categories/bulk', [CategoryCommissionController::class, 'bulkUpdate']);
    
    Route::post('/commissions/generate', [CommissionController::class, 'generate']);

    
 
    


    // Budget 
        
    Route::get('/budgets', [BudgetController::class, 'index']);
    Route::post('/budgets', [BudgetController::class, 'store']);
    Route::get('/budgets/active', [BudgetController::class, 'active']);
    Route::put('/budgets/{id}', [BudgetController::class, 'update']);
    Route::delete('/budgets/{id}', [BudgetController::class, 'destroy']);
    Route::patch('/budgets/{id}/cashier-prize', [BudgetController::class, 'updateCashierPrize']);


    // EXCEL EXPORT ROUTE


    Route::get(
        '/commissions/export',
        [CommissionReportController::class, 'exportExcel']
    );

    // Exportar premios de cajeros
    Route::get(
    '/reports/cashier-awards/export',
    [ReportController::class, 'cashierAwardsExport']
);

    // Exportar detalle de comisiones por vendedor
    Route::get(
    '/commissions/by-seller/{userId}/export',
    [CommissionReportController::class, 'exportSellerDetail']
);


});
