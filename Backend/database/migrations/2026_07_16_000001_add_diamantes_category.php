<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'budget';

    public function up(): void
    {
        $existing = DB::connection('budget')
            ->table('categories')
            ->where('classification_code', 'diamantes')
            ->exists();

        if ($existing) {
            DB::connection('budget')
                ->table('categories')
                ->where('classification_code', 'diamantes')
                ->update([
                    'name' => 'DIAMANTES',
                    'description' => 'DIAMANTES',
                    'updated_at' => now(),
                ]);

            return;
        }

        DB::connection('budget')->table('categories')->insert([
            'classification_code' => 'diamantes',
            'name' => 'DIAMANTES',
            'description' => 'DIAMANTES',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        $category = DB::connection('budget')
            ->table('categories')
            ->where('classification_code', 'diamantes')
            ->first();

        if (!$category) {
            return;
        }

        $hasCommissions = DB::connection('budget')
            ->table('category_commissions')
            ->where('category_id', $category->id)
            ->exists();

        if (!$hasCommissions) {
            DB::connection('budget')
                ->table('categories')
                ->where('id', $category->id)
                ->delete();
        }
    }
};
