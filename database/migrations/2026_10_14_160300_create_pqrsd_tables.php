<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pqrsd', function (Blueprint $table) {
            $table->id();
            $table->string('radicado', 30)->unique();
            $table->dateTime('fecha_recepcion');
            $table->dateTime('fecha_radicacion');
            $table->foreignId('canal_ingreso_id')->constrained('canales_ingreso');
            $table->foreignId('tipo_pqrsd_id')->nullable()->constrained('tipos_pqrsd')->nullOnDelete();
            $table->foreignId('motivo_pqrsd_id')->nullable()->constrained('motivos_pqrsd')->nullOnDelete();
            $table->enum('nivel_riesgo', [
                'pendiente',
                'vital_menor',
                'vital_general',
                'priorizado',
                'simple',
                'ordinario',
            ])->default('pendiente');
            $table->foreignId('regla_sla_id')->nullable()->constrained('reglas_sla')->nullOnDelete();
            $table->unsignedInteger('regla_sla_version')->nullable();
            $table->dateTime('fecha_inicio_conteo')->nullable();
            $table->dateTime('fecha_limite')->nullable();
            $table->enum('estado_operativo', [
                'recibido',
                'pendiente_clasificacion',
                'radicado',
                'asignado',
                'en_gestion',
                'pendiente_informacion',
                'en_revision',
                'respuesta_aprobada',
                'respondido',
                'cerrado',
                'reabierto',
            ])->default('radicado');
            $table->enum('estado_cumplimiento', [
                'dentro_plazo',
                'proximo_vencer',
                'vencido',
                'resuelto_oportuno',
                'resuelto_extemporaneo',
                'sin_clasificar',
            ])->default('sin_clasificar');
            $table->boolean('es_anonimo')->default(false);
            $table->string('solicitante_nombre')->nullable();
            $table->string('solicitante_tipo_documento', 10)->nullable();
            $table->string('solicitante_documento', 30)->nullable();
            $table->string('solicitante_email')->nullable();
            $table->string('solicitante_telefono', 30)->nullable();
            $table->string('paciente_nombre')->nullable();
            $table->string('paciente_tipo_documento', 10)->nullable();
            $table->string('paciente_documento', 30)->nullable();
            $table->date('paciente_fecha_nacimiento')->nullable();
            $table->unsignedTinyInteger('paciente_edad')->nullable();
            $table->string('representante_nombre')->nullable();
            $table->foreignId('aseguradora_id')->nullable()->constrained('aseguradoras')->nullOnDelete();
            $table->foreignId('sede_id')->nullable()->constrained('sedes')->nullOnDelete();
            $table->string('servicio_area')->nullable();
            $table->text('descripcion');
            $table->text('observaciones_internas')->nullable();
            $table->foreignId('responsable_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('radicado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('fecha_cierre')->nullable();
            $table->dateTime('fecha_respuesta')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['estado_operativo', 'estado_cumplimiento']);
            $table->index(['fecha_limite']);
            $table->index(['nivel_riesgo']);
        });

        Schema::create('asignaciones_pqrsd', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pqrsd_id')->constrained('pqrsd')->cascadeOnDelete();
            $table->foreignId('asignado_a')->constrained('users')->cascadeOnDelete();
            $table->foreignId('asignado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->string('motivo')->nullable();
            $table->dateTime('asignado_en');
            $table->dateTime('finalizado_en')->nullable();
            $table->boolean('activa')->default(true);
            $table->timestamps();
        });

        Schema::create('actuaciones_pqrsd', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pqrsd_id')->constrained('pqrsd')->cascadeOnDelete();
            $table->foreignId('usuario_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('tipo', 50);
            $table->string('titulo');
            $table->text('detalle')->nullable();
            $table->json('datos_adicionales')->nullable();
            $table->boolean('es_interna')->default(true);
            $table->timestamps();

            $table->index(['pqrsd_id', 'created_at']);
        });

        Schema::create('historial_estados_pqrsd', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pqrsd_id')->constrained('pqrsd')->cascadeOnDelete();
            $table->foreignId('usuario_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('campo', 50);
            $table->string('valor_anterior')->nullable();
            $table->string('valor_nuevo')->nullable();
            $table->string('motivo')->nullable();
            $table->timestamps();
        });

        Schema::create('adjuntos_pqrsd', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pqrsd_id')->constrained('pqrsd')->cascadeOnDelete();
            $table->foreignId('subido_por')->nullable()->constrained('users')->nullOnDelete();
            $table->string('nombre_original');
            $table->string('ruta');
            $table->string('mime', 100)->nullable();
            $table->unsignedBigInteger('tamano')->default(0);
            $table->string('tipo', 40)->default('soporte');
            $table->timestamps();
        });

        Schema::create('auditoria_pqrsd', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pqrsd_id')->nullable()->constrained('pqrsd')->nullOnDelete();
            $table->foreignId('usuario_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('accion', 80);
            $table->string('entidad', 80)->nullable();
            $table->unsignedBigInteger('entidad_id')->nullable();
            $table->json('antes')->nullable();
            $table->json('despues')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamps();

            $table->index(['entidad', 'entidad_id']);
        });

        Schema::create('secuencias_radicado', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('anio')->unique();
            $table->unsignedInteger('ultimo_numero')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('secuencias_radicado');
        Schema::dropIfExists('auditoria_pqrsd');
        Schema::dropIfExists('adjuntos_pqrsd');
        Schema::dropIfExists('historial_estados_pqrsd');
        Schema::dropIfExists('actuaciones_pqrsd');
        Schema::dropIfExists('asignaciones_pqrsd');
        Schema::dropIfExists('pqrsd');
    }
};
