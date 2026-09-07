<?php

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$request = Illuminate\Http\Request::create('/api/v1/visualizaciones/store-sales-whatsapp-preview', 'GET');
$controller = app(App\Http\Controllers\Api\VisualizationController::class);
$report = $controller->storeSalesReportData($request);
$bytes = app(App\Services\StoreSalesWhatsappImageService::class)->make($report);

echo json_encode([
    'date' => $report['date'] ?? null,
    'month_sales_usd' => $report['month_sales_usd'] ?? null,
    'png_bytes' => strlen($bytes),
], JSON_PRETTY_PRINT) . PHP_EOL;
