<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mensajes_correo', function (Blueprint $table) {
            $table->string('gmail_message_id')->nullable()->after('message_id');
            $table->string('gmail_thread_id')->nullable()->after('gmail_message_id');
            $table->unique(['cuenta_correo_id', 'gmail_message_id'], 'mensajes_correo_cuenta_gmail_unique');
        });

        Schema::create('correo_salidas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pqrsd_id')->nullable()->constrained('pqrsd')->nullOnDelete();
            $table->foreignId('cuenta_correo_id')->nullable()->constrained('cuentas_correo')->nullOnDelete();
            $table->string('tipo', 40);
            $table->string('estado', 40)->default('pendiente');
            $table->string('idempotency_key')->unique();
            $table->json('payload');
            $table->string('rfc_message_id')->nullable();
            $table->string('gmail_message_id')->nullable();
            $table->string('gmail_thread_id')->nullable();
            $table->boolean('proveedor_invocado')->default(false);
            $table->unsignedTinyInteger('intentos')->default(0);
            $table->text('ultimo_error')->nullable();
            $table->dateTime('enviado_en')->nullable();
            $table->timestamps();

            $table->index(['estado', 'tipo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('correo_salidas');
        Schema::table('mensajes_correo', function (Blueprint $table) {
            $table->dropUnique('mensajes_correo_cuenta_gmail_unique');
            $table->dropColumn(['gmail_message_id', 'gmail_thread_id']);
        });
    }
};
