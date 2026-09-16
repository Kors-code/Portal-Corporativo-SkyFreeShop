<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidatos', function (Blueprint $table) {
            $table->string('source_channel')->nullable();
            $table->string('source_email_message_id')->nullable();
            $table->string('source_email_from')->nullable();
            $table->text('source_email_subject')->nullable();
            $table->timestamp('source_email_received_at')->nullable();
            $table->string('routing_method')->nullable();

            $table->index(['source_channel', 'created_at'], 'candidatos_source_channel_created_at_idx');
            $table->index('source_email_from', 'candidatos_source_email_from_idx');
        });
    }

    public function down(): void
    {
        Schema::table('candidatos', function (Blueprint $table) {
            $table->dropIndex('candidatos_source_channel_created_at_idx');
            $table->dropIndex('candidatos_source_email_from_idx');
            $table->dropColumn([
                'source_channel',
                'source_email_message_id',
                'source_email_from',
                'source_email_subject',
                'source_email_received_at',
                'routing_method',
            ]);
        });
    }
};
