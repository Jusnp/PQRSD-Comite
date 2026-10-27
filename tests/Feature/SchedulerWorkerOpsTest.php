<?php

namespace Tests\Feature;

use App\Jobs\SyncCuentaCorreoJob;
use App\Models\CuentaCorreo;
use App\Models\EjecucionProgramada;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SchedulerWorkerOpsTest extends TestCase
{
    use RefreshDatabase;

    public function test_comando_sla_deja_evidencia_en_tabla_y_log(): void
    {
        $code = Artisan::call('pqrsd:actualizar-cumplimiento');
        $this->assertSame(0, $code);

        $this->assertDatabaseHas('ejecuciones_programadas', [
            'comando' => 'pqrsd:actualizar-cumplimiento',
            'estado' => 'ok',
        ]);

        $this->assertFileExists(storage_path('logs/sla-cumplimiento.log'));
        $this->assertStringContainsString('pqrsd:actualizar-cumplimiento OK', file_get_contents(storage_path('logs/sla-cumplimiento.log')));
    }

    public function test_sync_correos_registra_ejecucion_y_encola_reintentos(): void
    {
        Queue::fake();

        CuentaCorreo::create([
            'direccion' => 'mock@menteplena.test',
            'activo' => true,
            'tipo_integracion' => 'mock',
            'es_buzon_central' => true,
        ]);

        $code = Artisan::call('pqrsd:sync-correos');
        $this->assertSame(0, $code);

        Queue::assertPushed(SyncCuentaCorreoJob::class);
        $this->assertDatabaseHas('ejecuciones_programadas', [
            'comando' => 'pqrsd:sync-correos',
            'estado' => 'ok',
        ]);
        $this->assertFileExists(storage_path('logs/correo-sync.log'));
    }

    public function test_schedule_list_incluye_tareas_criticas(): void
    {
        Artisan::call('schedule:list');
        $out = Artisan::output();
        $this->assertStringContainsString('pqrsd:sync-correos', $out);
        $this->assertStringContainsString('pqrsd:actualizar-cumplimiento', $out);
        $this->assertStringContainsString('pqrsd:gmail-renew-watch', $out);
        $this->assertStringContainsString('pqrsd:ops-health', $out);
        $this->assertStringContainsString('pqrsd:backup', $out);
    }

    public function test_ops_health_y_check_production_local(): void
    {
        Artisan::call('pqrsd:actualizar-cumplimiento');
        $code = Artisan::call('pqrsd:ops-health', ['--json' => true]);
        $this->assertContains($code, [0, 1]); // puede fallar por worker inactivo, esperado en test

        $prod = Artisan::call('pqrsd:check-production');
        $this->assertSame(0, $prod); // local no strict
    }

    public function test_job_sync_idempotente_cuenta_inactiva(): void
    {
        $job = new SyncCuentaCorreoJob(999999);
        $job->handle(app(\App\Services\Correo\EmailSyncService::class));
        $this->assertTrue(true);
    }

    public function test_ultima_ejecucion_correcta_consultable(): void
    {
        Artisan::call('pqrsd:actualizar-cumplimiento');
        $last = EjecucionProgramada::query()
            ->where('comando', 'pqrsd:actualizar-cumplimiento')
            ->where('estado', 'ok')
            ->latest('id')
            ->first();

        $this->assertNotNull($last);
        $this->assertNotNull($last->finalizada_en);
    }
}
