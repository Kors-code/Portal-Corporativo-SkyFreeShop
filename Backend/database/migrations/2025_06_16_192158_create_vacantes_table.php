<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
Schema::create('vacantes', function (Blueprint $table) {
    $table->id();
    $table->string('titulo');
    $table->text('descripcion')->nullable();
    $table->text('palabras_clave'); // ejemplo: "Laravel, PHP, 3 años, Git"
    $table->timestamps();
});

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vacantes');
    }
};
