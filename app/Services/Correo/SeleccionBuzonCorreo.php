<?php

namespace App\Services\Correo;

use App\Models\CuentaCorreo;
use App\Models\Pqrsd;
use Illuminate\Support\Collection;

/**
 * Elige el buzón de una radicación web según la cuenta registrada.
 * No contiene direcciones fijas. Si hay más de un candidato del mismo alcance, no elige.
 */
class SeleccionBuzonCorreo
{
    /**
     * @return array{cuenta:?CuentaCorreo,motivo:?string}
     */
    public function paraRadicacionWeb(Pqrsd $caso): array
    {
        $candidatas = $this->candidatas()->filter(fn (CuentaCorreo $cuenta) => $this->admiteInsercion($cuenta))->values();
        $especificas = $caso->aseguradora_id
            ? $candidatas->where('aseguradora_id', $caso->aseguradora_id)->values()
            : new Collection;

        if ($especificas->count() > 1) {
            return ['cuenta' => null, 'motivo' => 'Hay más de un buzón de esa organización para radicaciones web. No se eligió ninguno.'];
        }
        if ($especificas->count() === 1) {
            return ['cuenta' => $especificas->first(), 'motivo' => null];
        }

        $institucionales = $candidatas->whereNull('aseguradora_id')->values();
        if ($institucionales->count() > 1) {
            return ['cuenta' => null, 'motivo' => 'Hay más de un buzón institucional para radicaciones web. No se eligió ninguno.'];
        }
        if ($institucionales->count() === 1) {
            return ['cuenta' => $institucionales->first(), 'motivo' => null];
        }

        return ['cuenta' => null, 'motivo' => 'No hay un buzón activo autorizado para radicaciones web.'];
    }

    public function permiteAsociar(CuentaCorreo $cuenta, Pqrsd $caso): bool
    {
        if ($cuenta->aseguradora_id === null) {
            return true;
        }

        return (int) $cuenta->aseguradora_id === (int) $caso->aseguradora_id;
    }

    public function admiteInsercion(CuentaCorreo $cuenta): bool
    {
        return ! ($cuenta->tipo_buzon === 'grupo' && blank($cuenta->buzon_canonico));
    }

    /**
     * @return Collection<int, CuentaCorreo>
     */
    private function candidatas(): Collection
    {
        return CuentaCorreo::query()
            ->where('activo', true)
            ->where('recibe_radicacion_web', true)
            ->where('tipo_integracion', 'google_api')
            ->orderBy('id')
            ->get();
    }
}
