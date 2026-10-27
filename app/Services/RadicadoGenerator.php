<?php

namespace App\Services;

use App\Models\SecuenciaRadicado;
use Illuminate\Support\Facades\DB;

class RadicadoGenerator
{
    public function siguiente(?int $anio = null): string
    {
        $anio ??= (int) now('America/Bogota')->format('Y');

        return DB::transaction(function () use ($anio) {
            $secuencia = SecuenciaRadicado::query()
                ->where('anio', $anio)
                ->lockForUpdate()
                ->first();

            if (! $secuencia) {
                $secuencia = SecuenciaRadicado::create([
                    'anio' => $anio,
                    'ultimo_numero' => 0,
                ]);

                $secuencia = SecuenciaRadicado::query()
                    ->where('anio', $anio)
                    ->lockForUpdate()
                    ->firstOrFail();
            }

            $secuencia->ultimo_numero++;
            $secuencia->save();

            return sprintf('PQRSD-%d-%06d', $anio, $secuencia->ultimo_numero);
        });
    }
}
