<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('budget')->table('budgets', function (Blueprint $table) {
            if (! Schema::connection('budget')->hasColumn('budgets', 'closure_review_until')) {
                $table->date('closure_review_until')->nullable()->after('closed_at');
            }

            if (! Schema::connection('budget')->hasColumn('budgets', 'closure_snapshot_created_at')) {
                $table->dateTime('closure_snapshot_created_at')->nullable()->after('closure_review_until');
            }
        });
    }

    public function down(): void
    {
        Schema::connection('budget')->table('budgets', function (Blueprint $table) {
            if (Schema::connection('budget')->hasColumn('budgets', 'closure_snapshot_created_at')) {
                $table->dropColumn('closure_snapshot_created_at');
            }

            if (Schema::connection('budget')->hasColumn('budgets', 'closure_review_until')) {
                $table->dropColumn('closure_review_until');
            }
        });
    }
};
