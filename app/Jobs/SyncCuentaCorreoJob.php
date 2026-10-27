<?php

namespace App\Jobs;

use App\Models\CuentaCorreo;
use App\Services\Correo\EmailSyncService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SyncCuentaCorreoJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 300;

    public int $timeout = 120;

    public function __construct(public int $cuentaCorreoId) {}

    public function uniqueId(): string
    {
        return 'sync-cuenta-'.$this->cuentaCorreoId;
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(EmailSyncService $sync): void
    {
        $cuenta = CuentaCorreo::find($this->cuentaCorreoId);
        if (! $cuenta || ! $cuenta->activo) {
            return;
        }

        $resultado = $sync->syncCuenta($cuenta);

        Log::channel('correo_sync')->info('SyncCuentaCorreoJob', [
            'cuenta' => $cuenta->direccion,
            'nuevos' => $resultado['nuevos'],
            'duplicados' => $resultado['duplicados'],
            'errores' => $resultado['errores'],
        ]);

        @file_put_contents(storage_path('logs/queue-worker.heartbeat'), now('America/Bogota')->toIso8601String());
    }
}
