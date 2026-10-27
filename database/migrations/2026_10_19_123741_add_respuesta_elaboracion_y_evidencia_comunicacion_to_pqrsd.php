<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pqrsd', function (Blueprint $table) {
            $table->timestamp('respuesta_elaborada_en')->nullable()->after('respuesta_estado');
            $table->string('respuesta_canal_comunicacion', 80)->nullable()->after('respuesta_enviada_en');
            $table->text('respuesta_evidencia_comunicacion')->nullable()->after('respuesta_canal_comunicacion');
        });
    }

    public function down(): void
    {
        Schema::table('pqrsd', function (Blueprint $table) {
            $table->dropColumn([
                'respuesta_elaborada_en',
                'respuesta_canal_comunicacion',
                'respuesta_evidencia_comunicacion',
            ]);
        });
    }
};
