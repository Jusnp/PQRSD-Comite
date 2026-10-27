<?php

namespace App\Services\Correo;

use App\Mail\DTO\InboundMessageData;
use App\Models\MensajeCorreo;
use App\Models\Pqrsd;

class EmailClassifier
{
    /**
     * @return array{
     *   clasificacion:string,
     *   pqrsd_id:?int,
     *   radicado_detectado:?string,
     *   motivo:string,
     *   thread_key:?string
     * }
     */
    public function classify(InboundMessageData $data): array
    {
        $radicado = $this->extraerRadicado(
            ($data->subject ?? '')."\n".($data->bodyText ?? '')."\n".($data->bodyHtml ?? '')
        );

        $porHeaders = $this->buscarPorHeaders($data);
        if ($porHeaders) {
            return [
                'clasificacion' => 'respuesta_caso',
                'pqrsd_id' => $porHeaders->pqrsd_id,
                'radicado_detectado' => $porHeaders->radicado_detectado ?? $radicado,
                'motivo' => 'Asociado por In-Reply-To / References / Message-ID de hilo',
                'thread_key' => $porHeaders->thread_key ?? $this->threadKey($data),
            ];
        }

        if ($radicado) {
            $caso = Pqrsd::query()->where('radicado', strtoupper($radicado))->first();
            if ($caso) {
                return [
                    'clasificacion' => 'respuesta_caso',
                    'pqrsd_id' => $caso->id,
                    'radicado_detectado' => strtoupper($radicado),
                    'motivo' => 'Radicado detectado en asunto/cuerpo',
                    'thread_key' => $this->threadKey($data),
                ];
            }
        }

        if ($this->pareceRuido($data)) {
            return [
                'clasificacion' => 'ruido',
                'pqrsd_id' => null,
                'radicado_detectado' => $radicado,
                'motivo' => 'Patrones de notificación automática / comercial',
                'thread_key' => $this->threadKey($data),
            ];
        }

        if ($this->parecePqrsd($data)) {
            return [
                'clasificacion' => 'nueva_pqrsd',
                'pqrsd_id' => null,
                'radicado_detectado' => $radicado,
                'motivo' => 'Palabras clave de PQRSD; requiere confirmación humana antes de radicar',
                'thread_key' => $this->threadKey($data),
            ];
        }

        return [
            'clasificacion' => 'pendiente_revision',
            'pqrsd_id' => null,
            'radicado_detectado' => $radicado,
            'motivo' => 'Ambiguo: bandeja de clasificación humana',
            'thread_key' => $this->threadKey($data),
        ];
    }

    public function extraerRadicado(string $texto): ?string
    {
        $regex = config('pqrsd_correo.radicado_regex');
        if (preg_match($regex, $texto, $m)) {
            return strtoupper('PQRSD-'.$m[1].'-'.$m[2]);
        }

        return null;
    }

    public function threadKey(InboundMessageData $data): string
    {
        if ($data->inReplyTo) {
            return sha1(strtolower(trim($data->inReplyTo)));
        }

        if ($data->references) {
            $parts = preg_split('/\s+/', trim($data->references)) ?: [];
            $first = $parts[0] ?? $data->messageId;

            return sha1(strtolower(trim($first)));
        }

        return sha1(strtolower(trim($data->messageId)));
    }

    private function buscarPorHeaders(InboundMessageData $data): ?MensajeCorreo
    {
        $ids = collect();

        if ($data->inReplyTo) {
            $ids->push($this->normalizarId($data->inReplyTo));
        }

        if ($data->references) {
            foreach (preg_split('/\s+/', trim($data->references)) ?: [] as $ref) {
                $ids->push($this->normalizarId($ref));
            }
        }

        $ids = $ids->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return null;
        }

        return MensajeCorreo::query()
            ->whereNotNull('pqrsd_id')
            ->where(function ($q) use ($ids) {
                foreach ($ids as $id) {
                    $q->orWhere('message_id', $id)
                        ->orWhere('message_id', '<'.$id.'>')
                        ->orWhere('in_reply_to', $id)
                        ->orWhere('thread_key', sha1(strtolower($id)));
                }
            })
            ->latest('id')
            ->first();
    }

    private function normalizarId(string $id): string
    {
        return trim($id);
    }

    private function pareceRuido(InboundMessageData $data): bool
    {
        $texto = strtolower(($data->subject ?? '').' '.($data->fromAddress ?? '').' '.($data->bodyText ?? ''));

        foreach (config('pqrsd_correo.palabras_ruido', []) as $palabra) {
            if (str_contains($texto, strtolower($palabra))) {
                return true;
            }
        }

        return false;
    }

    private function parecePqrsd(InboundMessageData $data): bool
    {
        $texto = strtolower(($data->subject ?? '').' '.($data->bodyText ?? ''));

        foreach (config('pqrsd_correo.palabras_pqrsd', []) as $palabra) {
            if (str_contains($texto, strtolower($palabra))) {
                return true;
            }
        }

        return false;
    }
}
