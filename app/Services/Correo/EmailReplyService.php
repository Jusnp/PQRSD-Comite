<?php

namespace App\Services\Correo;

use App\Mail\DTO\OutboundMessageData;
use App\Mail\MailProviderManager;
use App\Models\ActuacionPqrsd;
use App\Models\MensajeCorreo;
use App\Models\User;
use App\Services\PqrsdService;
use Illuminate\Support\Facades\DB;

class EmailReplyService
{
    public function __construct(
        private readonly MailProviderManager $providers,
        private readonly PqrsdService $pqrsdService,
    ) {}

    /**
     * @return array{ok:bool,mensaje:?MensajeCorreo,error:?string}
     */
    public function responder(MensajeCorreo $original, string $cuerpo, User $usuario, ?string $asunto = null): array
    {
        if (! $original->pqrsd_id && $original->clasificacion !== 'respuesta_caso') {
            // Se permite responder desde un mensaje ya asociado o tras asociar
        }

        $cuenta = $original->cuenta;
        $provider = $this->providers->for($cuenta);

        $asuntoFinal = $asunto ?: $this->asuntoRespuesta($original);
        $references = trim(($original->references_header ? $original->references_header.' ' : '').($original->message_id ?? ''));

        $outbound = new OutboundMessageData(
            to: $original->remitente,
            subject: $asuntoFinal,
            bodyText: $cuerpo,
            inReplyTo: $original->message_id,
            references: $references ?: $original->message_id,
        );

        $envio = $provider->send($cuenta, $outbound);

        if (! $envio['ok']) {
            return ['ok' => false, 'mensaje' => null, 'error' => $envio['error'] ?? 'Fallo de envío'];
        }

        $saliente = DB::transaction(function () use ($original, $cuerpo, $asuntoFinal, $envio, $usuario, $references) {
            $saliente = MensajeCorreo::create([
                'cuenta_correo_id' => $original->cuenta_correo_id,
                'pqrsd_id' => $original->pqrsd_id,
                'message_id' => $envio['message_id'],
                'in_reply_to' => $original->message_id,
                'references_header' => $references,
                'thread_key' => $original->thread_key,
                'remitente' => $original->cuenta->direccion,
                'destinatario' => $original->remitente,
                'asunto' => $asuntoFinal,
                'cuerpo_texto' => $cuerpo,
                'fecha_mensaje' => now('America/Bogota'),
                'fecha_importacion' => now('America/Bogota'),
                'direccion' => 'saliente',
                'clasificacion' => 'respuesta_caso',
                'estado_procesamiento' => 'procesado',
                'radicado_detectado' => $original->radicado_detectado,
                'motivo_clasificacion' => 'Respuesta enviada desde la plataforma',
                'metadatos' => ['origen' => 'plataforma'],
            ]);

            if ($original->pqrsd_id) {
                ActuacionPqrsd::create([
                    'pqrsd_id' => $original->pqrsd_id,
                    'usuario_id' => $usuario->id,
                    'tipo' => 'correo_saliente',
                    'titulo' => 'Respuesta por correo',
                    'detalle' => 'Enviado a '.$original->remitente.' — '.$asuntoFinal,
                    'es_interna' => false,
                    'datos_adicionales' => [
                        'mensaje_correo_id' => $saliente->id,
                        'message_id' => $envio['message_id'],
                    ],
                ]);

                $this->pqrsdService->auditar(
                    $original->pqrsd,
                    $usuario,
                    'correo_responder',
                    null,
                    ['message_id' => $envio['message_id'], 'to' => $original->remitente]
                );
            }

            return $saliente;
        });

        return ['ok' => true, 'mensaje' => $saliente, 'error' => null];
    }

    private function asuntoRespuesta(MensajeCorreo $original): string
    {
        $asunto = $original->asunto ?: '(sin asunto)';
        if (! str_starts_with(strtolower($asunto), 're:')) {
            $asunto = 'Re: '.$asunto;
        }

        if ($original->radicado_detectado && ! str_contains($asunto, $original->radicado_detectado)) {
            $asunto .= ' ['.$original->radicado_detectado.']';
        } elseif ($original->pqrsd?->radicado && ! str_contains($asunto, $original->pqrsd->radicado)) {
            $asunto .= ' ['.$original->pqrsd->radicado.']';
        }

        return $asunto;
    }
}
