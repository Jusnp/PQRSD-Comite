<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cuentas_correo', function (Blueprint $table) {
            $table->id();
            $table->string('direccion')->unique();
            $table->string('descripcion')->nullable();
            $table->foreignId('aseguradora_id')->nullable()->constrained('aseguradoras')->nullOnDelete();
            $table->foreignId('sede_id')->nullable()->constrained('sedes')->nullOnDelete();
            $table->string('area_responsable')->nullable();
            $table->boolean('activo')->default(true);
            $table->boolean('es_buzon_central')->default(false);
            $table->enum('tipo_integracion', [
                'mock',
                'imap',
                'microsoft_graph',
                'google_api',
                'central_forward',
            ])->default('mock');
            $table->string('credential_ref')->nullable()->comment('Nombre de variable de entorno, nunca el secreto');
            $table->string('host')->nullable();
            $table->unsignedSmallInteger('puerto')->nullable();
            $table->string('cifrado', 20)->nullable();
            $table->json('config_extra')->nullable();
            $table->enum('estado_conexion', [
                'desconocido',
                'ok',
                'error',
                'pendiente_credenciales',
            ])->default('desconocido');
            $table->dateTime('ultima_sincronizacion')->nullable();
            $table->text('ultimo_error')->nullable();
            $table->unsignedInteger('intentos_fallidos')->default(0);
            $table->string('cursor_sincronizacion')->nullable();
            $table->timestamps();
        });

        Schema::create('mensajes_correo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cuenta_correo_id')->constrained('cuentas_correo')->cascadeOnDelete();
            $table->foreignId('pqrsd_id')->nullable()->constrained('pqrsd')->nullOnDelete();
            $table->string('message_id')->nullable();
            $table->string('in_reply_to')->nullable();
            $table->text('references_header')->nullable();
            $table->string('thread_key', 191)->nullable()->index();
            $table->string('remitente');
            $table->string('remitente_nombre')->nullable();
            $table->string('destinatario')->nullable();
            $table->text('destinatarios_cc')->nullable();
            $table->string('destinatario_original')->nullable()->comment('Delivered-To / X-Original-To');
            $table->string('asunto')->nullable();
            $table->longText('cuerpo_texto')->nullable();
            $table->longText('cuerpo_html')->nullable();
            $table->longText('raw_headers')->nullable();
            $table->longText('raw_eml_path')->nullable();
            $table->dateTime('fecha_mensaje');
            $table->dateTime('fecha_importacion');
            $table->enum('direccion', ['entrante', 'saliente'])->default('entrante');
            $table->enum('clasificacion', [
                'pendiente_revision',
                'nueva_pqrsd',
                'respuesta_caso',
                'descartado',
                'ruido',
            ])->default('pendiente_revision');
            $table->enum('estado_procesamiento', [
                'recibido',
                'procesado',
                'error',
                'reintento',
                'ignorado_duplicado',
            ])->default('recibido');
            $table->string('radicado_detectado', 30)->nullable();
            $table->text('motivo_clasificacion')->nullable();
            $table->text('error_procesamiento')->nullable();
            $table->unsignedTinyInteger('intentos')->default(0);
            $table->dateTime('proximo_reintento')->nullable();
            $table->foreignId('revisado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('revisado_en')->nullable();
            $table->json('metadatos')->nullable();
            $table->timestamps();

            $table->unique(['cuenta_correo_id', 'message_id']);
            $table->index(['clasificacion', 'estado_procesamiento']);
            $table->index('fecha_mensaje');
        });

        Schema::create('adjuntos_correo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mensaje_correo_id')->constrained('mensajes_correo')->cascadeOnDelete();
            $table->string('nombre_original');
            $table->string('ruta');
            $table->string('mime', 120)->nullable();
            $table->unsignedBigInteger('tamano')->default(0);
            $table->string('checksum', 64)->nullable();
            $table->timestamps();
        });

        Schema::create('sincronizaciones_correo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cuenta_correo_id')->constrained('cuentas_correo')->cascadeOnDelete();
            $table->enum('resultado', ['ok', 'error', 'parcial'])->default('ok');
            $table->unsignedInteger('mensajes_nuevos')->default(0);
            $table->unsignedInteger('mensajes_duplicados')->default(0);
            $table->unsignedInteger('mensajes_error')->default(0);
            $table->text('detalle')->nullable();
            $table->dateTime('iniciada_en');
            $table->dateTime('finalizada_en')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sincronizaciones_correo');
        Schema::dropIfExists('adjuntos_correo');
        Schema::dropIfExists('mensajes_correo');
        Schema::dropIfExists('cuentas_correo');
    }
};
