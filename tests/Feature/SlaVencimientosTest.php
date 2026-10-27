<?php

namespace Tests\Feature;

use App\Models\CanalIngreso;
use App\Models\HistorialEstadoPqrsd;
use App\Models\NotificacionPqrsd;
use App\Models\Pqrsd;
use App\Models\User;
use App\Services\PqrsdService;
use App\Services\SlaCalculator;
use App\Services\VencimientoService;
use Carbon\Carbon;
use Database\Seeders\CatalogosSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SlaVencimientosTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private CanalIngreso $canal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(CatalogosSeeder::class);
        $this->user = User::factory()->create(['activo' => true, 'email' => 'lider-sla@menteplena.test']);
        $this->user->assignRole('lider_pqrsd');
        $this->canal = CanalIngreso::where('codigo', 'MANUAL')->firstOrFail();
        Mail::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_vital_menor_8_horas_calendario_incluye_noche_y_fin_de_semana(): void
    {
        // Viernes 9 oct 2026 23:00 → +8h = sábado 10 oct 07:00
        $recepcion = Carbon::parse('2026-10-09 23:00:00', 'America/Bogota');
        Carbon::setTestNow($recepcion->copy()->addMinutes(30));

        $caso = app(PqrsdService::class)->radicarManual([
            'fecha_recepcion' => $recepcion,
            'canal_ingreso_id' => $this->canal->id,
            'nivel_riesgo' => 'vital_menor',
            'paciente_edad' => 12,
            'descripcion' => 'Vital menor ficticio',
            'es_anonimo' => 1,
        ], $this->user);

        $this->assertSame('vital_menor', $caso->nivel_riesgo);
        $this->assertTrue(
            $caso->fecha_limite->equalTo(Carbon::parse('2026-10-10 07:00:00', 'America/Bogota'))
        );
        $this->assertTrue($caso->fecha_inicio_conteo->equalTo($recepcion));
        $this->assertDatabaseHas('notificaciones_pqrsd', [
            'pqrsd_id' => $caso->id,
            'tipo' => 'vital_inmediato',
        ]);
    }

    public function test_vital_general_24_horas_calendario(): void
    {
        $recepcion = Carbon::parse('2026-10-10 02:15:00', 'America/Bogota'); // sábado madrugada
        Carbon::setTestNow($recepcion);

        $caso = app(PqrsdService::class)->radicarManual([
            'fecha_recepcion' => $recepcion,
            'canal_ingreso_id' => $this->canal->id,
            'nivel_riesgo' => 'vital_general',
            'paciente_edad' => 40,
            'descripcion' => 'Vital adulto ficticio',
            'es_anonimo' => 1,
        ], $this->user);

        $this->assertTrue(
            $caso->fecha_limite->equalTo(Carbon::parse('2026-10-11 02:15:00', 'America/Bogota'))
        );
    }

    public function test_ordinario_10_dias_habiles_desde_viernes_salta_finde_y_festivo(): void
    {
        // Viernes 9 oct 2026. Festivo lun 12 oct. 10 hábiles → lun 26 oct 10:00
        $recepcion = Carbon::parse('2026-10-09 10:00:00', 'America/Bogota');
        Carbon::setTestNow($recepcion->copy()->addHours(3)); // radicación tardía el mismo día

        $caso = app(PqrsdService::class)->radicarManual([
            'fecha_recepcion' => $recepcion,
            'canal_ingreso_id' => $this->canal->id,
            'nivel_riesgo' => 'ordinario',
            'descripcion' => 'Ordinario desde viernes',
            'es_anonimo' => 1,
        ], $this->user);

        $this->assertTrue($caso->fecha_inicio_conteo->equalTo($recepcion));
        $this->assertTrue(
            $caso->fecha_limite->equalTo(Carbon::parse('2026-10-26 10:00:00', 'America/Bogota')),
            'Límite esperado 2026-10-26 10:00, obtenido '.$caso->fecha_limite
        );
        // Radicación posterior no oculta recepción
        $this->assertTrue($caso->fecha_radicacion->greaterThan($caso->fecha_recepcion));
    }

    public function test_ordinario_recibido_en_festivo_cuenta_dias_habiles_siguientes(): void
    {
        $recepcion = Carbon::parse('2026-10-12 09:00:00', 'America/Bogota'); // festivo
        Carbon::setTestNow($recepcion);

        $regla = app(SlaCalculator::class)->reglaParaNivel('ordinario');
        $limite = app(SlaCalculator::class)->calcularFechaLimite($regla, $recepcion);

        // Desde lunes festivo: primer hábil sumado = martes 13 ... 10º = lunes 26
        $this->assertTrue($limite->equalTo(Carbon::parse('2026-10-26 09:00:00', 'America/Bogota')));
        $this->assertFalse(app(SlaCalculator::class)->esDiaHabil($recepcion));
    }

    public function test_menor_que_cumple_18_despues_conserva_regla_vital_menor(): void
    {
        // Nació 2008-03-15. En recepción 2026-03-14 tiene 17 años.
        // En "hoy" 2026-10-14 ya tiene 18, pero el plazo no cambia.
        $recepcion = Carbon::parse('2026-03-14 08:00:00', 'America/Bogota');
        Carbon::setTestNow(Carbon::parse('2026-10-14 11:00:00', 'America/Bogota'));

        $caso = app(PqrsdService::class)->radicarManual([
            'fecha_recepcion' => $recepcion,
            'canal_ingreso_id' => $this->canal->id,
            'nivel_riesgo' => 'vital_general', // solicitado como general
            'paciente_fecha_nacimiento' => '2008-03-15',
            'descripcion' => 'Menor en recepción, adulto hoy',
            'es_anonimo' => 1,
        ], $this->user);

        $this->assertSame('vital_menor', $caso->nivel_riesgo);
        $this->assertSame(17, $caso->paciente_edad);
        $this->assertTrue(
            $caso->fecha_limite->equalTo(Carbon::parse('2026-03-14 16:00:00', 'America/Bogota'))
        );
        $this->assertSame('vencido', $caso->estado_cumplimiento);
    }

    public function test_reasignacion_y_cambio_estado_no_reinician_plazo(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-14 09:00:00', 'America/Bogota'));
        $recepcion = Carbon::parse('2026-10-14 08:00:00', 'America/Bogota');

        $caso = app(PqrsdService::class)->radicarManual([
            'fecha_recepcion' => $recepcion,
            'canal_ingreso_id' => $this->canal->id,
            'nivel_riesgo' => 'priorizado',
            'descripcion' => 'No reinicio',
            'es_anonimo' => 1,
        ], $this->user);

        $limite = $caso->fecha_limite->toDateTimeString();
        $inicio = $caso->fecha_inicio_conteo->toDateTimeString();

        $otro = User::factory()->create(['activo' => true]);
        $otro->assignRole('responsable_area');

        app(VencimientoService::class)->reasignar($caso, $otro->id, $this->user, 'Cambio de área');

        app(PqrsdService::class)->registrarCambioEstado(
            $caso->fresh(),
            $this->user,
            'estado_operativo',
            'en_gestion',
            'Inicio gestión'
        );

        $caso->refresh();
        $this->assertSame($limite, $caso->fecha_limite->toDateTimeString());
        $this->assertSame($inicio, $caso->fecha_inicio_conteo->toDateTimeString());
        $this->assertSame($otro->id, $caso->responsable_id);
    }

    public function test_respuesta_oportuna_y_tardia(): void
    {
        $recepcion = Carbon::parse('2026-10-14 08:00:00', 'America/Bogota');
        Carbon::setTestNow($recepcion);

        $caso = app(PqrsdService::class)->radicarManual([
            'fecha_recepcion' => $recepcion,
            'canal_ingreso_id' => $this->canal->id,
            'nivel_riesgo' => 'simple',
            'descripcion' => '72h',
            'es_anonimo' => 1,
        ], $this->user);

        // +24h dentro de 72h
        $oportuno = app(VencimientoService::class)->registrarRespuesta(
            $caso,
            $this->user,
            $recepcion->copy()->addHours(24)
        );
        $this->assertSame('resuelto_oportuno', $oportuno->estado_cumplimiento);

        $caso2 = app(PqrsdService::class)->radicarManual([
            'fecha_recepcion' => $recepcion,
            'canal_ingreso_id' => $this->canal->id,
            'nivel_riesgo' => 'simple',
            'descripcion' => '72h tarde',
            'es_anonimo' => 1,
        ], $this->user);

        $tarde = app(VencimientoService::class)->registrarRespuesta(
            $caso2,
            $this->user,
            $recepcion->copy()->addHours(80)
        );
        $this->assertSame('resuelto_extemporaneo', $tarde->estado_cumplimiento);
    }

    public function test_semaforo_proximo_a_vencer_y_auditoria(): void
    {
        $recepcion = Carbon::parse('2026-10-14 08:00:00', 'America/Bogota');
        Carbon::setTestNow($recepcion);

        $caso = app(PqrsdService::class)->radicarManual([
            'fecha_recepcion' => $recepcion,
            'canal_ingreso_id' => $this->canal->id,
            'nivel_riesgo' => 'vital_general',
            'paciente_edad' => 30,
            'descripcion' => 'Semáforo',
            'es_anonimo' => 1,
            'responsable_id' => $this->user->id,
        ], $this->user);

        $this->assertSame('dentro_plazo', $caso->estado_cumplimiento);

        // A 2 horas del límite (22h transcurridas de 24)
        Carbon::setTestNow($recepcion->copy()->addHours(22));
        $caso = app(VencimientoService::class)->refrescarCumplimiento($caso);
        $this->assertSame('proximo_vencer', $caso->estado_cumplimiento);

        Carbon::setTestNow($recepcion->copy()->addHours(25));
        $caso = app(VencimientoService::class)->refrescarCumplimiento($caso);
        app(\App\Services\SlaNotificationService::class)->evaluarRecordatorios($caso);
        $this->assertSame('vencido', $caso->estado_cumplimiento);

        $this->assertTrue(
            HistorialEstadoPqrsd::where('pqrsd_id', $caso->id)->where('campo', 'fecha_limite')->exists()
        );
        $this->assertTrue(
            NotificacionPqrsd::where('pqrsd_id', $caso->id)->where('tipo', 'vencido')->exists()
        );
    }

    public function test_priorizado_48_horas(): void
    {
        $inicio = Carbon::parse('2026-10-09 18:00:00', 'America/Bogota');
        $regla = app(SlaCalculator::class)->reglaParaNivel('priorizado');
        $limite = app(SlaCalculator::class)->calcularFechaLimite($regla, $inicio);
        $this->assertTrue($limite->equalTo(Carbon::parse('2026-10-11 18:00:00', 'America/Bogota')));
    }

    public function test_alerta_vital_llega_a_admin_y_auxiliar_sin_responsable_asignado(): void
    {
        $admin = User::factory()->create(['activo' => true, 'email' => 'admin-vital@menteplena.test']);
        $admin->assignRole('admin');
        $auxiliar = User::factory()->create(['activo' => true, 'email' => 'aux-vital@menteplena.test']);
        $auxiliar->assignRole('auxiliar_pqrsd');

        $recepcion = Carbon::parse('2026-10-09 23:00:00', 'America/Bogota');
        Carbon::setTestNow($recepcion->copy()->addMinutes(5));

        $caso = app(PqrsdService::class)->radicarManual([
            'fecha_recepcion' => $recepcion,
            'canal_ingreso_id' => $this->canal->id,
            'nivel_riesgo' => 'vital_menor',
            'paciente_edad' => 10,
            'descripcion' => 'Vital menor — bandeja compartida sin responsable',
            'es_anonimo' => 1,
        ], $admin);

        $this->assertNull($caso->responsable_id);

        $notif = NotificacionPqrsd::query()
            ->where('pqrsd_id', $caso->id)
            ->where('tipo', 'vital_inmediato')
            ->first();

        $this->assertNotNull($notif);
        $this->assertNotSame('omitida', $notif->estado);
        $this->assertStringContainsString('admin-vital@menteplena.test', (string) $notif->destinatario);
        $this->assertStringContainsString('aux-vital@menteplena.test', (string) $notif->destinatario);
    }
}
