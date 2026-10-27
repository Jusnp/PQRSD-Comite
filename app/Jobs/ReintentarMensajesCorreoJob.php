<?php

namespace App\Jobs;

use App\Mail\DTO\InboundMessageData;
use App\Models\MensajeCorreo;
use App\Services\Correo\EmailIngestionService;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

class ReintentarMensajesCorreoJob implements ShouldQueue
{
    use Queueable;

    public function handle(EmailIngestionService $ingestion): void
    {
        $max = config('pqrsd_correo.max_reintentos', 5);

        MensajeCorreo::query()
            ->where('estado_procesamiento', 'reintento')
            ->where(function ($q) {
                $q->whereNull('proximo_reintento')
                    ->orWhere('proximo_reintento', '<=', now('America/Bogota'));
            })
            ->where('intentos', '<', $max)
            ->limit(50)
            ->get()
            ->each(function (MensajeCorreo $mensaje) use ($ingestion, $max) {
                $raw = null;
                if ($mensaje->raw_eml_path && Storage::disk(config('pqrsd_correo.adjuntos_disk'))->exists($mensaje->raw_eml_path)) {
                    $raw = Storage::disk(config('pqrsd_correo.adjuntos_disk'))->get($mensaje->raw_eml_path);
                }

                $data = new InboundMessageData(
                    messageId: $mensaje->message_id.'-retry-'.$mensaje->intentos,
                    inReplyTo: $mensaje->in_reply_to,
                    references: $mensaje->references_header,
                    fromAddress: $mensaje->remitente,
                    fromName: $mensaje->remitente_nombre,
                    toAddress: $mensaje->destinatario,
                    cc: $mensaje->destinatarios_cc,
                    originalRecipient: $mensaje->destinatario_original,
                    subject: $mensaje->asunto,
                    bodyText: $mensaje->cuerpo_texto,
                    bodyHtml: $mensaje->cuerpo_html,
                    rawHeaders: $mensaje->raw_headers,
                    rawEml: $raw,
                    date: $mensaje->fecha_mensaje ?? now('America/Bogota'),
                    adjuntos: [],
                    metadatos: array_merge($mensaje->metadatos ?? [], ['retry_of' => $mensaje->id]),
                );

                // Reproceso in-place: recalifica sin crear duplicado si message_id cambia
                $mensaje->intentos++;
                $resultado = $ingestion->ingest($mensaje->cuenta, $data);

                if ($resultado['error']) {
                    $mensaje->update([
                        'intentos' => $mensaje->intentos,
                        'estado_procesamiento' => $mensaje->intentos >= $max ? 'error' : 'reintento',
                        'error_procesamiento' => $resultado['error'],
                        'proximo_reintento' => now('America/Bogota')
                            ->addMinutes(config('pqrsd_correo.reintento_minutos', 10)),
                    ]);
                } else {
                    $mensaje->update([
                        'estado_procesamiento' => 'procesado',
                        'error_procesamiento' => null,
                        'proximo_reintento' => null,
                    ]);
                }
            });
    }
}
