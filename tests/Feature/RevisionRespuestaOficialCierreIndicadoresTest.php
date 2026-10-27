<?php

namespace Tests\Feature;

use App\Models\ActuacionPqrsd;
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
use Tests\TestCase;

class RevisionRespuestaOficialCierreIndicadoresTest extends TestCase
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

    public function test_pendiente_clasificacion_no_finaliza_y_muestra_progreso(): void
    {
        $admin = User::where('email', 'admin@menteplena.test')->firstOrFail();
        $aux = User::where('email', 'auxiliar1@menteplena.test')->firstOrFail();
        $caso = $this->crearPendiente($admin);

        $this->assertFalse($caso->tieneClasificacionFormal());
        $this->assertNotEmpty($caso->requisitosPendientesCierre());

        $this->actingAs($aux)->post(route('pqrsd.cerrar', $caso), [
            'motivo_cierre' => 'Intento de cierre sin clasificación formal del radicado.',
            'expediente_version' => $caso->versionToken(),
        ])->assertSessionHasErrors();

        $html = $this->actingAs($aux)->get(route('pqrsd.show', $caso))->assertOk()->getContent();
        $this->assertStringContainsString('En trámite — pendiente de respuesta', $html);
        $this->assertStringNotContainsString('Finalizar PQRSD</button>', $html);
        $this->assertStringNotContainsString('Finalizar PQRSD (no disponible)', $html);
    }

    public function test_clasificado_sin_respuesta_no_finaliza(): void
    {
        $admin = User::where('email', 'admin@menteplena.test')->firstOrFail();
        $aux = User::where('email', 'auxiliar1@menteplena.test')->firstOrFail();
        $caso = $this->crearClasificado($admin);

        $this->actingAs($aux)->post(route('pqrsd.cerrar', $caso), [
            'motivo_cierre' => 'Intento de cierre sin respuesta ni comunicación válida.',
            'expediente_version' => $caso->versionToken(),
        ])->assertSessionHasErrors();

        $this->assertStringContainsString(
            'comunicación válida',
            implode(' ', $caso->fresh()->requisitosPendientesCierre())
        );
    }

    public function test_solo_elaborada_no_oportunidad_ni_cierre(): void
    {
        $admin = User::where('email', 'admin@menteplena.test')->firstOrFail();
        $aux = User::where('email', 'auxiliar1@menteplena.test')->firstOrFail();
        $caso = $this->crearClasificado($admin);
        $antes = $caso->estado_cumplimiento;

        $this->actingAs($aux)->post(route('pqrsd.responder', $caso), $this->conVersionExpediente($caso, [
            'detalle_respuesta' => 'Borrador elaborado sin comunicación al ciudadano.',
            'respuesta_estado_destino' => 'elaborada',
        ]))->assertRedirect();

        $caso->refresh();
        $this->assertSame('elaborada', $caso->respuesta_estado);
        $this->assertNotNull($caso->respuesta_elaborada_en);
        $this->assertNull($caso->fecha_respuesta);
        $this->assertSame($antes, $caso->estado_cumplimiento);
        $this->assertFalse($caso->puedeFinalizarse());

        $this->actingAs($aux)->post(route('pqrsd.cerrar', $caso), $this->conVersionExpediente($caso, [
            'motivo_cierre' => 'Intento de cierre con solo borrador elaborado.',
        ]))->assertSessionHasErrors();
    }

    public function test_puesta_disposicion_alimenta_oportunidad_y_permite_cierre(): void
    {
        $admin = User::where('email', 'admin@menteplena.test')->firstOrFail();
        $aux = User::where('email', 'auxiliar1@menteplena.test')->firstOrFail();
        $caso = $this->crearClasificado($admin);

        $this->actingAs($aux)->post(route('pqrsd.responder', $caso), $this->conVersionExpediente($caso, [
            'detalle_respuesta' => 'Texto publicado en consulta segura del portal institucional.',
            'respuesta_estado_destino' => 'puesta_disposicion',
            'poner_disposicion_consulta' => '1',
        ]))->assertRedirect();

        $caso->refresh();
        $this->assertSame('puesta_disposicion', $caso->respuesta_estado);
        $this->assertNotNull($caso->fecha_respuesta);
        $this->assertNotNull($caso->respuesta_puesta_disposicion_en);
        $this->assertSame('resuelto_oportuno', $caso->estado_cumplimiento);
        $this->assertTrue($caso->puedeFinalizarse());

        $this->actingAs($aux)->post(route('pqrsd.cerrar', $caso), $this->conVersionExpediente($caso, [
            'motivo_cierre' => 'Cierre tras puesta a disposición en consulta segura.',
        ]))->assertRedirect();

        $cerrado = $caso->fresh();
        $this->assertSame('cerrado', $cerrado->estado_operativo);
        $this->assertNotNull($cerrado->fecha_cierre);
    }

    public function test_envio_sin_evidencia_rechazado_y_con_evidencia_ok(): void
    {
        $admin = User::where('email', 'admin@menteplena.test')->firstOrFail();
        $aux = User::where('email', 'auxiliar1@menteplena.test')->firstOrFail();
        $caso = $this->crearClasificado($admin);

        $this->actingAs($aux)->from(route('pqrsd.show', $caso))->post(route('pqrsd.responder', $caso), $this->conVersionExpediente($caso, [
            'detalle_respuesta' => 'Intento de marcar envío sin canal ni evidencia suficiente.',
            'marcar_envio_verificable' => '1',
        ]))->assertRedirect(route('pqrsd.show', $caso))
            ->assertSessionHasErrors(['respuesta_canal_comunicacion', 'respuesta_evidencia_comunicacion']);

        $this->assertNull($caso->fresh()->respuesta_enviada_en);

        $this->actingAs($aux)->post(route('pqrsd.responder', $caso->fresh()), $this->conVersionExpediente($caso->fresh(), [
            'detalle_respuesta' => 'Respuesta comunicada por correspondencia certificada al domicilio.',
            'marcar_envio_verificable' => '1',
            'respuesta_canal_comunicacion' => 'Correspondencia certificada',
            'respuesta_evidencia_comunicacion' => 'Guía 4-72 número DEMO-998877 de 19/10/2026.',
        ]))->assertRedirect();

        $caso->refresh();
        $this->assertSame('enviada', $caso->respuesta_estado);
        $this->assertNotNull($caso->fecha_respuesta);
        $this->assertSame('Correspondencia certificada', $caso->respuesta_canal_comunicacion);
        $this->assertTrue($caso->puedeFinalizarse());
    }

    public function test_cerrado_solo_lectura_y_segundo_cierre_rechazado(): void
    {
        $admin = User::where('email', 'admin@menteplena.test')->firstOrFail();
        $aux1 = User::where('email', 'auxiliar1@menteplena.test')->firstOrFail();
        $aux2 = User::where('email', 'auxiliar2@menteplena.test')->firstOrFail();
        $caso = $this->crearClasificado($admin);

        $this->actingAs($aux1)->post(route('pqrsd.responder', $caso), $this->conVersionExpediente($caso, [
            'detalle_respuesta' => 'Respuesta publicada para cierre y modo lectura.',
            'poner_disposicion_consulta' => '1',
        ]))->assertRedirect();

        $version = $caso->fresh()->versionToken();
        $this->actingAs($aux1)->post(route('pqrsd.cerrar', $caso->fresh()), [
            'motivo_cierre' => 'Finalización válida con comunicación en portal.',
            'expediente_version' => $version,
        ])->assertRedirect();

        $cerrado = $caso->fresh();
        $this->assertSame('cerrado', $cerrado->estado_operativo);

        $html = $this->actingAs($aux2)->get(route('pqrsd.show', $cerrado))->assertOk()->getContent();
        $this->assertStringContainsString('PQRSD finalizada', $html);
        $this->assertStringContainsString('Finalización válida con comunicación', $html);
        $this->assertStringNotContainsString('Guardar respuesta</button>', $html);
        $this->assertStringNotContainsString('name="motivo_cierre"', $html);

        $this->actingAs($aux2)->post(route('pqrsd.cerrar', $cerrado), [
            'motivo_cierre' => 'Segundo intento de cierre no permitido.',
            'expediente_version' => $cerrado->versionToken(),
        ])->assertForbidden();

        $this->assertSame(1, ActuacionPqrsd::where('pqrsd_id', $cerrado->id)->where('tipo', 'cierre')->count());
    }

    public function test_historial_registra_elaboracion_comunicacion_y_cierre(): void
    {
        $admin = User::where('email', 'admin@menteplena.test')->firstOrFail();
        $aux = User::where('email', 'auxiliar1@menteplena.test')->firstOrFail();
        $caso = $this->crearClasificado($admin);

        $this->actingAs($aux)->post(route('pqrsd.responder', $caso), $this->conVersionExpediente($caso, [
            'detalle_respuesta' => 'Primero se elabora el contenido de la respuesta.',
            'respuesta_estado_destino' => 'elaborada',
        ]))->assertRedirect();

        $this->actingAs($aux)->post(route('pqrsd.responder', $caso->fresh()), $this->conVersionExpediente($caso->fresh(), [
            'detalle_respuesta' => 'Primero se elabora el contenido de la respuesta.',
            'poner_disposicion_consulta' => '1',
        ]))->assertRedirect();

        $this->actingAs($aux)->post(route('pqrsd.cerrar', $caso->fresh()), $this->conVersionExpediente($caso->fresh(), [
            'motivo_cierre' => 'Cierre con historial de elaboración y comunicación.',
        ]))->assertRedirect();

        $html = $this->actingAs($aux)->get(route('pqrsd.show', $caso->fresh()))->assertOk()->getContent();
        $this->assertStringContainsString('Respuesta elaborada', $html);
        $this->assertStringContainsString('Respuesta puesta a disposición', $html);
        $this->assertStringContainsString('PQRSD finalizada', $html);
        $this->assertTrue($caso->fresh()->auditorias()->where('accion', 'cerrar')->exists());
        $this->assertTrue($caso->fresh()->auditorias()->where('accion', 'elaborar_respuesta')->exists());
    }

    public function test_ui_finalizacion_y_secciones_en_escritorio(): void
    {
        $admin = User::where('email', 'admin@menteplena.test')->firstOrFail();
        $aux = User::where('email', 'auxiliar1@menteplena.test')->firstOrFail();
        $caso = $this->crearClasificado($admin);

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
        $this->assertStringContainsString('En trámite — pendiente de respuesta', $html);
        $this->assertStringNotContainsString('Enviado por Gmail', $html);
    }

    public function test_legacy_fecha_respuesta_sin_estado_moderno_sigue_cerrable(): void
    {
        $admin = User::where('email', 'admin@menteplena.test')->firstOrFail();
        $aux = User::where('email', 'auxiliar1@menteplena.test')->firstOrFail();
        $caso = $this->crearClasificado($admin);

        app(VencimientoService::class)->registrarRespuesta(
            $caso->fresh(),
            $aux,
            Carbon::parse('2026-10-19 11:00:00', 'America/Bogota')
        );

        $caso->refresh();
        $this->assertNull($caso->respuesta_estado);
        $this->assertTrue($caso->tieneComunicacionValida());
        $this->assertTrue($caso->puedeFinalizarse());
    }

    private function crearPendiente(User $usuario)
    {
        return app(PqrsdService::class)->radicarManual([
            'fecha_recepcion' => Carbon::parse('2026-10-18 09:00:00', 'America/Bogota'),
            'canal_ingreso_id' => CanalIngreso::where('codigo', 'MANUAL')->value('id'),
            'nivel_riesgo' => 'pendiente',
            'descripcion' => 'Caso ficticio pendiente de clasificación formal',
            'solicitante_nombre' => 'Ciudadano Pendiente Demo',
        ], $usuario);
    }

    private function crearClasificado(User $usuario)
    {
        return app(PqrsdService::class)->radicarManual([
            'fecha_recepcion' => Carbon::parse('2026-10-18 09:00:00', 'America/Bogota'),
            'canal_ingreso_id' => CanalIngreso::where('codigo', 'MANUAL')->value('id'),
            'tipo_pqrsd_id' => TipoPqrsd::where('codigo', 'QUEJA')->value('id'),
            'nivel_riesgo' => 'ordinario',
            'descripcion' => 'Caso ficticio clasificado para revisión de respuesta y cierre',
            'solicitante_nombre' => 'Ciudadano Clasificado Demo',
        ], $usuario);
    }
}
