<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private string $connection = 'mysql_personal';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        if ($schema->hasTable('empleados') && !$schema->hasColumn('empleados', 'firma_personal')) {
            $schema->table('empleados', function (Blueprint $table) {
                $table->longText('firma_personal')
                    ->nullable()
                    ->after('deleted_at')
                    ->comment('Firma personal reutilizable en base64 o SVG');
            });
        }

        if (!$schema->hasTable('entregas')) {
            $schema->create('entregas', function (Blueprint $table) {
                $table->id();
                $table->string('codigo_acta', 50)->nullable()->unique();
                $table->string('nombre_acta')->nullable();
                $table->foreignId('lider_entrega_id')->constrained('empleados')->cascadeOnDelete();
                $table->foreignId('lider_recibe_id')->constrained('empleados')->cascadeOnDelete();
                $table->enum('turno', ['mañana', 'tarde', 'noche'])->default('mañana');
                $table->date('fecha_acta');
                $table->string('sede', 100)->nullable();
                $table->enum('estado', ['abierta', 'entregada', 'recibida', 'completada', 'rechazada'])->default('abierta')->index();
                $table->timestamp('fecha_entrega')->nullable();
                $table->timestamp('fecha_recepcion')->nullable();
                $table->text('observaciones')->nullable();
                $table->text('razon_rechazo')->nullable();
                $table->string('pdf_path', 500)->nullable();
                $table->boolean('correo_enviado')->default(false);
                $table->timestamps();
                $table->softDeletes();
                $table->index('fecha_acta');
            });
        }

        if (!$schema->hasTable('novedades')) {
            $schema->create('novedades', function (Blueprint $table) {
                $table->id();
                $table->foreignId('entrega_id')->constrained('entregas')->cascadeOnDelete();
                $table->enum('categoria', [
                    'precios_promociones',
                    'logistica',
                    'cajas',
                    'personal',
                    'otros_temas',
                    'temas_pendientes',
                ])->index();
                $table->string('titulo')->nullable();
                $table->text('descripcion');
                $table->enum('prioridad', ['baja', 'media', 'alta', 'urgente'])->default('media');
                $table->boolean('requiere_seguimiento')->default(false);
                $table->boolean('resuelto')->default(false)->index();
                $table->text('observaciones_receptor')->nullable();
                $table->integer('orden')->default(0);
                $table->timestamps();
            });
        } elseif (!$schema->hasColumn('novedades', 'resuelto')) {
            $schema->table('novedades', function (Blueprint $table) {
                $table->boolean('resuelto')->default(false)->after('requiere_seguimiento')->index();
            });
        }

        if (!$schema->hasTable('firmas_digitales')) {
            $schema->create('firmas_digitales', function (Blueprint $table) {
                $table->id();
                $table->foreignId('entrega_id')->constrained('entregas')->cascadeOnDelete();
                $table->foreignId('empleado_id')->constrained('empleados')->cascadeOnDelete();
                $table->enum('tipo_firma', ['entrega', 'recepcion']);
                $table->longText('firma_data');
                $table->enum('formato', ['svg', 'png', 'base64'])->default('base64');
                $table->string('ip_address', 45)->nullable();
                $table->string('user_agent', 500)->nullable();
                $table->timestamp('fecha_firma')->useCurrent();
                $table->timestamps();
                $table->unique(['entrega_id', 'tipo_firma'], 'firmas_entrega_tipo_unique');
            });
        }

        if (!$schema->hasTable('entrega_log')) {
            $schema->create('entrega_log', function (Blueprint $table) {
                $table->id();
                $table->foreignId('entrega_id')->constrained('entregas')->cascadeOnDelete();
                $table->foreignId('empleado_id')->nullable()->constrained('empleados')->nullOnDelete();
                $table->string('accion', 100);
                $table->text('detalles')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->timestamp('created_at')->nullable()->useCurrent();
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);

        $schema->dropIfExists('entrega_log');
        $schema->dropIfExists('firmas_digitales');
        $schema->dropIfExists('novedades');
        $schema->dropIfExists('entregas');

        if ($schema->hasTable('empleados') && $schema->hasColumn('empleados', 'firma_personal')) {
            $schema->table('empleados', function (Blueprint $table) {
                $table->dropColumn('firma_personal');
            });
        }
    }
};
