<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::connection('budget')->hasTable('sales')) {
            return;
        }

        DB::connection('budget')->statement('CREATE TABLE IF NOT EXISTS `sales_import_staging` LIKE `sales`');

        if (Schema::connection('budget')->hasTable('import_batches')) {
            Schema::connection('budget')->table('import_batches', function (Blueprint $table) {
                if (!Schema::connection('budget')->hasColumn('import_batches', 'replace_existing')) {
                    $table->boolean('replace_existing')->default(false)->after('status')->index();
                }

                if (!Schema::connection('budget')->hasColumn('import_batches', 'source_checksum')) {
                    $table->string('source_checksum', 128)->nullable()->after('checksum')->index();
                }

                if (!Schema::connection('budget')->hasColumn('import_batches', 'published_at')) {
                    $table->timestamp('published_at')->nullable()->after('import_date')->index();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection('budget')->hasTable('import_batches')) {
            Schema::connection('budget')->table('import_batches', function (Blueprint $table) {
                if (Schema::connection('budget')->hasColumn('import_batches', 'replace_existing')) {
                    $table->dropIndex(['replace_existing']);
                    $table->dropColumn('replace_existing');
                }

                if (Schema::connection('budget')->hasColumn('import_batches', 'source_checksum')) {
                    $table->dropIndex(['source_checksum']);
                    $table->dropColumn('source_checksum');
                }

                if (Schema::connection('budget')->hasColumn('import_batches', 'published_at')) {
                    $table->dropIndex(['published_at']);
                    $table->dropColumn('published_at');
                }
            });
        }

        Schema::connection('budget')->dropIfExists('sales_import_staging');
    }
};
