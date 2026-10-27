<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cuentas_correo', function (Blueprint $table) {
            $table->string('tipo_buzon', 40)->default('desconocido')->after('es_buzon_central')
                ->comment('individual|alias|grupo|reenvio|desconocido — confirmar con Workspace Admin');
            $table->string('buzon_canonico')->nullable()->after('tipo_buzon')
                ->comment('Dirección real a sincronizar si esta fila es alias/reenvío');
            $table->dateTime('watch_expira_en')->nullable()->after('cursor_sincronizacion');
            $table->string('ultimo_history_id')->nullable()->after('watch_expira_en');
        });

        Schema::create('gmail_oauth_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cuenta_correo_id')->unique()->constrained('cuentas_correo')->cascadeOnDelete();
            $table->text('payload_encrypted');
            $table->string('scopes')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->string('token_type', 40)->default('Bearer');
            $table->timestamps();
        });

        Schema::create('gmail_pubsub_events', function (Blueprint $table) {
            $table->id();
            $table->string('message_id')->nullable()->index();
            $table->foreignId('cuenta_correo_id')->nullable()->constrained('cuentas_correo')->nullOnDelete();
            $table->string('email_address')->nullable()->index();
            $table->string('history_id')->nullable();
            $table->string('estado', 30)->default('recibido');
            $table->text('detalle')->nullable();
            $table->timestamps();

            $table->unique(['message_id']);
        });

        Schema::table('respaldos_pqrsd', function (Blueprint $table) {
            $table->boolean('cifrado')->default(false)->after('checksum_sha256');
            $table->string('destino_externo')->nullable()->after('cifrado');
            $table->string('checksum_remoto', 64)->nullable()->after('destino_externo');
            $table->string('estado_transferencia', 30)->nullable()->after('checksum_remoto');
        });
    }

    public function down(): void
    {
        Schema::table('respaldos_pqrsd', function (Blueprint $table) {
            $table->dropColumn(['cifrado', 'destino_externo', 'checksum_remoto', 'estado_transferencia']);
        });
        Schema::dropIfExists('gmail_pubsub_events');
        Schema::dropIfExists('gmail_oauth_tokens');
        Schema::table('cuentas_correo', function (Blueprint $table) {
            $table->dropColumn(['tipo_buzon', 'buzon_canonico', 'watch_expira_en', 'ultimo_history_id']);
        });
    }
};
