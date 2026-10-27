<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cuentas_correo', function (Blueprint $table) {
            $table->string('responsable_nombre')->nullable()->after('area_responsable');
            $table->string('estado_autorizacion', 40)->default('pendiente')->after('estado_conexion')
                ->comment('pendiente|autorizado|rechazado|no_aplica');
            $table->dateTime('fecha_ultima_validacion_inventario')->nullable()->after('watch_expira_en');
            $table->text('observaciones_inventario')->nullable()->after('fecha_ultima_validacion_inventario');
        });
    }

    public function down(): void
    {
        Schema::table('cuentas_correo', function (Blueprint $table) {
            $table->dropColumn([
                'responsable_nombre',
                'estado_autorizacion',
                'fecha_ultima_validacion_inventario',
                'observaciones_inventario',
            ]);
        });
    }
};
