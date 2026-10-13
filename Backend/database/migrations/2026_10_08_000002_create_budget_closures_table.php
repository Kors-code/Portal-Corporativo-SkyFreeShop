<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('budget')->create('budget_closures', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('budget_id');
            $table->string('status', 40)->default('closed');
            $table->string('triggered_by', 40)->default('manual');
            $table->dateTime('closed_at')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->date('review_until')->nullable();
            $table->json('summary_json')->nullable();
            $table->json('error_json')->nullable();
            $table->timestamps();

            $table->index(['budget_id', 'status']);
            $table->index('closed_at');
        });
    }

    public function down(): void
    {
        Schema::connection('budget')->dropIfExists('budget_closures');
    }
};
