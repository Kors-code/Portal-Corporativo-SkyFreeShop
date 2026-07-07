<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addIndexIfPossible('products', 'idx_products_product_code', ['product_code']);
        $this->addIndexIfPossible('products', 'idx_products_upc', ['upc']);
    }

    public function down(): void
    {
        $this->dropIndexIfExists('products', 'idx_products_product_code');
        $this->dropIndexIfExists('products', 'idx_products_upc');
    }

    private function addIndexIfPossible(string $table, string $index, array $columns): void
    {
        if (!Schema::connection('budget')->hasTable($table) || $this->indexExists($table, $index)) {
            return;
        }

        foreach ($columns as $column) {
            if (!Schema::connection('budget')->hasColumn($table, $column)) {
                return;
            }
        }

        $columnsSql = implode(',', array_map(fn ($column) => "`{$column}`", $columns));
        DB::connection('budget')->statement("ALTER TABLE `{$table}` ADD INDEX `{$index}` ({$columnsSql})");
    }

    private function dropIndexIfExists(string $table, string $index): void
    {
        if (!Schema::connection('budget')->hasTable($table) || !$this->indexExists($table, $index)) {
            return;
        }

        DB::connection('budget')->statement("ALTER TABLE `{$table}` DROP INDEX `{$index}`");
    }

    private function indexExists(string $table, string $index): bool
    {
        $database = DB::connection('budget')->getDatabaseName();

        return DB::connection('budget')
            ->table('information_schema.statistics')
            ->where('table_schema', $database)
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->exists();
    }
};
