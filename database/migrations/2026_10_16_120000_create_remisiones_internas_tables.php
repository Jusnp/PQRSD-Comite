<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('areas_institucionales', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 40)->unique();
            $table->string('nombre');
            $table->unsignedSmallInteger('orden')->default(100);
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::create('remisiones_internas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pqrsd_id')->constrained('pqrsd')->cascadeOnDelete();
            $table->foreignId('area_institucional_id')->constrained('areas_institucionales')->restrictOnDelete();
            $table->string('motivo');
            $table->text('informacion_solicitada');
            $table->foreignId('responsable_seguimiento_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('fecha_limite_interna')->nullable();
            $table->text('observaciones')->nullable();
            $table->string('estado', 40)->default('preparada');
            $table->string('medio_comunicacion', 80)->nullable();
            $table->text('evidencia_envio_detalle')->nullable();
            $table->dateTime('fecha_remision')->nullable();
            $table->dateTime('fecha_respuesta')->nullable();
            $table->string('persona_responde')->nullable();
            $table->text('contenido_respuesta')->nullable();
            $table->text('observaciones_respuesta')->nullable();
            $table->foreignId('creada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('finalizada_en')->nullable();
            $table->timestamps();

            $table->index(['pqrsd_id', 'estado']);
            $table->index(['area_institucional_id', 'estado']);
        });

        Schema::table('adjuntos_pqrsd', function (Blueprint $table) {
            $table->foreignId('remision_interna_id')
                ->nullable()
                ->after('pqrsd_id')
                ->constrained('remisiones_internas')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('adjuntos_pqrsd', function (Blueprint $table) {
            $table->dropConstrainedForeignId('remision_interna_id');
        });
        Schema::dropIfExists('remisiones_internas');
        Schema::dropIfExists('areas_institucionales');
    }
};
