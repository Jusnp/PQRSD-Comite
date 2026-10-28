<?php

namespace App\Services;

use App\Models\ActuacionPqrsd;
use App\Models\AuditoriaPqrsd;
use App\Models\HistorialEstadoPqrsd;
use App\Models\Pqrsd;
use App\Models\TipoPqrsd;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;

class PqrsdService
{
    public function __construct(
        private readonly RadicadoGenerator $radicadoGenerator,
        private readonly SlaCalculator $slaCalculator,
    ) {}

    public function radicarManual(array $data, User $usuario): Pqrsd
    {
        return DB::transaction(function () use ($data, $usuario) {
            $recepcion = isset($data['fecha_recepcion'])
                ? \Carbon\Carbon::parse($data['fecha_recepcion'], 'America/Bogota')
                : now('America/Bogota');

            if (! empty($data['es_anonimo'])) {
                $data['solicitante_telefono'] = null;
            }

            $nivelSolicitado = $data['nivel_riesgo'] ?? 'pendiente';

            $resolucion = $nivelSolicitado !== 'pendiente'
                ? $this->slaCalculator->resolverNivelRiesgo(
                    $nivelSolicitado,
                    isset($data['paciente_fecha_nacimiento'])
                        ? \Carbon\Carbon::parse($data['paciente_fecha_nacimiento'], 'America/Bogota')
                        : null,
                    $recepcion,
                    isset($data['paciente_edad']) ? (int) $data['paciente_edad'] : null
                )
                : ['nivel_riesgo' => 'pendiente', 'edad_en_recepcion' => null, 'motivo' => 'Sin clasificar'];

            $nivelRiesgo = $resolucion['nivel_riesgo'];
            $estadoOperativo = ($nivelRiesgo === 'pendiente' || empty($data['tipo_pqrsd_id']))
                ? 'pendiente_clasificacion'
                : 'radicado';

            $payload = [
                'radicado' => $this->radicadoGenerator->siguiente(),
                'fecha_recepcion' => $recepcion,
                'fecha_radicacion' => now('America/Bogota'),
                'canal_ingreso_id' => $data['canal_ingreso_id'],
                'tipo_pqrsd_id' => $data['tipo_pqrsd_id'] ?? null,
                'motivo_pqrsd_id' => $data['motivo_pqrsd_id'] ?? null,
                'nivel_riesgo' => $nivelRiesgo,
                'estado_operativo' => $estadoOperativo,
                'estado_cumplimiento' => 'sin_clasificar',
                'es_anonimo' => (bool) ($data['es_anonimo'] ?? false),
                'solicitante_nombre' => $data['solicitante_nombre'] ?? null,
                'solicitante_tipo_documento' => $data['solicitante_tipo_documento'] ?? null,
                'solicitante_documento' => $data['solicitante_documento'] ?? null,
                'solicitante_email' => $data['solicitante_email'] ?? null,
                'solicitante_telefono' => $data['solicitante_telefono'] ?? null,
                'paciente_nombre' => $data['paciente_nombre'] ?? null,
                'paciente_tipo_documento' => $data['paciente_tipo_documento'] ?? null,
                'paciente_documento' => $data['paciente_documento'] ?? null,
                'paciente_fecha_nacimiento' => $data['paciente_fecha_nacimiento'] ?? null,
                'paciente_edad' => $resolucion['edad_en_recepcion'] ?? ($data['paciente_edad'] ?? null),
                'representante_nombre' => $data['representante_nombre'] ?? null,
                'aseguradora_id' => $data['aseguradora_id'] ?? null,
                'sede_id' => $data['sede_id'] ?? null,
                'servicio_area' => $data['servicio_area'] ?? null,
                'descripcion' => $data['descripcion'],
                'observaciones_internas' => $data['observaciones_internas'] ?? null,
                'responsable_id' => $data['responsable_id'] ?? null,
                'creado_por' => $usuario->id,
                'radicado_por' => $usuario->id,
            ];

            $tipoCodigo = null;
            if (! empty($data['tipo_pqrsd_id'])) {
                $tipoCodigo = TipoPqrsd::query()->whereKey($data['tipo_pqrsd_id'])->value('codigo');
            }

            $regla = $this->slaCalculator->reglaParaCaso($nivelRiesgo, $tipoCodigo);
            if ($regla) {
                // Conteo desde recepción original, no desde radicación tardía.
                // No recalcula casos históricos ya persistidos: solo aplica al crear/clasificar.
                $payload = array_merge($payload, $this->slaCalculator->aplicarACaso($regla, $recepcion));
            }

            $caso = Pqrsd::create($payload);

            ActuacionPqrsd::create([
                'pqrsd_id' => $caso->id,
                'usuario_id' => $usuario->id,
                'tipo' => 'radicacion',
                'titulo' => 'Radicación manual',
                'detalle' => 'Caso radicado con número '.$caso->radicado
                    .'. Recepción preservada: '.$recepcion->format('d/m/Y H:i')
                    .($caso->fecha_limite ? '. Límite: '.$caso->fecha_limite->format('d/m/Y H:i') : ''),
                'es_interna' => true,
                'datos_adicionales' => [
                    'resolucion_riesgo' => $resolucion,
                    'fecha_inicio_conteo' => $caso->fecha_inicio_conteo,
                    'fecha_limite' => $caso->fecha_limite,
                ],
            ]);

            HistorialEstadoPqrsd::create([
                'pqrsd_id' => $caso->id,
                'usuario_id' => $usuario->id,
                'campo' => 'estado_operativo',
                'valor_anterior' => null,
                'valor_nuevo' => $caso->estado_operativo,
                'motivo' => 'Radicación inicial',
            ]);

            if ($caso->fecha_limite) {
                HistorialEstadoPqrsd::create([
                    'pqrsd_id' => $caso->id,
                    'usuario_id' => $usuario->id,
                    'campo' => 'fecha_limite',
                    'valor_anterior' => null,
                    'valor_nuevo' => $caso->fecha_limite,
                    'motivo' => 'Plazo inicial (inicio = recepción)',
                ]);
            }

            $despues = $caso->toArray();
            if ($caso->es_anonimo) {
                unset($despues['solicitante_telefono']);
            }
            $this->auditar($caso, $usuario, 'radicar', null, $despues);

            if (in_array($nivelRiesgo, ['vital_menor', 'vital_general'], true)) {
                app(SlaNotificationService::class)->notificarVitalInmediato($caso);
            }

            return $caso->fresh([
                'canal', 'tipo', 'motivo', 'sede', 'aseguradora', 'responsable', 'reglaSla',
            ]);
        });
    }

