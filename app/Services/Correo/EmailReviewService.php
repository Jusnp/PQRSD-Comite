<?php

namespace App\Services\Correo;

use App\Models\ActuacionPqrsd;
use App\Models\CanalIngreso;
use App\Models\MensajeCorreo;
use App\Models\Pqrsd;
use App\Models\User;
use App\Services\PqrsdService;
use Illuminate\Support\Facades\DB;

class EmailReviewService
{
    public function __construct(private readonly PqrsdService $pqrsdService) {}

    public function confirmarNuevaPqrsd(MensajeCorreo $mensaje, User $usuario, array $extra = []): MensajeCorreo
    {
        return DB::transaction(function () use ($mensaje, $usuario, $extra) {
            $mensaje = MensajeCorreo::query()->with('cuenta')->lockForUpdate()->findOrFail($mensaje->id);
            if ($mensaje->pqrsd_id || $mensaje->clasificacion !== 'pendiente_revision') {
                throw new \InvalidArgumentException('Este correo ya fue revisado. No se crea otro expediente.');
            }

            $cuenta = $mensaje->cuenta;
            if ($cuenta?->aseguradora_id) {
                if (isset($extra['aseguradora_id']) && (int) $extra['aseguradora_id'] !== (int) $cuenta->aseguradora_id) {
                    throw new \InvalidArgumentException('El expediente debe quedar en la organización del buzón de origen.');
                }
                $extra['aseguradora_id'] = $cuenta->aseguradora_id;
            }

            $canal = CanalIngreso::query()->where('codigo', 'CORREO')->firstOrFail();
            $anonimo = (bool) ($extra['es_anonimo'] ?? false);

            $caso = $this->pqrsdService->radicarManual([
                'fecha_recepcion' => $mensaje->fecha_mensaje,
                'canal_ingreso_id' => $canal->id,
                'nivel_riesgo' => $extra['nivel_riesgo'] ?? 'pendiente',
                'tipo_pqrsd_id' => $extra['tipo_pqrsd_id'] ?? null,
                'motivo_pqrsd_id' => $extra['motivo_pqrsd_id'] ?? null,
                'descripcion' => $extra['descripcion']
                    ?? trim(($mensaje->asunto ? $mensaje->asunto."\n\n" : '').($mensaje->cuerpo_texto ?? '')),
                'solicitante_nombre' => $anonimo ? null : ($extra['solicitante_nombre'] ?? $mensaje->remitente_nombre),
                'solicitante_tipo_documento' => $extra['solicitante_tipo_documento'] ?? null,
                'solicitante_documento' => $extra['solicitante_documento'] ?? null,
                'solicitante_email' => $anonimo ? null : ($extra['solicitante_email'] ?? $mensaje->remitente),
                'solicitante_telefono' => $anonimo ? null : ($extra['solicitante_telefono'] ?? null),
                'es_anonimo' => $anonimo,
                'sede_id' => $extra['sede_id'] ?? $cuenta?->sede_id,
                'aseguradora_id' => $extra['aseguradora_id'] ?? $cuenta?->aseguradora_id,
            ], $usuario);

            $mensaje->update([
                'pqrsd_id' => $caso->id,
                'clasificacion' => 'nueva_pqrsd',
                'radicado_detectado' => $caso->radicado,
                'revisado_por' => $usuario->id,
                'revisado_en' => now('America/Bogota'),
                'motivo_clasificacion' => 'Confirmado como nueva PQRSD desde bandeja de revisión',
                'estado_procesamiento' => 'procesado',
            ]);

            $this->asociarPendientesDelHilo(
                $mensaje,
                $caso,
                $usuario,
                'Asociado al expediente al confirmar el hilo de Gmail'
            );
            ActuacionPqrsd::create([
                'pqrsd_id' => $caso->id,
                'usuario_id' => $usuario->id,
                'tipo' => 'correo_entrada',
                'titulo' => 'Origen correo',
                'detalle' => 'Importado desde '.$mensaje->cuenta->direccion.' — Message-ID '.$mensaje->message_id,
                'es_interna' => true,
                'datos_adicionales' => [
                    'mensaje_correo_id' => $mensaje->id,
                    'gmail_message_id' => $mensaje->gmail_message_id,
                ],
            ]);

            $this->pqrsdService->auditar($caso, $usuario, 'correo_radicado', null, [
                'mensaje_correo_id' => $mensaje->id,
                'gmail_message_id' => $mensaje->gmail_message_id,
                'cuenta_correo_id' => $mensaje->cuenta_correo_id,
            ], 'mensaje_correo', $mensaje->id);

            return $mensaje->fresh(['pqrsd', 'cuenta', 'adjuntos']);
        });
    }

