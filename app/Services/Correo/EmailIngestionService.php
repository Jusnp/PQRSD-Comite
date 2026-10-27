<?php

namespace App\Services\Correo;

use App\Mail\DTO\InboundMessageData;
use App\Models\AdjuntoCorreo;
use App\Models\CuentaCorreo;
use App\Models\MensajeCorreo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class EmailIngestionService
{
    public function __construct(private readonly EmailClassifier $classifier) {}

    /**
     * @return array{mensaje:?MensajeCorreo,duplicado:bool,error:?string}
     */
    public function ingest(CuentaCorreo $cuenta, InboundMessageData $data): array
    {
        try {
            return DB::transaction(function () use ($cuenta, $data) {
                $messageId = trim($data->messageId);

                $existente = MensajeCorreo::query()
                    ->where('cuenta_correo_id', $cuenta->id)
                    ->where('message_id', $messageId)
                    ->first();

                if ($existente) {
                    return ['mensaje' => $existente, 'duplicado' => true, 'error' => null];
                }

                // Duplicado global por Message-ID (mismo correo llegado a varias cuentas / central)
                $global = MensajeCorreo::query()->where('message_id', $messageId)->first();
                if ($global) {
                    $copia = $global->replicate([
                        'cuenta_correo_id',
                        'estado_procesamiento',
                        'clasificacion',
                    ]);
                    $copia->cuenta_correo_id = $cuenta->id;
                    $copia->estado_procesamiento = 'ignorado_duplicado';
                    $copia->clasificacion = $global->clasificacion;
                    $copia->motivo_clasificacion = 'Duplicado por Message-ID ya importado en otra cuenta';
                    $copia->pqrsd_id = $global->pqrsd_id;
                    $copia->save();

                    return ['mensaje' => $copia, 'duplicado' => true, 'error' => null];
                }

                $clasificacion = $this->classifier->classify($data);

                $rawPath = null;
                if ($data->rawEml) {
                    $rawPath = config('pqrsd_correo.raw_path').'/'.$cuenta->id.'/'.Str::uuid().'.eml';
                    Storage::disk(config('pqrsd_correo.adjuntos_disk'))->put($rawPath, $data->rawEml);
                }

                $mensaje = MensajeCorreo::create([
                    'cuenta_correo_id' => $cuenta->id,
                    'pqrsd_id' => $clasificacion['pqrsd_id'],
                    'message_id' => $messageId,
                    'in_reply_to' => $data->inReplyTo,
                    'references_header' => $data->references,
                    'thread_key' => $clasificacion['thread_key'],
                    'remitente' => $data->fromAddress,
                    'remitente_nombre' => $data->fromName,
                    'destinatario' => $data->toAddress,
                    'destinatarios_cc' => $data->cc,
                    'destinatario_original' => $data->originalRecipient,
                    'asunto' => $data->subject,
                    'cuerpo_texto' => $data->bodyText,
                    'cuerpo_html' => $data->bodyHtml,
                    'raw_headers' => $data->rawHeaders,
                    'raw_eml_path' => $rawPath,
                    'fecha_mensaje' => $data->date,
                    'fecha_importacion' => now('America/Bogota'),
                    'direccion' => 'entrante',
                    'clasificacion' => in_array($clasificacion['clasificacion'], ['nueva_pqrsd', 'ruido'], true)
                        ? ($clasificacion['clasificacion'] === 'ruido' ? 'ruido' : 'pendiente_revision')
                        : $clasificacion['clasificacion'],
                    'estado_procesamiento' => 'procesado',
                    'radicado_detectado' => $clasificacion['radicado_detectado'],
                    'motivo_clasificacion' => $clasificacion['motivo']
                        .($clasificacion['clasificacion'] === 'nueva_pqrsd'
                            ? ' (sugerido como nueva PQRSD; pendiente confirmación)'
                            : ''),
                    'metadatos' => $data->metadatos,
                ]);

                // Sugerencias de nueva PQRSD o ruido ambivalente van a revisión humana
                if ($clasificacion['clasificacion'] === 'nueva_pqrsd') {
                    $mensaje->update([
                        'clasificacion' => 'pendiente_revision',
                        'motivo_clasificacion' => $clasificacion['motivo'].' — confirmación humana requerida',
                    ]);
                }

                foreach ($data->adjuntos as $adjunto) {
                    $contenido = $adjunto['contenido'] ?? '';
                    $ruta = config('pqrsd_correo.adjuntos_path').'/'.$mensaje->id.'/'.Str::uuid().'_'.($adjunto['nombre'] ?? 'archivo');
                    Storage::disk(config('pqrsd_correo.adjuntos_disk'))->put($ruta, $contenido);

                    AdjuntoCorreo::create([
                        'mensaje_correo_id' => $mensaje->id,
                        'nombre_original' => $adjunto['nombre'] ?? 'adjunto',
                        'ruta' => $ruta,
                        'mime' => $adjunto['mime'] ?? null,
                        'tamano' => strlen($contenido),
                        'checksum' => hash('sha256', $contenido),
                    ]);
                }

                return ['mensaje' => $mensaje->fresh(['adjuntos']), 'duplicado' => false, 'error' => null];
            });
        } catch (\Throwable $e) {
            return ['mensaje' => null, 'duplicado' => false, 'error' => $e->getMessage()];
        }
    }
}
