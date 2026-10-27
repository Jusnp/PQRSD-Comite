<?php

namespace App\Services\Ops;

use App\Models\EjecucionProgramada;
use Illuminate\Support\Facades\Log;
use Throwable;

class ScheduleRunRecorder
{
    /**
     * @param  callable(): array{procesados?:int, detalle?:string}  $callback
     */
    public function run(string $comando, string $logChannel, callable $callback): int
    {
        $inicio = now('America/Bogota');
        $row = EjecucionProgramada::create([
            'comando' => $comando,
            'estado' => 'running',
            'iniciada_en' => $inicio,
        ]);

        try {
            $result = $callback();
            $procesados = (int) ($result['procesados'] ?? 0);
            $detalle = (string) ($result['detalle'] ?? 'OK');

            $row->update([
                'estado' => 'ok',
                'procesados' => $procesados,
                'detalle' => $detalle,
                'finalizada_en' => now('America/Bogota'),
            ]);

            Log::channel($logChannel)->info($comando.' OK', [
                'procesados' => $procesados,
                'detalle' => $detalle,
                'inicio' => $inicio->toDateTimeString(),
            ]);

            return 0;
        } catch (Throwable $e) {
            $row->update([
                'estado' => 'error',
                'detalle' => $e->getMessage(),
                'finalizada_en' => now('America/Bogota'),
            ]);

            Log::channel($logChannel)->error($comando.' ERROR', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
