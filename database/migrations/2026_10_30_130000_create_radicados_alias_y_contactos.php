<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('radicados_alias')) {
            Schema::create('radicados_alias', function (Blueprint $table) {
                $table->id();
                $table->foreignId('pqrsd_id')->constrained('pqrsd')->cascadeOnDelete();
                $table->string('radicado', 30)->unique();
                $table->string('motivo', 80);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('contactos_regularizacion_radicado')) {
            Schema::create('contactos_regularizacion_radicado', function (Blueprint $table) {
                $table->id();
                $table->foreignId('pqrsd_id')->constrained('pqrsd')->cascadeOnDelete();
                $table->foreignId('usuario_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('medio', 20);
                $table->string('resultado', 40);
                $table->timestamp('fecha_contacto')->nullable();
                $table->string('observacion', 500)->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('contactos_regularizacion_radicado');
        Schema::dropIfExists('radicados_alias');
    }
};
