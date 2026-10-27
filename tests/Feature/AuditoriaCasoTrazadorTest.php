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

class AuditoriaCasoTrazadorTest extends TestCase
{
    use RefreshDatabase;

    public function test_reconstruye_caso_trazador_completo_con_evidencia(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(CatalogosSeeder::class);

        $lider = User::factory()->create(['activo' => true, 'email' => 'trazador-lider@test']);
        $lider->assignRole('lider_pqrsd');
        $responsable = User::factory()->create(['activo' => true, 'email' => 'trazador-resp@test']);
        $responsable->assignRole('responsable_area');
        $ajeno = User::factory()->create(['activo' => true]);
        $ajeno->assignRole('responsable_area');

        Carbon::setTestNow(Carbon::parse('2026-10-14 10:00:00', 'America/Bogota'));
        $recepcion = Carbon::parse('2026-10-14 08:00:00', 'America/Bogota');

        $caso = app(PqrsdService::class)->radicarManual([
            'fecha_recepcion' => $recepcion,
            'canal_ingreso_id' => CanalIngreso::where('codigo', 'MANUAL')->value('id'),
            'tipo_pqrsd_id' => TipoPqrsd::where('codigo', 'QUEJA')->value('id'),
            'nivel_riesgo' => 'simple',
            'descripcion' => 'Caso trazador ficticio para auditoría',
            'solicitante_nombre' => 'Solicitante Demo',
            'paciente_nombre' => 'Paciente Demo',
            'observaciones_internas' => 'Nota clínica interna ficticia — no debe filtrarse a ajeno',
            'responsable_id' => $responsable->id,
        ], $lider);

        $this->assertSame('simple', $caso->nivel_riesgo);
        $this->assertTrue($caso->fecha_inicio_conteo->equalTo($recepcion));
        $this->assertTrue($caso->fecha_limite->equalTo($recepcion->copy()->addHours(72)));

        app(VencimientoService::class)->reasignar($caso->fresh(), $responsable->id, $lider, 'Confirmación de responsable');
        $caso = app(VencimientoService::class)->registrarRespuesta(
            $caso->fresh(),
            $responsable,
            Carbon::parse('2026-10-15 09:00:00', 'America/Bogota')
        );

        $cerrado = app(VencimientoService::class)->cerrar(
            $caso->fresh(),
            $lider,
            'Cierre con respuesta de fondo registrada'
        );

        $this->assertSame('cerrado', $cerrado->estado_operativo);
        $this->assertSame('resuelto_oportuno', $cerrado->estado_cumplimiento);
        $this->assertNotNull($cerrado->fecha_cierre);

        // Reconstrucción trazadora
        $trazador = Pqrsd::with(['actuaciones', 'historialEstados', 'auditorias', 'reglaSla', 'responsable', 'tipo', 'canal'])
            ->where('radicado', $cerrado->radicado)
            ->firstOrFail();

        $this->assertGreaterThanOrEqual(3, $trazador->actuaciones->count());
        $this->assertTrue($trazador->historialEstados->contains(fn ($h) => $h->campo === 'fecha_limite'));
        $this->assertTrue($trazador->auditorias->contains(fn ($a) => $a->accion === 'radicar'));
        $this->assertTrue($trazador->auditorias->contains(fn ($a) => $a->accion === 'cerrar'));
        $this->assertNotNull($trazador->reglaSla);

        // Acceso indebido: responsable ajeno no ve el caso
        $this->actingAs($ajeno)->get(route('pqrsd.show', $trazador))->assertForbidden();
        $this->actingAs($responsable)->get(route('pqrsd.show', $trazador))->assertOk();
        $this->actingAs($lider)->get(route('pqrsd.show', $trazador))->assertOk()->assertSee('Caso trazador ficticio');

        // Cierre sin evidencia de respuesta
        $sinRespuesta = app(PqrsdService::class)->radicarManual([
            'fecha_recepcion' => $recepcion,
            'canal_ingreso_id' => CanalIngreso::where('codigo', 'MANUAL')->value('id'),
            'tipo_pqrsd_id' => TipoPqrsd::where('codigo', 'PETICION')->value('id'),
            'nivel_riesgo' => 'simple',
            'descripcion' => 'Caso sin respuesta — no debe cerrar',
            'solicitante_nombre' => 'Demo',
        ], $lider);

        $this->expectException(\InvalidArgumentException::class);
        app(VencimientoService::class)->cerrar($sinRespuesta, $lider, 'Intento de cierre sin evidencia');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}
