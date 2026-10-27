<?php

namespace Tests\Feature;

use App\Models\CanalIngreso;
use App\Models\Pqrsd;
use App\Models\ReglaSla;
use App\Models\User;
use App\Services\IndicadoresPqrsdService;
use App\Services\PqrsdService;
use App\Services\ReporteService;
use App\Services\SlaCalculator;
use Carbon\Carbon;
use Database\Seeders\CatalogosSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\UsersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class CoherenciaOportunidadPqrsdTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(CatalogosSeeder::class);
        $this->seed(UsersSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-10-23 12:00:00', 'America/Bogota'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_tablero_bandeja_vencimientos_y_reporte_usan_las_fechas(): void
    {
        $admin = User::where('email', 'admin@menteplena.test')->firstOrFail();

        $oportuna = $this->caso($admin, '2026-10-01 08:00:00', '2026-10-04 08:00:00', '2026-10-02 08:00:00', 'resuelto_extemporaneo');
        $tardia = $this->caso($admin, '2026-10-05 08:00:00', '2026-10-08 08:00:00', '2026-10-10 08:00:00', 'resuelto_oportuno');
        $enLimite = $this->caso($admin, '2026-10-20 08:00:00', '2026-10-23 08:00:00', '2026-10-23 08:00:00', 'dentro_plazo');
        $pendienteAlDia = $this->caso($admin, '2026-10-22 08:00:00', '2026-10-25 08:00:00', null, 'vencido');
        $pendienteVencido = $this->caso($admin, '2026-10-10 08:00:00', '2026-10-13 08:00:00', null, 'dentro_plazo');
        $otraCohorte = $this->caso($admin, '2026-09-15 08:00:00', '2026-09-18 08:00:00', '2026-09-16 08:00:00', 'resuelto_extemporaneo');

        $this->assertSame('resuelto_oportuno', $oportuna->cumplimientoVigente());
        $this->assertSame('resuelto_extemporaneo', $tardia->cumplimientoVigente());
        $this->assertSame('resuelto_oportuno', $enLimite->cumplimientoVigente());
        $this->assertSame('dentro_plazo', $pendienteAlDia->cumplimientoVigente());
        $this->assertSame('vencido', $pendienteVencido->cumplimientoVigente());

        $filtros = ['anio' => 2026, 'mes' => 10];
        $tablero = app(IndicadoresPqrsdService::class)->construir(Request::create('/reportes/indicadores', 'GET', $filtros));
        $this->assertSame(5, $tablero['generales']['recibidas']);
        $this->assertSame(2, $tablero['generales']['oportunas']);
        $this->assertSame(1, $tablero['generales']['fuera_plazo']);
        $this->assertSame(3, $tablero['generales']['evaluables']);
        $this->assertSame(66.7, $tablero['generales']['pct_oportunidad']);
        $this->assertSame(1, $tablero['generales']['vencidas_sin_respuesta']);
        $this->assertSame(2, $tablero['generales']['pendientes']);
        $this->assertSame(95.0, $tablero['generales']['meta_oportunidad_pct']);

        $reporte = app(ReporteService::class)->indicadores(
            app(ReporteService::class)->filtrar(Request::create('/reportes', 'GET', [
                'desde' => '2026-10-01',
                'hasta' => '2026-10-31',
            ]))
        );
        $this->assertSame($tablero['generales']['pct_oportunidad'], $reporte['pct_oportunidad']);
        $this->assertSame(2, $reporte['oportunos']);
        $this->assertSame(1, $reporte['extemporaneos']);
        $this->assertSame(1, $reporte['vencidos']);
        $this->assertSame(1, (int) ($reporte['por_cumplimiento_almacenado']['resuelto_oportuno'] ?? 0));
        $this->assertNotSame(
            (int) ($reporte['por_cumplimiento_almacenado']['resuelto_oportuno'] ?? 0),
            $reporte['oportunos']
        );

        $filas = app(ReporteService::class)->filasExportacion(Pqrsd::query()->whereKey($oportuna->id)->get());
        $this->assertSame('resuelto_oportuno', $filas->first()['Cumplimiento']);
        $this->assertSame('resuelto_extemporaneo', $filas->first()['Cumplimiento almacenado']);

        $filasIndicadores = app(IndicadoresPqrsdService::class)->filasExportacion($tablero);
        $this->assertTrue($filasIndicadores->contains(fn (array $fila) => ($fila['% Oportunidad'] ?? null) !== 'Sin datos'));

        $this->actingAs($admin)
            ->get(route('reportes.indicadores', $filtros))
            ->assertOk()
            ->assertSee('66.7%');

        $this->actingAs($admin)
            ->get(route('reportes.index', ['desde' => '2026-10-01', 'hasta' => '2026-10-31']))
            ->assertOk()
            ->assertSee('66.7%')
            ->assertSee('Respondido tardíamente');

        $pdf = view('reportes.pdf', [
            'rows' => app(ReporteService::class)->filasExportacion(
                Pqrsd::query()->whereBetween('fecha_recepcion', ['2026-10-01', '2026-10-31 23:59:59'])->get()
            ),
            'indicadores' => $reporte,
            'generado' => now('America/Bogota'),
            'filtros' => [],
        ])->render();
        $this->assertStringContainsString('66.7%', $pdf);

        $pdfIndicadores = view('reportes.indicadores-pdf', [
            'tablero' => $tablero,
            'rows' => $filasIndicadores,
            'generado' => now('America/Bogota'),
            'filtros' => $filtros,
        ])->render();
        $this->assertStringContainsString('66.7', $pdfIndicadores);

        $this->actingAs($admin)
            ->get(route('pqrsd.index'))
            ->assertOk()
            ->assertSee('Respondido oportunamente')
            ->assertSee('Respondido tardíamente')
            ->assertSee('Vencido')
            ->assertSee('Dentro del plazo');

        $this->actingAs($admin)
            ->get(route('pqrsd.index', ['estado_cumplimiento' => 'vencido']))
            ->assertOk()
            ->assertSee($pendienteVencido->radicado)
            ->assertDontSee($pendienteAlDia->radicado);

        $this->actingAs($admin)
            ->get(route('vencimientos.vencidos'))
            ->assertOk()
            ->assertSee($pendienteVencido->radicado)
            ->assertDontSee($pendienteAlDia->radicado)
            ->assertDontSee($otraCohorte->radicado);

        $this->assertSame('resuelto_extemporaneo', $oportuna->fresh()->estado_cumplimiento);
        $this->assertSame('2026-10-02 08:00:00', $oportuna->fresh()->fecha_respuesta->timezone('America/Bogota')->format('Y-m-d H:i:s'));
    }

    public function test_plazos_configurados_siguen_igual_y_ordinario_no_esta_aprobado(): void
    {
        $this->assertSame(8, ReglaSla::where('codigo', 'VITAL_MENOR_8H')->value('cantidad'));
        $this->assertSame(24, ReglaSla::where('codigo', 'VITAL_GENERAL_24H')->value('cantidad'));
        $this->assertSame(48, ReglaSla::where('codigo', 'PRIORIZADO_48H')->value('cantidad'));
        $this->assertSame(72, ReglaSla::where('codigo', 'SIMPLE_72H')->value('cantidad'));
        $this->assertSame(10, ReglaSla::where('codigo', 'ORDINARIO_10DH')->value('cantidad'));
        $this->assertSame(10, ReglaSla::where('codigo', 'DENUNCIA_10DH')->value('cantidad'));
        $this->assertTrue(config('pqrsd_sla.ordinario_validacion_juridica_pendiente'));
        $this->assertTrue(config('pqrsd_sla.denuncia_aprobacion_juridica_produccion_pendiente'));
        $this->assertSame(95.0, (float) config('pqrsd_sla.meta_oportunidad_pct'));

        $viernes = Carbon::parse('2026-10-09 15:00:00', 'America/Bogota');
        $regla = ReglaSla::where('codigo', 'ORDINARIO_10DH')->firstOrFail();
        $limite = app(SlaCalculator::class)->calcularFechaLimite($regla, $viernes);
        $this->assertTrue($limite->isWeekday());
        $this->assertTrue($limite->greaterThan($viernes->copy()->addDays(10)));
    }

    private function caso(User $actor, string $recepcion, string $limite, ?string $respuesta, string $estadoAlmacenado): Pqrsd
    {
        $caso = app(PqrsdService::class)->radicarManual([
            'fecha_recepcion' => Carbon::parse($recepcion, 'America/Bogota'),
            'canal_ingreso_id' => CanalIngreso::where('codigo', 'MANUAL')->value('id'),
            'nivel_riesgo' => 'simple',
            'paciente_edad' => 40,
            'descripcion' => 'Caso ficticio de coherencia de oportunidad. No es un paciente real.',
            'es_anonimo' => 1,
        ], $actor);

        $caso->update([
            'fecha_limite' => Carbon::parse($limite, 'America/Bogota'),
            'fecha_respuesta' => $respuesta ? Carbon::parse($respuesta, 'America/Bogota') : null,
            'estado_cumplimiento' => $estadoAlmacenado,
        ]);

        return $caso->fresh();
    }
}
