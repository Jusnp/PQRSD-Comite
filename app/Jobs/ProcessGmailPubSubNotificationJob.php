<?php

namespace App\Jobs;

use App\Models\CuentaCorreo;
use App\Models\GmailPubsubEvent;
use App\Services\Correo\EmailSyncService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessGmailPubSubNotificationJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $uniqueFor = 120;

    public function __construct(public int $eventId) {}

    public function uniqueId(): string
    {
        return 'gmail-pubsub-'.$this->eventId;
    }

    public function backoff(): array
    {
        return [30, 60, 180, 600];
    }

    public function handle(EmailSyncService $sync): void
    {
        $event = GmailPubsubEvent::find($this->eventId);
        if (! $event || $event->estado === 'procesado') {
            return;
        }

        $cuenta = CuentaCorreo::query()
            ->where('activo', true)
            ->where('tipo_integracion', 'google_api')
            ->when($event->email_address, fn ($q) => $q->whereRaw('LOWER(direccion) = ?', [strtolower($event->email_address)]))
            ->first();

        if (! $cuenta) {
            $event->update([
                'estado' => 'error',
                'detalle' => 'Sin cuenta google_api para '.$event->email_address,
            ]);
            Log::channel('correo_sync')->warning('Pub/Sub sin cuenta', [
                'email' => $event->email_address,
            ]);

            return;
        }

        $event->update(['cuenta_correo_id' => $cuenta->id, 'estado' => 'procesando']);

        $resultado = $sync->syncCuenta($cuenta);

        $event->update([
            'estado' => ($resultado['errores'] ?? 0) > 0 ? 'parcial' : 'procesado',
            'detalle' => sprintf(
                'nuevos=%d dup=%d err=%d',
                $resultado['nuevos'],
                $resultado['duplicados'],
                $resultado['errores']
            ),
        ]);
    }
}
