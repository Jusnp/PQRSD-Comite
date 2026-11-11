<?php

namespace App\Services\Correo;

use App\Mail\DTO\OutboundMessageData;
use App\Mail\MailProviderManager;
use App\Models\ActuacionPqrsd;
use App\Models\CorreoSalida;
use App\Models\CuentaCorreo;
use App\Models\MensajeCorreo;
use App\Models\Pqrsd;
use App\Models\User;
use App\Services\PqrsdService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EmailReplyService
{
    public function __construct(
        private readonly MailProviderManager $providers,
        private readonly PqrsdService $pqrsdService,
        private readonly SeleccionBuzonCorreo $buzones,
    ) {}

    /**
     * @return array{ok:bool,mensaje:?MensajeCorreo,error:?string}
     */
    public function responder(MensajeCorreo $original, string $cuerpo, User $usuario, ?string $asunto = null): array
    {
        $cuenta = $original->cuenta;
        if (! $cuenta) {
            return ['ok' => false, 'mensaje' => null, 'error' => 'El mensaje no tiene buzón.'];
        }
        if ($original->pqrsd && ! $this->buzones->permiteAsociar($cuenta, $original->pqrsd)) {
            return ['ok' => false, 'mensaje' => null, 'error' => 'Ese buzón no puede responder un expediente de otra organización.'];
        }

        return $this->enviar(
            $cuenta,
            $original->pqrsd,
            $original->remitente,
            $cuerpo,
            $usuario,
            $original,
            $asunto ?: $this->asuntoRespuesta($original)
        );
    }

    /**
     * @return array{ok:bool,mensaje:?MensajeCorreo,error:?string}
     */
    public function responderDesdeExpediente(Pqrsd $caso, string $cuerpo, User $usuario, string $destinatario): array
    {
        $destinatario = strtolower(trim($destinatario));
        if (! filter_var($destinatario, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'mensaje' => null, 'error' => 'El destinatario no es un correo válido.'];
        }

        $original = MensajeCorreo::query()
            ->with('cuenta')
            ->where('pqrsd_id', $caso->id)
            ->where('direccion', 'entrante')
            ->whereRaw('LOWER(remitente) = ?', [$destinatario])
            ->latest('fecha_mensaje')
            ->get()
            ->first(fn (MensajeCorreo $mensaje) => $mensaje->cuenta && $this->buzones->permiteAsociar($mensaje->cuenta, $caso));

        if ($original) {
            return $this->responder($original, $cuerpo, $usuario);
        }

        $seleccion = $this->buzones->paraRadicacionWeb($caso);
        if (! $seleccion['cuenta']) {
            return ['ok' => false, 'mensaje' => null, 'error' => $seleccion['motivo'] ?? 'No hay buzón para esta respuesta.'];
        }

        $asunto = 'PQRSD '.$caso->radicado;

        return $this->enviar($seleccion['cuenta'], $caso, $destinatario, $cuerpo, $usuario, null, $asunto);
    }

    /**
     * @return array{ok:bool,mensaje:?MensajeCorreo,error:?string}
     */
    private function enviar(
        CuentaCorreo $cuenta,
        ?Pqrsd $caso,
        string $destinatario,
        string $cuerpo,
        User $usuario,
        ?MensajeCorreo $original,
        string $asunto,
    ): array {
        if (! $cuenta->activo) {
            return [
                'ok' => false,
                'mensaje' => null,
                'error' => 'El buzón está inactivo. La autorización no habilita el envío.',
            ];
        }

        $huella = hash('sha256', trim($cuerpo));
        $clave = 'respuesta:'.$cuenta->id.':'.hash('sha256', ($original->message_id ?? 'nuevo').'|'.$destinatario.'|'.$huella);
        $rfcId = '<pqrsd-resp-'.substr(hash('sha256', $clave), 0, 24).'@pqrsd.local>';

        $reserva = $this->reservar($clave, $cuenta, $caso, $rfcId, $destinatario, $asunto);
        if (! $reserva['enviar']) {
            return [
                'ok' => false,
                'mensaje' => $reserva['mensaje'],
                'error' => $reserva['error'],
            ];
        }

        $references = $original
            ? trim(($original->references_header ? $original->references_header.' ' : '').($original->message_id ?? ''))
            : null;

        $provider = $this->providers->for($cuenta);
        $envio = $provider->send($cuenta, new OutboundMessageData(
            to: $destinatario,
            subject: $asunto,
            bodyText: $cuerpo,
            inReplyTo: $original?->message_id,
            references: $references ?: $original?->message_id,
            rfcMessageId: $rfcId,
            gmailThreadId: $original?->gmail_thread_id,
        ));

        if (! ($envio['ok'] ?? false)) {
            $error = (string) ($envio['error'] ?? 'Fallo de envío');
            $autenticacion = str_contains(strtolower($error), 'autentic') || str_contains($error, '401');
            $reserva['salida']->update([
                'estado' => $autenticacion ? 'pendiente_conexion' : 'requiere_revision',
                'proveedor_invocado' => ! $autenticacion,
                'intentos' => $reserva['salida']->intentos + 1,
                'ultimo_error' => $error,
            ]);

            return ['ok' => false, 'mensaje' => null, 'error' => $error];
        }

        $saliente = DB::transaction(function () use ($cuenta, $caso, $destinatario, $cuerpo, $usuario, $original, $asunto, $references, $huella, $rfcId, $envio, $reserva) {
            $reserva['salida']->update([
                'estado' => 'enviado',
                'rfc_message_id' => $rfcId,
                'gmail_message_id' => $envio['gmail_message_id'] ?? null,
                'gmail_thread_id' => $envio['gmail_thread_id'] ?? null,
                'enviado_en' => now('America/Bogota'),
                'ultimo_error' => null,
            ]);

            $saliente = MensajeCorreo::create([
                'cuenta_correo_id' => $cuenta->id,
                'pqrsd_id' => $caso?->id,
                'message_id' => $rfcId,
                'gmail_message_id' => $envio['gmail_message_id'] ?? null,
                'gmail_thread_id' => $envio['gmail_thread_id'] ?? $original?->gmail_thread_id,
                'in_reply_to' => $original?->message_id,
                'references_header' => $references,
                'thread_key' => $original?->thread_key ?? sha1(strtolower($rfcId)),
                'remitente' => $cuenta->direccion_visible ?: $cuenta->direccion,
                'destinatario' => $destinatario,
                'asunto' => $asunto,
                'cuerpo_texto' => $cuerpo,
                'fecha_mensaje' => now('America/Bogota'),
                'fecha_importacion' => now('America/Bogota'),
                'direccion' => 'saliente',
                'clasificacion' => 'respuesta_caso',
                'estado_procesamiento' => 'procesado',
                'radicado_detectado' => $caso?->radicado ?? $original?->radicado_detectado,
                'motivo_clasificacion' => 'Respuesta enviada desde la plataforma',
                'metadatos' => [
                    'origen' => 'plataforma',
                    'huella_cuerpo' => $huella,
                    'correo_salida_id' => $reserva['salida']->id,
                ],
            ]);

            if ($caso) {
                ActuacionPqrsd::create([
                    'pqrsd_id' => $caso->id,
                    'usuario_id' => $usuario->id,
                    'tipo' => 'correo_saliente',
                    'titulo' => 'Respuesta por correo',
                    'detalle' => 'Enviado a '.$destinatario.' — '.$asunto,
                    'es_interna' => false,
                    'datos_adicionales' => [
                        'mensaje_correo_id' => $saliente->id,
                        'message_id' => $rfcId,
                        'gmail_message_id' => $envio['gmail_message_id'] ?? null,
                        'cuenta_correo_id' => $cuenta->id,
                    ],
                ]);

                $this->pqrsdService->auditar(
                    $caso,
                    $usuario,
                    'correo_responder',
                    null,
                    ['message_id' => $rfcId, 'to' => $destinatario, 'cuenta_correo_id' => $cuenta->id]
                );
            }

            return $saliente;
        });

        return ['ok' => true, 'mensaje' => $saliente, 'error' => null];
    }

    /**
     * @return array{enviar:bool,salida:CorreoSalida,mensaje:?MensajeCorreo,error:?string}
     */
    private function reservar(string $clave, CuentaCorreo $cuenta, ?Pqrsd $caso, string $rfcId, string $destinatario, string $asunto): array
    {
        if (! Schema::hasTable('correo_salidas')) {
            return [
                'enviar' => false,
                'salida' => new CorreoSalida,
                'mensaje' => null,
                'error' => 'La cola de salida todavía no está disponible.',
            ];
        }

        return DB::transaction(function () use ($clave, $cuenta, $caso, $rfcId, $destinatario, $asunto) {
            $salida = CorreoSalida::query()->where('idempotency_key', $clave)->lockForUpdate()->first();
            if (! $salida) {
                $salida = CorreoSalida::query()->create([
                    'pqrsd_id' => $caso?->id,
                    'cuenta_correo_id' => $cuenta->id,
                    'tipo' => 'respuesta',
                    'estado' => 'enviando',
                    'idempotency_key' => $clave,
                    'payload' => [
                        'destinatario' => $destinatario,
                        'asunto' => $asunto,
                    ],
                    'rfc_message_id' => $rfcId,
                    'proveedor_invocado' => true,
                ]);

                return ['enviar' => true, 'salida' => $salida, 'mensaje' => null, 'error' => null];
            }

            if ($salida->estado === 'enviado' || filled($salida->gmail_message_id)) {
                return [
                    'enviar' => false,
                    'salida' => $salida,
                    'mensaje' => MensajeCorreo::query()->where('message_id', $salida->rfc_message_id)->first(),
                    'error' => 'Esta respuesta ya fue enviada. No se envió otra vez.',
                ];
            }

            return [
                'enviar' => false,
                'salida' => $salida,
                'mensaje' => null,
                'error' => 'Esa respuesta quedó pendiente de revisión para no duplicar el envío.',
            ];
        });
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
