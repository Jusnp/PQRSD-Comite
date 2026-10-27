<?php

namespace App\Services;

use App\Models\ActuacionPqrsd;
use App\Models\HistorialEstadoPqrsd;
use App\Models\Pqrsd;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class VencimientoService
{
    public function __construct(
        private readonly SlaCalculator $sla,
        private readonly PqrsdService $pqrsdService,
        private readonly SlaNotificationService $notificaciones,
    ) {}

    /**
     * Aplica o recalcula plazo solo por clasificación de riesgo.
     * La fecha de inicio de conteo es siempre la recepción original.
     */
    public function aplicarClasificacion(
        Pqrsd $caso,
        string $nivelSolicitado,
        User $usuario,
        ?string $motivo = null,
        bool $forzarRecalculo = false,
        ?string $expectedVersion = null,
        bool $versionObligatoria = false
    ): Pqrsd {
        return DB::transaction(function () use ($caso, $nivelSolicitado, $usuario, $motivo, $forzarRecalculo, $expectedVersion, $versionObligatoria) {
            $caso = Pqrsd::bloquearParaEscritura((int) $caso->id, $expectedVersion, $versionObligatoria);
            $recepcion = Carbon::parse($caso->fecha_recepcion)->timezone('America/Bogota');
            $resolucion = $this->sla->resolverNivelRiesgo(
                $nivelSolicitado,
                $caso->paciente_fecha_nacimiento,
                $recepcion,
                $caso->paciente_edad
            );

            $nivel = $resolucion['nivel_riesgo'];
            $tipoCodigo = $caso->tipo?->codigo
                ?? ($caso->tipo_pqrsd_id
                    ? \App\Models\TipoPqrsd::query()->whereKey($caso->tipo_pqrsd_id)->value('codigo')
                    : null);
            $regla = $this->sla->reglaParaCaso($nivel, $tipoCodigo);

            $antes = $caso->only([
                'nivel_riesgo', 'regla_sla_id', 'regla_sla_version',
                'fecha_inicio_conteo', 'fecha_limite', 'estado_cumplimiento',
            ]);

            $updates = [
                'nivel_riesgo' => $nivel,
            ];

            if ($regla) {
                // No se reinicia el conteo: siempre desde recepción original
                $aplicado = $this->sla->aplicarACaso($regla, $recepcion);
                $updates = array_merge($updates, $aplicado);
            } else {
                $updates['estado_cumplimiento'] = 'sin_clasificar';
            }

            $caso->fill($updates);
            $caso->save();

            HistorialEstadoPqrsd::create([
                'pqrsd_id' => $caso->id,
                'usuario_id' => $usuario->id,
                'campo' => 'nivel_riesgo',
                'valor_anterior' => $antes['nivel_riesgo'],
                'valor_nuevo' => $nivel,
                'motivo' => $motivo ?? $resolucion['motivo'],
            ]);

            if (($antes['fecha_limite']?->toDateTimeString() ?? null) !== ($caso->fecha_limite?->toDateTimeString())) {
                HistorialEstadoPqrsd::create([
                    'pqrsd_id' => $caso->id,
                    'usuario_id' => $usuario->id,
                    'campo' => 'fecha_limite',
                    'valor_anterior' => $antes['fecha_limite'],
                    'valor_nuevo' => $caso->fecha_limite,
                    'motivo' => 'Recálculo por clasificación (sin alterar fecha de recepción)',
                ]);
            }

            ActuacionPqrsd::create([
                'pqrsd_id' => $caso->id,
                'usuario_id' => $usuario->id,
                'tipo' => 'clasificacion_sla',
                'titulo' => 'Clasificación / plazo aplicado',
                'detalle' => sprintf(
                    'Riesgo %s → límite %s (regla %s v%s). Inicio conteo = recepción %s. %s',
                    $nivel,
                    optional($caso->fecha_limite)->timezone('America/Bogota')->format('d/m/Y H:i'),
                    $regla?->codigo ?? 'N/A',
                    $regla?->version ?? '-',
                    $recepcion->format('d/m/Y H:i'),
                    $motivo ?? $resolucion['motivo']
                ),
                'es_interna' => true,
                'datos_adicionales' => [
                    'antes' => $antes,
                    'despues' => $caso->only(array_keys($antes)),
                    'edad_en_recepcion' => $resolucion['edad_en_recepcion'] ?? null,
                    'forzar' => $forzarRecalculo,
                ],
            ]);

            $this->pqrsdService->auditar(
                $caso,
                $usuario,
                'aplicar_sla',
                $antes,
                $caso->only(array_keys($antes))
            );

            if (in_array($nivel, ['vital_menor', 'vital_general'], true)) {
                $this->notificaciones->notificarVitalInmediato($caso);
            }

            return $caso->fresh(['reglaSla']);
        });
    }

    /**
     * Reasignación: NO reinicia plazos.
     */
    public function reasignar(Pqrsd $caso, ?int $nuevoResponsableId, User $actor, ?string $motivo = null): Pqrsd
    {
        $limiteAntes = $caso->fecha_limite?->toDateTimeString();
        $inicioAntes = $caso->fecha_inicio_conteo?->toDateTimeString();
        $anterior = $caso->responsable_id;

        $caso->update(['responsable_id' => $nuevoResponsableId]);

        \App\Models\AsignacionPqrsd::query()
            ->where('pqrsd_id', $caso->id)
            ->where('activa', true)
            ->update([
                'activa' => false,
                'finalizado_en' => now('America/Bogota'),
            ]);

        if ($nuevoResponsableId) {
            \App\Models\AsignacionPqrsd::create([
                'pqrsd_id' => $caso->id,
                'asignado_a' => $nuevoResponsableId,
                'asignado_por' => $actor->id,
                'motivo' => $motivo ?? 'Asignación / reasignación de responsable',
                'asignado_en' => now('America/Bogota'),
                'activa' => true,
            ]);
        }

        if ($nuevoResponsableId && in_array($caso->estado_operativo, ['radicado', 'pendiente_clasificacion'], true)) {
            $this->pqrsdService->registrarCambioEstado(
                $caso->fresh(),
                $actor,
                'estado_operativo',
                'asignado',
                $motivo ?? 'Asignación de responsable'
            );
            $caso->refresh();
        }

        ActuacionPqrsd::create([
            'pqrsd_id' => $caso->id,
            'usuario_id' => $actor->id,
            'tipo' => 'reasignacion',
            'titulo' => 'Reasignación de responsable',
            'detalle' => sprintf(
                'Responsable %s → %s. Plazos preservados (inicio %s / límite %s).',
                $anterior ?? 'N/A',
                $nuevoResponsableId ?? 'N/A',
                $inicioAntes ?? 'N/A',
                $limiteAntes ?? 'N/A'
            ),
            'es_interna' => true,
        ]);

        $caso->refresh();
        $this->assertPlazosIntactos($caso, $inicioAntes, $limiteAntes);

        return $caso;
    }

    public function registrarRespuesta(
        Pqrsd $caso,
        User $usuario,
        ?CarbonInterface $fechaRespuesta = null,
        ?string $expectedVersion = null,
        bool $yaBloqueadoEnTransaccion = false,
        bool $versionObligatoria = false
    ): Pqrsd {
        $escritor = function () use ($caso, $usuario, $fechaRespuesta, $expectedVersion, $yaBloqueadoEnTransaccion, $versionObligatoria) {
            if ($yaBloqueadoEnTransaccion) {
                $caso->assertAbiertoParaEscritura();
                $casoTrabajo = $caso;
            } else {
                $casoTrabajo = Pqrsd::bloquearParaEscritura((int) $caso->id, $expectedVersion, $versionObligatoria);
            }

            $inicioAntes = $casoTrabajo->fecha_inicio_conteo?->toDateTimeString();
            $limiteAntes = $casoTrabajo->fecha_limite?->toDateTimeString();

            $fecha = Carbon::parse($fechaRespuesta ?? now('America/Bogota'))->timezone('America/Bogota');
            $estado = $this->sla->calcularEstadoCumplimiento(
                $casoTrabajo->fecha_inicio_conteo,
                $casoTrabajo->fecha_limite,
                $casoTrabajo->nivel_riesgo,
                $fecha,
                respondido: true,
                fechaRespuesta: $fecha
            );

            $antes = $casoTrabajo->estado_cumplimiento;
            $casoTrabajo->update([
                'fecha_respuesta' => $fecha,
                'estado_cumplimiento' => $estado,
                'estado_operativo' => 'respondido',
            ]);

            HistorialEstadoPqrsd::create([
                'pqrsd_id' => $casoTrabajo->id,
                'usuario_id' => $usuario->id,
                'campo' => 'estado_cumplimiento',
                'valor_anterior' => $antes,
                'valor_nuevo' => $estado,
                'motivo' => 'Registro de respuesta de PQRS',
            ]);

            ActuacionPqrsd::create([
                'pqrsd_id' => $casoTrabajo->id,
                'usuario_id' => $usuario->id,
                'tipo' => 'respuesta',
                'titulo' => 'Respuesta registrada',
                'detalle' => 'Cumplimiento: '.$estado.' (límite '.$casoTrabajo->fecha_limite?->format('d/m/Y H:i').')',
                'es_interna' => true,
            ]);

            $casoTrabajo->refresh();
            $this->assertPlazosIntactos($casoTrabajo, $inicioAntes, $limiteAntes);

            $this->pqrsdService->auditar($casoTrabajo, $usuario, 'registrar_respuesta', [
                'estado_cumplimiento' => $antes,
            ], [
                'estado_cumplimiento' => $estado,
                'fecha_respuesta' => $fecha->toDateTimeString(),
            ]);

            return $casoTrabajo->fresh();
        };

        return $yaBloqueadoEnTransaccion
            ? $escritor()
            : DB::transaction($escritor);
    }

    public function refrescarCumplimiento(Pqrsd $caso, ?CarbonInterface $ahora = null): Pqrsd
    {
        if (in_array($caso->estado_cumplimiento, ['resuelto_oportuno', 'resuelto_extemporaneo'], true)) {
            return $caso;
        }

        $nuevo = $this->sla->calcularEstadoCumplimiento(
            $caso->fecha_inicio_conteo,
            $caso->fecha_limite,
            $caso->nivel_riesgo,
            $ahora,
            respondido: (bool) $caso->fecha_respuesta,
            fechaRespuesta: $caso->fecha_respuesta
        );

        if ($nuevo !== $caso->estado_cumplimiento) {
            $antes = $caso->estado_cumplimiento;
            $caso->update(['estado_cumplimiento' => $nuevo]);

            HistorialEstadoPqrsd::create([
                'pqrsd_id' => $caso->id,
                'usuario_id' => null,
                'campo' => 'estado_cumplimiento',
                'valor_anterior' => $antes,
                'valor_nuevo' => $nuevo,
                'motivo' => 'Actualización automática de semáforo',
            ]);
        }

        return $caso->fresh();
    }

    public function refrescarAbiertos(?CarbonInterface $ahora = null): int
    {
        $count = 0;
        Pqrsd::query()
            ->whereNotIn('estado_cumplimiento', ['resuelto_oportuno', 'resuelto_extemporaneo', 'sin_clasificar'])
            ->whereNotNull('fecha_limite')
            ->orderBy('id')
            ->chunkById(100, function ($casos) use (&$count, $ahora) {
                foreach ($casos as $caso) {
                    $this->refrescarCumplimiento($caso, $ahora);
                    $this->notificaciones->evaluarRecordatorios($caso->fresh());
                    $count++;
                }
            });

        return $count;
    }

    public function snapshot(Pqrsd $caso, ?CarbonInterface $ahora = null): array
    {
        return $this->sla->snapshotPlazo(
            $caso->fecha_inicio_conteo,
            $caso->fecha_limite,
            $caso->nivel_riesgo,
            $ahora,
            in_array($caso->estado_cumplimiento, ['resuelto_oportuno', 'resuelto_extemporaneo'], true),
            $caso->fecha_respuesta
        );
    }

    /**
     * Cierre verificable: exige clasificación formal y comunicación válida
     * (puesta a disposición o envío evidenciado), no solo un borrador elaborado.
     * No altera fecha_limite ni fecha_recepcion.
     */
    public function cerrar(
        Pqrsd $caso,
        User $usuario,
        string $motivo,
        ?string $expectedVersion = null,
        bool $versionObligatoria = false
    ): Pqrsd {
        return DB::transaction(function () use ($caso, $usuario, $motivo, $expectedVersion, $versionObligatoria) {
            /** @var Pqrsd $caso */
            $caso = Pqrsd::query()->whereKey($caso->id)->lockForUpdate()->firstOrFail();

            if ($caso->estado_operativo === 'cerrado') {
                throw new InvalidArgumentException(
                    'El radicado ya está cerrado. Recargue el expediente para consultarlo en solo lectura.'
                );
            }

            $caso->assertVersionCompatible($expectedVersion, $versionObligatoria);

            $pendientes = $caso->requisitosPendientesCierre();
            if ($pendientes !== []) {
                throw new InvalidArgumentException(
                    'No se puede finalizar todavía. Pendientes: '.implode(' ', $pendientes)
                );
            }

            $limiteAntes = $caso->fecha_limite?->toDateTimeString();
            $inicioAntes = $caso->fecha_inicio_conteo?->toDateTimeString();
            $antes = $caso->estado_operativo;

            $caso->update([
                'estado_operativo' => 'cerrado',
                'fecha_cierre' => now('America/Bogota'),
            ]);

            HistorialEstadoPqrsd::create([
                'pqrsd_id' => $caso->id,
                'usuario_id' => $usuario->id,
                'campo' => 'estado_operativo',
                'valor_anterior' => $antes,
                'valor_nuevo' => 'cerrado',
                'motivo' => $motivo,
            ]);

            ActuacionPqrsd::create([
                'pqrsd_id' => $caso->id,
                'usuario_id' => $usuario->id,
                'tipo' => 'cierre',
                'titulo' => 'Cierre del radicado',
                'detalle' => $motivo.' | Evidencia: respuesta el '
                    .optional($caso->fecha_respuesta)->timezone('America/Bogota')->format('d/m/Y H:i')
                    .' | Estado respuesta: '.($caso->respuesta_estado ?: 'histórico')
                    .' | Cumplimiento: '.$caso->estado_cumplimiento,
                'es_interna' => true,
            ]);

            $caso->refresh();
            $this->assertPlazosIntactos($caso, $inicioAntes, $limiteAntes);
            $this->pqrsdService->auditar($caso, $usuario, 'cerrar', [
                'estado_operativo' => $antes,
            ], [
                'estado_operativo' => 'cerrado',
                'fecha_cierre' => $caso->fecha_cierre?->toDateTimeString(),
                'motivo' => $motivo,
                'cerrado_por' => $usuario->id,
            ]);

            return $caso;
        });
    }

    private function assertPlazosIntactos(Pqrsd $caso, ?string $inicioAntes, ?string $limiteAntes): void
    {
        if ($inicioAntes !== $caso->fecha_inicio_conteo?->toDateTimeString()
            || $limiteAntes !== $caso->fecha_limite?->toDateTimeString()) {
            throw new \RuntimeException('Violación de integridad: la reasignación no debe alterar plazos.');
        }
    }
}
