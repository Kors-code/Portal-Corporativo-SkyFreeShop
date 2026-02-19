<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */public function up()
{
    Schema::table('candidatos', function (Illuminate\Database\Schema\Blueprint $table) {
        $table->longText('cv_text')->nullable();
    });
}

public function down()
{
    Schema::table('candidatos', function (Illuminate\Database\Schema\Blueprint $table) {
        $table->dropColumn('cv_text');
    });
}
};
