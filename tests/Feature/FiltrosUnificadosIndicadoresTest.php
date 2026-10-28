<?php

namespace Tests\Feature;

use App\Models\Aseguradora;
use App\Models\CanalIngreso;
use App\Models\Pqrsd;
use App\Models\Sede;
use App\Models\TipoPqrsd;
use App\Models\User;
use App\Services\IndicadoresPqrsdService;
use App\Services\PqrsdService;
use App\Services\ReporteService;
use Carbon\Carbon;
use Database\Seeders\CatalogosSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\UsersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class FiltrosUnificadosIndicadoresTest extends TestCase
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

    public function test_la_pantalla_muestra_los_filtros_recuperados_en_una_sola_seccion(): void
    {
        $admin = User::where('email', 'admin@menteplena.test')->firstOrFail();
        $auxiliar = User::where('email', 'auxiliar1@menteplena.test')->firstOrFail();

        foreach ([$admin, $auxiliar] as $usuario) {
            $html = $this->actingAs($usuario)->get(route('reportes.indicadores'))->assertOk()->getContent();
            $this->assertSame(1, substr_count($html, '<h1>Indicadores y reportes</h1>'));
            $this->assertStringContainsString('name="desde"', $html);
            $this->assertStringContainsString('name="hasta"', $html);
            $this->assertStringContainsString('name="nivel_riesgo"', $html);
            $this->assertStringContainsString('name="responsable_id"', $html);
            $this->assertStringContainsString('name="estado_cumplimiento"', $html);
            $this->assertStringContainsString('name="anio"', $html);
            $this->assertStringContainsString('name="motivo_pqrsd_id"', $html);
            $this->assertStringContainsString('Más filtros', $html);
            $this->assertStringContainsString('Exportar Excel', $html);
            $this->assertStringContainsString('Exportar PDF', $html);
            $this->assertStringContainsString('Exportaciones', $html);
            $this->assertStringNotContainsString('href="'.route('reportes.index').'"', $html);
        }

        $sinPermiso = User::factory()->create(['activo' => true]);
        $sinPermiso->assignRole('responsable_area');
        $this->actingAs($sinPermiso)->get(route('reportes.indicadores'))->assertForbidden();
        $this->actingAs($sinPermiso)->get(route('reportes.index'))->assertForbidden();
    }

    public function test_cada_filtro_y_su_combinacion_cambian_la_cohorte_de_recepcion(): void
    {
        $admin = User::where('email', 'admin@menteplena.test')->firstOrFail();
        $otro = User::where('email', 'auxiliar1@menteplena.test')->firstOrFail();
        $sede = Sede::where('codigo', 'URABA')->firstOrFail();
        $otraSede = Sede::where('codigo', 'MEDELLIN')->firstOrFail();

        $marzo = $this->crear($admin, [
            'fecha_recepcion' => '2026-03-10 09:00:00',
            'sede_id' => $sede->id,
            'nivel_riesgo' => 'ordinario',
        ]);
        $marzo->update(['responsable_id' => $admin->id]);
        $octubre = $this->crear($admin, [
            'fecha_recepcion' => '2026-10-02 09:00:00',
            'sede_id' => $otraSede->id,
            'nivel_riesgo' => 'simple',
        ]);
        $octubre->update(['responsable_id' => $otro->id]);

        $this->assertMismosIds(['desde' => '2026-03-01', 'hasta' => '2026-03-31'], [$marzo->id]);
        $this->assertMismosIds(['nivel_riesgo' => 'ordinario'], [$marzo->id]);
        $this->assertMismosIds(['responsable_id' => $admin->id], [$marzo->id]);
        $this->assertMismosIds(['responsable_id' => $otro->id], [$octubre->id]);
        $this->assertMismosIds([
            'anio' => 2026,
            'mes' => 3,
            'sede_id' => $sede->id,
            'nivel_riesgo' => 'ordinario',
            'desde' => '2026-03-01',
            'hasta' => '2026-03-31',
            'responsable_id' => $admin->id,
        ], [$marzo->id]);
        $this->assertMismosIds([
            'anio' => 2026,
            'mes' => 10,
            'desde' => '2026-03-01',
            'hasta' => '2026-03-31',
        ], []);

        $html = $this->actingAs($admin)->get(route('reportes.indicadores', [
            'desde' => '2026-03-01',
            'hasta' => '2026-03-31',
            'nivel_riesgo' => 'ordinario',
        ]))->assertOk()->getContent();
        $this->assertStringContainsString($marzo->radicado, $html);
        $this->assertStringContainsString('Expediente completo', $html);
        $this->assertStringNotContainsString($octubre->radicado, $html);
        $this->assertStringContainsString('desde=2026-03-01', $html);
        $this->assertStringContainsString('nivel_riesgo=ordinario', $html);
        $this->assertStringContainsString(route('reportes.indicadores'), $html);
    }

    public function test_el_cumplimiento_usa_fechas_reales_aunque_el_estado_guardado_este_desactualizado(): void
    {
        $admin = User::where('email', 'admin@menteplena.test')->firstOrFail();
        $vencidoReal = $this->crear($admin, [
            'fecha_recepcion' => '2026-10-01 08:00:00',
            'nivel_riesgo' => 'simple',
        ]);
        $vencidoReal->update(['estado_cumplimiento' => 'dentro_plazo']);

        $alDia = $this->crear($admin, [
            'fecha_recepcion' => '2026-10-14 08:00:00',
            'nivel_riesgo' => 'simple',
        ]);
        $alDia->update(['estado_cumplimiento' => 'vencido']);

        $this->assertSame('vencido', $vencidoReal->fresh()->cumplimientoVigente());
        $this->assertSame('dentro_plazo', $alDia->fresh()->cumplimientoVigente());
        $this->assertMismosIds(['estado_cumplimiento' => 'vencido'], [$vencidoReal->id]);
        $this->assertMismosIds(['estado_cumplimiento' => 'dentro_plazo'], [$alDia->id]);
    }

    public function test_fechas_invalidas_no_se_aplican_y_un_rango_vacio_no_inventa_casos(): void
    {
        $admin = User::where('email', 'admin@menteplena.test')->firstOrFail();
        $caso = $this->crear($admin, [
            'fecha_recepcion' => '2026-03-10 09:00:00',
            'nivel_riesgo' => 'simple',
        ]);

        $this->actingAs($admin)
            ->get(route('reportes.indicadores', ['desde' => '2026-13-40']))
            ->assertOk()
            ->assertSee('La fecha desde no es válida', false)
            ->assertSee($caso->radicado, false)
            ->assertDontSee('desde=2026-13-40', false);

        $this->actingAs($admin)
            ->get(route('reportes.indicadores', ['desde' => '2026-10-20', 'hasta' => '2026-03-01']))
            ->assertOk()
            ->assertSee('anterior a la fecha desde', false)
            ->assertSee($caso->radicado, false);

        $this->actingAs($admin)
            ->get(route('reportes.excel', ['desde' => 'no-es-fecha']))
            ->assertRedirect(route('reportes.indicadores'));

        $vacio = $this->actingAs($admin)
            ->get(route('reportes.indicadores', ['anio' => 1999]))
            ->assertOk();
        $vacio->assertSee('No hay expedientes en esta cohorte.', false);
        $this->assertStringNotContainsString($caso->radicado, $vacio->getContent());
    }

    public function test_las_exportaciones_y_la_ruta_anterior_conservan_los_filtros_validos(): void
    {
        $admin = User::where('email', 'admin@menteplena.test')->firstOrFail();
        $incluido = $this->crear($admin, [
            'fecha_recepcion' => '2026-04-02 09:00:00',
            'nivel_riesgo' => 'ordinario',
        ]);
        $excluido = $this->crear($admin, [
            'fecha_recepcion' => '2026-04-03 09:00:00',
            'nivel_riesgo' => 'simple',
        ]);

        $this->actingAs($admin)
            ->get(route('reportes.index', [
                'desde' => '2026-04-01',
                'hasta' => '2026-04-30',
                'nivel_riesgo' => 'ordinario',
            ]))
            ->assertRedirect(route('reportes.indicadores', [
                'desde' => '2026-04-01',
                'hasta' => '2026-04-30',
                'nivel_riesgo' => 'ordinario',
            ]));

        $consulta = ['desde' => '2026-04-01', 'hasta' => '2026-04-30', 'nivel_riesgo' => 'ordinario'];
        $excel = $this->actingAs($admin)->get(route('reportes.excel', $consulta));
        $excel->assertOk();
        $texto = $this->textoExcel($excel->baseResponse->getFile()->getPathname());
        $this->assertStringContainsString($incluido->radicado, $texto);
        $this->assertStringNotContainsString($excluido->radicado, $texto);

        $this->actingAs($admin)->get(route('reportes.pdf', $consulta))->assertOk();
        $this->actingAs($admin)->get(route('reportes.indicadores.excel', $consulta))->assertOk();
        $this->actingAs($admin)->get(route('reportes.indicadores.pdf', $consulta))->assertOk();
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @param  list<int>  $esperados
     */
    private function assertMismosIds(array $filtros, array $esperados): void
    {
        $solicitud = new Request($filtros);
        $indicadores = app(IndicadoresPqrsdService::class)
            ->filtrarCohorteRecepcion($solicitud)
            ->pluck('id')
            ->sort()
            ->values()
            ->all();
        $reporte = app(ReporteService::class)
            ->filtrar($solicitud)
            ->pluck('id')
            ->sort()
            ->values()
            ->all();

        sort($esperados);
        $this->assertSame($esperados, $indicadores);
        $this->assertSame($indicadores, $reporte);
    }

    /**
     * @param  array<string, mixed>  $opts
     */
    private function crear(User $actor, array $opts): Pqrsd
    {
        return app(PqrsdService::class)->radicarManual([
            'fecha_recepcion' => Carbon::parse($opts['fecha_recepcion'], 'America/Bogota'),
            'canal_ingreso_id' => CanalIngreso::where('codigo', 'MANUAL')->value('id'),
            'tipo_pqrsd_id' => TipoPqrsd::where('codigo', 'QUEJA')->value('id'),
            'nivel_riesgo' => $opts['nivel_riesgo'] ?? 'simple',
            'descripcion' => 'Caso ficticio para filtros de indicadores',
            'solicitante_nombre' => 'Demo',
            'sede_id' => $opts['sede_id'] ?? Sede::where('activo', true)->value('id'),
            'aseguradora_id' => Aseguradora::where('activo', true)->value('id'),
        ], $actor)->fresh();
    }

    private function textoExcel(string $ruta): string
    {
        $hoja = IOFactory::load($ruta)->getActiveSheet()->toArray();
        $texto = '';
        foreach ($hoja as $fila) {
            $texto .= ' '.implode(' ', array_map(fn ($valor) => (string) $valor, $fila));
        }

        return $texto;
    }
}
