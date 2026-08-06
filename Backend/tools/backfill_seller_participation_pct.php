<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$db = Illuminate\Support\Facades\DB::connection('budget');

$updatedRole1 = $db->update("
    UPDATE category_commissions cc
    JOIN budgets b ON b.id = cc.budget_id
    LEFT JOIN (
        SELECT
            cc2.budget_id,
            SUM(COALESCE(cc2.participation_value, 0)) AS discount_amount
        FROM category_commissions cc2
        JOIN categories c ON c.id = cc2.category_id
        WHERE cc2.role_id = 1
          AND c.classification_code = '25'
        GROUP BY cc2.budget_id
    ) d ON d.budget_id = cc.budget_id
    SET cc.particiaption_pct_sellers = CASE
        WHEN (COALESCE(b.target_amount, 0) - COALESCE(d.discount_amount, 0)) > 0
        THEN ROUND(
            (COALESCE(cc.participation_value, 0) /
            (COALESCE(b.target_amount, 0) - COALESCE(d.discount_amount, 0))) * 100,
            9
        )
        ELSE 0
    END
    WHERE cc.role_id = 1
");

$updatedOthers = $db->table('category_commissions')
    ->where('role_id', '<>', 1)
    ->update(['particiaption_pct_sellers' => 0]);

$updatedAdvisorCategories = $db->table('category_commissions as cc')
    ->join('categories as c', 'c.id', '=', 'cc.category_id')
    ->where('cc.role_id', 1)
    ->where('c.classification_code', '25')
    ->update(['cc.particiaption_pct_sellers' => 0]);

$sample = $db->table('category_commissions')
    ->select('id', 'category_id', 'role_id', 'budget_id', 'participation_value', 'participation_pct', 'particiaption_pct_sellers')
    ->where('role_id', 1)
    ->orderByDesc('budget_id')
    ->limit(10)
    ->get()
    ->toArray();

print json_encode([
    'updated_role_1' => $updatedRole1,
    'updated_other_roles' => $updatedOthers,
    'updated_advisor_categories' => $updatedAdvisorCategories,
    'sample' => $sample,
], JSON_PRETTY_PRINT) . PHP_EOL;