    public function asociarACaso(MensajeCorreo $mensaje, int $pqrsdId, User $usuario): MensajeCorreo
    {
        return DB::transaction(function () use ($mensaje, $pqrsdId, $usuario) {
            $mensaje = MensajeCorreo::query()->with('cuenta')->lockForUpdate()->findOrFail($mensaje->id);
            if ($mensaje->pqrsd_id) {
                if ((int) $mensaje->pqrsd_id === $pqrsdId) {
                    return $mensaje->fresh(['pqrsd']);
                }
                throw new \InvalidArgumentException('Este correo ya está asociado a un expediente.');
            }

            $caso = Pqrsd::query()->findOrFail($pqrsdId);
            $cuenta = $mensaje->cuenta;
            if ($cuenta && ! app(SeleccionBuzonCorreo::class)->permiteAsociar($cuenta, $caso)) {
                throw new \InvalidArgumentException('Ese mensaje pertenece a otro buzón y no se asocia a este expediente.');
            }

            $mensaje->update([
                'pqrsd_id' => $pqrsdId,
                'clasificacion' => 'respuesta_caso',
                'revisado_por' => $usuario->id,
                'revisado_en' => now('America/Bogota'),
                'motivo_clasificacion' => 'Asociado manualmente a radicado existente',
                'estado_procesamiento' => 'procesado',
            ]);

            $this->asociarPendientesDelHilo(
                $mensaje,
                $caso,
                $usuario,
                'Asociado al expediente desde la revisión del hilo de Gmail'
            );
            ActuacionPqrsd::create([
                'pqrsd_id' => $pqrsdId,
                'usuario_id' => $usuario->id,
                'tipo' => 'correo_asociado',
                'titulo' => 'Correo asociado al caso',
                'detalle' => 'Mensaje '.$mensaje->id.' asociado al expediente',
                'es_interna' => true,
                'datos_adicionales' => [
                    'mensaje_correo_id' => $mensaje->id,
                    'gmail_message_id' => $mensaje->gmail_message_id,
                ],
            ]);

            $this->pqrsdService->auditar($caso, $usuario, 'correo_asociado', null, [
                'mensaje_correo_id' => $mensaje->id,
                'gmail_message_id' => $mensaje->gmail_message_id,
                'cuenta_correo_id' => $mensaje->cuenta_correo_id,
            ], 'mensaje_correo', $mensaje->id);

            return $mensaje->fresh(['pqrsd']);
        });
    }

    /**
     * Vincula los mensajes entrantes pendientes del mismo hilo de Gmail.
     * Nunca sobrescribe expedientes existentes ni cruza cuentas de correo.
     */
    private function asociarPendientesDelHilo(
        MensajeCorreo $mensaje,
        Pqrsd $caso,
        User $usuario,
        string $motivo
    ): void {
        $threadId = $mensaje->gmail_thread_id;
        $cuentaId = $mensaje->cuenta_correo_id;

        if (! $threadId || ! $cuentaId) {
            return;
        }

        $mensajes = MensajeCorreo::query()
            ->where('cuenta_correo_id', $cuentaId)
            ->where('gmail_thread_id', $threadId)
            ->lockForUpdate()
            ->get(['id', 'pqrsd_id', 'clasificacion', 'direccion']);

        // Si el hilo ya contiene asociaciones a varios expedientes,
        // no se propaga ninguna asociación automáticamente.
        $casosVinculados = $mensajes->pluck('pqrsd_id')
            ->filter()
            ->map(static fn ($id) => (int) $id)
            ->unique()
            ->values();

        if (
            $casosVinculados->count() !== 1
            || (int) $casosVinculados->first() !== (int) $caso->id
        ) {
            return;
        }

        $pendientesIds = $mensajes
            ->filter(static function (MensajeCorreo $hermano): bool {
                return $hermano->pqrsd_id === null
                    && $hermano->direccion === 'entrante'
                    && ! in_array(
                        $hermano->clasificacion,
                        ['ruido', 'descartado'],
                        true
                    );
            })
            ->pluck('id')
            ->all();

        if (empty($pendientesIds)) {
            return;
        }

        MensajeCorreo::query()
            ->whereIn('id', $pendientesIds)
            ->where('cuenta_correo_id', $cuentaId)
            ->where('gmail_thread_id', $threadId)
            ->where('direccion', 'entrante')
            ->whereNull('pqrsd_id')
            ->whereNotIn('clasificacion', ['ruido', 'descartado'])
            ->update([
                'pqrsd_id' => $caso->id,
                'clasificacion' => 'respuesta_caso',
                'radicado_detectado' => $caso->radicado,
                'revisado_por' => $usuario->id,
                'revisado_en' => now('America/Bogota'),
                'motivo_clasificacion' => $motivo,
                'estado_procesamiento' => 'procesado',
            ]);
    }
    public function descartar(MensajeCorreo $mensaje, User $usuario, string $motivo): MensajeCorreo
    {
        return DB::transaction(function () use ($mensaje, $usuario, $motivo) {
            $mensaje = MensajeCorreo::query()->lockForUpdate()->findOrFail($mensaje->id);
            if ($mensaje->pqrsd_id) {
                throw new \InvalidArgumentException('Este correo ya tiene expediente y no se descarta.');
            }

            $mensaje->update([
                'clasificacion' => 'descartado',
                'revisado_por' => $usuario->id,
                'revisado_en' => now('America/Bogota'),
                'motivo_clasificacion' => $motivo,
                'estado_procesamiento' => 'procesado',
            ]);

            $this->pqrsdService->auditar(null, $usuario, 'correo_descartado', null, [
                'mensaje_correo_id' => $mensaje->id,
                'clasificacion' => 'descartado',
            ], 'mensaje_correo', $mensaje->id);

            return $mensaje;
        });
    }
}
