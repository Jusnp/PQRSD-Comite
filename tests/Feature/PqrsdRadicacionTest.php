<?php

namespace Tests\Feature;

use App\Models\CanalIngreso;
use App\Models\Pqrsd;
use App\Models\User;
use App\Services\RadicadoGenerator;
use App\Services\SlaCalculator;
use Database\Seeders\CatalogosSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PqrsdRadicacionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(CatalogosSeeder::class);
    }

    public function test_admin_puede_radicar_manual_con_numero_unico(): void
    {
        $user = User::factory()->create(['activo' => true]);
        $user->assignRole('admin');

        $canal = CanalIngreso::where('codigo', 'MANUAL')->firstOrFail();

        $response = $this->actingAs($user)->post(route('pqrsd.store'), [
            'canal_ingreso_id' => $canal->id,
            'nivel_riesgo' => 'ordinario',
            'descripcion' => 'Caso de prueba sintética sin datos reales de pacientes.',
            'es_anonimo' => 1,
        ]);

        $caso = Pqrsd::first();
        $this->assertNotNull($caso);
        $this->assertMatchesRegularExpression('/^PQRSD-\d{4}-\d{6}$/', $caso->radicado);
        $response->assertRedirect(route('pqrsd.show', $caso));
        $this->assertDatabaseHas('actuaciones_pqrsd', [
            'pqrsd_id' => $caso->id,
            'tipo' => 'radicacion',
        ]);
        $this->assertDatabaseHas('historial_estados_pqrsd', [
            'pqrsd_id' => $caso->id,
            'campo' => 'estado_operativo',
        ]);
    }

    public function test_auxiliar_no_puede_radicar_manual_por_http(): void
    {
        $user = User::factory()->create(['activo' => true]);
        $user->assignRole('auxiliar_pqrsd');
        $canal = CanalIngreso::where('codigo', 'MANUAL')->firstOrFail();

        $this->actingAs($user)->get(route('pqrsd.create'))->assertForbidden();
        $this->actingAs($user)->post(route('pqrsd.store'), [
            'canal_ingreso_id' => $canal->id,
            'nivel_riesgo' => 'ordinario',
            'descripcion' => 'Intento de radicación manual no autorizada.',
            'es_anonimo' => 1,
        ])->assertForbidden();

        $this->assertSame(0, Pqrsd::count());
    }

    public function test_radicados_son_consecutivos_y_unicos(): void
    {
        $generator = app(RadicadoGenerator::class);
        $a = $generator->siguiente(2026);
        $b = $generator->siguiente(2026);

        $this->assertSame('PQRSD-2026-000001', $a);
        $this->assertSame('PQRSD-2026-000002', $b);
        $this->assertNotSame($a, $b);
    }

    public function test_sla_horas_calendario_vital_menor(): void
    {
        $regla = \App\Models\ReglaSla::where('codigo', 'VITAL_MENOR_8H')->firstOrFail();
        $inicio = now('America/Bogota')->startOfDay()->setTime(10, 0);
        $limite = app(SlaCalculator::class)->calcularFechaLimite($regla, $inicio);

        $this->assertTrue($limite->equalTo($inicio->copy()->addHours(8)));
    }

    public function test_auditor_no_puede_radicar(): void
    {
        $user = User::factory()->create(['activo' => true]);
        $user->assignRole('auditor');
        $canal = CanalIngreso::where('codigo', 'MANUAL')->firstOrFail();

        $response = $this->actingAs($user)->post(route('pqrsd.store'), [
            'canal_ingreso_id' => $canal->id,
            'nivel_riesgo' => 'ordinario',
            'descripcion' => 'Intento no autorizado',
        ]);

        $response->assertForbidden();
        $this->assertSame(0, Pqrsd::count());
    }

    public function test_usuario_autenticado_con_permiso_ve_bandeja(): void
    {
        $user = User::factory()->create(['activo' => true]);
        $user->assignRole('lider_pqrsd');

        $this->actingAs($user)
            ->get(route('pqrsd.index'))
            ->assertOk()
            ->assertSee('Bandeja de PQRSD');
    }
}
