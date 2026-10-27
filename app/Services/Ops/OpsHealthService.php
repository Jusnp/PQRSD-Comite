<?php

namespace App\Services\Ops;

use App\Models\CuentaCorreo;
use App\Models\EjecucionProgramada;
use App\Models\NotificacionPqrsd;
use App\Models\RespaldoPqrsd;
use Illuminate\Support\Facades\DB;

class OpsHealthService
{
    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $jobs = DB::table('jobs')->count();
        $failed = DB::table('failed_jobs')->count();
        $oldest = DB::table('jobs')->orderBy('available_at')->value('available_at');
        $oldestAge = $oldest ? max(0, now()->timestamp - (int) $oldest) : null;

        $heartbeatPath = storage_path('logs/queue-worker.heartbeat');
        $workerAge = is_file($heartbeatPath) ? time() - filemtime($heartbeatPath) : null;
        $workerActive = $workerAge !== null && $workerAge < (int) config('pqrsd_ops.worker_heartbeat_max_seconds', 120);

        $lastSync = EjecucionProgramada::query()->where('comando', 'pqrsd:sync-correos')->latest('iniciada_en')->first();
        $lastSla = EjecucionProgramada::query()->where('comando', 'pqrsd:actualizar-cumplimiento')->latest('iniciada_en')->first();
        $lastBackup = RespaldoPqrsd::query()->where('estado', 'ok')->latest('id')->first();

        $gmailCuentas = CuentaCorreo::query()->where('tipo_integracion', 'google_api')->where('activo', true)->get();
        $gmailOk = $gmailCuentas->where('estado_conexion', 'ok')->count();
        $gmailError = $gmailCuentas->where('estado_conexion', 'error')->count();
        $gmailPendiente = $gmailCuentas->whereIn('estado_conexion', ['pendiente_credenciales', 'desconocido'])->count();

        $cronFresh = $lastSla && $lastSla->iniciada_en
            && $lastSla->iniciada_en->greaterThan(now('America/Bogota')->subMinutes(5));

        $alertasSlaAbiertas = NotificacionPqrsd::query()
            ->where('created_at', '>=', now('America/Bogota')->subDay())
            ->count();

        return [
            'generated_at' => now('America/Bogota')->toDateTimeString(),
            'gmail' => [
                'cuentas_google_api' => $gmailCuentas->count(),
                'ok' => $gmailOk,
                'error' => $gmailError,
                'pendiente_credenciales' => $gmailPendiente,
                'pilot_mailbox' => config('pqrsd_correo.gmail.pilot_mailbox'),
                'oauth_configured' => filled(config('pqrsd_correo.gmail.oauth_client_id')),
                'ultima_sync_global' => $lastSync?->iniciada_en?->timezone('America/Bogota')->toDateTimeString(),
            ],
            'scheduler' => [
                'last_sla' => $lastSla?->only(['estado', 'iniciada_en', 'finalizada_en', 'procesados']),
                'last_correo_sync' => $lastSync?->only(['estado', 'iniciada_en', 'finalizada_en', 'procesados']),
                'cron_parece_activo' => (bool) $cronFresh,
            ],
            'queue' => [
                'connection' => config('queue.default'),
                'pending' => $jobs,
                'failed' => $failed,
                'oldest_job_age_seconds' => $oldestAge,
                'worker_heartbeat_age_seconds' => $workerAge,
                'worker_active' => $workerActive,
            ],
            'sla_alerts_24h' => $alertasSlaAbiertas,
            'backup' => [
                'last_ok_id' => $lastBackup?->id,
                'last_ok_at' => $lastBackup?->finalizada_en?->timezone('America/Bogota')->toDateTimeString(),
                'cifrado' => (bool) ($lastBackup?->cifrado),
                'transferencia' => $lastBackup?->estado_transferencia,
                'destino' => $lastBackup?->destino_externo,
                'rpo_hours_pending_approval' => config('pqrsd_backup.rpo_hours_pending_approval'),
                'rto_hours_pending_approval' => config('pqrsd_backup.rto_hours_pending_approval'),
            ],
            'healthy' => $failed === 0
                && ($oldestAge === null || $oldestAge < (int) config('pqrsd_ops.oldest_job_alert_seconds', 900))
                && $gmailError === 0,
        ];
    }
}
