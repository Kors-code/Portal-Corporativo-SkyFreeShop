<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ImportSalesController;
use App\Http\Controllers\Api\CommissionController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\CommissionReportController;
use App\Http\Controllers\Api\CategoryCommissionController;
use App\Http\Controllers\Api\ImportBatchController;
use App\Http\Controllers\Api\BankImportBatchController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\BudgetController;
use App\Http\Controllers\SalesByUserController;
use App\Http\Controllers\Api\BudgetProgressController;
use App\Http\Controllers\Api\CommissionActionController;
use App\Http\Controllers\Api\MobileAuthController;
use App\Http\Controllers\Api\TurnsImportController;
use App\Http\Controllers\Api\WhatsappAutomationController;
use App\Http\Controllers\Api\WhatsappWebhookController;
use App\Http\Controllers\Api\AdvisorInfoController;
use App\Http\Controllers\Api\PassengerIntelligenceController;
use App\Http\Controllers\importAutomation;
use App\Http\Controllers\ApiInventarios\InventoryImportController;
use App\Http\Controllers\ApiInventarios\InventoryImportBatchController;
use App\Http\Controllers\ProductCatalogImportController;
use App\Http\Controllers\EntregaController;



Route::get('/test-api', function () {
    return response()->json([
        'ok' => true
    ]);
})->middleware('auth:sanctum');

$automationMiddleware = ['throttle:automation', 'automation.token'];

