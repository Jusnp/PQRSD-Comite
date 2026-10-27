<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pqrsd', function (Blueprint $table) {
            $table->string('medio_respuesta', 40)->nullable()->after('origen_publico');
            $table->string('solicitante_direccion', 500)->nullable()->after('solicitante_telefono');
            $table->boolean('consentimiento_comunicacion_email')->default(false)->after('acepto_tratamiento_datos');
            $table->boolean('anonimo_pendiente_validacion_juridica')->default(false)->after('es_anonimo');
            $table->string('respuesta_estado', 40)->nullable()->after('fecha_respuesta');
            $table->text('respuesta_consulta_texto')->nullable()->after('respuesta_estado');
            $table->timestamp('respuesta_puesta_disposicion_en')->nullable()->after('respuesta_consulta_texto');
            $table->timestamp('respuesta_enviada_en')->nullable()->after('respuesta_puesta_disposicion_en');
            $table->timestamp('respuesta_consultada_en')->nullable()->after('respuesta_enviada_en');
        });
    }

    public function down(): void
    {
        Schema::table('pqrsd', function (Blueprint $table) {
            $table->dropColumn([
                'medio_respuesta',
                'solicitante_direccion',
                'consentimiento_comunicacion_email',
                'anonimo_pendiente_validacion_juridica',
                'respuesta_estado',
                'respuesta_consulta_texto',
                'respuesta_puesta_disposicion_en',
                'respuesta_enviada_en',
                'respuesta_consultada_en',
            ]);
        });
    }
};
