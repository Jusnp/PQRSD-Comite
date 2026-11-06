<?php

namespace App\Support;

use App\Models\MotivoPqrsd;
use App\Models\TipoPqrsd;

/**
 * Corrige solo los nombres visibles de Petición y Felicitación.
 * Conserva códigos, identificadores y el resto de columnas.
 * No toca reglas SLA, sedes ni aseguradoras.
 *
 * No ejecutar contra la base de producción hasta autorización explícita.
 */
class CorreccionTildesTiposPqrsd
{
    /**
     * @return array{tipos: int, motivos: int}
     */
    public static function aplicar(): array
    {
        $nombres = [
            'PETICION' => 'Petición',
            'FELICITACION' => 'Felicitación',
        ];
        $tipos = 0;
        $motivos = 0;

        foreach ($nombres as $codigo => $nombre) {
            $tipo = TipoPqrsd::query()->where('codigo', $codigo)->first();
            if (! $tipo) {
                continue;
            }

            if ($tipo->nombre !== $nombre) {
                $tipo->nombre = $nombre;
                $tipo->save();
                $tipos++;
            }

            $motivo = MotivoPqrsd::query()
                ->where('tipo_pqrsd_id', $tipo->id)
                ->where('codigo', 'GENERAL')
                ->first();
            $motivoNombre = 'Motivo general - '.$nombre;
            if ($motivo && $motivo->nombre !== $motivoNombre) {
                $motivo->nombre = $motivoNombre;
                $motivo->save();
                $motivos++;
            }
        }

        return ['tipos' => $tipos, 'motivos' => $motivos];
    }
}
