<?php

namespace Tests\Feature;

use App\Models\ActuacionPqrsd;
use App\Models\AreaInstitucional;
use App\Models\Aseguradora;
use App\Models\CanalIngreso;
use App\Models\Sede;
use App\Models\TipoPqrsd;
use App\Models\User;
use App\Services\PqrsdService;
use App\Services\VencimientoService;
use Carbon\Carbon;
use Database\Seeders\CatalogosSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\UsersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SimplificacionExpedienteAuxiliaresTest extends TestCase
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

    public function test_bandeja_compartida_sin_columna_responsable_obligatorio(): void
    {
        $admin = User::where('email', 'admin@menteplena.test')->firstOrFail();
        $aux = User::where('email', 'auxiliar1@menteplena.test')->firstOrFail();
        $caso = $this->crearCasoSinResponsable($admin);

        $html = $this->actingAs($aux)->get(route('pqrsd.index'))->assertOk()->getContent();
        $this->assertStringContainsString($caso->radicado, $html);
        $this->assertStringNotContainsString('<th>Responsable</th>', $html);
        $this->assertStringNotContainsString('name="responsable_id"', $html);
    }

    public function test_auxiliares_gestionan_sin_responsable_y_ruta_asignar_403(): void
    {
        $admin = User::where('email', 'admin@menteplena.test')->firstOrFail();
        $aux1 = User::where('email', 'auxiliar1@menteplena.test')->firstOrFail();
        $aux2 = User::where('email', 'auxiliar2@menteplena.test')->firstOrFail();
        $caso = $this->crearCasoSinResponsable($admin);
        $this->assertNull($caso->responsable_id);

        $this->actingAs($aux1)->post(route('pqrsd.asignar', $caso), [
            'responsable_id' => $aux2->id,
            'motivo' => 'No debe permitirse en operación diaria.',
        ])->assertForbidden();

        $this->actingAs($admin)->post(route('pqrsd.asignar', $caso), [
            'responsable_id' => $aux1->id,
            'motivo' => 'Admin tampoco asigna por HTTP operativo.',
        ])->assertForbidden();

        $html = $this->actingAs($aux1)->get(route('pqrsd.show', $caso))->assertOk()->getContent();
        $this->assertStringNotContainsString('Asignar / reasignar responsable', $html);
        $this->assertStringNotContainsString('Guardar asignación', $html);
        $this->assertStringNotContainsString('Registrar gestión', $html);

        $recepcion = $caso->fecha_recepcion?->toDateTimeString();

        $this->actingAs($aux1)->post(route('pqrsd.clasificar', $caso), $this->conVersionExpediente($caso, [
            'nivel_riesgo' => 'ordinario',
            'justificacion' => 'Clasificación compartida sin asignación de responsable.',
        ]))->assertRedirect();

        $caso->refresh();
        $this->assertNull($caso->responsable_id);
        $this->assertSame($recepcion, $caso->fecha_recepcion?->toDateTimeString());
        $inicioTrasClasificar = $caso->fecha_inicio_conteo?->toDateTimeString();
        $limiteTrasClasificar = $caso->fecha_limite?->toDateTimeString();
        $this->assertNotNull($inicioTrasClasificar);
        $this->assertNotNull($limiteTrasClasificar);

        $this->actingAs($aux2)->post(route('pqrsd.actuaciones.store', $caso), [
            'titulo' => 'Continuación por auxiliar 2',
            'detalle' => 'Gestión manual ya no forma parte de la operación.',
            'es_interna' => 1,
        ])->assertForbidden();

        $this->actingAs($aux2)->post(route('pqrsd.responder', $caso), $this->conVersionExpediente($caso, [
            'detalle_respuesta' => 'Respuesta elaborada por auxiliar 2 desde bandeja compartida sin responsable.',
            'respuesta_estado_destino' => 'elaborada',
        ]))->assertRedirect();

        $caso->refresh();
        $this->assertNull($caso->responsable_id);
        $this->assertSame($recepcion, $caso->fecha_recepcion?->toDateTimeString());
        $this->assertSame($inicioTrasClasificar, $caso->fecha_inicio_conteo?->toDateTimeString());
        $this->assertSame($limiteTrasClasificar, $caso->fecha_limite?->toDateTimeString());
        $this->assertTrue($caso->tieneRespuestaElaborada());
    }

    public function test_solicitud_informacion_deshabilitada_y_respuesta_oficial_disponible(): void
    {
        $admin = User::where('email', 'admin@menteplena.test')->firstOrFail();
        $aux = User::where('email', 'auxiliar1@menteplena.test')->firstOrFail();
        $caso = $this->crearCasoSinResponsable($admin);
        $area = AreaInstitucional::where('activo', true)->firstOrFail();
        $limiteAntes = $caso->fecha_limite?->toDateTimeString();

        $html = $this->actingAs($aux)->get(route('pqrsd.show', $caso))->assertOk()->getContent();
        $this->assertStringNotContainsString('Solicitar información a otra área', $html);
        $this->assertStringContainsString('Respuesta de PQRS', $html);
        $this->assertStringContainsString('Historial automático', $html);

        $this->actingAs($aux)->post(route('pqrsd.remisiones.store', $caso), [
            'area_institucional_id' => $area->id,
            'motivo' => 'Se solicita informe de agenda al área institucional.',
        ])->assertForbidden();

        $caso->refresh();
        $this->assertSame($limiteAntes, $caso->fecha_limite?->toDateTimeString());
        $this->assertDatabaseMissing('remisiones_internas', [
            'pqrsd_id' => $caso->id,
        ]);

        $this->actingAs($aux)->post(route('pqrsd.responder', $caso), $this->conVersionExpediente($caso, [
            'detalle_respuesta' => 'Respuesta oficial de PQRS al ciudadano sin remisión a otra área.',
            'respuesta_estado_destino' => 'puesta_disposicion',
            'poner_disposicion_consulta' => '1',
        ]))->assertRedirect();

        $this->assertTrue(ActuacionPqrsd::where('pqrsd_id', $caso->id)->where('tipo', 'respuesta')->exists()
            || ActuacionPqrsd::where('pqrsd_id', $caso->id)->where('titulo', 'like', '%Respuesta%')->exists());
        $this->assertSame(95.0, (float) config('pqrsd_sla.meta_oportunidad_pct'));
    }

    public function test_caso_historico_con_responsable_sigue_accesible_y_cerrado_protegido(): void
    {
        $admin = User::where('email', 'admin@menteplena.test')->firstOrFail();
        $aux1 = User::where('email', 'auxiliar1@menteplena.test')->firstOrFail();
        $aux2 = User::where('email', 'auxiliar2@menteplena.test')->firstOrFail();

        $caso = app(PqrsdService::class)->radicarManual([
            'fecha_recepcion' => Carbon::parse('2026-10-08 09:00:00', 'America/Bogota'),
            'canal_ingreso_id' => CanalIngreso::where('codigo', 'MANUAL')->value('id'),
            'tipo_pqrsd_id' => TipoPqrsd::where('codigo', 'QUEJA')->value('id'),
            'nivel_riesgo' => 'simple',
            'descripcion' => 'Caso histórico con responsable previo conservado',
            'solicitante_nombre' => 'Histórico Demo',
            'sede_id' => Sede::where('activo', true)->value('id'),
            'aseguradora_id' => Aseguradora::where('activo', true)->value('id'),
            'responsable_id' => $aux1->id,
        ], $admin);

        $this->assertSame($aux1->id, $caso->responsable_id);
        $this->actingAs($aux2)->get(route('pqrsd.show', $caso))->assertOk();

        app(VencimientoService::class)->registrarRespuesta(
            $caso->fresh(),
            $aux2,
            Carbon::parse('2026-10-09 11:00:00', 'America/Bogota')
        );
        app(VencimientoService::class)->cerrar(
            $caso->fresh(),
            $admin,
            'Cierre histórico de verificación de simplificación del expediente.'
        );

        $html = $this->actingAs($aux1)->get(route('pqrsd.show', $caso->fresh()))->assertOk()->getContent();
        $this->assertStringContainsString('PQRSD finalizada', $html);
        $this->assertStringNotContainsString('Aplicar clasificación', $html);
        $this->assertSame($aux1->id, $caso->fresh()->responsable_id);
    }

    private function crearCasoSinResponsable(User $actor): \App\Models\Pqrsd
    {
        return app(PqrsdService::class)->radicarManual([
            'fecha_recepcion' => Carbon::parse('2026-10-10 09:00:00', 'America/Bogota'),
            'canal_ingreso_id' => CanalIngreso::where('codigo', 'WEB')->value('id')
                ?? CanalIngreso::where('codigo', 'MANUAL')->value('id'),
            'tipo_pqrsd_id' => TipoPqrsd::where('codigo', 'QUEJA')->value('id'),
            'nivel_riesgo' => 'pendiente',
            'descripcion' => 'Caso de bandeja compartida sin responsable asignado',
            'solicitante_nombre' => 'Ciudadano Bandeja Compartida',
            'sede_id' => Sede::where('activo', true)->value('id'),
            'aseguradora_id' => Aseguradora::where('activo', true)->value('id'),
        ], $actor)->fresh();
    }
}
