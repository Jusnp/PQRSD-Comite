<?php

namespace App\Services;

use App\Models\ActuacionPqrsd;
use App\Models\AuditoriaPqrsd;
use App\Models\HistorialEstadoPqrsd;
use App\Models\Pqrsd;
use App\Models\RadicadoAlias;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Cambia el radicado oficial, conserva el anterior como alias y deja auditoría.
 * No reescribe actuaciones ni registra por sí solo que el ciudadano fue informado.
 */
class RegularizacionRadicado
{
    public function aplicar(Pqrsd $caso, string $radicadoNuevo, User $actor, string $motivo): Pqrsd
    {
        $radicadoNuevo = strtoupper(trim($radicadoNuevo));
        $motivo = trim($motivo);
        if (preg_match('/^PQRSD-\d{4}-\d{4,}$/', $radicadoNuevo) !== 1) {
            throw new InvalidArgumentException('radicado');
        }
        if ($motivo === '' || strlen($motivo) > 80) {
            throw new InvalidArgumentException('motivo');
        }
        if (! $actor->exists) {
            throw new InvalidArgumentException('actor');
        }

        return DB::transaction(function () use ($caso, $radicadoNuevo, $actor, $motivo): Pqrsd {
            $bloqueado = Pqrsd::query()->whereKey($caso->id)->lockForUpdate()->firstOrFail();
            $anterior = (string) $bloqueado->radicado;
            if ($anterior === $radicadoNuevo) {
                throw new RuntimeException('mismo-radicado');
            }
            if (Pqrsd::query()->where('radicado', $radicadoNuevo)->whereKeyNot($bloqueado->id)->exists()) {
                throw new RuntimeException('radicado-ocupado');
            }
            if (RadicadoAlias::query()->where('radicado', $anterior)->exists()) {
                throw new RuntimeException('alias-ocupado');
            }

            $textos = ActuacionPqrsd::query()
                ->where('pqrsd_id', $bloqueado->id)
                ->pluck('detalle', 'id');
            $recepcion = $bloqueado->fecha_recepcion?->format('Y-m-d H:i:s');
            $creacion = $bloqueado->created_at?->format('Y-m-d H:i:s');
            $riesgo = $bloqueado->nivel_riesgo;
            $limite = $bloqueado->fecha_limite?->format('Y-m-d H:i:s');
            $regla = $bloqueado->regla_sla_id;
            $version = $bloqueado->regla_sla_version;

            $bloqueado->radicado = $radicadoNuevo;
            $bloqueado->save();

            RadicadoAlias::query()->create([
                'pqrsd_id' => $bloqueado->id,
                'radicado' => $anterior,
                'motivo' => $motivo,
            ]);

            $explicacion = 'Regularización del radicado. El número anterior queda como alias de consulta. La comunicación al ciudadano queda pendiente.';

            HistorialEstadoPqrsd::query()->create([
                'pqrsd_id' => $bloqueado->id,
                'usuario_id' => $actor->id,
                'campo' => 'radicado',
                'valor_anterior' => $anterior,
                'valor_nuevo' => $radicadoNuevo,
                'motivo' => $explicacion,
            ]);

            AuditoriaPqrsd::query()->create([
                'pqrsd_id' => $bloqueado->id,
                'usuario_id' => $actor->id,
                'accion' => 'regularizacion_radicado',
                'entidad' => 'pqrsd',
                'entidad_id' => $bloqueado->id,
                'antes' => ['radicado' => $anterior],
                'despues' => [
                    'radicado' => $radicadoNuevo,
                    'motivo' => $motivo,
                    'comunicacion_ciudadano' => 'no_registrada',
                    'textos_historicos' => 'conservados',
                ],
                'ip' => request()->ip(),
                'user_agent' => substr((string) request()->userAgent(), 0, 255),
            ]);

            $fresco = $bloqueado->fresh();
            if ($fresco->fecha_recepcion?->format('Y-m-d H:i:s') !== $recepcion
                || $fresco->created_at?->format('Y-m-d H:i:s') !== $creacion
                || $fresco->nivel_riesgo !== $riesgo
                || $fresco->fecha_limite?->format('Y-m-d H:i:s') !== $limite
                || $fresco->regla_sla_id !== $regla
                || $fresco->regla_sla_version !== $version) {
                throw new RuntimeException('datos-conservados');
            }

            foreach ($textos as $id => $detalle) {
                $actual = ActuacionPqrsd::query()->whereKey($id)->value('detalle');
                if ($actual !== $detalle) {
                    throw new RuntimeException('texto-historico');
                }
            }

            return $fresco;
        });
    }
}
