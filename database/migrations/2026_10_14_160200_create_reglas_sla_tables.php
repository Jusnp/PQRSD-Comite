<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reglas_sla', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 50)->unique();
            $table->string('nombre');
            $table->text('descripcion')->nullable();
            $table->enum('nivel_riesgo', [
                'vital_menor',
                'vital_general',
                'priorizado',
                'simple',
                'ordinario',
            ]);
            $table->unsignedInteger('cantidad');
            $table->enum('unidad', ['horas_calendario', 'dias_calendario', 'dias_habiles']);
            $table->unsignedInteger('prioridad')->default(100);
            $table->boolean('activo')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('vigente_desde')->nullable();
            $table->timestamp('vigente_hasta')->nullable();
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('versiones_reglas_sla', function (Blueprint $table) {
            $table->id();
            $table->foreignId('regla_sla_id')->constrained('reglas_sla')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('snapshot');
            $table->foreignId('modificado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->string('motivo')->nullable();
            $table->timestamps();

            $table->unique(['regla_sla_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('versiones_reglas_sla');
        Schema::dropIfExists('reglas_sla');
    }
};
