<?php

namespace Tests\Feature;

use App\Models\CanalIngreso;
use App\Models\TipoPqrsd;
use App\Models\User;
use App\Services\PqrsdService;
use Carbon\Carbon;
use Database\Seeders\CatalogosSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\UsersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CorreccionUiBandejaExpedienteRespuestasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(CatalogosSeeder::class);
        $this->seed(UsersSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-10-19 12:00:00', 'America/Bogota'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_bandeja_prioriza_columnas_y_mantiene_ver_expediente(): void
    {
        $admin = User::where('email', 'admin@menteplena.test')->firstOrFail();
        $aux = User::where('email', 'auxiliar1@menteplena.test')->firstOrFail();
        $caso = $this->crearCaso($admin);

        $html = $this->actingAs($aux)->get(route('pqrsd.index'))->assertOk()->getContent();

        $this->assertStringContainsString('bandeja-table', $html);
        $this->assertStringContainsString('btn-ver-expediente', $html);
        $this->assertStringContainsString('Ver expediente', $html);
        $this->assertStringContainsString($caso->radicado, $html);
        $this->assertStringContainsString('Tipo / motivo', $html);
        $this->assertStringContainsString('Última gestión', $html);
        $this->assertStringContainsString('col-accion', $html);
        $this->assertStringContainsString('bandeja-table-wrap', $html);
        $this->assertStringContainsString('bandeja-scroll-hint', $html);
    }

    public function test_expediente_alineacion_secciones_y_estados_respuesta_claros(): void
    {
        $admin = User::where('email', 'admin@menteplena.test')->firstOrFail();
        $aux = User::where('email', 'auxiliar1@menteplena.test')->firstOrFail();
        $caso = $this->crearCaso($admin);

        $html = $this->actingAs($aux)->get(route('pqrsd.show', $caso))->assertOk()->getContent();

        foreach ([
            'Información de la PQRSD',
            'Clasificación',
            'Respuesta de PQRS',
            'Estado y finalización',
        ] as $titulo) {
            $this->assertStringContainsString('exp-section-title">'.$titulo, $html);
        }
        $this->assertStringContainsString('Historial automático', $html);

        $this->assertStringContainsString('exp-section-meta', $html);
        $this->assertStringContainsString('exp-chips', $html);
        $this->assertStringContainsString('Fecha de elaboración de respuesta', $html);
        $this->assertStringContainsString('Respuesta elaborada', $html);
        $this->assertStringContainsString('Disponible en consulta segura', $html);
        $this->assertStringContainsString('Registrar envío realizado por otro medio', $html);
        $this->assertStringContainsString('Guardar respuesta', $html);
        $this->assertStringNotContainsString('Enviado por Gmail', $html);
        $this->assertStringNotContainsString('Guardar respuesta de PQRS', $html);
    }

    public function test_guardar_elaborada_no_alimenta_oportunidad_hasta_comunicar(): void
    {
        $admin = User::where('email', 'admin@menteplena.test')->firstOrFail();
        $aux = User::where('email', 'auxiliar1@menteplena.test')->firstOrFail();
        $caso = $this->crearCaso($admin);
        $cumplimientoAntes = $caso->estado_cumplimiento;

        $this->actingAs($aux)->post(route('pqrsd.responder', $caso), [
            'detalle_respuesta' => 'Respuesta elaborada de prueba para distinguir guardado de envío.',
            'respuesta_estado_destino' => 'elaborada',
            'expediente_version' => $caso->versionToken(),
        ])->assertRedirect();

        $caso->refresh();
        $this->assertSame('elaborada', $caso->respuesta_estado);
        $this->assertNotNull($caso->respuesta_elaborada_en);
        $this->assertNull($caso->fecha_respuesta);
        $this->assertNull($caso->respuesta_enviada_en);
        $this->assertNull($caso->respuesta_puesta_disposicion_en);
        $this->assertSame($cumplimientoAntes, $caso->estado_cumplimiento);
        $this->assertNotSame('respondido', $caso->estado_operativo);
        $this->assertFalse($caso->puedeFinalizarse());

        $html = $this->actingAs($aux)->get(route('pqrsd.show', $caso))->assertOk()->getContent();
        $this->assertStringContainsString('exp-section-meta muted">Respuesta elaborada', $html);
        $this->assertStringContainsString('Fecha de elaboración', $html);

        Carbon::setTestNow(Carbon::parse('2026-10-19 11:30:00', 'America/Bogota'));

        $this->actingAs($aux)->post(route('pqrsd.responder', $caso->fresh()), [
            'detalle_respuesta' => 'Respuesta elaborada de prueba para distinguir guardado de envío.',
            'respuesta_estado_destino' => 'puesta_disposicion',
            'poner_disposicion_consulta' => '1',
            'expediente_version' => $caso->fresh()->versionToken(),
        ])->assertRedirect();

        $caso->refresh();
        $this->assertSame('puesta_disposicion', $caso->respuesta_estado);
        $this->assertNotNull($caso->respuesta_puesta_disposicion_en);
        $this->assertNull($caso->respuesta_enviada_en);
        $this->assertNotNull($caso->fecha_respuesta);
        $this->assertSame('respondido', $caso->estado_operativo);
        $this->assertSame('resuelto_oportuno', $caso->estado_cumplimiento);
        $this->assertTrue($caso->puedeFinalizarse());
    }

    private function crearCaso(User $usuario)
    {
        return app(PqrsdService::class)->radicarManual([
            'fecha_recepcion' => Carbon::parse('2026-10-18 09:00:00', 'America/Bogota'),
            'canal_ingreso_id' => CanalIngreso::where('codigo', 'MANUAL')->value('id'),
            'tipo_pqrsd_id' => TipoPqrsd::where('codigo', 'QUEJA')->value('id'),
            'nivel_riesgo' => 'ordinario',
            'descripcion' => 'Caso ficticio para corrección UI bandeja y respuestas',
            'solicitante_nombre' => 'Ciudadano UI Demo',
        ], $usuario);
    }
}
