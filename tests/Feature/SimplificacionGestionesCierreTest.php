<?php

namespace Tests\Feature;

use App\Models\ActuacionPqrsd;
use App\Models\AdjuntoPqrsd;
use App\Models\CanalIngreso;
use App\Models\TipoPqrsd;
use App\Models\User;
use App\Services\PqrsdService;
use App\Services\VencimientoService;
use Carbon\Carbon;
use Database\Seeders\CatalogosSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\UsersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SimplificacionGestionesCierreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(CatalogosSeeder::class);
        $this->seed(UsersSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-10-19 11:00:00', 'America/Bogota'));
        Storage::fake(config('pqrsd_correo.adjuntos_disk', 'local'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_gestion_manual_deshabilitada_y_soporte_desde_respuesta(): void
    {
        $admin = User::where('email', 'admin@menteplena.test')->firstOrFail();
        $aux1 = User::where('email', 'auxiliar1@menteplena.test')->firstOrFail();
        $aux2 = User::where('email', 'auxiliar2@menteplena.test')->firstOrFail();
        $caso = $this->crearCaso($admin);

        $this->actingAs($aux1)->post(route('pqrsd.actuaciones.store', $caso), [
            'titulo' => 'Llamada de seguimiento',
            'detalle' => 'Se contactó al solicitante para ampliar información del caso.',
            'es_interna' => 1,
        ])->assertForbidden();

        $this->assertFalse(
            ActuacionPqrsd::query()
                ->where('pqrsd_id', $caso->id)
                ->where('tipo', 'actuacion_operativa')
                ->exists()
        );

        $pdf = UploadedFile::fake()->createWithContent('soporte-demo.pdf', "%PDF-1.4\ngestion-demo\n");
        $this->actingAs($aux2)->post(route('pqrsd.adjuntos.store', $caso), [
            'adjuntos' => [$pdf],
        ])->assertRedirect();

        $this->assertTrue(
            AdjuntoPqrsd::query()->where('pqrsd_id', $caso->id)->where('nombre_original', 'soporte-demo.pdf')->exists()
        );

        foreach ([$aux1, $aux2, $admin] as $operador) {
            $html = $this->actingAs($operador)
                ->get(route('pqrsd.show', $caso->fresh()))
                ->assertOk()
                ->getContent();

            $this->assertStringContainsString('Respuesta de PQRS', $html);
            $this->assertStringContainsString('Estado y finalización', $html);
            $this->assertStringContainsString('Historial automático', $html);
            $this->assertStringContainsString('PQRSD registrada', $html);
            $this->assertStringContainsString('soporte-demo.pdf', $html);
            $this->assertStringContainsString('En trámite — pendiente de respuesta', $html);
            $this->assertStringNotContainsString('Registrar gestión', $html);
            $this->assertStringNotContainsString('Gestiones y soportes', $html);
            $this->assertStringNotContainsString('Finalizar PQRSD</button>', $html);
            $this->assertStringNotContainsString('Actuaciones y documentos', $html);
        }

        $this->get(route('publico.formulario'))->assertOk();
        $this->actingAs($admin)->get(route('reportes.indicadores'))->assertOk()->assertSee('95%', false);
    }

    public function test_no_finaliza_sin_respuesta_y_cerrado_queda_protegido(): void
    {
        $admin = User::where('email', 'admin@menteplena.test')->firstOrFail();
        $aux = User::where('email', 'auxiliar1@menteplena.test')->firstOrFail();
        $caso = $this->crearCaso($admin);

        $this->actingAs($aux)->post(route('pqrsd.cerrar', $caso), [
            'motivo_cierre' => 'Intento de cierre sin respuesta oficial registrada.',
            'expediente_version' => $caso->versionToken(),
        ])->assertSessionHasErrors('motivo_cierre');

        $this->assertNotSame('cerrado', $caso->fresh()->estado_operativo);

        app(VencimientoService::class)->registrarRespuesta(
            $caso->fresh(),
            $aux,
            Carbon::parse('2026-10-19 12:00:00', 'America/Bogota')
        );

        $fechaRespuesta = $caso->fresh()->fecha_respuesta?->toDateTimeString();
        $cumplimiento = $caso->fresh()->estado_cumplimiento;

        $htmlAntes = $this->actingAs($aux)->get(route('pqrsd.show', $caso->fresh()))->assertOk()->getContent();
        $this->assertStringContainsString('Finalizar PQRSD', $htmlAntes);
        $this->assertStringContainsString('Lista para finalizar', $htmlAntes);

        $this->actingAs($aux)->post(route('pqrsd.cerrar', $caso->fresh()), [
            'motivo_cierre' => 'Gestión concluida tras respuesta oficial al ciudadano.',
            'expediente_version' => $caso->fresh()->versionToken(),
        ])->assertRedirect();

        $cerrado = $caso->fresh();
        $this->assertSame('cerrado', $cerrado->estado_operativo);
        $this->assertSame($fechaRespuesta, $cerrado->fecha_respuesta?->toDateTimeString());
        $this->assertSame($cumplimiento, $cerrado->estado_cumplimiento);

        $this->actingAs($aux)->post(route('pqrsd.actuaciones.store', $cerrado), [
            'titulo' => 'No debe permitirse',
            'detalle' => 'Intento de gestión sobre expediente finalizado.',
        ])->assertForbidden();

        $html = $this->actingAs($admin)->get(route('pqrsd.show', $cerrado))->assertOk()->getContent();
        $this->assertStringContainsString('PQRSD finalizada', $html);
        $this->assertStringContainsString('Ver detalles de auditoría', $html);
        $this->assertStringNotContainsString('Guardar gestión', $html);
    }

    private function crearCaso(User $usuario)
    {
        return app(PqrsdService::class)->radicarManual([
            'fecha_recepcion' => Carbon::parse('2026-10-18 09:00:00', 'America/Bogota'),
            'canal_ingreso_id' => CanalIngreso::where('codigo', 'MANUAL')->value('id'),
            'tipo_pqrsd_id' => TipoPqrsd::where('codigo', 'QUEJA')->value('id'),
            'nivel_riesgo' => 'ordinario',
            'descripcion' => 'Caso ficticio para simplificación de gestiones y cierre',
            'solicitante_nombre' => 'Ciudadano Demo Gestiones',
            'es_anonimo' => 0,
        ], $usuario);
    }
}
