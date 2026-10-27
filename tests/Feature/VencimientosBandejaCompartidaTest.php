<?php

namespace Tests\Feature;

use App\Models\CanalIngreso;
use App\Models\Pqrsd;
use App\Models\TipoPqrsd;
use App\Models\User;
use App\Services\PqrsdService;
use App\Services\VencimientoService;
use Carbon\Carbon;
use Database\Seeders\CatalogosSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Vencimientos con bandeja compartida: sin dependencia de responsable_id.
 */
class VencimientosBandejaCompartidaTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $auxiliar1;

    private User $auxiliar2;

    private CanalIngreso $canal;

    private Carbon $ahora;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(CatalogosSeeder::class);

        $this->admin = User::factory()->create([
            'email' => 'admin@menteplena.test',
            'activo' => true,
        ]);
        $this->admin->assignRole('admin');

        $this->auxiliar1 = User::factory()->create([
            'email' => 'auxiliar1@menteplena.test',
            'activo' => true,
        ]);
        $this->auxiliar1->assignRole('auxiliar_pqrsd');

        $this->auxiliar2 = User::factory()->create([
            'email' => 'auxiliar2@menteplena.test',
            'activo' => true,
        ]);
        $this->auxiliar2->assignRole('auxiliar_pqrsd');

        $this->canal = CanalIngreso::where('codigo', 'MANUAL')->firstOrFail();
        $this->ahora = Carbon::parse('2026-10-19 10:00:00', 'America/Bogota');
        Carbon::setTestNow($this->ahora);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_proximos_y_vencidos_visibles_sin_responsable_para_admin_y_auxiliares(): void
    {
        $proximo = $this->radicar([
            'fecha_recepcion' => $this->ahora->copy()->subHours(21),
            'nivel_riesgo' => 'vital_general',
            'paciente_edad' => 40,
            'descripcion' => 'Caso próximo a vencer sin responsable',
        ]);
        $this->assertNull($proximo->responsable_id);
        $this->assertSame('proximo_vencer', $proximo->fresh()->estado_cumplimiento);

        $vencido = $this->radicar([
            'fecha_recepcion' => $this->ahora->copy()->subHours(80),
            'nivel_riesgo' => 'simple',
            'descripcion' => 'Caso vencido sin responsable',
        ]);
        $this->assertNull($vencido->responsable_id);
        $this->assertSame('vencido', $vencido->fresh()->estado_cumplimiento);

        $vitalProximo = $this->radicar([
            'fecha_recepcion' => $this->ahora->copy()->subHours(7),
            'nivel_riesgo' => 'vital_menor',
            'paciente_edad' => 10,
            'descripcion' => 'Vital menor próximo a vencer',
        ]);
        $this->assertSame('proximo_vencer', $vitalProximo->fresh()->estado_cumplimiento);

        $fueraUmbral = $this->radicar([
            'fecha_recepcion' => $this->ahora->copy()->subHours(12),
            'nivel_riesgo' => 'simple',
            'descripcion' => 'Simple dentro de plazo fuera de umbral',
        ]);
        $this->assertSame('dentro_plazo', $fueraUmbral->fresh()->estado_cumplimiento);

        $respondido = $this->radicar([
            'fecha_recepcion' => $this->ahora->copy()->subHours(10),
            'nivel_riesgo' => 'simple',
            'descripcion' => 'Respondido oportunamente',
        ]);
        app(VencimientoService::class)->registrarRespuesta(
            $respondido,
            $this->admin,
            $this->ahora->copy()->subHours(2)
        );
        $this->assertSame('resuelto_oportuno', $respondido->fresh()->estado_cumplimiento);

        $cerrado = $this->radicar([
            'fecha_recepcion' => $this->ahora->copy()->subHours(20),
            'nivel_riesgo' => 'simple',
            'descripcion' => 'Caso cerrado no debe listarse',
        ]);
        app(VencimientoService::class)->registrarRespuesta(
            $cerrado,
            $this->admin,
            $this->ahora->copy()->subHours(1)
        );
        app(VencimientoService::class)->cerrar($cerrado->fresh(), $this->admin, 'Cierre demo evidencia respuesta');
        $this->assertSame('cerrado', $cerrado->fresh()->estado_operativo);

        foreach ([$this->admin, $this->auxiliar1, $this->auxiliar2] as $operador) {
            $htmlProximos = $this->actingAs($operador)
                ->get(route('vencimientos.proximos'))
                ->assertOk()
                ->assertSee($proximo->radicado, false)
                ->assertSee($vitalProximo->radicado, false)
                ->assertDontSee($fueraUmbral->radicado, false)
                ->assertDontSee($vencido->radicado, false)
                ->assertDontSee($respondido->radicado, false)
                ->assertDontSee($cerrado->radicado, false)
                ->assertSee('Estado respuesta', false)
                ->assertSee('Restan', false)
                ->getContent();

            $this->assertStringNotContainsString('Responsable', $htmlProximos);

            $this->actingAs($operador)
                ->get(route('vencimientos.vencidos'))
                ->assertOk()
                ->assertSee($vencido->radicado, false)
                ->assertDontSee($proximo->radicado, false)
                ->assertDontSee($fueraUmbral->radicado, false)
                ->assertDontSee($cerrado->radicado, false)
                ->assertSee('Vencido hace', false);

            $this->actingAs($operador)
                ->get(route('vencimientos.vitales'))
                ->assertOk()
                ->assertSee($proximo->radicado, false)
                ->assertSee($vitalProximo->radicado, false)
                ->assertDontSee($vencido->radicado, false)
                ->assertSee('Prioridad vital', false);
        }

        // Coherencia con Bandeja: los mismos radicados abiertos aparecen allí.
        $bandeja = $this->actingAs($this->auxiliar1)
            ->get(route('pqrsd.index'))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString($proximo->radicado, $bandeja);
        $this->assertStringContainsString($vencido->radicado, $bandeja);
        $this->assertStringContainsString($fueraUmbral->radicado, $bandeja);

        // Indicadores usan cohorte por recepción; no deben romper con estos casos.
        $this->actingAs($this->admin)
            ->get(route('reportes.indicadores'))
            ->assertOk()
            ->assertSee('95%', false);
    }

    public function test_mensaje_vacio_claro_cuando_no_hay_proximos(): void
    {
        $this->radicar([
            'fecha_recepcion' => $this->ahora->copy()->subHours(12),
            'nivel_riesgo' => 'simple',
            'descripcion' => 'Solo dentro de plazo',
        ]);

        $this->actingAs($this->auxiliar1)
            ->get(route('vencimientos.proximos'))
            ->assertOk()
            ->assertSee('No hay radicados próximos a vencer según el umbral SLA actual', false)
            ->assertDontSee('No hay casos en este estado.', false);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function radicar(array $extra): Pqrsd
    {
        $caso = app(PqrsdService::class)->radicarManual(array_merge([
            'canal_ingreso_id' => $this->canal->id,
            'tipo_pqrsd_id' => TipoPqrsd::where('codigo', 'QUEJA')->value('id'),
            'es_anonimo' => 1,
        ], $extra), $this->admin);

        return app(VencimientoService::class)->refrescarCumplimiento($caso->fresh());
    }
}
