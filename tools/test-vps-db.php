<?php

require __DIR__ . '/../Backend/vendor/autoload.php';

$app = require __DIR__ . '/../Backend/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$connections = ['mysql', 'budget', 'mysql_personal'];

foreach ($connections as $name) {
    try {
        DB::connection($name)->getPdo();
        $database = DB::connection($name)->getDatabaseName();
        echo "{$name}: OK ({$database})\n";
    } catch (Throwable $e) {
        echo "{$name}: FAIL";
        if (getenv('LOCAL_DB_VERBOSE_ERRORS') === '1') {
            echo ' - ' . $e->getMessage();
        }
        echo "\n";
    }
}
