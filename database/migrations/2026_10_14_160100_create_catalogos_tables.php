<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sedes', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 20)->unique();
            $table->string('nombre');
            $table->string('ciudad')->nullable();
            $table->string('direccion')->nullable();
            $table->string('telefono', 30)->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::create('aseguradoras', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 30)->unique();
            $table->string('nombre');
            $table->string('nit', 20)->nullable();
            $table->enum('tipo', ['eps', 'arl', 'poliza', 'otra'])->default('eps');
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::create('canales_ingreso', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 40)->unique();
            $table->string('nombre');
            $table->text('descripcion')->nullable();
            $table->boolean('requiere_digitalizacion')->default(false);
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::create('tipos_pqrsd', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 30)->unique();
            $table->string('nombre');
            $table->text('descripcion')->nullable();
            $table->unsignedInteger('orden')->default(0);
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::create('motivos_pqrsd', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tipo_pqrsd_id')->constrained('tipos_pqrsd')->cascadeOnDelete();
            $table->string('codigo', 40);
            $table->string('nombre');
            $table->text('descripcion')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->unique(['tipo_pqrsd_id', 'codigo']);
        });

        Schema::create('festivos', function (Blueprint $table) {
            $table->id();
            $table->date('fecha')->unique();
            $table->string('nombre');
            $table->string('ambito', 30)->default('nacional');
            $table->timestamps();
        });

        Schema::create('configuraciones', function (Blueprint $table) {
            $table->id();
            $table->string('clave')->unique();
            $table->text('valor')->nullable();
            $table->string('descripcion')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('configuraciones');
        Schema::dropIfExists('festivos');
        Schema::dropIfExists('motivos_pqrsd');
        Schema::dropIfExists('tipos_pqrsd');
        Schema::dropIfExists('canales_ingreso');
        Schema::dropIfExists('aseguradoras');
        Schema::dropIfExists('sedes');
    }
};
