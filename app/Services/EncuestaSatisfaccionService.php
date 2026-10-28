<?php

namespace App\Services;

use App\Models\EncuestaSatisfaccionPqrsd;
use App\Models\NotificacionPqrsd;
use App\Models\Pqrsd;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EncuestaSatisfaccionService
{
    /**
     * Una encuesta por expediente cerrado. No reabre el caso ni envía correo.
     *
     * @param  array{puntuacion:int, atendida:string, comentario:?string}  $datos
     */
    public function registrar(Pqrsd $caso, array $datos): EncuestaSatisfaccionPqrsd
    {
        if ($caso->estado_operativo !== 'cerrado') {
            throw ValidationException::withMessages([
                'puntuacion' => 'La encuesta solo está disponible después del cierre.',
            ]);
        }

        if ($caso->encuesta()->exists()) {
            throw ValidationException::withMessages([
                'puntuacion' => 'Este radicado ya tiene una encuesta registrada.',
            ]);
        }

        return DB::transaction(function () use ($caso, $datos) {
            $encuesta = EncuestaSatisfaccionPqrsd::create([
                'pqrsd_id' => $caso->id,
                'puntuacion' => (int) $datos['puntuacion'],
                'atendida' => $datos['atendida'],
                'comentario' => filled($datos['comentario'] ?? null) ? $datos['comentario'] : null,
                'respondida_en' => now('America/Bogota'),
            ]);

            if ($encuesta->esBaja() && ! NotificacionPqrsd::query()
                ->where('pqrsd_id', $caso->id)
                ->where('tipo', 'satisfaccion_baja')
                ->exists()) {
                NotificacionPqrsd::create([
                    'pqrsd_id' => $caso->id,
                    'tipo' => 'satisfaccion_baja',
                    'canal' => 'interno',
                    'asunto' => 'Valoración baja '.$caso->radicado,
                    'mensaje' => 'El ciudadano registró una valoración baja. El expediente permanece cerrado.',
                    'estado' => 'omitida',
                    'umbral_clave' => 'satisfaccion_baja',
                    'metadatos' => [
                        'puntuacion' => $encuesta->puntuacion,
                        'atendida' => $encuesta->atendida,
                    ],
                ]);
            }

            return $encuesta;
        });
    }
}
