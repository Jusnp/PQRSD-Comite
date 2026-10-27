<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pqrsd', function (Blueprint $table) {
            $table->string('consulta_token_hash', 64)->nullable()->after('radicado');
            $table->boolean('acepto_tratamiento_datos')->default(false)->after('es_anonimo');
            $table->string('origen_publico', 40)->nullable()->after('acepto_tratamiento_datos');
            $table->index('consulta_token_hash');
        });
    }

    public function down(): void
    {
        Schema::table('pqrsd', function (Blueprint $table) {
            $table->dropIndex(['consulta_token_hash']);
            $table->dropColumn(['consulta_token_hash', 'acepto_tratamiento_datos', 'origen_publico']);
        });
    }
};
