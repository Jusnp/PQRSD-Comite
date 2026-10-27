<?php

namespace App\Services\Correo;

use App\Models\ActuacionPqrsd;
use App\Models\CanalIngreso;
use App\Models\MensajeCorreo;
use App\Models\User;
use App\Services\PqrsdService;
use Illuminate\Support\Facades\DB;

class EmailReviewService
{
    public function __construct(private readonly PqrsdService $pqrsdService) {}

    public function confirmarNuevaPqrsd(MensajeCorreo $mensaje, User $usuario, array $extra = []): MensajeCorreo
    {
        return DB::transaction(function () use ($mensaje, $usuario, $extra) {
            $canal = CanalIngreso::query()->where('codigo', 'CORREO')->firstOrFail();

            $caso = $this->pqrsdService->radicarManual([
                'fecha_recepcion' => $mensaje->fecha_mensaje,
                'canal_ingreso_id' => $canal->id,
                'nivel_riesgo' => $extra['nivel_riesgo'] ?? 'pendiente',
                'tipo_pqrsd_id' => $extra['tipo_pqrsd_id'] ?? null,
                'descripcion' => $extra['descripcion']
                    ?? trim(($mensaje->asunto ? $mensaje->asunto."\n\n" : '').($mensaje->cuerpo_texto ?? '')),
                'solicitante_nombre' => $extra['solicitante_nombre'] ?? $mensaje->remitente_nombre,
                'solicitante_email' => $extra['solicitante_email'] ?? $mensaje->remitente,
                'es_anonimo' => false,
                'sede_id' => $extra['sede_id'] ?? $mensaje->cuenta?->sede_id,
                'aseguradora_id' => $extra['aseguradora_id'] ?? $mensaje->cuenta?->aseguradora_id,
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

            ActuacionPqrsd::create([
                'pqrsd_id' => $caso->id,
                'usuario_id' => $usuario->id,
                'tipo' => 'correo_entrada',
                'titulo' => 'Origen correo',
                'detalle' => 'Importado desde '.$mensaje->cuenta->direccion.' — Message-ID '.$mensaje->message_id,
                'es_interna' => true,
                'datos_adicionales' => ['mensaje_correo_id' => $mensaje->id],
            ]);

            return $mensaje->fresh(['pqrsd', 'cuenta', 'adjuntos']);
        });
    }

    public function asociarACaso(MensajeCorreo $mensaje, int $pqrsdId, User $usuario): MensajeCorreo
    {
        $mensaje->update([
            'pqrsd_id' => $pqrsdId,
            'clasificacion' => 'respuesta_caso',
            'revisado_por' => $usuario->id,
            'revisado_en' => now('America/Bogota'),
            'motivo_clasificacion' => 'Asociado manualmente a radicado existente',
            'estado_procesamiento' => 'procesado',
        ]);

        ActuacionPqrsd::create([
            'pqrsd_id' => $pqrsdId,
            'usuario_id' => $usuario->id,
            'tipo' => 'correo_asociado',
            'titulo' => 'Correo asociado al caso',
            'detalle' => $mensaje->asunto,
            'es_interna' => true,
            'datos_adicionales' => ['mensaje_correo_id' => $mensaje->id],
        ]);

        return $mensaje->fresh(['pqrsd']);
    }

    public function descartar(MensajeCorreo $mensaje, User $usuario, string $motivo): MensajeCorreo
    {
        $mensaje->update([
            'clasificacion' => 'descartado',
            'revisado_por' => $usuario->id,
            'revisado_en' => now('America/Bogota'),
            'motivo_clasificacion' => $motivo,
            'estado_procesamiento' => 'procesado',
        ]);

        return $mensaje;
    }
}
