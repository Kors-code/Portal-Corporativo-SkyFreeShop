<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidatos', function (Blueprint $table) {
            $table->timestamp('gmail_seen_at')->nullable()->index();
            $table->longText('cv_summary')->nullable();
            $table->json('vacancy_suggestions')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('candidatos', function (Blueprint $table) {
            $table->dropColumn(['gmail_seen_at', 'cv_summary', 'vacancy_suggestions']);
        });
    }
};
