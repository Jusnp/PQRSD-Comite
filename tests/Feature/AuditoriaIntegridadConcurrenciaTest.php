<?php

namespace Tests\Feature;

use App\Models\ActuacionPqrsd;
use App\Models\AreaInstitucional;
use App\Models\Aseguradora;
use App\Models\AuditoriaPqrsd;
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

/**
 * Auditoría de integridad: protección de cerrados + concurrencia.
 *
 * - test_secuencial_* : flujos en serie (simulan pantallas obsoletas / post-cierre).
 * - test_concurrencia_* : escenarios de conflicto entre auxiliares (versión / doble cierre).
 */
class AuditoriaIntegridadConcurrenciaTest extends TestCase
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

    public function test_secuencial_cerrado_bloquea_mutaciones_http(): void
    {
        $admin = User::where('email', 'admin@menteplena.test')->firstOrFail();
        $aux = User::where('email', 'auxiliar1@menteplena.test')->firstOrFail();
        $caso = $this->crearCasoSimple($admin);
        $area = AreaInstitucional::where('activo', true)->firstOrFail();

        app(VencimientoService::class)->registrarRespuesta(
            $caso->fresh(),
            $aux,
            Carbon::parse('2026-10-11 10:00:00', 'America/Bogota')
        );
        app(VencimientoService::class)->cerrar(
            $caso->fresh(),
            $admin,
            'Cierre secuencial para bloquear mutaciones posteriores.'
        );

        $cerrado = $caso->fresh();
        $this->assertSame('cerrado', $cerrado->estado_operativo);

        $this->actingAs($aux)->post(route('pqrsd.clasificar', $cerrado), [
            'nivel_riesgo' => 'ordinario',
            'justificacion' => 'Intento de reclasificar un expediente ya cerrado.',
            'expediente_version' => $cerrado->versionToken(),
        ])->assertForbidden();

        $this->actingAs($aux)->post(route('pqrsd.responder', $cerrado), [
            'detalle_respuesta' => 'Intento de alterar la respuesta oficial tras el cierre del radicado.',
            'expediente_version' => $cerrado->versionToken(),
        ])->assertForbidden();

        $this->actingAs($aux)->post(route('pqrsd.cerrar', $cerrado), [
            'motivo_cierre' => 'Intento de cierre duplicado sobre expediente cerrado.',
            'expediente_version' => $cerrado->versionToken(),
        ])->assertForbidden();

        $this->actingAs($aux)->post(route('pqrsd.remisiones.store', $cerrado), [
            'area_institucional_id' => $area->id,
            'motivo' => 'Solicitud indebida sobre expediente cerrado.',
        ])->assertForbidden();

        $this->assertSame('cerrado', $cerrado->fresh()->estado_operativo);
        $this->assertSame(
            $cerrado->fecha_limite?->toDateTimeString(),
            $cerrado->fresh()->fecha_limite?->toDateTimeString()
        );
    }

    public function test_concurrencia_clasificacion_con_version_obsoleta_no_sobrescribe(): void
    {
        $admin = User::where('email', 'admin@menteplena.test')->firstOrFail();
        $aux1 = User::where('email', 'auxiliar1@menteplena.test')->firstOrFail();
        $aux2 = User::where('email', 'auxiliar2@menteplena.test')->firstOrFail();
        $caso = $this->crearCasoPendiente($admin);
        $versionAntigua = $caso->fresh()->versionToken();

        $this->actingAs($aux1)->post(route('pqrsd.clasificar', $caso), [
            'nivel_riesgo' => 'ordinario',
            'justificacion' => 'Clasificación vigente del auxiliar 1 en bandeja compartida.',
            'expediente_version' => $versionAntigua,
        ])->assertRedirect();

        $caso->refresh();
        $this->assertSame('ordinario', $caso->nivel_riesgo);
        $limiteTrasAux1 = $caso->fecha_limite?->toDateTimeString();

        $this->actingAs($aux2)->from(route('pqrsd.show', $caso))->post(route('pqrsd.clasificar', $caso), [
            'nivel_riesgo' => 'simple',
            'justificacion' => 'Clasificación obsoleta del auxiliar 2 con pantalla desactualizada.',
            'expediente_version' => $versionAntigua,
        ])->assertRedirect(route('pqrsd.show', $caso))
            ->assertSessionHasErrors('expediente');

        $caso->refresh();
        $this->assertSame('ordinario', $caso->nivel_riesgo);
        $this->assertSame($limiteTrasAux1, $caso->fecha_limite?->toDateTimeString());
        $this->assertTrue(
            ActuacionPqrsd::where('pqrsd_id', $caso->id)
                ->where('usuario_id', $aux1->id)
                ->where('tipo', 'clasificacion_sla')
                ->exists()
        );
    }

    public function test_concurrencia_respuesta_obsoleta_no_borra_texto_vigente(): void
    {
        $admin = User::where('email', 'admin@menteplena.test')->firstOrFail();
        $aux1 = User::where('email', 'auxiliar1@menteplena.test')->firstOrFail();
        $aux2 = User::where('email', 'auxiliar2@menteplena.test')->firstOrFail();
        $caso = $this->crearCasoSimple($admin);
        $versionAntigua = $caso->fresh()->versionToken();

        $this->actingAs($aux1)->post(route('pqrsd.responder', $caso), [
            'detalle_respuesta' => 'Respuesta oficial vigente elaborada por el auxiliar 1.',
            'fecha_respuesta' => '2026-10-11T11:00',
            'respuesta_estado_destino' => 'elaborada',
            'expediente_version' => $versionAntigua,
        ])->assertRedirect();

        $caso->refresh();
        $this->assertSame('elaborada', $caso->respuesta_estado);
        $this->assertStringContainsString('auxiliar 1', (string) $caso->respuesta_consulta_texto);

        $this->actingAs($aux2)->from(route('pqrsd.show', $caso))->post(route('pqrsd.responder', $caso), [
            'detalle_respuesta' => 'Texto obsoleto del auxiliar 2 que no debe reemplazar silenciosamente.',
            'fecha_respuesta' => '2026-10-11T12:00',
            'respuesta_estado_destino' => 'elaborada',
            'expediente_version' => $versionAntigua,
        ])->assertRedirect(route('pqrsd.show', $caso))
            ->assertSessionHasErrors('expediente');

        $caso->refresh();
        $this->assertStringContainsString('auxiliar 1', (string) $caso->respuesta_consulta_texto);
        $this->assertStringNotContainsString('auxiliar 2', (string) $caso->respuesta_consulta_texto);
        $this->assertNotSame('enviada', $caso->respuesta_estado);
    }

    public function test_concurrencia_segundo_cierre_no_duplica_ni_altera_sla(): void
    {
        $admin = User::where('email', 'admin@menteplena.test')->firstOrFail();
        $aux1 = User::where('email', 'auxiliar1@menteplena.test')->firstOrFail();
        $aux2 = User::where('email', 'auxiliar2@menteplena.test')->firstOrFail();
        $caso = $this->crearCasoSimple($admin);

        app(VencimientoService::class)->registrarRespuesta(
            $caso->fresh(),
            $aux1,
            Carbon::parse('2026-10-11 10:00:00', 'America/Bogota')
        );
        $caso->refresh();
        $version = $caso->versionToken();
        $limite = $caso->fecha_limite?->toDateTimeString();
        $inicio = $caso->fecha_inicio_conteo?->toDateTimeString();

        $this->actingAs($aux1)->post(route('pqrsd.cerrar', $caso), [
            'motivo_cierre' => 'Cierre válido con evidencia de respuesta por auxiliar 1.',
            'expediente_version' => $version,
        ])->assertRedirect();

        $cierres = ActuacionPqrsd::where('pqrsd_id', $caso->id)->where('tipo', 'cierre')->count();
        $this->assertSame(1, $cierres);

        $this->actingAs($aux2)->post(route('pqrsd.cerrar', $caso->fresh()), [
            'motivo_cierre' => 'Segundo intento de cierre concurrente por auxiliar 2.',
            'expediente_version' => $version,
        ])->assertForbidden();

        $caso->refresh();
        $this->assertSame('cerrado', $caso->estado_operativo);
        $this->assertSame(1, ActuacionPqrsd::where('pqrsd_id', $caso->id)->where('tipo', 'cierre')->count());
        $this->assertSame($limite, $caso->fecha_limite?->toDateTimeString());
        $this->assertSame($inicio, $caso->fecha_inicio_conteo?->toDateTimeString());
    }

    public function test_http_sin_token_version_rechaza_clasificar_responder_y_cerrar(): void
    {
        $admin = User::where('email', 'admin@menteplena.test')->firstOrFail();
        $aux = User::where('email', 'auxiliar1@menteplena.test')->firstOrFail();
        $caso = $this->crearCasoPendiente($admin);

        $this->actingAs($aux)->from(route('pqrsd.show', $caso))->post(route('pqrsd.clasificar', $caso), [
            'nivel_riesgo' => 'ordinario',
            'justificacion' => 'Intento HTTP sin token de versión para eludir concurrencia.',
        ])->assertRedirect(route('pqrsd.show', $caso))
            ->assertSessionHasErrors('expediente_version');

        $this->assertSame('pendiente', $caso->fresh()->nivel_riesgo);

        $casoSimple = $this->crearCasoSimple($admin);
        $this->actingAs($aux)->from(route('pqrsd.show', $casoSimple))->post(route('pqrsd.responder', $casoSimple), [
            'detalle_respuesta' => 'Intento de respuesta oficial sin token de versión del expediente.',
        ])->assertRedirect(route('pqrsd.show', $casoSimple))
            ->assertSessionHasErrors('expediente_version');

        $this->assertNull($casoSimple->fresh()->fecha_respuesta);

        app(VencimientoService::class)->registrarRespuesta(
            $casoSimple->fresh(),
            $aux,
            Carbon::parse('2026-10-11 10:00:00', 'America/Bogota')
        );

        $this->actingAs($aux)->from(route('pqrsd.show', $casoSimple))->post(route('pqrsd.cerrar', $casoSimple->fresh()), [
            'motivo_cierre' => 'Intento de cierre sin token de versión del expediente.',
        ])->assertRedirect(route('pqrsd.show', $casoSimple))
            ->assertSessionHasErrors('expediente_version');

        $this->assertNotSame('cerrado', $casoSimple->fresh()->estado_operativo);
    }

    public function test_http_token_manipulado_no_aprueba_version_obsoleta(): void
    {
        $admin = User::where('email', 'admin@menteplena.test')->firstOrFail();
        $aux = User::where('email', 'auxiliar1@menteplena.test')->firstOrFail();
        $caso = $this->crearCasoPendiente($admin);
        $tokenFalso = str_repeat('a', 64);

        $this->actingAs($aux)->from(route('pqrsd.show', $caso))->post(route('pqrsd.clasificar', $caso), [
            'nivel_riesgo' => 'ordinario',
            'justificacion' => 'Token manipulado con formato válido pero contenido incorrecto.',
            'expediente_version' => $tokenFalso,
        ])->assertRedirect(route('pqrsd.show', $caso))
            ->assertSessionHasErrors('expediente');

        $this->assertSame('pendiente', $caso->fresh()->nivel_riesgo);
    }

    public function test_secuencial_respuesta_area_no_es_oficial_y_auditoria_conserva_autor(): void
    {
        $admin = User::where('email', 'admin@menteplena.test')->firstOrFail();
        $aux = User::where('email', 'auxiliar1@menteplena.test')->firstOrFail();
        $caso = $this->crearCasoSimple($admin);
        $area = AreaInstitucional::where('activo', true)->firstOrFail();

        $this->actingAs($aux)->post(route('pqrsd.remisiones.store', $caso), [
            'area_institucional_id' => $area->id,
            'motivo' => 'Se solicita informe interno de agenda al área.',
        ])->assertForbidden();

        $remision = app(\App\Services\RemisionInternaService::class)->crear($caso, $aux, [
            'area_institucional_id' => $area->id,
            'motivo' => 'Remisión histórica para auditoría.',
            'informacion_solicitada' => 'Informe interno histórico.',
            'responsable_seguimiento_id' => $aux->id,
        ], []);

        $this->actingAs($aux)->post(route('pqrsd.remisiones.respuesta', [$caso, $remision]), [
            'contenido_respuesta' => 'Información interna del área para el auxiliar. No es respuesta al ciudadano.',
            'persona_responde' => 'Funcionario área demo',
        ])->assertForbidden();

        $caso->refresh();
        $this->assertNull($caso->fecha_respuesta);
        $this->assertNotSame('respondido', $caso->estado_operativo);
        $this->assertFalse(
            ActuacionPqrsd::where('pqrsd_id', $caso->id)
                ->where('tipo', 'respuesta_area')
                ->where('usuario_id', $aux->id)
                ->exists()
        );
        $this->assertTrue(
            AuditoriaPqrsd::where('pqrsd_id', $caso->id)
                ->where('accion', 'remision.crear')
                ->where('usuario_id', $aux->id)
                ->exists()
        );
        $this->assertSame(95.0, (float) config('pqrsd_sla.meta_oportunidad_pct'));
    }

    private function crearCasoSimple(User $actor): \App\Models\Pqrsd
    {
        return app(PqrsdService::class)->radicarManual([
            'fecha_recepcion' => Carbon::parse('2026-10-10 09:00:00', 'America/Bogota'),
            'canal_ingreso_id' => CanalIngreso::where('codigo', 'MANUAL')->value('id'),
            'tipo_pqrsd_id' => TipoPqrsd::where('codigo', 'QUEJA')->value('id'),
            'nivel_riesgo' => 'simple',
            'descripcion' => 'Caso ficticio de auditoría de integridad y concurrencia',
            'solicitante_nombre' => 'Ciudadano Auditoría',
            'sede_id' => Sede::where('activo', true)->value('id'),
            'aseguradora_id' => Aseguradora::where('activo', true)->value('id'),
        ], $actor)->fresh();
    }

    private function crearCasoPendiente(User $actor): \App\Models\Pqrsd
    {
        return app(PqrsdService::class)->radicarManual([
            'fecha_recepcion' => Carbon::parse('2026-10-10 09:00:00', 'America/Bogota'),
            'canal_ingreso_id' => CanalIngreso::where('codigo', 'WEB')->value('id')
                ?? CanalIngreso::where('codigo', 'MANUAL')->value('id'),
            'tipo_pqrsd_id' => TipoPqrsd::where('codigo', 'QUEJA')->value('id'),
            'nivel_riesgo' => 'pendiente',
            'descripcion' => 'Caso pendiente para conflicto de clasificación concurrente',
            'solicitante_nombre' => 'Ciudadano Concurrencia',
            'sede_id' => Sede::where('activo', true)->value('id'),
            'aseguradora_id' => Aseguradora::where('activo', true)->value('id'),
        ], $actor)->fresh();
    }
}
