<?php

namespace Tests\Feature;

use App\Models\CanalIngreso;
use App\Models\ReglaSla;
use App\Models\TipoPqrsd;
use App\Models\User;
use App\Services\IndicadoresPqrsdService;
use App\Services\PqrsdService;
use App\Services\SlaCalculator;
use App\Services\VencimientoService;
use Carbon\Carbon;
use Database\Seeders\CatalogosSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class DenunciaYMetaOportunidadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(CatalogosSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-10-14 12:00:00', 'America/Bogota'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_regla_denuncia_10_dias_habiles_activa_en_desarrollo(): void
    {
        $regla = ReglaSla::where('codigo', 'DENUNCIA_10DH')->first();
        $this->assertNotNull($regla);
        $this->assertTrue($regla->activo);
        $this->assertSame(10, (int) $regla->cantidad);
        $this->assertSame('dias_habiles', $regla->unidad);
        $this->assertFalse((bool) config('pqrsd_sla.denuncia_unidad_conteo_pendiente_confirmacion'));
        $this->assertSame(95.0, (float) config('pqrsd_sla.meta_oportunidad_pct'));
    }

    public function test_denuncia_desde_viernes_salta_finde_y_festivo(): void
    {
        // Viernes 9 oct 2026. Festivo lun 12. 10 hábiles → lun 26 oct 10:00 (misma convención que ordinario).
        $user = User::factory()->create(['activo' => true]);
        $user->assignRole('auxiliar_pqrsd');
        $recepcion = Carbon::parse('2026-10-09 10:00:00', 'America/Bogota');
        Carbon::setTestNow($recepcion);

        $caso = app(PqrsdService::class)->radicarManual([
            'fecha_recepcion' => $recepcion,
            'canal_ingreso_id' => CanalIngreso::where('codigo', 'MANUAL')->value('id'),
            'tipo_pqrsd_id' => TipoPqrsd::where('codigo', 'DENUNCIA')->value('id'),
            'nivel_riesgo' => 'ordinario',
            'descripcion' => 'Denuncia ficticia para SLA de 10 días hábiles',
            'solicitante_nombre' => 'Demo',
        ], $user);

        $this->assertSame('DENUNCIA_10DH', $caso->reglaSla?->codigo);
        $this->assertTrue($caso->fecha_inicio_conteo->equalTo($recepcion));
        $this->assertTrue(
            $caso->fecha_limite->equalTo(Carbon::parse('2026-10-26 10:00:00', 'America/Bogota')),
            'Límite denuncia: '.$caso->fecha_limite
        );
    }

    public function test_denuncia_que_atraviesa_festivo_desde_antes_del_12_oct(): void
    {
        $user = User::factory()->create(['activo' => true]);
        $user->assignRole('admin');
        $recepcion = Carbon::parse('2026-10-08 09:00:00', 'America/Bogota'); // jueves
        Carbon::setTestNow($recepcion);

        $caso = app(PqrsdService::class)->radicarManual([
            'fecha_recepcion' => $recepcion,
            'canal_ingreso_id' => CanalIngreso::where('codigo', 'MANUAL')->value('id'),
            'tipo_pqrsd_id' => TipoPqrsd::where('codigo', 'DENUNCIA')->value('id'),
            'nivel_riesgo' => 'pendiente',
            'descripcion' => 'Denuncia atravesando festivo 12 oct',
            'solicitante_nombre' => 'Demo',
        ], $user);

        $this->assertSame('DENUNCIA_10DH', $caso->reglaSla?->codigo);
        // Jueves 8: 10 hábiles saltando 10-11 (finde) y 12 (festivo) → viernes 23 oct 09:00
        $this->assertTrue(
            $caso->fecha_limite->equalTo(Carbon::parse('2026-10-23 09:00:00', 'America/Bogota')),
            'Límite: '.$caso->fecha_limite
        );
    }

    public function test_denuncia_respuesta_exacta_y_despues_del_vencimiento(): void
    {
        $user = User::factory()->create(['activo' => true]);
        $user->assignRole('auxiliar_pqrsd');
        $recepcion = Carbon::parse('2026-10-09 10:00:00', 'America/Bogota');
        Carbon::setTestNow($recepcion);

        $caso = app(PqrsdService::class)->radicarManual([
            'fecha_recepcion' => $recepcion,
            'canal_ingreso_id' => CanalIngreso::where('codigo', 'MANUAL')->value('id'),
            'tipo_pqrsd_id' => TipoPqrsd::where('codigo', 'DENUNCIA')->value('id'),
            'nivel_riesgo' => 'ordinario',
            'descripcion' => 'Denuncia para oportunidad en límite',
            'solicitante_nombre' => 'Demo',
        ], $user);

        $limite = $caso->fecha_limite->copy();
        $svc = app(IndicadoresPqrsdService::class);
        $venc = app(VencimientoService::class);

        $oportuno = $venc->registrarRespuesta($caso->fresh(), $user, $limite);
        $this->assertTrue($svc->esOportuno($oportuno));
        $this->assertSame('resuelto_oportuno', $oportuno->estado_cumplimiento);

        $caso2 = app(PqrsdService::class)->radicarManual([
            'fecha_recepcion' => $recepcion,
            'canal_ingreso_id' => CanalIngreso::where('codigo', 'MANUAL')->value('id'),
            'tipo_pqrsd_id' => TipoPqrsd::where('codigo', 'DENUNCIA')->value('id'),
            'nivel_riesgo' => 'ordinario',
            'descripcion' => 'Denuncia fuera de plazo',
            'solicitante_nombre' => 'Demo',
        ], $user);
        $fuera = $venc->registrarRespuesta($caso2->fresh(), $user, $limite->copy()->addMinute());
        $this->assertFalse($svc->esOportuno($fuera));
        $this->assertSame('resuelto_extemporaneo', $fuera->estado_cumplimiento);
    }

    public function test_vital_sigue_teniendo_prioridad_sobre_denuncia(): void
    {
        $user = User::factory()->create(['activo' => true]);
        $user->assignRole('admin');
        $recepcion = Carbon::parse('2026-10-14 08:00:00', 'America/Bogota');

        $caso = app(PqrsdService::class)->radicarManual([
            'fecha_recepcion' => $recepcion,
            'canal_ingreso_id' => CanalIngreso::where('codigo', 'MANUAL')->value('id'),
            'tipo_pqrsd_id' => TipoPqrsd::where('codigo', 'DENUNCIA')->value('id'),
            'nivel_riesgo' => 'vital_general',
            'paciente_fecha_nacimiento' => '1990-01-01',
            'descripcion' => 'Denuncia con riesgo vital — prioriza SLA vital',
            'solicitante_nombre' => 'Demo',
        ], $user);

        $this->assertSame('vital_general', $caso->nivel_riesgo);
        $this->assertSame('VITAL_GENERAL_24H', $caso->reglaSla?->codigo);
        $this->assertTrue($caso->fecha_limite->equalTo($recepcion->copy()->addHours(24)));
    }

    public function test_meta_95_cumple_94_99_no_cumple_100_cumple_sin_datos(): void
    {
        $svc = app(IndicadoresPqrsdService::class);
        $this->assertSame(95.0, $svc->metaOportunidad());

        $this->assertSame('cumple', $svc->evaluacionMeta(95.0));
        $this->assertSame('cumple', $svc->evaluacionMeta(100.0));
        $this->assertSame('no_cumple', $svc->evaluacionMeta(94.99));
        $this->assertSame('sin_datos', $svc->evaluacionMeta(null));

        // 19/20 = 95.0 exacto → cumple; comparación sin redondear previo
        $this->assertSame('cumple', $svc->evaluacionMeta($svc->pctOportunidadPreciso(19, 20)));
        // 94.99 simulado
        $this->assertSame('no_cumple', $svc->evaluacionMeta(94.99));
    }

    public function test_tablero_refleja_meta_y_linea_95_sin_distorsionar_felicitaciones(): void
    {
        $user = User::factory()->create(['activo' => true]);
        $user->assignRole('admin');
        $recepcion = Carbon::parse('2026-10-14 08:00:00', 'America/Bogota');

        // 20 respondidos simples: 19 oportunos, 1 fuera → 95%
        for ($i = 0; $i < 19; $i++) {
            $c = app(PqrsdService::class)->radicarManual([
                'fecha_recepcion' => $recepcion,
                'canal_ingreso_id' => CanalIngreso::where('codigo', 'MANUAL')->value('id'),
                'tipo_pqrsd_id' => TipoPqrsd::where('codigo', 'QUEJA')->value('id'),
                'nivel_riesgo' => 'simple',
                'descripcion' => "Caso oportuno {$i} para meta 95",
                'solicitante_nombre' => 'Demo',
            ], $user);
            app(VencimientoService::class)->registrarRespuesta($c->fresh(), $user, $recepcion->copy()->addHours(24));
        }
        $fuera = app(PqrsdService::class)->radicarManual([
            'fecha_recepcion' => $recepcion,
            'canal_ingreso_id' => CanalIngreso::where('codigo', 'MANUAL')->value('id'),
            'tipo_pqrsd_id' => TipoPqrsd::where('codigo', 'QUEJA')->value('id'),
            'nivel_riesgo' => 'simple',
            'descripcion' => 'Caso fuera de plazo para meta 95',
            'solicitante_nombre' => 'Demo',
        ], $user);
        app(VencimientoService::class)->registrarRespuesta($fuera->fresh(), $user, $recepcion->copy()->addHours(80));

        // Felicitación sin SLA — no debe alterar evaluables
        app(PqrsdService::class)->radicarManual([
            'fecha_recepcion' => $recepcion,
            'canal_ingreso_id' => CanalIngreso::where('codigo', 'MANUAL')->value('id'),
            'tipo_pqrsd_id' => TipoPqrsd::where('codigo', 'FELICITACION')->value('id'),
            'nivel_riesgo' => 'pendiente',
            'descripcion' => 'Felicitación sin plazo — no distorsiona oportunidad',
            'solicitante_nombre' => 'Demo',
        ], $user);

        $tablero = app(IndicadoresPqrsdService::class)->construir(new Request([
            'anio' => 2026,
            'mes' => 10,
            'sede_id' => 'todos',
            'aseguradora_id' => 'todos',
            'tipo_pqrsd_id' => 'todos',
        ]));

        $this->assertSame(95.0, $tablero['meta_oportunidad_pct']);
        $this->assertSame(20, $tablero['generales']['evaluables']);
        $this->assertSame(95.0, $tablero['generales']['pct_oportunidad_preciso']);
        $this->assertSame('cumple', $tablero['generales']['cumplimiento_meta']);
        $this->assertSame(95.0, $tablero['evolucion_oportunidad']['meta']);

        $felic = collect($tablero['circulares_tipo'])->firstWhere('clave', 'FELICITACION');
        $this->assertNotNull($felic);
        $this->assertSame('sin_datos', $felic['cumplimiento_meta']);

        $this->actingAs($user)
            ->get(route('reportes.indicadores', ['anio' => 2026, 'mes' => 10]))
            ->assertOk()
            ->assertSee('Meta institucional de oportunidad', false)
            ->assertSee('95', false);
    }

    public function test_ordinario_no_usa_regla_denuncia_por_prioridad_de_codigo(): void
    {
        $regla = app(SlaCalculator::class)->reglaParaNivel('ordinario');
        $this->assertSame('ORDINARIO_10DH', $regla?->codigo);
    }
}
