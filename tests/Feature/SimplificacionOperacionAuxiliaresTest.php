<?php

namespace Tests\Feature;

use App\Models\Aseguradora;
use App\Models\CanalIngreso;
use App\Models\MotivoPqrsd;
use App\Models\Sede;
use App\Models\TipoPqrsd;
use App\Models\User;
use App\Services\IndicadoresPqrsdService;
use App\Services\PqrsdService;
use App\Services\VencimientoService;
use Carbon\Carbon;
use Database\Seeders\CatalogosSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\UsersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tests\TestCase;

class SimplificacionOperacionAuxiliaresTest extends TestCase
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

    public function test_admin_accede_a_nueva_pqrsd_y_menu_administracion(): void
    {
        $admin = User::where('email', 'admin@menteplena.test')->firstOrFail();

        $this->assertTrue($admin->can('pqrsd.create_manual'));
        $html = $this->actingAs($admin)->get(route('pqrsd.index'))->assertOk()->getContent();
        $this->assertStringContainsString('Administración', $html);
        $this->assertStringContainsString('Nueva PQRSD', $html);
        $this->assertStringContainsString(route('pqrsd.create', absolute: false), $html);

        $this->actingAs($admin)->get(route('pqrsd.create'))->assertOk()->assertSee('Nueva PQRSD', false);
        $this->actingAs($admin)->get(route('usuarios.index'))->assertOk();
        $this->actingAs($admin)->get(route('motivos.index'))->assertOk();
        $this->actingAs($admin)->get(route('reglas.index'))->assertOk();
    }

    public function test_auxiliar_no_visualiza_nueva_ni_administracion(): void
    {
        $auxiliar = User::where('email', 'auxiliar1@menteplena.test')->firstOrFail();

        $this->assertFalse($auxiliar->can('pqrsd.create_manual'));
        $this->assertFalse($auxiliar->can('pqrsd.create'));
        $this->assertFalse($auxiliar->can('usuarios.manage'));
        $this->assertFalse($auxiliar->can('motivos.manage'));

        $html = $this->actingAs($auxiliar)->get(route('pqrsd.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('Nueva PQRSD', $html);
        $this->assertStringNotContainsString('Nueva radicación', $html);
        $this->assertStringNotContainsString('>Administración</summary>', $html);
        $this->assertStringContainsString('Bandeja de PQRSD', $html);
        $this->assertStringContainsString('Indicadores PQRSD', $html);
        $this->assertStringContainsString('Mi perfil', $html);
    }

    public function test_auxiliar_recibe_403_en_radicacion_manual(): void
    {
        $auxiliar = User::where('email', 'auxiliar1@menteplena.test')->firstOrFail();

        $this->actingAs($auxiliar)->get(route('pqrsd.create'))->assertForbidden();
        $this->actingAs($auxiliar)->post(route('pqrsd.store'), [
            'canal_ingreso_id' => CanalIngreso::where('codigo', 'MANUAL')->value('id'),
            'nivel_riesgo' => 'ordinario',
            'descripcion' => 'Intento auxiliar de radicación manual.',
            'es_anonimo' => 1,
        ])->assertForbidden();
    }

    public function test_auxiliar_consulta_todas_clasifica_responde_y_remite(): void
    {
        $admin = User::where('email', 'admin@menteplena.test')->firstOrFail();
        $auxiliar = User::where('email', 'auxiliar1@menteplena.test')->firstOrFail();
        $tipo = TipoPqrsd::where('codigo', 'QUEJA')->firstOrFail();
        $motivo = MotivoPqrsd::where('tipo_pqrsd_id', $tipo->id)->where('activo', true)->first();

        $caso = app(PqrsdService::class)->radicarManual([
            'fecha_recepcion' => Carbon::parse('2026-10-10 09:00:00', 'America/Bogota'),
            'canal_ingreso_id' => CanalIngreso::where('codigo', 'WEB')->value('id')
                ?? CanalIngreso::where('codigo', 'MANUAL')->value('id'),
            'tipo_pqrsd_id' => $tipo->id,
            'motivo_pqrsd_id' => $motivo?->id,
            'nivel_riesgo' => 'pendiente',
            'descripcion' => 'Caso bandeja para auxiliar — consulta y gestión',
            'solicitante_nombre' => 'Solicitante Demo',
            'sede_id' => Sede::where('activo', true)->value('id'),
            'aseguradora_id' => Aseguradora::where('activo', true)->value('id'),
        ], $admin);

        $this->actingAs($auxiliar)
            ->get(route('pqrsd.index'))
            ->assertOk()
            ->assertSee($caso->radicado, false);

        $this->actingAs($auxiliar)
            ->get(route('pqrsd.show', $caso))
            ->assertOk();

        $this->actingAs($auxiliar)
            ->post(route('pqrsd.clasificar', $caso), $this->conVersionExpediente($caso, [
                'tipo_pqrsd_id' => $tipo->id,
                'nivel_riesgo' => 'simple',
                'justificacion' => 'Clasificación operativa por auxiliar según hechos del caso.',
            ]))
            ->assertRedirect();

        $this->actingAs($auxiliar)
            ->get(route('pqrsd.remisiones.create', $caso))
            ->assertForbidden();

        $this->actingAs($auxiliar)
            ->post(route('pqrsd.responder', $caso), $this->conVersionExpediente($caso, [
                'detalle_respuesta' => 'Respuesta operativa del auxiliar al solicitante.',
                'respuesta_estado_destino' => 'puesta_disposicion',
                'poner_disposicion_consulta' => '1',
            ]))
            ->assertRedirect();

        $caso->refresh();
        $this->assertSame('respondido', $caso->estado_operativo);
    }

    public function test_auxiliar_no_administra_catalogos_ni_usuarios_ni_sla(): void
    {
        $auxiliar = User::where('email', 'auxiliar2@menteplena.test')->firstOrFail();

        $this->actingAs($auxiliar)->get(route('usuarios.index'))->assertForbidden();
        $this->actingAs($auxiliar)->get(route('motivos.index'))->assertForbidden();
        $this->actingAs($auxiliar)->get(route('tipos.index'))->assertForbidden();
        $this->actingAs($auxiliar)->get(route('sedes.index'))->assertForbidden();
        $this->actingAs($auxiliar)->get(route('aseguradoras.index'))->assertForbidden();
        $this->actingAs($auxiliar)->get(route('reglas.index'))->assertForbidden();
        $this->actingAs($auxiliar)->get(route('canales.index'))->assertForbidden();
    }

    public function test_auxiliar_conserva_perfil_indicadores_y_reportes(): void
    {
        $auxiliar = User::where('email', 'auxiliar1@menteplena.test')->firstOrFail();

        $this->actingAs($auxiliar)->get(route('profile.edit'))->assertOk();
        $this->actingAs($auxiliar)->get(route('reportes.indicadores'))->assertOk()->assertSee('Indicadores PQRSD', false);
        $this->actingAs($auxiliar)->get(route('reportes.index'))->assertOk();
        $this->actingAs($auxiliar)->get(route('publico.qr'))->assertOk();
    }

    public function test_radicacion_publica_sigue_funcionando(): void
    {
        $tipo = TipoPqrsd::where('codigo', 'PETICION')->firstOrFail();
        $sede = Sede::where('activo', true)->firstOrFail();
        $eps = Aseguradora::where('activo', true)->firstOrFail();

        $this->post(route('publico.store'), [
            'idempotency_key' => (string) Str::uuid(),
            'tipo_pqrsd_id' => $tipo->id,
            'sede_id' => $sede->id,
            'aseguradora_id' => $eps->id,
            'quien_radica' => 'paciente',
            'asunto' => 'Solicitud pública de prueba',
            'descripcion' => 'Solicitud ciudadana vía formulario público para simplificación operativa.',
            'solicitante_nombre' => 'Ciudadano Demo',
            'solicitante_tipo_documento' => 'CC',
            'solicitante_documento' => '1098765432',
            'solicitante_email' => 'ciudadano.demo@example.com',
            'medio_respuesta' => 'email',
            'consentimiento_comunicacion_email' => '1',
            'acepto_tratamiento_datos' => '1',
        ])->assertRedirect();

        $this->assertDatabaseHas('pqrsd', [
            'solicitante_nombre' => 'Ciudadano Demo',
        ]);
    }

    public function test_bandeja_filtra_por_motivo_y_combinados(): void
    {
        $admin = User::where('email', 'admin@menteplena.test')->firstOrFail();
        $auxiliar = User::where('email', 'auxiliar1@menteplena.test')->firstOrFail();
        $tipo = TipoPqrsd::where('codigo', 'QUEJA')->firstOrFail();
        $motivo = MotivoPqrsd::where('tipo_pqrsd_id', $tipo->id)->where('activo', true)->firstOrFail();
        $sede = Sede::where('codigo', 'URABA')->first() ?? Sede::where('activo', true)->firstOrFail();
        $eps = Aseguradora::where('activo', true)->firstOrFail();

        $conMotivo = app(PqrsdService::class)->radicarManual([
            'fecha_recepcion' => Carbon::parse('2026-09-05 10:00:00', 'America/Bogota'),
            'canal_ingreso_id' => CanalIngreso::where('codigo', 'MANUAL')->value('id'),
            'tipo_pqrsd_id' => $tipo->id,
            'motivo_pqrsd_id' => $motivo->id,
            'nivel_riesgo' => 'simple',
            'descripcion' => 'Caso con motivo para filtro bandeja',
            'solicitante_nombre' => 'Con Motivo',
            'sede_id' => $sede->id,
            'aseguradora_id' => $eps->id,
        ], $admin);

        $sinMotivo = app(PqrsdService::class)->radicarManual([
            'fecha_recepcion' => Carbon::parse('2026-09-06 10:00:00', 'America/Bogota'),
            'canal_ingreso_id' => CanalIngreso::where('codigo', 'MANUAL')->value('id'),
            'tipo_pqrsd_id' => $tipo->id,
            'nivel_riesgo' => 'simple',
            'descripcion' => 'Caso sin motivo para filtro bandeja',
            'solicitante_nombre' => 'Sin Motivo',
            'sede_id' => $sede->id,
            'aseguradora_id' => $eps->id,
        ], $admin);

        $htmlMotivo = $this->actingAs($auxiliar)
            ->get(route('pqrsd.index', ['motivo_pqrsd_id' => $motivo->id]))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString($conMotivo->radicado, $htmlMotivo);
        $this->assertStringNotContainsString($sinMotivo->radicado, $htmlMotivo);

        $htmlCombo = $this->actingAs($auxiliar)
            ->get(route('pqrsd.index', [
                'sede_id' => $sede->id,
                'aseguradora_id' => $eps->id,
                'tipo_pqrsd_id' => $tipo->id,
                'fecha_desde' => '2026-09-01',
                'fecha_hasta' => '2026-09-30',
            ]))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString($conMotivo->radicado, $htmlCombo);
        $this->assertStringContainsString($sinMotivo->radicado, $htmlCombo);
        $this->assertStringContainsString('name="motivo_pqrsd_id"', $htmlCombo);
        $this->assertStringContainsString('Más filtros', $htmlCombo);
    }

    public function test_evolucion_anual_mensual_y_sin_datos(): void
    {
        $admin = User::where('email', 'admin@menteplena.test')->firstOrFail();
        $tipo = TipoPqrsd::where('codigo', 'QUEJA')->firstOrFail();
        $motivo = MotivoPqrsd::where('tipo_pqrsd_id', $tipo->id)->where('activo', true)->first();
        $sede = Sede::where('activo', true)->firstOrFail();
        $eps = Aseguradora::where('activo', true)->firstOrFail();

        $marzo = app(PqrsdService::class)->radicarManual([
            'fecha_recepcion' => Carbon::parse('2026-03-10 09:00:00', 'America/Bogota'),
            'canal_ingreso_id' => CanalIngreso::where('codigo', 'MANUAL')->value('id'),
            'tipo_pqrsd_id' => $tipo->id,
            'motivo_pqrsd_id' => $motivo?->id,
            'nivel_riesgo' => 'simple',
            'descripcion' => 'Caso marzo evolución anual',
            'solicitante_nombre' => 'Demo Marzo',
            'sede_id' => $sede->id,
            'aseguradora_id' => $eps->id,
        ], $admin);
        app(VencimientoService::class)->registrarRespuesta(
            $marzo->fresh(),
            $admin,
            Carbon::parse('2026-03-11 10:00:00', 'America/Bogota')
        );

        $octubreA = app(PqrsdService::class)->radicarManual([
            'fecha_recepcion' => Carbon::parse('2026-10-05 09:00:00', 'America/Bogota'),
            'canal_ingreso_id' => CanalIngreso::where('codigo', 'MANUAL')->value('id'),
            'tipo_pqrsd_id' => $tipo->id,
            'motivo_pqrsd_id' => $motivo?->id,
            'nivel_riesgo' => 'simple',
            'descripcion' => 'Caso octubre día 5',
            'solicitante_nombre' => 'Demo Oct 5',
            'sede_id' => $sede->id,
            'aseguradora_id' => $eps->id,
        ], $admin);
        app(VencimientoService::class)->registrarRespuesta(
            $octubreA->fresh(),
            $admin,
            Carbon::parse('2026-10-06 10:00:00', 'America/Bogota')
        );

        $octubreB = app(PqrsdService::class)->radicarManual([
            'fecha_recepcion' => Carbon::parse('2026-10-20 09:00:00', 'America/Bogota'),
            'canal_ingreso_id' => CanalIngreso::where('codigo', 'MANUAL')->value('id'),
            'tipo_pqrsd_id' => $tipo->id,
            'nivel_riesgo' => 'simple',
            'descripcion' => 'Caso octubre día 20',
            'solicitante_nombre' => 'Demo Oct 20',
            'sede_id' => $sede->id,
            'aseguradora_id' => $eps->id,
        ], $admin);
        app(VencimientoService::class)->registrarRespuesta(
            $octubreB->fresh(),
            $admin,
            Carbon::parse('2026-10-21 10:00:00', 'America/Bogota')
        );

        $svc = app(IndicadoresPqrsdService::class);

        $anual = $svc->evolucionMensualRecepcion(new Request([
            'anio' => 2026,
            'mes' => 'todos',
            'sede_id' => $sede->id,
            'aseguradora_id' => $eps->id,
            'tipo_pqrsd_id' => $tipo->id,
            'motivo_pqrsd_id' => 'todos',
        ]));
        $this->assertSame('mes', $anual['agrupacion']);
        $this->assertCount(12, $anual['labels']);
        $this->assertSame(95.0, (float) $anual['meta']);
        $this->assertNotNull($anual['detalle'][2]['pct_oportunidad']); // marzo = índice 2
        $this->assertFalse($anual['sin_datos_serie']);

        $mensual = $svc->evolucionMensualRecepcion(new Request([
            'anio' => 2026,
            'mes' => 10,
            'sede_id' => $sede->id,
            'aseguradora_id' => $eps->id,
            'tipo_pqrsd_id' => $tipo->id,
        ]));
        $this->assertContains($mensual['agrupacion'], ['dia', 'semana']);
        $this->assertGreaterThan(1, count($mensual['labels']));
        $this->assertStringContainsString('Evolución', $mensual['titulo']);
        $this->assertFalse($mensual['sin_datos_serie']);

        $tablero = $svc->construir(new Request([
            'anio' => 2026,
            'mes' => 10,
            'sede_id' => $sede->id,
            'aseguradora_id' => $eps->id,
            'tipo_pqrsd_id' => $tipo->id,
            'motivo_pqrsd_id' => $motivo?->id ?? 'todos',
        ]));
        $this->assertSame($tablero['generales']['recibidas'], collect($tablero['evolucion_oportunidad']['detalle'])->sum('recibidos'));
        $this->assertSame(95.0, (float) $tablero['meta_oportunidad_pct']);

        $vacio = $svc->evolucionMensualRecepcion(new Request(['anio' => 2018, 'mes' => 'todos']));
        $this->assertTrue($vacio['sin_datos_serie']);
        $this->assertNull($vacio['detalle'][0]['pct_oportunidad']);

        $html = $this->actingAs($admin)
            ->get(route('reportes.indicadores', ['anio' => 2018]))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('Sin datos', $html);
        $this->assertStringContainsString('name="motivo_pqrsd_id"', $html);
    }
}
