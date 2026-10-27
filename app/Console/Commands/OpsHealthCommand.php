<?php

namespace App\Console\Commands;

use App\Services\Ops\OpsHealthService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class OpsHealthCommand extends Command
{
    protected $signature = 'pqrsd:ops-health {--json : Salida JSON}';

    protected $description = 'Comprueba salud de Gmail, colas, scheduler y respaldos (sin datos clínicos)';

    public function handle(OpsHealthService $health): int
    {
        $status = $health->snapshot();
        Log::channel('ops')->info('ops-health', [
            'healthy' => $status['healthy'],
            'pending' => $status['queue']['pending'],
            'failed' => $status['queue']['failed'],
            'worker_active' => $status['queue']['worker_active'],
            'gmail_error' => $status['gmail']['error'],
        ]);

        if ($this->option('json')) {
            $this->line(json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            $this->info('Healthy: '.($status['healthy'] ? 'yes' : 'no'));
            $this->table(
                ['Métrica', 'Valor'],
                [
                    ['Gmail OK/total', $status['gmail']['ok'].'/'.$status['gmail']['cuentas_google_api']],
                    ['Worker activo', $status['queue']['worker_active'] ? 'sí' : 'no'],
                    ['Jobs pending/failed', $status['queue']['pending'].'/'.$status['queue']['failed']],
                    ['Cron fresco', $status['scheduler']['cron_parece_activo'] ? 'sí' : 'no'],
                    ['Último backup', $status['backup']['last_ok_at'] ?? '—'],
                ]
            );
        }

        return ($status['healthy'] ?? false) ? self::SUCCESS : self::FAILURE;
    }
}
