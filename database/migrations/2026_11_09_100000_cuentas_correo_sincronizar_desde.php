<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cuentas_correo', function (Blueprint $table) {
            $table->dateTime('sincronizar_desde')->nullable()->after('verificacion_buzon');
        });
    }

    public function down(): void
    {
        Schema::table('cuentas_correo', function (Blueprint $table) {
            $table->dropColumn('sincronizar_desde');
        });
    }
};
