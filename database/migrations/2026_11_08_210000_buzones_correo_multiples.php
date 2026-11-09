<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cuentas_correo', function (Blueprint $table) {
            $table->string('direccion_visible')->nullable()->after('buzon_canonico');
            $table->boolean('recibe_radicacion_web')->default(false)->after('direccion_visible');
            $table->string('verificacion_buzon', 40)->default('pendiente')->after('recibe_radicacion_web');
        });

        $direccion = 'callcenter@menteplena.com.co';
        $existe = DB::table('cuentas_correo')->whereRaw('LOWER(direccion) = ?', [$direccion])->exists();
        if ($existe) {
            return;
        }

        $ahora = now();
        DB::table('cuentas_correo')->insert([
            'direccion' => $direccion,
            'descripcion' => 'Usuario de Google Workspace confirmado por Admin. Permanece inactivo hasta OAuth y activación explícita.',
            'activo' => false,
            'es_buzon_central' => false,
            'tipo_buzon' => 'desconocido',
            'direccion_visible' => $direccion,
            'recibe_radicacion_web' => true,
            'verificacion_buzon' => 'pendiente',
            'tipo_integracion' => 'google_api',
            'estado_conexion' => 'pendiente_credenciales',
            'estado_autorizacion' => 'pendiente',
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ]);
    }

    public function down(): void
    {
        Schema::table('cuentas_correo', function (Blueprint $table) {
            $table->dropColumn(['direccion_visible', 'recibe_radicacion_web', 'verificacion_buzon']);
        });
    }
};
