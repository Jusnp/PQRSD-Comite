<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notificaciones_pqrsd', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pqrsd_id')->constrained('pqrsd')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('tipo', 60);
            $table->string('canal', 30)->default('sistema');
            $table->string('destinatario')->nullable();
            $table->string('asunto')->nullable();
            $table->text('mensaje');
            $table->enum('estado', ['pendiente', 'enviada', 'fallida', 'omitida'])->default('pendiente');
            $table->text('error')->nullable();
            $table->string('umbral_clave', 80)->nullable()->comment('Evita recordatorios duplicados por umbral');
            $table->json('metadatos')->nullable();
            $table->dateTime('programada_para')->nullable();
            $table->dateTime('enviada_en')->nullable();
            $table->timestamps();

            $table->index(['pqrsd_id', 'tipo']);
            $table->unique(['pqrsd_id', 'tipo', 'umbral_clave'], 'notif_pqrsd_unique_umbral');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notificaciones_pqrsd');
    }
};
