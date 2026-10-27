<?php

namespace Tests\Feature;

use App\Models\ActuacionPqrsd;
use App\Models\AdjuntoPqrsd;
use App\Models\AreaInstitucional;
use App\Models\CanalIngreso;
use App\Models\TipoPqrsd;
use App\Models\User;
use App\Services\PqrsdService;
use App\Services\RemisionInternaService;
use App\Services\VencimientoService;
use Carbon\Carbon;
use Database\Seeders\CatalogosSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\UsersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SimplificacionDefinitivaFlujoPqrsdTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(CatalogosSeeder::class);
        $this->seed(UsersSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-10-19 10:00:00', 'America/Bogota'));
        Storage::fake(config('pqrsd_correo.adjuntos_disk', 'local'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_auxiliar_atiende_flujo_completo_sin_gestion_ni_remision(): void
    {
        $admin = User::where('email', 'admin@menteplena.test')->firstOrFail();
        $aux1 = User::where('email', 'auxiliar1@menteplena.test')->firstOrFail();
        $aux2 = User::where('email', 'auxiliar2@menteplena.test')->firstOrFail();

        $caso = $this->crearCaso($admin, 'ordinario', Carbon::parse('2026-10-18 08:00:00', 'America/Bogota'));

        $html = $this->actingAs($aux1)->get(route('pqrsd.show', $caso))->assertOk()->getContent();
        $this->assertStringNotContainsString('Registrar gestión', $html);
        $this->assertStringNotContainsString('Solicitar información a otra área', $html);
        $this->assertStringContainsString('En trámite — pendiente de respuesta', $html);

        $this->actingAs($aux1)->post(route('pqrsd.clasificar', $caso), $this->conVersionExpediente($caso, [
            'nivel_riesgo' => 'ordinario',
            'justificacion' => 'Clasificación formal por auxiliar 1 en bandeja compartida.',
        ]))->assertRedirect();

        $this->actingAs($aux1)->post(route('pqrsd.responder', $caso->fresh()), $this->conVersionExpediente($caso->fresh(), [
            'detalle_respuesta' => 'Borrador elaborado. Aún no comunica ni habilita cierre.',
            'respuesta_estado_destino' => 'elaborada',
        ]))->assertRedirect();

        $this->actingAs($aux1)->post(route('pqrsd.cerrar', $caso->fresh()), $this->conVersionExpediente($caso->fresh(), [
            'motivo_cierre' => 'Intento prematuro sin comunicación válida.',
        ]))->assertSessionHasErrors();

        $pdf = UploadedFile::fake()->createWithContent('soporte-respuesta.pdf', "%PDF-1.4\nresp\n");
        $this->actingAs($aux1)->post(route('pqrsd.adjuntos.store', $caso->fresh()), [
            'adjuntos' => [$pdf],
        ])->assertRedirect();
        $this->assertTrue(AdjuntoPqrsd::where('pqrsd_id', $caso->id)->where('nombre_original', 'soporte-respuesta.pdf')->exists());

        // Finalizar antes del vencimiento (plazo ordinario 15 días hábiles aprox.)
        $this->actingAs($aux2)->post(route('pqrsd.responder', $caso->fresh()), $this->conVersionExpediente($caso->fresh(), [
            'detalle_respuesta' => 'Respuesta comunicada al ciudadano por consulta segura antes del vencimiento.',
            'respuesta_estado_destino' => 'puesta_disposicion',
            'poner_disposicion_consulta' => '1',
        ]))->assertRedirect();

        $caso->refresh();
        $this->assertTrue($caso->puedeFinalizarse());
        $this->assertTrue($caso->fecha_limite->greaterThan(now('America/Bogota')));

        $this->actingAs($aux2)->post(route('pqrsd.cerrar', $caso), $this->conVersionExpediente($caso, [
            'motivo_cierre' => 'Atención completa y comunicación válida antes del vencimiento.',
        ]))->assertRedirect();

        $cerrado = $caso->fresh();
        $this->assertSame('cerrado', $cerrado->estado_operativo);
        $this->assertNotNull($cerrado->fecha_respuesta);
        $this->assertTrue($admin->can('usuarios.manage'));
        $this->assertSame(95.0, (float) config('pqrsd_sla.meta_oportunidad_pct'));
    }

    public function test_vencido_sin_respuesta_no_cierra_y_tardia_conserva_incumplimiento(): void
    {
        $admin = User::where('email', 'admin@menteplena.test')->firstOrFail();
        $aux = User::where('email', 'auxiliar1@menteplena.test')->firstOrFail();

        $caso = $this->crearCaso($admin, 'simple', Carbon::parse('2026-10-10 09:00:00', 'America/Bogota'));
        Carbon::setTestNow(Carbon::parse('2026-10-20 12:00:00', 'America/Bogota'));

        app(VencimientoService::class)->refrescarCumplimiento($caso->fresh());
        $caso->refresh();
        $this->assertSame('vencido', $caso->estado_cumplimiento);
        $this->assertNotSame('cerrado', $caso->estado_operativo);

        $this->actingAs($aux)->post(route('pqrsd.responder', $caso), $this->conVersionExpediente($caso, [
            'detalle_respuesta' => 'Respuesta tardía comunicada por canal verificable con evidencia.',
            'respuesta_estado_destino' => 'enviada',
            'marcar_envio_verificable' => '1',
            'respuesta_canal_comunicacion' => 'Correo institucional',
            'respuesta_evidencia_comunicacion' => 'Correo enviado el 20/10/2026 con asunto Respuesta PQRSD.',
        ]))->assertRedirect();

        $caso->refresh();
        $cumplimiento = $caso->estado_cumplimiento;
        $this->assertTrue($caso->puedeFinalizarse());
        $this->assertSame('resuelto_extemporaneo', $cumplimiento);

        $this->actingAs($aux)->post(route('pqrsd.cerrar', $caso), $this->conVersionExpediente($caso, [
            'motivo_cierre' => 'Cierre tras respuesta tardía; se conserva incumplimiento.',
        ]))->assertRedirect();

        $this->assertSame($cumplimiento, $caso->fresh()->estado_cumplimiento);
        $this->assertSame('cerrado', $caso->fresh()->estado_operativo);
    }

    public function test_mutaciones_retiradas_y_historicos_consultables(): void
    {
        $admin = User::where('email', 'admin@menteplena.test')->firstOrFail();
        $aux = User::where('email', 'auxiliar1@menteplena.test')->firstOrFail();
        $caso = $this->crearCaso($admin, 'ordinario', Carbon::parse('2026-10-18 08:00:00', 'America/Bogota'));
        $area = AreaInstitucional::where('activo', true)->firstOrFail();

        $this->actingAs($aux)->post(route('pqrsd.actuaciones.store', $caso), [
            'titulo' => 'No permitido',
            'detalle' => 'Gestión manual retirada de la operación.',
        ])->assertForbidden();

        $this->actingAs($aux)->post(route('pqrsd.remisiones.store', $caso), [
            'area_institucional_id' => $area->id,
            'motivo' => 'Remisión nueva no permitida.',
        ])->assertForbidden();

        ActuacionPqrsd::create([
            'pqrsd_id' => $caso->id,
            'usuario_id' => $admin->id,
            'tipo' => 'actuacion_operativa',
            'titulo' => 'Gestión histórica previa',
            'detalle' => 'Conservada para consulta.',
            'es_interna' => true,
        ]);

        $remision = app(RemisionInternaService::class)->crear($caso, $admin, [
            'area_institucional_id' => $area->id,
            'motivo' => 'Remisión histórica conservada',
            'informacion_solicitada' => 'Solo consulta.',
            'responsable_seguimiento_id' => $admin->id,
        ], []);

        $html = $this->actingAs($aux)->get(route('pqrsd.show', $caso))->assertOk()->getContent();
        $this->assertStringContainsString('Consultar gestiones históricas', $html);
        $this->assertStringContainsString('Gestión histórica previa', $html);
        $this->assertStringContainsString('Consultar remisiones históricas', $html);
        $this->assertStringContainsString('Remisión histórica conservada', $html);

        $this->actingAs($aux)
            ->get(route('pqrsd.remisiones.show', [$caso, $remision]))
            ->assertOk()
            ->assertSee('solo lectura', false);
    }

    private function crearCaso(User $actor, string $riesgo, Carbon $recepcion)
    {
        return app(PqrsdService::class)->radicarManual([
            'fecha_recepcion' => $recepcion,
            'canal_ingreso_id' => CanalIngreso::where('codigo', 'MANUAL')->value('id'),
            'tipo_pqrsd_id' => TipoPqrsd::where('codigo', 'QUEJA')->value('id'),
            'nivel_riesgo' => $riesgo,
            'descripcion' => 'Caso ficticio de simplificación definitiva del flujo PQRSD',
            'solicitante_nombre' => 'Ciudadano Flujo Definitivo',
            'es_anonimo' => 0,
        ], $actor);
    }
}
