<?php

namespace Tests\Feature;

use App\Models\Aseguradora;
use App\Models\CanalIngreso;
use App\Models\Sede;
use App\Models\TipoPqrsd;
use App\Models\User;
use App\Services\PqrsdService;
use Carbon\Carbon;
use Database\Seeders\CatalogosSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\UsersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpedienteInterfazOperativaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(CatalogosSeeder::class);
        $this->seed(UsersSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-10-14 12:00:00', 'America/Bogota'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_expediente_pendiente_muestra_resumen_alertas_y_secciones(): void
    {
        $admin = User::where('email', 'admin@menteplena.test')->firstOrFail();
        $aux = User::where('email', 'auxiliar1@menteplena.test')->firstOrFail();

        $caso = app(PqrsdService::class)->radicarManual([
            'fecha_recepcion' => Carbon::parse('2026-10-14 08:00:00', 'America/Bogota'),
            'canal_ingreso_id' => CanalIngreso::where('codigo', 'WEB')->value('id')
                ?? CanalIngreso::where('codigo', 'MANUAL')->value('id'),
            'tipo_pqrsd_id' => TipoPqrsd::where('codigo', 'QUEJA')->value('id'),
            'nivel_riesgo' => 'pendiente',
            'descripcion' => 'Caso ficticio para interfaz de expediente operativa',
            'solicitante_nombre' => 'Solicitante UI',
            'sede_id' => Sede::where('activo', true)->value('id'),
            'aseguradora_id' => Aseguradora::where('activo', true)->value('id'),
        ], $admin);

        $html = $this->actingAs($aux)
            ->get(route('pqrsd.show', $caso))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('exp-summary', $html);
        $this->assertStringContainsString('Pendiente de evaluación', $html);
        $this->assertStringContainsString('id="sec-personas"', $html);
        $this->assertStringContainsString('id="sec-clasificacion"', $html);
        $this->assertStringContainsString('id="sec-respuesta"', $html);
        $this->assertStringContainsString('id="sec-cierre"', $html);
        $this->assertStringContainsString('Información de la PQRSD', $html);
        $this->assertStringContainsString('Clasificación', $html);
        $this->assertStringContainsString('Clasificar riesgo', $html);
        $this->assertStringContainsString('Respuesta de PQRS', $html);
        $this->assertStringContainsString('Estado y finalización', $html);
        $this->assertStringContainsString('En trámite — pendiente de respuesta', $html);
        $this->assertStringContainsString('Historial automático', $html);
        $this->assertStringContainsString('PQRSD registrada', $html);
        $this->assertStringContainsString('Adjuntar soporte a la respuesta', $html);
        $this->assertStringNotContainsString('id="sec-actuaciones"', $html);
        $this->assertStringNotContainsString('id="sec-remisiones"', $html);
        $this->assertStringNotContainsString('Registrar gestión', $html);
        $this->assertStringNotContainsString('Asunto de la gestión', $html);
        $this->assertStringNotContainsString('Solicitar información a otra área', $html);
        $this->assertStringNotContainsString('Guardar gestión', $html);
        $this->assertStringNotContainsString('Asignar / reasignar responsable', $html);
        $this->assertStringNotContainsString('Guardar asignación', $html);
        $this->assertStringContainsString('href="#sec-clasificacion"', $html);
        $this->assertStringNotContainsString('Nueva PQRSD', $html);
    }

    public function test_expediente_cerrado_oculta_acciones_de_edicion(): void
    {
        $admin = User::where('email', 'admin@menteplena.test')->firstOrFail();
        $aux = User::where('email', 'auxiliar1@menteplena.test')->firstOrFail();

        $caso = app(PqrsdService::class)->radicarManual([
            'fecha_recepcion' => Carbon::parse('2026-10-10 09:00:00', 'America/Bogota'),
            'canal_ingreso_id' => CanalIngreso::where('codigo', 'MANUAL')->value('id'),
            'tipo_pqrsd_id' => TipoPqrsd::where('codigo', 'QUEJA')->value('id'),
            'nivel_riesgo' => 'simple',
            'descripcion' => 'Caso cerrado para comprobar solo lectura en interfaz',
            'solicitante_nombre' => 'Cerrado UI',
            'sede_id' => Sede::where('activo', true)->value('id'),
            'aseguradora_id' => Aseguradora::where('activo', true)->value('id'),
        ], $admin);

        app(\App\Services\VencimientoService::class)->registrarRespuesta(
            $caso->fresh(),
            $admin,
            Carbon::parse('2026-10-11 10:00:00', 'America/Bogota')
        );
        app(\App\Services\VencimientoService::class)->cerrar(
            $caso->fresh(),
            $admin,
            'Cierre ficticio para prueba de interfaz de expediente.'
        );

        $html = $this->actingAs($aux)
            ->get(route('pqrsd.show', $caso->fresh()))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('PQRSD finalizada', $html);
        $this->assertStringContainsString('Estado: Finalizada', $html);
        $this->assertStringNotContainsString('class="exp-actions"', $html);
        $this->assertStringNotContainsString('Aplicar clasificación', $html);
        $this->assertStringNotContainsString('Guardar respuesta</button>', $html);
        $this->assertStringNotContainsString('Finalizar PQRSD</button>', $html);
        $this->assertStringNotContainsString('Registrar gestión', $html);
        $this->assertStringNotContainsString('Solicitar información', $html);
        $this->assertStringContainsString('Estado y finalización', $html);
        $this->assertStringContainsString('Historial automático', $html);
    }
}
