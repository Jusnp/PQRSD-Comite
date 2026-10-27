<?php

namespace Tests\Feature;

use App\Models\AreaInstitucional;
use App\Models\Pqrsd;
use App\Models\RemisionInterna;
use App\Models\TipoPqrsd;
use App\Models\User;
use App\Services\RemisionInternaService;
use Database\Seeders\CatalogosSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\UsersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Remisiones: mutaciones operativas deshabilitadas; consulta histórica autorizada.
 */
class RemisionesInternasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(CatalogosSeeder::class);
        $this->seed(UsersSeeder::class);
    }

    public function test_mutaciones_de_remision_quedan_deshabilitadas(): void
    {
        $tipo = TipoPqrsd::where('codigo', 'QUEJA')->firstOrFail();
        $this->post(route('publico.store'), [
            'idempotency_key' => (string) Str::uuid(),
            'tipo_pqrsd_id' => $tipo->id,
            'quien_radica' => 'paciente',
            'asunto' => 'Queja por atención de Psicología',
            'descripcion' => 'Paciente ficticio presenta queja relacionada con demora en atención de Psicología.',
            'solicitante_nombre' => 'Paciente Ficticio Remisión',
            'solicitante_tipo_documento' => 'CC',
            'solicitante_documento' => '100200300',
            'solicitante_email' => 'paciente.remision@example.test',
            'medio_respuesta' => 'consulta_segura',
            'acepto_tratamiento_datos' => '1',
            'consentimiento_comunicacion_email' => '1',
        ])->assertRedirect(route('publico.confirmacion'));

        $caso = Pqrsd::latest('id')->firstOrFail();
        $aux1 = User::where('email', 'auxiliar1@menteplena.test')->firstOrFail();
        $psicologia = AreaInstitucional::where('codigo', 'PSICOLOGIA')->firstOrFail();

        $this->actingAs($aux1)->post(route('pqrsd.clasificar', $caso), $this->conVersionExpediente($caso, [
            'nivel_riesgo' => 'ordinario',
            'tipo_pqrsd_id' => $tipo->id,
            'justificacion' => 'Queja ordinaria ficticia para verificar remisiones deshabilitadas.',
        ]))->assertRedirect();

        $html = $this->actingAs($aux1)->get(route('pqrsd.show', $caso))->assertOk()->getContent();
        $this->assertStringNotContainsString('Solicitar información a otra área', $html);
        $this->assertStringNotContainsString('Registrar solicitud', $html);

        $this->actingAs($aux1)->get(route('pqrsd.remisiones.create', $caso))->assertForbidden();
        $this->actingAs($aux1)->post(route('pqrsd.remisiones.store', $caso), [
            'area_institucional_id' => $psicologia->id,
            'motivo' => 'Intento de crear remisión operativa nueva.',
        ])->assertForbidden();

        $this->assertSame(0, RemisionInterna::where('pqrsd_id', $caso->id)->count());
    }

    public function test_remision_historica_sigue_consultable_en_solo_lectura(): void
    {
        $aux = User::where('email', 'auxiliar1@menteplena.test')->firstOrFail();
        $aux2 = User::where('email', 'auxiliar2@menteplena.test')->firstOrFail();
        $caso = $this->crearCasoClasificado($aux);
        $area = AreaInstitucional::where('codigo', 'PSICOLOGIA')->firstOrFail();

        $remision = app(RemisionInternaService::class)->crear($caso, $aux, [
            'area_institucional_id' => $area->id,
            'motivo' => 'Remisión histórica previa a la simplificación definitiva.',
            'informacion_solicitada' => 'Información histórica para consulta autorizada.',
            'responsable_seguimiento_id' => $aux->id,
        ], []);

        $html = $this->actingAs($aux2)->get(route('pqrsd.show', $caso))->assertOk()->getContent();
        $this->assertStringContainsString('Consultar remisiones históricas', $html);
        $this->assertStringContainsString('Psicología', $html);

        $this->actingAs($aux2)
            ->get(route('pqrsd.remisiones.show', [$caso, $remision]))
            ->assertOk()
            ->assertSee('solo lectura', false)
            ->assertDontSee('Registrar remisión externa', false)
            ->assertDontSee('Guardar respuesta del área', false);

        $this->actingAs($aux)->post(route('pqrsd.remisiones.remitir', [$caso, $remision]), [
            'medio_comunicacion' => 'correo_institucional_manual',
            'evidencia_envio_detalle' => 'Intento de mutación sobre historial.',
        ])->assertForbidden();

        $this->actingAs($aux)->post(route('pqrsd.remisiones.respuesta', [$caso, $remision]), [
            'contenido_respuesta' => 'Intento de mutación de respuesta del área.',
        ])->assertForbidden();

        $this->actingAs($aux)->post(route('pqrsd.remisiones.finalizar', [$caso, $remision]), [
            'motivo' => 'Intento de finalizar remisión histórica.',
        ])->assertForbidden();

        $ajeno = User::factory()->create(['activo' => true]);
        $this->actingAs($ajeno)
            ->get(route('pqrsd.remisiones.show', [$caso, $remision]))
            ->assertForbidden();
    }

    public function test_catalogo_areas_disponible(): void
    {
        $this->assertTrue(AreaInstitucional::where('codigo', 'PSICOLOGIA')->exists());
        $this->assertTrue(AreaInstitucional::where('codigo', 'FACTURACION')->exists());
    }

    private function crearCasoClasificado(User $aux): Pqrsd
    {
        $tipo = TipoPqrsd::where('codigo', 'QUEJA')->firstOrFail();
        $this->post(route('publico.store'), [
            'idempotency_key' => (string) Str::uuid(),
            'tipo_pqrsd_id' => $tipo->id,
            'quien_radica' => 'paciente',
            'asunto' => 'Caso para remisión histórica',
            'descripcion' => 'Descripción ficticia de caso para consulta histórica de remisiones.',
            'solicitante_nombre' => 'Ciudadano Remisión Histórica',
            'solicitante_tipo_documento' => 'CC',
            'solicitante_documento' => '900100200',
            'solicitante_email' => 'remision.historica@example.test',
            'medio_respuesta' => 'consulta_segura',
            'acepto_tratamiento_datos' => '1',
            'consentimiento_comunicacion_email' => '1',
        ])->assertRedirect(route('publico.confirmacion'));

        $caso = Pqrsd::latest('id')->firstOrFail();
        $this->actingAs($aux)->post(route('pqrsd.clasificar', $caso), $this->conVersionExpediente($caso, [
            'nivel_riesgo' => 'ordinario',
            'tipo_pqrsd_id' => $tipo->id,
            'justificacion' => 'Clasificación para conservar remisión histórica consultable.',
        ]))->assertRedirect();

        return $caso->fresh();
    }
}
