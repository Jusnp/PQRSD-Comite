<?php

namespace Tests\Feature;

use App\Models\Aseguradora;
use App\Models\CanalIngreso;
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
use Tests\TestCase;

class IndicadoresPqrsdTest extends TestCase
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

    public function test_tres_operadores_acceden_a_indicadores_y_login_sigue_en_bandeja(): void
    {
        foreach (['admin@menteplena.test', 'auxiliar1@menteplena.test', 'auxiliar2@menteplena.test'] as $email) {
            $user = User::where('email', $email)->firstOrFail();
            $this->actingAs($user)
                ->get(route('reportes.indicadores'))
                ->assertOk()
                ->assertSee('Indicadores PQRSD', false)
                ->assertSee('Bandeja de PQRSD', false)
                ->assertDontSee('Dashboard PQRSD', false);
            $this->post('/logout');
        }
    }

    public function test_filtros_anio_mes_sede_aseguradora_y_combinacion(): void
    {
        $admin = User::where('email', 'admin@menteplena.test')->firstOrFail();
        $sede = Sede::where('codigo', 'URABA')->firstOrFail();
        $otra = Sede::where('codigo', 'MEDELLIN')->firstOrFail();
        $eps = Aseguradora::where('codigo', 'SAVIA')->firstOrFail();

        $this->crearCaso($admin, [
            'fecha_recepcion' => '2026-03-10 09:00:00',
            'sede_id' => $sede->id,
            'aseguradora_id' => $eps->id,
            'nivel_riesgo' => 'simple',
            'responder_en' => '2026-03-11 10:00:00',
        ]);
        $this->crearCaso($admin, [
            'fecha_recepcion' => '2026-03-10 09:00:00',
            'sede_id' => $otra->id,
            'aseguradora_id' => $eps->id,
            'nivel_riesgo' => 'simple',
            'responder_en' => '2026-03-11 10:00:00',
        ]);

        $svc = app(IndicadoresPqrsdService::class);
        $soloUraba = $svc->construir(new Request([
            'anio' => 2026,
            'mes' => 3,
            'sede_id' => $sede->id,
            'aseguradora_id' => $eps->id,
            'tipo_pqrsd_id' => 'todos',
        ]));

        $this->assertSame(1, $soloUraba['generales']['recibidas']);
        $this->assertSame(1, $soloUraba['generales']['respondidas']);
    }

    public function test_oportunidad_en_limite_y_fuera_de_plazo(): void
    {
        $admin = User::where('email', 'admin@menteplena.test')->firstOrFail();
        $recepcion = Carbon::parse('2026-10-14 08:00:00', 'America/Bogota');

        $oportuno = $this->crearCaso($admin, [
            'fecha_recepcion' => $recepcion,
            'nivel_riesgo' => 'simple', // 72h
            'responder_en' => $recepcion->copy()->addHours(72),
        ]);
        $fuera = $this->crearCaso($admin, [
            'fecha_recepcion' => $recepcion,
            'nivel_riesgo' => 'simple',
            'responder_en' => $recepcion->copy()->addHours(72)->addMinute(),
        ]);

        $svc = app(IndicadoresPqrsdService::class);
        $this->assertTrue($svc->esOportuno($oportuno->fresh()));
        $this->assertFalse($svc->esOportuno($fuera->fresh()));

        $tablero = $svc->construir(new Request(['anio' => 2026, 'mes' => 10]));
        $this->assertSame(2, $tablero['generales']['evaluables']);
        $this->assertSame(1, $tablero['generales']['oportunas']);
        $this->assertSame(1, $tablero['generales']['fuera_plazo']);
        $this->assertSame(50.0, $tablero['generales']['pct_oportunidad']);
    }

    public function test_pendientes_y_vencidos_sin_respuesta_y_sin_datos(): void
    {
        $admin = User::where('email', 'admin@menteplena.test')->firstOrFail();
        $this->crearCaso($admin, [
            'fecha_recepcion' => '2026-10-01 08:00:00',
            'nivel_riesgo' => 'simple',
            // sin respuesta; límite 72h → vencido a 14-oct
        ]);

        $svc = app(IndicadoresPqrsdService::class);
        $tablero = $svc->construir(new Request(['anio' => 2026, 'mes' => 10]));
        $this->assertGreaterThanOrEqual(1, $tablero['generales']['pendientes']);
        $this->assertGreaterThanOrEqual(1, $tablero['generales']['vencidas_sin_respuesta']);

        $vacio = $svc->construir(new Request(['anio' => 2019, 'mes' => 'todos']));
        $this->assertSame(0, $vacio['generales']['recibidas']);
        $this->assertNull($vacio['generales']['pct_oportunidad']);
    }

    public function test_vital_menor_por_fecha_nacimiento_en_recepcion(): void
    {
        $admin = User::where('email', 'admin@menteplena.test')->firstOrFail();
        $caso = $this->crearCaso($admin, [
            'fecha_recepcion' => '2026-03-01 10:00:00',
            'nivel_riesgo' => 'vital_general',
            'paciente_fecha_nacimiento' => '2008-06-01', // 17 años a marzo 2026
            'responder_en' => '2026-03-01 17:00:00',
        ]);

        $caso->refresh();
        $this->assertSame('vital_menor', $caso->nivel_riesgo);
        $this->assertSame(17, $caso->paciente_edad);
        $this->assertNotNull($caso->paciente_fecha_nacimiento);
    }

    public function test_felicitacion_sin_sla_inventado_y_en_catalogo(): void
    {
        $tipo = TipoPqrsd::where('codigo', 'FELICITACION')->first();
        $this->assertNotNull($tipo);

        $admin = User::where('email', 'admin@menteplena.test')->firstOrFail();
        $caso = app(PqrsdService::class)->radicarManual([
            'fecha_recepcion' => Carbon::parse('2026-10-14 09:00:00', 'America/Bogota'),
            'canal_ingreso_id' => CanalIngreso::where('codigo', 'MANUAL')->value('id'),
            'tipo_pqrsd_id' => $tipo->id,
            'nivel_riesgo' => 'pendiente',
            'descripcion' => 'Felicitación al personal de enfermería por trato humanizado.',
            'servicio_area' => 'Enfermería Urabá',
            'solicitante_nombre' => 'Usuario Demo',
        ], $admin);

        $this->assertNull($caso->fecha_limite);
        $this->assertSame('sin_clasificar', $caso->estado_cumplimiento);

        $this->actingAs($admin)
            ->get(route('reportes.indicadores', ['anio' => 2026]))
            ->assertOk()
            ->assertSee('Felicitación', false);
    }

    public function test_denuncia_regla_activa_10_dias_habiles(): void
    {
        $this->assertFalse(config('pqrsd_sla.denuncia_unidad_conteo_pendiente_confirmacion'));
        $regla = \App\Models\ReglaSla::where('codigo', 'DENUNCIA_10DH')->first();
        $this->assertNotNull($regla);
        $this->assertTrue((bool) $regla->activo);
        $this->assertSame('dias_habiles', $regla->unidad);
        $this->assertSame(10, (int) $regla->cantidad);
    }

    public function test_fecha_nacimiento_invalida_futura_rechazada_en_manual(): void
    {
        $admin = User::where('email', 'admin@menteplena.test')->firstOrFail();
        $this->actingAs($admin)->post(route('pqrsd.store'), [
            'fecha_recepcion' => now('America/Bogota')->format('Y-m-d\TH:i'),
            'canal_ingreso_id' => CanalIngreso::where('codigo', 'MANUAL')->value('id'),
            'tipo_pqrsd_id' => TipoPqrsd::where('codigo', 'QUEJA')->value('id'),
            'nivel_riesgo' => 'simple',
            'descripcion' => 'Caso con fecha de nacimiento futura inválida para prueba.',
            'paciente_fecha_nacimiento' => now('America/Bogota')->addDay()->toDateString(),
        ])->assertSessionHasErrors('paciente_fecha_nacimiento');
    }

    public function test_exportacion_indicadores_excel_pdf(): void
    {
        $admin = User::where('email', 'admin@menteplena.test')->firstOrFail();
        $this->actingAs($admin)->get(route('reportes.indicadores.excel'))->assertOk();
        $this->actingAs($admin)->get(route('reportes.indicadores.pdf'))->assertOk();
    }

    public function test_terminologia_respuesta_de_pqrs_en_expediente(): void
    {
        $admin = User::where('email', 'admin@menteplena.test')->firstOrFail();
        $caso = $this->crearCaso($admin, [
            'fecha_recepcion' => '2026-10-14 08:00:00',
            'nivel_riesgo' => 'simple',
        ]);

        $html = $this->actingAs($admin)
            ->get(route('pqrsd.show', $caso))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Respuesta de PQRS', $html);
        $this->assertStringContainsString('Guardar respuesta', $html);
        $this->assertStringContainsString('Fecha de elaboración de respuesta', $html);
        $this->assertStringContainsString('Disponible en consulta segura', $html);
        $this->assertStringNotContainsString('Registrar respuesta de fondo', $html);
    }

    /**
     * @param  array<string, mixed>  $opts
     */
    private function crearCaso(User $actor, array $opts): \App\Models\Pqrsd
    {
        $recepcion = $opts['fecha_recepcion'] instanceof Carbon
            ? $opts['fecha_recepcion']
            : Carbon::parse($opts['fecha_recepcion'], 'America/Bogota');

        $caso = app(PqrsdService::class)->radicarManual([
            'fecha_recepcion' => $recepcion,
            'canal_ingreso_id' => CanalIngreso::where('codigo', 'MANUAL')->value('id'),
            'tipo_pqrsd_id' => $opts['tipo_pqrsd_id'] ?? TipoPqrsd::where('codigo', 'QUEJA')->value('id'),
            'nivel_riesgo' => $opts['nivel_riesgo'] ?? 'simple',
            'descripcion' => $opts['descripcion'] ?? 'Caso ficticio para indicadores de calidad',
            'solicitante_nombre' => 'Demo',
            'sede_id' => $opts['sede_id'] ?? Sede::where('activo', true)->value('id'),
            'aseguradora_id' => $opts['aseguradora_id'] ?? Aseguradora::where('activo', true)->value('id'),
            'paciente_fecha_nacimiento' => $opts['paciente_fecha_nacimiento'] ?? null,
            'paciente_edad' => $opts['paciente_edad'] ?? null,
        ], $actor);

        if (! empty($opts['responder_en'])) {
            $fecha = $opts['responder_en'] instanceof Carbon
                ? $opts['responder_en']
                : Carbon::parse($opts['responder_en'], 'America/Bogota');
            app(VencimientoService::class)->registrarRespuesta($caso->fresh(), $actor, $fecha);
        }

        return $caso->fresh();
    }
}
