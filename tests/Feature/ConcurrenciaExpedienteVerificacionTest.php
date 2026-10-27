<?php

namespace Tests\Feature;

use App\Models\ActuacionPqrsd;
use App\Models\Aseguradora;
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

class ConcurrenciaExpedienteVerificacionTest extends TestCase
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

    public function test_actuaciones_concurrentes_son_apendice_no_sobrescriben(): void
    {
        $admin = User::where('email', 'admin@menteplena.test')->firstOrFail();
        $aux1 = User::where('email', 'auxiliar1@menteplena.test')->firstOrFail();
        $aux2 = User::where('email', 'auxiliar2@menteplena.test')->firstOrFail();

        $caso = app(PqrsdService::class)->radicarManual([
            'fecha_recepcion' => Carbon::parse('2026-10-10 09:00:00', 'America/Bogota'),
            'canal_ingreso_id' => CanalIngreso::where('codigo', 'MANUAL')->value('id'),
            'tipo_pqrsd_id' => TipoPqrsd::where('codigo', 'QUEJA')->value('id'),
            'nivel_riesgo' => 'simple',
            'descripcion' => 'Caso para verificar concurrencia append-only de actuaciones',
            'solicitante_nombre' => 'Concurrencia Demo',
            'sede_id' => Sede::where('activo', true)->value('id'),
            'aseguradora_id' => Aseguradora::where('activo', true)->value('id'),
        ], $admin);

        // Las gestiones manuales están deshabilitadas; la concurrencia operativa
        // se verifica con elaboraciones de respuesta append/overwrite controlado.
        $this->actingAs($aux1)->post(route('pqrsd.actuaciones.store', $caso), [
            'titulo' => 'Nota auxiliar 1',
            'detalle' => 'Primera actuación concurrente en bandeja compartida.',
            'es_interna' => 1,
        ])->assertForbidden();

        $this->actingAs($aux2)->post(route('pqrsd.actuaciones.store', $caso), [
            'titulo' => 'Nota auxiliar 2',
            'detalle' => 'Segunda actuación concurrente sin borrar la anterior.',
            'es_interna' => 1,
        ])->assertForbidden();

        $this->actingAs($aux1)->post(route('pqrsd.responder', $caso), $this->conVersionExpediente($caso, [
            'detalle_respuesta' => 'Borrador elaborado por auxiliar 1 en bandeja compartida.',
            'respuesta_estado_destino' => 'elaborada',
        ]))->assertRedirect();

        $this->actingAs($aux2)->post(route('pqrsd.responder', $caso->fresh()), $this->conVersionExpediente($caso->fresh(), [
            'detalle_respuesta' => 'Borrador actualizado por auxiliar 2 sin perder trazabilidad de auditoría.',
            'respuesta_estado_destino' => 'elaborada',
        ]))->assertRedirect();

        $this->assertSame(0, ActuacionPqrsd::where('pqrsd_id', $caso->id)
            ->whereIn('titulo', ['Nota auxiliar 1', 'Nota auxiliar 2'])
            ->count());
        $this->assertTrue($caso->fresh()->tieneRespuestaElaborada());
        $this->assertDatabaseHas('auditoria_pqrsd', [
            'pqrsd_id' => $caso->id,
            'accion' => 'elaborar_respuesta',
        ]);
    }

    public function test_caso_cerrado_rechaza_actuacion_por_http(): void
    {
        $admin = User::where('email', 'admin@menteplena.test')->firstOrFail();
        $aux = User::where('email', 'auxiliar1@menteplena.test')->firstOrFail();

        $caso = app(PqrsdService::class)->radicarManual([
            'fecha_recepcion' => Carbon::parse('2026-10-08 09:00:00', 'America/Bogota'),
            'canal_ingreso_id' => CanalIngreso::where('codigo', 'MANUAL')->value('id'),
            'tipo_pqrsd_id' => TipoPqrsd::where('codigo', 'QUEJA')->value('id'),
            'nivel_riesgo' => 'simple',
            'descripcion' => 'Caso cerrado para probar bloqueo HTTP de actuaciones',
            'solicitante_nombre' => 'Cerrado HTTP',
            'sede_id' => Sede::where('activo', true)->value('id'),
            'aseguradora_id' => Aseguradora::where('activo', true)->value('id'),
        ], $admin);

        app(VencimientoService::class)->registrarRespuesta(
            $caso->fresh(),
            $aux,
            Carbon::parse('2026-10-09 11:00:00', 'America/Bogota')
        );
        app(VencimientoService::class)->cerrar(
            $caso->fresh(),
            $admin,
            'Cierre para verificar bloqueo HTTP de actuaciones posteriores.'
        );

        $antes = ActuacionPqrsd::where('pqrsd_id', $caso->id)->count();

        $this->actingAs($aux)->post(route('pqrsd.actuaciones.store', $caso->fresh()), [
            'titulo' => 'Intento sobre cerrado',
            'detalle' => 'Esta actuación no debería persistirse en un expediente cerrado.',
            'es_interna' => 1,
        ])->assertForbidden();

        $this->assertSame($antes, ActuacionPqrsd::where('pqrsd_id', $caso->id)->count());
        $this->assertDatabaseMissing('actuaciones_pqrsd', [
            'pqrsd_id' => $caso->id,
            'titulo' => 'Intento sobre cerrado',
        ]);
    }
}
