<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_report_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('type', 80);
            $table->string('status', 30)->default('pending');
            $table->date('report_date')->nullable();
            $table->json('payload')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('available_at')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'available_at']);
            $table->index(['type', 'report_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_report_jobs');
    }
};
