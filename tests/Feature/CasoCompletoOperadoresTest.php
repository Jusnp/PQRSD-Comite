<?php

namespace Tests\Feature;

use App\Models\ActuacionPqrsd;
use App\Models\Aseguradora;
use App\Models\CanalIngreso;
use App\Models\Pqrsd;
use App\Models\Sede;
use App\Models\TipoPqrsd;
use App\Models\User;
use App\Services\VencimientoService;
use Carbon\Carbon;
use Database\Seeders\CatalogosSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Flujo operativo con admin + auxiliares (sin usuarios de área en plataforma).
 * Remisión a áreas: registrada como actuación interna (no hay UI/envío institucional aún).
 */
class CasoCompletoOperadoresTest extends TestCase
{
    use RefreshDatabase;

    public function test_auxiliar_gestiona_caso_completo_sin_reiniciar_sla(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(CatalogosSeeder::class);

        $admin = User::factory()->create(['activo' => true, 'name' => 'Admin Validación']);
        $admin->assignRole('admin');
        $auxiliar1 = User::factory()->create(['activo' => true, 'name' => 'Auxiliar 1 Validación']);
        $auxiliar1->assignRole('auxiliar_pqrsd');
        $auxiliar2 = User::factory()->create(['activo' => true, 'name' => 'Auxiliar 2 Validación']);
        $auxiliar2->assignRole('auxiliar_pqrsd');

        Carbon::setTestNow(Carbon::parse('2026-10-14 10:00:00', 'America/Bogota'));
        $recepcion = Carbon::parse('2026-10-14 08:00:00', 'America/Bogota');

        // 1-3) Radicación manual reservada al admin; auxiliar gestiona el expediente.
        $this->actingAs($admin)
            ->post(route('pqrsd.store'), [
                'fecha_recepcion' => $recepcion->format('Y-m-d\TH:i'),
                'canal_ingreso_id' => CanalIngreso::where('codigo', 'MANUAL')->value('id'),
                'tipo_pqrsd_id' => TipoPqrsd::where('codigo', 'QUEJA')->value('id'),
                'nivel_riesgo' => 'simple',
                'descripcion' => 'Caso ficticio validación operativa — demora en cita de psicología',
                'solicitante_nombre' => 'Solicitante Ficticio',
                'paciente_nombre' => 'Paciente Ficticio',
                'sede_id' => Sede::where('activo', true)->value('id'),
                'aseguradora_id' => Aseguradora::where('activo', true)->value('id'),
                'servicio_area' => 'Coordinación clínica',
                'responsable_id' => $auxiliar2->id,
            ])
            ->assertRedirect();

        $caso = Pqrsd::query()->latest('id')->firstOrFail();
        $inicio = $caso->fecha_inicio_conteo?->toDateTimeString();
        $limite = $caso->fecha_limite?->toDateTimeString();

        $this->assertSame('simple', $caso->nivel_riesgo);
        $this->assertNotNull($inicio);
        $this->assertNotNull($limite);
        $this->assertTrue($caso->fecha_inicio_conteo->equalTo($recepcion));

        // 4) Asignación / reasignación entre operadores (servicio — no reinicia SLA)
        app(VencimientoService::class)->reasignar(
            $caso->fresh(),
            $auxiliar1->id,
            $admin,
            'Seguimiento queda en auxiliar 1'
        );
        $caso->refresh();
        $this->assertSame($inicio, $caso->fecha_inicio_conteo?->toDateTimeString());
        $this->assertSame($limite, $caso->fecha_limite?->toDateTimeString());
        $this->assertSame($auxiliar1->id, $caso->responsable_id);

        // 5) Remisión al área (simulada: actuación interna — sin correo institucional real)
        ActuacionPqrsd::create([
            'pqrsd_id' => $caso->id,
            'usuario_id' => $auxiliar1->id,
            'tipo' => 'remision_area',
            'titulo' => 'Remisión a área (registro manual)',
            'detalle' => 'Área: Coordinación clínica. Motivo: solicitar agenda y justificación de demora. Canal: registro interno (Gmail bloqueado).',
            'es_interna' => true,
            'datos_adicionales' => [
                'area' => 'Coordinación clínica',
                'canal_comunicacion' => 'manual_registro',
                'envio_institucional' => false,
            ],
        ]);

        // 6) Respuesta del área (simulada: actuación)
        ActuacionPqrsd::create([
            'pqrsd_id' => $caso->id,
            'usuario_id' => $auxiliar2->id,
            'tipo' => 'respuesta_area',
            'titulo' => 'Respuesta recibida del área',
            'detalle' => 'Área informa cupo reprogramado para 2026-10-20. Evidencia archivada en expediente (manual).',
            'es_interna' => true,
        ]);

        $caso->refresh();
        $this->assertSame($inicio, $caso->fecha_inicio_conteo?->toDateTimeString());
        $this->assertSame($limite, $caso->fecha_limite?->toDateTimeString());

        // 7) Respuesta al solicitante (HTTP)
        $this->actingAs($auxiliar1)
            ->post(route('pqrsd.responder', $caso), $this->conVersionExpediente($caso, [
                'detalle_respuesta' => 'Se informa al solicitante la reprogramación de la cita según respuesta del área.',
                'respuesta_estado_destino' => 'puesta_disposicion',
                'poner_disposicion_consulta' => '1',
            ]))
            ->assertRedirect();

        $caso->refresh();
        $this->assertSame('respondido', $caso->estado_operativo);
        $this->assertSame($inicio, $caso->fecha_inicio_conteo?->toDateTimeString());
        $this->assertSame($limite, $caso->fecha_limite?->toDateTimeString());

        // 8) Cierre (HTTP)
        $this->actingAs($auxiliar1)
            ->post(route('pqrsd.cerrar', $caso), $this->conVersionExpediente($caso, [
                'motivo_cierre' => 'Cierre con respuesta de fondo y evidencia de gestión con el área.',
            ]))
            ->assertRedirect();

        $caso->refresh();
        $this->assertSame('cerrado', $caso->estado_operativo);
        $this->assertSame($inicio, $caso->fecha_inicio_conteo?->toDateTimeString());
        $this->assertSame($limite, $caso->fecha_limite?->toDateTimeString());

        // Visibilidad cruzada admin + ambos auxiliares + reportes
        foreach ([$admin, $auxiliar1, $auxiliar2] as $operador) {
            $this->actingAs($operador)
                ->get(route('pqrsd.show', $caso))
                ->assertOk()
                ->assertSee('Caso ficticio validación operativa')
                ->assertSee('Remisión a área')
                ->assertSee('Respuesta recibida del área');
        }

        $this->actingAs($auxiliar2)
            ->get(route('reportes.index'))
            ->assertOk();

        $this->actingAs($auxiliar1)
            ->get(route('vencimientos.proximos'))
            ->assertOk();

        // Auxiliar no accede a configuración crítica
        $this->actingAs($auxiliar1)->get(route('cuentas-correo.index'))->assertForbidden();
        $this->actingAs($auxiliar1)->get(route('reglas.index'))->assertForbidden();
        $this->actingAs($admin)->get(route('cuentas-correo.index'))->assertOk();

        $this->assertTrue(
            $caso->actuaciones()->where('tipo', 'remision_area')->exists()
        );
        $this->assertTrue(
            $caso->auditorias()->where('accion', 'cerrar')->exists()
            || $caso->actuaciones()->where('tipo', 'cierre')->exists()
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}
