<?php

namespace App\Console\Commands;

use App\Services\Ops\ScheduleRunRecorder;
use App\Services\VencimientoService;
use Illuminate\Console\Command;

class ActualizarCumplimientoCommand extends Command
{
    protected $signature = 'pqrsd:actualizar-cumplimiento';

    protected $description = 'Actualiza semáforo de cumplimiento y dispara recordatorios SLA';

    public function handle(VencimientoService $vencimientos, ScheduleRunRecorder $recorder): int
    {
        return $recorder->run('pqrsd:actualizar-cumplimiento', 'sla_cumplimiento', function () use ($vencimientos) {
            $n = $vencimientos->refrescarAbiertos();
            $this->info("Casos evaluados: {$n}");

            return ['procesados' => $n, 'detalle' => "Casos evaluados: {$n}"];
        }) === 0 ? self::SUCCESS : self::FAILURE;
    }
}