Route::post('/automation/import-sales', [ImportSalesController::class, 'importAutomation'])->middleware($automationMiddleware);
Route::post('/automation/import-sales/chunk', [ImportSalesController::class, 'importAutomationChunk'])->middleware($automationMiddleware);
Route::post('/automation/import-catalog', [ProductCatalogImportController::class, 'importAutomation'])->middleware($automationMiddleware);
Route::post('/automation/import-catalog/start', [ProductCatalogImportController::class, 'startAutomation'])->middleware($automationMiddleware);
Route::post('/automation/import-catalog/chunk', [ProductCatalogImportController::class, 'chunkAutomation'])->middleware($automationMiddleware);
Route::post('/automation/import-product-catalog', [ProductCatalogImportController::class, 'importAutomation'])->middleware($automationMiddleware);
Route::post('/automation/import-product-catalog/start', [ProductCatalogImportController::class, 'startAutomation'])->middleware($automationMiddleware);
Route::post('/automation/import-product-catalog/chunk', [ProductCatalogImportController::class, 'chunkAutomation'])->middleware($automationMiddleware);
Route::post('/v1/product-catalog/import-automation', [ProductCatalogImportController::class, 'importAutomation'])->middleware($automationMiddleware);
Route::post('/v1/product-catalog/import-automation/start', [ProductCatalogImportController::class, 'startAutomation'])->middleware($automationMiddleware);
Route::post('/v1/product-catalog/import-automation/chunk', [ProductCatalogImportController::class, 'chunkAutomation'])->middleware($automationMiddleware);
Route::post('/v1/inventory/import-automation', [InventoryImportController::class, 'importAutomation'])->middleware($automationMiddleware);
Route::get('/webhooks/whatsapp', [WhatsappWebhookController::class, 'verify'])->middleware('throttle:automation');
Route::post('/webhooks/whatsapp', [WhatsappWebhookController::class, 'receive'])->middleware('throttle:automation');
Route::get('/automation/whatsapp/jobs/next', [WhatsappAutomationController::class, 'next'])->middleware($automationMiddleware);
Route::post('/automation/whatsapp/jobs/{job}/complete', [WhatsappAutomationController::class, 'complete'])->middleware($automationMiddleware);
Route::get('/v1/ping', function () {
    return response()->json([
        'status' => 'ok',
        'message' => 'API funcionando correctamente',
        ]);
        });
        
        Route::prefix('v1')->group(function () {

    Route::prefix('mobile')->group(function () {
        Route::post('login', [MobileAuthController::class, 'login'])
            ->middleware('throttle:5,1');

        Route::middleware('auth:sanctum')->group(function () {
            Route::get('me', [MobileAuthController::class, 'me']);
            Route::post('logout', [MobileAuthController::class, 'logout']);
            Route::get('budgets', [BudgetController::class, 'index']);
            Route::get('budgets/active', [BudgetController::class, 'active']);
            Route::get('commissions/my', [CommissionReportController::class, 'myCommissions']);
            Route::get('commissions/my/export', [CommissionReportController::class, 'myExport']);
        });
    });

    Route::middleware('auth:sanctum')->group(function () {

    Route::middleware('permission:advisor-info.view')->group(function () {
        Route::get('advisor-info', [AdvisorInfoController::class, 'index']);
        Route::get('advisor-info/providers/{providerId}', [AdvisorInfoController::class, 'provider']);
        Route::get('advisor-info/files/{itemId}', [AdvisorInfoController::class, 'file']);
        Route::get('advisor-info/files/{itemId}/content', [AdvisorInfoController::class, 'content']);
    });

    Route::middleware('permission:passenger-intelligence.view')->group(function () {
        Route::get('passenger-intelligence/summary', [PassengerIntelligenceController::class, 'summary']);
        Route::get('passenger-intelligence/batches', [PassengerIntelligenceController::class, 'batches']);
        Route::get('passenger-intelligence/profiles', [PassengerIntelligenceController::class, 'profiles']);
        Route::get('passenger-intelligence/source-files', [PassengerIntelligenceController::class, 'sourceFiles']);
        Route::get('passenger-intelligence/monthly-facts', [PassengerIntelligenceController::class, 'monthlyFacts']);
        Route::get('passenger-intelligence/flight-estimates', [PassengerIntelligenceController::class, 'flightEstimates']);
        Route::get('passenger-intelligence/monthly-estimates', [PassengerIntelligenceController::class, 'monthlyEstimateAnalytics']);
        Route::get('passenger-intelligence/forecasts', [PassengerIntelligenceController::class, 'forecasts']);
        Route::get('passenger-intelligence/external-signals', [PassengerIntelligenceController::class, 'externalSignals']);
        Route::get('passenger-intelligence/external-signals/impact', [PassengerIntelligenceController::class, 'externalSignalImpact']);
        Route::get('passenger-intelligence/migration-microdata/audit', [PassengerIntelligenceController::class, 'migrationMicrodataAudit']);
    });

    Route::middleware('permission:passenger-intelligence.import')->group(function () {
        Route::post('passenger-intelligence/import', [PassengerIntelligenceController::class, 'import']);
        Route::post('passenger-intelligence/onedrive/import', [PassengerIntelligenceController::class, 'importOneDriveFile']);
        Route::post('passenger-intelligence/migration-microdata/import', [PassengerIntelligenceController::class, 'importMigrationMicrodata']);
    });

    Route::middleware('permission:passenger-intelligence.manage')->group(function () {
        Route::post('passenger-intelligence/profiles', [PassengerIntelligenceController::class, 'storeProfile']);
        Route::post('passenger-intelligence/sync-official-sources', [PassengerIntelligenceController::class, 'syncOfficialSources']);
        Route::post('passenger-intelligence/onedrive/sync-files', [PassengerIntelligenceController::class, 'syncOneDriveFiles']);
        Route::post('passenger-intelligence/recalculate-all', [PassengerIntelligenceController::class, 'recalculateAll']);
        Route::post('passenger-intelligence/exposure/recalculate', [PassengerIntelligenceController::class, 'recalculateExposure']);
        Route::post('passenger-intelligence/flight-estimates/recalculate', [PassengerIntelligenceController::class, 'recalculateFlightEstimates']);
    });

    Route::post('passenger-intelligence/forecasts/generate', [PassengerIntelligenceController::class, 'generateForecast'])
        ->middleware('permission:passenger-intelligence.manage,passenger-intelligence.forecast');

    Route::post('passenger-intelligence/external-signals/sync', [PassengerIntelligenceController::class, 'syncExternalSignals'])
        ->middleware('permission:passenger-intelligence.manage,passenger-intelligence.signals.manage');

    Route::middleware('permission:inventarios.importes')->group(function () {
        Route::get   ('inventory-imports',               [InventoryImportBatchController::class, 'index']);
        Route::get   ('inventory-imports/{id}',          [InventoryImportBatchController::class, 'show']);
        Route::post  ('inventory-imports/import',        [InventoryImportBatchController::class, 'import']);
        Route::post  ('inventory-imports/bulk-delete',   [InventoryImportBatchController::class, 'bulkDestroy']);
        Route::delete('inventory-imports/{id}',          [InventoryImportBatchController::class, 'destroy']);
    });

    Route::middleware('permission:entregas.view')->group(function () {
        Route::get('entregas/categorias', [EntregaController::class, 'categorias']);
        Route::get('entregas/lideres', [EntregaController::class, 'lideres']);
        Route::get('entregas/empleados', [EntregaController::class, 'empleados']);
        Route::get('entregas/dashboard', [EntregaController::class, 'dashboard']);
        Route::get('entregas/me', [EntregaController::class, 'empleadoActual']);
        Route::get('entregas/export/resumen', [EntregaController::class, 'exportarResumen']);
        Route::get('entregas', [EntregaController::class, 'index']);
        Route::get('entregas/{id}', [EntregaController::class, 'show']);
        Route::get('entregas/{id}/pdf', [EntregaController::class, 'descargarPdf']);
        Route::get('entregas/{id}/pdf-view', [EntregaController::class, 'verPdf']);
        Route::get('empleados/{id}/firma', [EntregaController::class, 'obtenerFirmaEmpleado']);
    });

    Route::middleware('permission:entregas.manage')->group(function () {
        Route::post('entregas', [EntregaController::class, 'store']);
        Route::put('entregas/{id}', [EntregaController::class, 'update']);
        Route::delete('entregas/{id}', [EntregaController::class, 'destroy']);
        Route::post('entregas/{id}/firmar', [EntregaController::class, 'firmar']);
        Route::post('entregas/{id}/cerrar', [EntregaController::class, 'cerrarActa']);
        Route::post('entregas/{id}/rechazar', [EntregaController::class, 'rechazar']);
        Route::patch('entregas/{id}/novedades/{novedadId}', [EntregaController::class, 'actualizarNovedad']);
        Route::post('entregas/{id}/novedades/{novedadId}/observacion', [EntregaController::class, 'agregarObservacionNovedad']);
        Route::patch('entregas/{id}/novedades/{novedadId}/resuelto', [EntregaController::class, 'actualizarEstadoNovedad']);
        Route::post('empleados/{id}/firma', [EntregaController::class, 'guardarFirmaEmpleado']);
    });
            
    // TURNOS (poner primero para evitar conflicto con imports/{id})
    Route::middleware('permission:imports.create')->group(function () {
        Route::post('import-turns', [TurnsImportController::class, 'import']);
        Route::get('imports/turns', [TurnsImportController::class, 'index']);
        Route::get('imports/turns/{id}', [TurnsImportController::class, 'show']);
    });

    Route::middleware('permission:imports.manage')->group(function () {
        Route::delete('imports/turns/{id}', [TurnsImportController::class, 'deleteBatch']);
        Route::delete('imports/turns', [TurnsImportController::class, 'bulkDelete']);
    });

            
            // USERS & ROLES
    Route::middleware('permission:users.manage')->group(function () {
        Route::get('users', [UserController::class, 'index']);
        Route::post('users/{id}/assign-role', [UserController::class, 'assignRole']);
        Route::get('roles', [RoleController::class, 'index']);
    });
    
    // IMPORTS (EXCEL)
    Route::middleware('permission:imports.create')->group(function () {
        Route::post('import-sales', [ImportSalesController::class, 'import']);
        Route::get('imports', [ImportBatchController::class, 'index']);
        Route::get('imports/{id}', [ImportBatchController::class, 'show']);
    });

    Route::middleware('permission:imports.manage')->group(function () {
        Route::delete('imports/{id}', [ImportBatchController::class, 'destroy']);
        Route::post('imports/bulk-delete', [ImportBatchController::class, 'bulkDestroy']);
    });

    Route::get('bank-imports', [BankImportBatchController::class, 'index'])
        ->middleware('permission:accounting.bank-imports.view,imports.create');
    Route::post('bank-imports/import', [BankImportBatchController::class, 'import'])
        ->middleware('permission:accounting.bank-imports.create,imports.create');
    Route::get('bank-imports/movements/audit', [BankImportBatchController::class, 'movementsAudit'])
        ->middleware('permission:accounting.bank-imports.view,imports.create');
    Route::get('bank-imports/movements/export', [BankImportBatchController::class, 'exportMovements'])
        ->middleware('permission:accounting.bank-imports.export,imports.manage');
    Route::get('bank-imports/movements', [BankImportBatchController::class, 'movements'])
        ->middleware('permission:accounting.bank-imports.view,imports.create');
    Route::get('bank-imports/{id}', [BankImportBatchController::class, 'show'])
        ->middleware('permission:accounting.bank-imports.view,imports.manage');
    Route::get('bank-imports/{id}/export', [BankImportBatchController::class, 'export'])
        ->middleware('permission:accounting.bank-imports.export,imports.manage');
    Route::get('bank-imports/{id}/export-davibank', [BankImportBatchController::class, 'exportDavibank'])
        ->middleware('permission:accounting.bank-imports.export,imports.manage');
    Route::delete('bank-imports/{id}', [BankImportBatchController::class, 'destroy'])
        ->middleware('permission:accounting.bank-imports.manage,imports.manage');
    Route::post('bank-imports/bulk-delete', [BankImportBatchController::class, 'bulkDestroy'])
        ->middleware('permission:accounting.bank-imports.manage,imports.manage');

    
    // SALES
    Route::get('sales/users', [SalesByUserController::class, 'getUsersWithSales']);
    Route::get('sales/by-user', [SalesByUserController::class, 'getSalesByUser']);
    
    // COMMISSIONS – LOGIC
    Route::get('commissions/summary', [CommissionController::class,'userSummary'])
        ->middleware('permission:budget.commissions.view');
    Route::middleware('permission:budget.commissions.manage')->group(function () {
        Route::post('commissions/recalc-sale/{id}', [CommissionController::class,'recalcSale']);
        Route::post('commissions/recalc-user/{userId}/{month}', [CommissionController::class,'recalcUserMonth']);
        Route::post('commissions/finalize', [CommissionController::class,'finalize']);
    });
    
    // COMMISSIONS – REPORTS (👈 TU CONTROLADOR)
    
    Route::get('/commissions/by-seller', [CommissionReportController::class, 'bySeller'])
        ->middleware('permission:budget.commissions.view');
    Route::get('/commissions/by-seller/{userId}', [CommissionReportController::class, 'bySellerDetail'])
        ->middleware('permission:budget.commissions.view,commissions.user.view');
    Route::middleware('permission:budget.commissions.manage')->group(function () {
        Route::put('commissions/assign-turns/{userId}/{budget_id}', [CommissionReportController::class, 'assignTurns']);
        Route::post('commissions/assign-turns/{userId}/{budget_id}', [CommissionReportController::class, 'assignTurns']);
    });
    

    // REPORTS Cajeros
    Route::get('reports/cashier-awards', [ReportController::class, 'cashierAwards'])
        ->middleware('permission:budget.cashier.view');
    Route::get('reports/cashier/{userId}/categories', [ReportController::class, 'cashierCategories'])
        ->middleware('permission:budget.cashier.view');
    Route::post('/cashier-adjustments', [ReportController::class,'storeCashierAdjustment'])
        ->middleware('permission:budget.cashier.manage');

    // COMMISSION CONFIG
    Route::get('commissions/categories', [CategoryCommissionController::class, 'index'])
        ->middleware('permission:budget.commissions.view');
    Route::get('commissions/category-commissions/overrides', [AdvisorController::class, 'getCommissionOverrides'])
        ->middleware('permission:budget.commissions.view');
    Route::post('commissions/category-commissions/overrides', [AdvisorController::class, 'saveCommissionOverrides'])
        ->middleware('permission:budget.commissions.manage');
    Route::middleware('permission:budget.commissions.manage')->group(function () {
        Route::post('commissions/categories', [CategoryCommissionController::class, 'upsert']);
        Route::delete('commissions/categories/{id}', [CategoryCommissionController::class, 'destroy']);
        Route::post('commissions/categories/bulk', [CategoryCommissionController::class, 'bulkUpdate']);
    });
    
    Route::middleware('permission:budget.commissions.manage')->group(function () {
        Route::post('/commissions/generate', [CommissionController::class, 'generate']);
        Route::post('/commissions/rectify-sales-roles', [CommissionController::class, 'rectifySalesRoles']);
    });

    // INVENTORY IMPORT 
Route::middleware('permission:inventarios.importes')->group(function () {
    Route::post('/inventory/import', [InventoryImportController::class, 'import']);
    Route::get('/inventory/stores', [InventoryImportController::class, 'stores']);
    Route::delete('/inventory/batches/{batchId}', [InventoryImportController::class, 'deleteBatch']);
});

Route::middleware('permission:inventarios.cobertura')->group(function () {
    Route::get('/inventory/export', [\App\Http\Controllers\ApiInventarios\InventoryController::class, 'export']);
});
    
Route::patch(
    '/budgets/{id}/cashier-prizes',
    [BudgetController::class, 'updateCashierPrizes']
)->middleware('permission:budget.cashier.manage');

    // Budget 
        
    Route::get('/budgets', [BudgetController::class, 'index'])
        ->middleware('permission:budget.view');
    Route::get('/budgets/active', [BudgetController::class, 'active'])
        ->middleware('permission:budget.view');
    Route::middleware('permission:budget.manage')->group(function () {
        Route::post('/budgets', [BudgetController::class, 'store']);
        Route::put('/budgets/{id}', [BudgetController::class, 'update']);
        Route::delete('/budgets/{id}', [BudgetController::class, 'destroy']);
    });
    Route::patch('/budgets/{id}/cashier-prize', [BudgetController::class, 'updateCashierPrize'])
        ->middleware('permission:budget.cashier.manage');


    // EXCEL EXPORT ROUTE


    Route::get(
        '/commissions/export',
        [CommissionReportController::class, 'exportExcel']
    )->middleware('permission:budget.commissions.view');

    // Exportar premios de cajeros
    Route::get(
    '/reports/cashier-awards/export',
    [ReportController::class, 'cashierAwardsExport']
)->middleware('permission:budget.cashier.view');

    // Exportar detalle de comisiones por vendedor
    Route::get(
    '/commissions/by-seller/{userId}/export',
    [CommissionReportController::class, 'exportSellerDetail']
)->middleware('permission:budget.commissions.view');

    });
});
