<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pqrsd_idempotencia_publica', function (Blueprint $table) {
            $table->id();
            $table->uuid('clave')->unique();
            $table->char('huella', 64);
            $table->foreignId('pqrsd_id')->nullable()->constrained('pqrsd')->nullOnDelete();
            $table->text('token_cifrado');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pqrsd_idempotencia_publica');
    }
};
