<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('budget');

        if (!$schema->hasTable('bank_movements')) {
            return;
        }

        $schema->table('bank_movements', function (Blueprint $table) use ($schema) {
            if (!$schema->hasColumn('bank_movements', 'movement_uid')) {
                $table->string('movement_uid', 64)->nullable()->after('source_type');
            }
        });

        $schema->table('bank_movements', function (Blueprint $table) {
            $table->unique(['bank', 'movement_uid'], 'bank_movements_bank_uid_unique');
        });
    }

    public function down(): void
    {
        $schema = Schema::connection('budget');

        if (!$schema->hasTable('bank_movements')) {
            return;
        }

        $schema->table('bank_movements', function (Blueprint $table) use ($schema) {
            $table->dropUnique('bank_movements_bank_uid_unique');

            if ($schema->hasColumn('bank_movements', 'movement_uid')) {
                $table->dropColumn('movement_uid');
            }
        });
    }
};
