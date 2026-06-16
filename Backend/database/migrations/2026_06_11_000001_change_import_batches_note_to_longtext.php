<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::connection('budget')->hasTable('import_batches')) {
            return;
        }

        DB::connection('budget')->statement('ALTER TABLE import_batches MODIFY note LONGTEXT NULL');
    }

    public function down(): void
    {
        if (!Schema::connection('budget')->hasTable('import_batches')) {
            return;
        }

        DB::connection('budget')->statement('ALTER TABLE import_batches MODIFY note TEXT NULL');
    }
};
