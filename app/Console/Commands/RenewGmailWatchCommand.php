<?php

namespace App\Console\Commands;

use App\Mail\Providers\GoogleGmailMailProvider;
use App\Models\CuentaCorreo;
use App\Services\Ops\ScheduleRunRecorder;
use Illuminate\Console\Command;

class RenewGmailWatchCommand extends Command
{
    protected $signature = 'pqrsd:gmail-renew-watch';

    protected $description = 'Renueva suscripciones Gmail users.watch (Pub/Sub) para cuentas google_api';

    public function handle(GoogleGmailMailProvider $gmail, ScheduleRunRecorder $recorder): int
    {
        return $recorder->run('pqrsd:gmail-renew-watch', 'correo_sync', function () use ($gmail) {
            $cuentas = CuentaCorreo::query()
                ->where('activo', true)
                ->where('tipo_integracion', 'google_api')
                ->get();

            $ok = 0;
            $fail = 0;
            foreach ($cuentas as $cuenta) {
                $r = $gmail->renewWatch($cuenta);
                if ($r['ok'] ?? false) {
                    $ok++;
                    $this->info('Watch OK: '.$cuenta->direccion);
                } else {
                    $fail++;
                    $this->warn('Watch FAIL: '.$cuenta->direccion.' — '.($r['error'] ?? ''));
                }
            }

            return [
                'procesados' => $cuentas->count(),
                'detalle' => "ok={$ok} fail={$fail}",
            ];
        }) === 0 ? self::SUCCESS : self::FAILURE;
    }
}
