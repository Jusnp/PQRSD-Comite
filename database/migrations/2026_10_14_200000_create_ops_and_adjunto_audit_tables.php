<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('descargas_adjunto_pqrsd', function (Blueprint $table) {
            $table->id();
            $table->foreignId('adjunto_pqrsd_id')->constrained('adjuntos_pqrsd')->cascadeOnDelete();
            $table->foreignId('pqrsd_id')->constrained('pqrsd')->cascadeOnDelete();
            $table->foreignId('usuario_id')->constrained('users')->cascadeOnDelete();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamps();

            $table->index(['adjunto_pqrsd_id', 'created_at']);
        });

        Schema::create('ejecuciones_programadas', function (Blueprint $table) {
            $table->id();
            $table->string('comando', 120);
            $table->string('estado', 20);
            $table->unsignedInteger('procesados')->default(0);
            $table->text('detalle')->nullable();
            $table->timestamp('iniciada_en');
            $table->timestamp('finalizada_en')->nullable();
            $table->timestamps();

            $table->index(['comando', 'iniciada_en']);
        });

        Schema::create('respaldos_pqrsd', function (Blueprint $table) {
            $table->id();
            $table->string('tipo', 40);
            $table->string('ruta');
            $table->string('checksum_sha256', 64)->nullable();
            $table->unsignedBigInteger('tamano')->default(0);
            $table->string('estado', 20);
            $table->text('detalle')->nullable();
            $table->foreignId('usuario_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('iniciada_en');
            $table->timestamp('finalizada_en')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('respaldos_pqrsd');
        Schema::dropIfExists('ejecuciones_programadas');
        Schema::dropIfExists('descargas_adjunto_pqrsd');
    }
};
