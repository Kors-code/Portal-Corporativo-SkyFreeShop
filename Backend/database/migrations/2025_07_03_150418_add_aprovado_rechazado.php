<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('candidatos', function (Blueprint $table) {
            $table->enum('estado', ['aprobado', 'rechazado'])->nullable()->after('email'); // o 'nombre', o el campo que quieras
        });
    }

    public function down(): void
    {
        Schema::table('candidatos', function (Blueprint $table) {
            $table->dropColumn('estado');
        });
    }
};