    public function registrarCambioEstado(
        Pqrsd $caso,
        User $usuario,
        string $campo,
        ?string $valorNuevo,
        ?string $motivo = null
    ): void {
        // Campos de plazo protegidos: no se alteran por cambios operativos
        if (in_array($campo, ['fecha_limite', 'fecha_inicio_conteo', 'fecha_recepcion', 'regla_sla_id'], true)) {
            throw new \InvalidArgumentException("El campo {$campo} no puede modificarse como cambio de estado operativo.");
        }

        $caso->assertAbiertoParaEscritura();

        $anterior = $caso->{$campo};
        $limiteAntes = $caso->fecha_limite?->toDateTimeString();
        $inicioAntes = $caso->fecha_inicio_conteo?->toDateTimeString();

        if ((string) $anterior === (string) $valorNuevo) {
            return;
        }

        $caso->update([$campo => $valorNuevo]);

        HistorialEstadoPqrsd::create([
            'pqrsd_id' => $caso->id,
            'usuario_id' => $usuario->id,
            'campo' => $campo,
            'valor_anterior' => $anterior,
            'valor_nuevo' => $valorNuevo,
            'motivo' => $motivo,
        ]);

        ActuacionPqrsd::create([
            'pqrsd_id' => $caso->id,
            'usuario_id' => $usuario->id,
            'tipo' => 'cambio_estado',
            'titulo' => 'Cambio de '.$campo,
            'detalle' => trim(($anterior ?? 'N/A').' → '.($valorNuevo ?? 'N/A').($motivo ? '. Motivo: '.$motivo : '')),
            'es_interna' => true,
        ]);

        $caso->refresh();
        if ($limiteAntes !== $caso->fecha_limite?->toDateTimeString()
            || $inicioAntes !== $caso->fecha_inicio_conteo?->toDateTimeString()) {
            throw new \RuntimeException('Integridad SLA: un cambio de estado no debe reiniciar plazos.');
        }

        $this->auditar($caso, $usuario, 'cambiar_'.$campo, [$campo => $anterior], [$campo => $valorNuevo]);
    }

    public function auditar(
        ?Pqrsd $caso,
        ?User $usuario,
        string $accion,
        ?array $antes = null,
        ?array $despues = null,
        ?string $entidad = 'pqrsd',
        ?int $entidadId = null
    ): void {
        AuditoriaPqrsd::create([
            'pqrsd_id' => $caso?->id,
            'usuario_id' => $usuario?->id,
            'accion' => $accion,
            'entidad' => $entidad,
            'entidad_id' => $entidadId ?? $caso?->id,
            'antes' => $antes,
            'despues' => $despues,
            'ip' => Request::ip(),
            'user_agent' => Request::userAgent(),
        ]);
    }
}
