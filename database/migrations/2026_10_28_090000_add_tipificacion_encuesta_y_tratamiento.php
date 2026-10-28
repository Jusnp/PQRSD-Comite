<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categorias_pqrsd', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 40)->unique();
            $table->string('nombre');
            $table->text('descripcion')->nullable();
            $table->unsignedInteger('orden')->default(0);
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::create('subcategorias_pqrsd', function (Blueprint $table) {
            $table->id();
            $table->foreignId('categoria_pqrsd_id')->constrained('categorias_pqrsd')->cascadeOnDelete();
            $table->string('codigo', 40);
            $table->string('nombre');
            $table->text('descripcion')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->unique(['categoria_pqrsd_id', 'codigo']);
        });

        Schema::table('motivos_pqrsd', function (Blueprint $table) {
            $table->foreignId('subcategoria_sugerida_id')
                ->nullable()
                ->after('tipo_pqrsd_id')
                ->constrained('subcategorias_pqrsd')
                ->nullOnDelete();
        });

        Schema::table('pqrsd', function (Blueprint $table) {
            $table->foreignId('categoria_pqrsd_id')->nullable()->after('motivo_pqrsd_id')->constrained('categorias_pqrsd')->nullOnDelete();
            $table->foreignId('subcategoria_pqrsd_id')->nullable()->after('categoria_pqrsd_id')->constrained('subcategorias_pqrsd')->nullOnDelete();
            $table->boolean('ciudadano_no_clasifica')->default(false)->after('nivel_riesgo');
            $table->boolean('alerta_vital_preventiva')->default(false)->after('ciudadano_no_clasifica');
            $table->dateTime('tratamiento_aceptado_en')->nullable()->after('acepto_tratamiento_datos');
            $table->string('tratamiento_politica_version', 80)->nullable()->after('tratamiento_aceptado_en');

            $table->index('solicitante_documento');
            $table->index('paciente_documento');
            $table->index('ciudadano_no_clasifica');
            $table->index('alerta_vital_preventiva');
        });

        Schema::create('encuestas_satisfaccion_pqrsd', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pqrsd_id')->unique()->constrained('pqrsd')->cascadeOnDelete();
            $table->unsignedTinyInteger('puntuacion');
            $table->string('atendida', 20);
            $table->text('comentario')->nullable();
            $table->dateTime('respondida_en');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('encuestas_satisfaccion_pqrsd');

        Schema::table('pqrsd', function (Blueprint $table) {
            $table->dropIndex(['solicitante_documento']);
            $table->dropIndex(['paciente_documento']);
            $table->dropIndex(['ciudadano_no_clasifica']);
            $table->dropIndex(['alerta_vital_preventiva']);
            $table->dropConstrainedForeignId('subcategoria_pqrsd_id');
            $table->dropConstrainedForeignId('categoria_pqrsd_id');
            $table->dropColumn([
                'ciudadano_no_clasifica',
                'alerta_vital_preventiva',
                'tratamiento_aceptado_en',
                'tratamiento_politica_version',
            ]);
        });

        Schema::table('motivos_pqrsd', function (Blueprint $table) {
            $table->dropConstrainedForeignId('subcategoria_sugerida_id');
        });

        Schema::dropIfExists('subcategorias_pqrsd');
        Schema::dropIfExists('categorias_pqrsd');
    }
};
