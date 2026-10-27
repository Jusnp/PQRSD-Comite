<?php

namespace App\Services;

use App\Models\ActuacionPqrsd;
use App\Models\Pqrsd;
use App\Models\RemisionInterna;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Remisiones a áreas institucionales (sin cuentas Laravel para esas áreas).
 * No modifica SLA, recepción, riesgo ni cierre del expediente.
 */
class RemisionInternaService
{
    public function __construct(
        private readonly AdjuntoPqrsdService $adjuntos,
        private readonly PqrsdService $pqrsdService,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  list<UploadedFile>  $files
     */
    public function crear(Pqrsd $caso, User $actor, array $data, array $files = []): RemisionInterna
    {
        $inicioAntes = $caso->fecha_inicio_conteo?->toDateTimeString();
        $limiteAntes = $caso->fecha_limite?->toDateTimeString();
        $riesgoAntes = $caso->nivel_riesgo;

        return DB::transaction(function () use ($caso, $actor, $data, $files, $inicioAntes, $limiteAntes, $riesgoAntes) {
            $caso = Pqrsd::bloquearParaEscritura((int) $caso->id);
            $inicioAntes = $caso->fecha_inicio_conteo?->toDateTimeString();
            $limiteAntes = $caso->fecha_limite?->toDateTimeString();
            $riesgoAntes = $caso->nivel_riesgo;

            $remision = RemisionInterna::create([
                'pqrsd_id' => $caso->id,
                'area_institucional_id' => $data['area_institucional_id'],
                'motivo' => $data['motivo'],
                'informacion_solicitada' => $data['informacion_solicitada'],
                'responsable_seguimiento_id' => $data['responsable_seguimiento_id'] ?? $actor->id,
                'fecha_limite_interna' => isset($data['fecha_limite_interna'])
                    ? Carbon::parse($data['fecha_limite_interna'], 'America/Bogota')
                    : null,
                'observaciones' => $data['observaciones'] ?? null,
                'estado' => RemisionInterna::ESTADO_PREPARADA,
                'creada_por' => $actor->id,
            ]);
            $remision->load('area');

            if ($files !== []) {
                $this->adjuntos->guardarSoportesInternos($caso, $files, $actor, $remision->id, 'remision_solicitud');
            }

            ActuacionPqrsd::create([
                'pqrsd_id' => $caso->id,
                'usuario_id' => $actor->id,
                'tipo' => 'remision_area',
                'titulo' => 'Remisión interna preparada',
                'detalle' => sprintf(
                    'Área: %s. Motivo: %s. Plazo interno: %s. (No reinicia SLA del radicado.)',
                    $remision->area?->nombre ?? (string) $remision->area_institucional_id,
                    $remision->motivo,
                    $remision->fecha_limite_interna?->timezone('America/Bogota')->format('d/m/Y H:i') ?? 'sin definir'
                ),
                'es_interna' => true,
                'datos_adicionales' => [
                    'remision_interna_id' => $remision->id,
                    'estado' => $remision->estado,
                    'envio_institucional_laravel' => false,
                ],
            ]);

            $this->pqrsdService->auditar($caso, $actor, 'remision.crear', null, [
                'remision_interna_id' => $remision->id,
                'estado' => $remision->estado,
            ], 'remision_interna', $remision->id);

            $this->assertSlaIntacto($caso->fresh(), $inicioAntes, $limiteAntes, $riesgoAntes);

            return $remision->fresh(['area', 'responsableSeguimiento', 'adjuntos']);
        });
    }

    /**
     * Registra que el auxiliar remitió manualmente por un canal externo.
     * No afirma envío electrónico desde Laravel.
     *
     * @param  list<UploadedFile>  $files
     */
    public function registrarRemisionExterna(
        RemisionInterna $remision,
        User $actor,
        array $data,
        array $files = []
    ): RemisionInterna {
        $caso = $remision->pqrsd;
        $inicioAntes = $caso->fecha_inicio_conteo?->toDateTimeString();
        $limiteAntes = $caso->fecha_limite?->toDateTimeString();
        $riesgoAntes = $caso->nivel_riesgo;

        if (in_array($remision->estado, [RemisionInterna::ESTADO_FINALIZADA], true)) {
            throw new InvalidArgumentException('No se puede registrar remisión externa en una remisión finalizada.');
        }

        return DB::transaction(function () use ($remision, $actor, $data, $files, $caso, $inicioAntes, $limiteAntes, $riesgoAntes) {
            $caso = Pqrsd::bloquearParaEscritura((int) $caso->id);
            $inicioAntes = $caso->fecha_inicio_conteo?->toDateTimeString();
            $limiteAntes = $caso->fecha_limite?->toDateTimeString();
            $riesgoAntes = $caso->nivel_riesgo;
            $remision = RemisionInterna::query()->whereKey($remision->id)->lockForUpdate()->firstOrFail();

            $antes = $remision->estado;
            $fecha = isset($data['fecha_remision'])
                ? Carbon::parse($data['fecha_remision'], 'America/Bogota')
                : now('America/Bogota');

            $remision->update([
                'estado' => RemisionInterna::ESTADO_PENDIENTE_RESPUESTA,
                'medio_comunicacion' => $data['medio_comunicacion'],
                'evidencia_envio_detalle' => $data['evidencia_envio_detalle'] ?? null,
                'fecha_remision' => $fecha,
            ]);

            // Historial explícito del estado intermedio "remitida por canal externo".
            ActuacionPqrsd::create([
                'pqrsd_id' => $caso->id,
                'usuario_id' => $actor->id,
                'tipo' => 'remision_area',
                'titulo' => 'Registrada como remitida por canal externo',
                'detalle' => sprintf(
                    'Medio: %s. Evidencia: %s. Estado operativo de seguimiento: pendiente de respuesta. No hubo envío automático desde Laravel.',
                    RemisionInterna::MEDIOS_COMUNICACION[$data['medio_comunicacion']] ?? $data['medio_comunicacion'],
                    $data['evidencia_envio_detalle'] ?? 'registrada en adjuntos / detalle'
                ),
                'es_interna' => true,
                'datos_adicionales' => [
                    'remision_interna_id' => $remision->id,
                    'estado_anterior' => $antes,
                    'estado_transitorio' => RemisionInterna::ESTADO_REMITIDA_CANAL_EXTERNO,
                    'estado' => RemisionInterna::ESTADO_PENDIENTE_RESPUESTA,
                    'envio_institucional_laravel' => false,
                ],
            ]);

            if ($files !== []) {
                $this->adjuntos->guardarSoportesInternos($caso, $files, $actor, $remision->id, 'remision_evidencia_envio');
            }

            $this->pqrsdService->auditar($caso, $actor, 'remision.remitir_externo', [
                'estado' => $antes,
            ], [
                'estado' => $remision->estado,
                'medio_comunicacion' => $remision->medio_comunicacion,
            ], 'remision_interna', $remision->id);

            $this->assertSlaIntacto($caso->fresh(), $inicioAntes, $limiteAntes, $riesgoAntes);

            return $remision->fresh(['area', 'responsableSeguimiento', 'adjuntos']);
        });
    }

    /**
     * Respuesta del área (información interna). No es la respuesta de PQRS al ciudadano.
     *
     * @param  list<UploadedFile>  $files
     */
    public function registrarRespuestaArea(
        RemisionInterna $remision,
        User $actor,
        array $data,
        array $files = []
    ): RemisionInterna {
        $caso = $remision->pqrsd;
        $inicioAntes = $caso->fecha_inicio_conteo?->toDateTimeString();
        $limiteAntes = $caso->fecha_limite?->toDateTimeString();
        $riesgoAntes = $caso->nivel_riesgo;

        if ($remision->estado === RemisionInterna::ESTADO_FINALIZADA) {
            throw new InvalidArgumentException('La remisión ya está finalizada.');
        }

        return DB::transaction(function () use ($remision, $actor, $data, $files, $caso, $inicioAntes, $limiteAntes, $riesgoAntes) {
            $caso = Pqrsd::bloquearParaEscritura((int) $caso->id);
            $inicioAntes = $caso->fecha_inicio_conteo?->toDateTimeString();
            $limiteAntes = $caso->fecha_limite?->toDateTimeString();
            $riesgoAntes = $caso->nivel_riesgo;
            $remision = RemisionInterna::query()->whereKey($remision->id)->lockForUpdate()->firstOrFail();

            $antes = $remision->estado;
            $fecha = isset($data['fecha_respuesta'])
                ? Carbon::parse($data['fecha_respuesta'], 'America/Bogota')
                : now('America/Bogota');

            $remision->update([
                'estado' => RemisionInterna::ESTADO_RESPUESTA_RECIBIDA,
                'fecha_respuesta' => $fecha,
                'persona_responde' => $data['persona_responde'] ?? null,
                'contenido_respuesta' => $data['contenido_respuesta'],
                'observaciones_respuesta' => $data['observaciones_respuesta'] ?? null,
            ]);

            if ($files !== []) {
                $this->adjuntos->guardarSoportesInternos($caso, $files, $actor, $remision->id, 'remision_respuesta_area');
            }

            ActuacionPqrsd::create([
                'pqrsd_id' => $caso->id,
                'usuario_id' => $actor->id,
                'tipo' => 'respuesta_area',
                'titulo' => 'Respuesta del área recibida (información interna)',
                'detalle' => sprintf(
                    'Área: %s. Persona: %s. Contenido: %s. Esta información NO es la respuesta oficial de PQRS al solicitante.',
                    $remision->area?->nombre ?? 'N/A',
                    $data['persona_responde'] ?? 'no identificada',
                    $data['contenido_respuesta']
                ),
                'es_interna' => true,
                'datos_adicionales' => [
                    'remision_interna_id' => $remision->id,
                    'estado_anterior' => $antes,
                    'estado' => RemisionInterna::ESTADO_RESPUESTA_RECIBIDA,
                    'es_respuesta_oficial_ciudadano' => false,
                ],
            ]);

            $this->pqrsdService->auditar($caso, $actor, 'remision.respuesta_area', [
                'estado' => $antes,
            ], [
                'estado' => $remision->estado,
            ], 'remision_interna', $remision->id);

            $this->assertSlaIntacto($caso->fresh(), $inicioAntes, $limiteAntes, $riesgoAntes);

            return $remision->fresh(['area', 'responsableSeguimiento', 'adjuntos']);
        });
    }

    public function finalizar(RemisionInterna $remision, User $actor, ?string $motivo = null): RemisionInterna
    {
        $caso = $remision->pqrsd;
        $inicioAntes = $caso->fecha_inicio_conteo?->toDateTimeString();
        $limiteAntes = $caso->fecha_limite?->toDateTimeString();
        $riesgoAntes = $caso->nivel_riesgo;

        if ($remision->estado === RemisionInterna::ESTADO_FINALIZADA) {
            return $remision;
        }

        if ($remision->estado !== RemisionInterna::ESTADO_RESPUESTA_RECIBIDA) {
            throw new InvalidArgumentException('Solo se puede finalizar una remisión con respuesta del área recibida.');
        }

        return DB::transaction(function () use ($remision, $actor, $motivo, $caso, $inicioAntes, $limiteAntes, $riesgoAntes) {
            $caso = Pqrsd::bloquearParaEscritura((int) $caso->id);
            $inicioAntes = $caso->fecha_inicio_conteo?->toDateTimeString();
            $limiteAntes = $caso->fecha_limite?->toDateTimeString();
            $riesgoAntes = $caso->nivel_riesgo;
            $remision = RemisionInterna::query()->whereKey($remision->id)->lockForUpdate()->firstOrFail();

            $antes = $remision->estado;
            $remision->update([
                'estado' => RemisionInterna::ESTADO_FINALIZADA,
                'finalizada_en' => now('America/Bogota'),
            ]);

            ActuacionPqrsd::create([
                'pqrsd_id' => $caso->id,
                'usuario_id' => $actor->id,
                'tipo' => 'remision_area',
                'titulo' => 'Remisión interna finalizada',
                'detalle' => $motivo ?: 'Seguimiento de remisión cerrado. El expediente PQRSD permanece según su propio flujo.',
                'es_interna' => true,
                'datos_adicionales' => [
                    'remision_interna_id' => $remision->id,
                    'estado_anterior' => $antes,
                    'estado' => RemisionInterna::ESTADO_FINALIZADA,
                ],
            ]);

            $this->assertSlaIntacto($caso->fresh(), $inicioAntes, $limiteAntes, $riesgoAntes);

            return $remision->fresh();
        });
    }

    private function assertSlaIntacto(Pqrsd $caso, ?string $inicioAntes, ?string $limiteAntes, string $riesgoAntes): void
    {
        if ($inicioAntes !== $caso->fecha_inicio_conteo?->toDateTimeString()
            || $limiteAntes !== $caso->fecha_limite?->toDateTimeString()
            || $riesgoAntes !== $caso->nivel_riesgo) {
            throw new \RuntimeException('Integridad SLA: una remisión interna no debe alterar plazos ni riesgo.');
        }
    }
}
