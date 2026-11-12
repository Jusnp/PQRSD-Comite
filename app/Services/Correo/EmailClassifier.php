<?php

namespace App\Services\Correo;

use App\Mail\DTO\InboundMessageData;
use App\Models\CuentaCorreo;
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
    public function classify(InboundMessageData $data, ?CuentaCorreo $cuenta = null): array
    {
        if ($this->esAvisoPropio($data)) {
            $radicado = $this->extraerRadicado(
                ($data->subject ?? '')."\n".($data->bodyText ?? '')."\n".($data->rawHeaders ?? '')
            );
            $caso = $radicado
                ? Pqrsd::query()->where('radicado', strtoupper($radicado))->first()
                : null;
            $permitido = $caso && $this->enlacePermitido($cuenta, $caso);

            return [
                'clasificacion' => $permitido ? 'respuesta_caso' : 'ruido',
                'pqrsd_id' => $permitido ? $caso->id : null,
                'radicado_detectado' => $radicado ? strtoupper($radicado) : null,
                'motivo' => $permitido
                    ? 'Aviso generado por PQRSD. No abre otro expediente.'
                    : 'Aviso generado por PQRSD. No se asoció a un expediente de otra organización.',
                'thread_key' => $this->threadKey($data),
            ];
        }

        $radicado = $this->extraerRadicado(
            ($data->subject ?? '')."\n".($data->bodyText ?? '')."\n".($data->bodyHtml ?? '')
        );

        // Gmail thread_id permite reconocer respuestas aunque no traigan
        // In-Reply-To o References.
        $porHiloGmail = $this->buscarPorHiloGmail($data, $cuenta, $radicado);

        if ($porHiloGmail !== null) {
            return $porHiloGmail;
        }
        $porHeaders = $this->buscarPorHeaders($data, $cuenta);
        if ($porHeaders && $this->enlacePermitido($cuenta, $porHeaders->pqrsd)) {
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
            if ($caso && $this->enlacePermitido($cuenta, $caso)) {
                return [
                    'clasificacion' => 'respuesta_caso',
                    'pqrsd_id' => $caso->id,
                    'radicado_detectado' => strtoupper($radicado),
                    'motivo' => 'Radicado detectado en asunto/cuerpo',
                    'thread_key' => $this->threadKey($data),
                ];
            }
            if ($caso) {
                return [
                    'clasificacion' => 'pendiente_revision',
                    'pqrsd_id' => null,
                    'radicado_detectado' => strtoupper($radicado),
                    'motivo' => 'El radicado pertenece a otra organización. No se asoció automáticamente.',
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

    private function enlacePermitido(?CuentaCorreo $cuenta, ?Pqrsd $caso): bool
    {
        if (! $cuenta || ! $caso) {
            return $caso !== null;
        }

        return app(SeleccionBuzonCorreo::class)->permiteAsociar($cuenta, $caso);
    }

    /**
     * Busca expedientes asociados al mismo hilo de Gmail.
     * La búsqueda siempre queda limitada al buzón de origen.
     */
    private function buscarPorHiloGmail(
        InboundMessageData $data,
        ?CuentaCorreo $cuenta,
        ?string $radicado
    ): ?array {
        if (! $cuenta) {
            return null;
        }

        $threadId = $data->metadatos['thread_id']
            ?? $data->metadatos['gmail_thread_id']
            ?? null;

        if (! is_scalar($threadId)) {
            return null;
        }

        $threadId = trim((string) $threadId);

        if ($threadId === '') {
            return null;
        }

        $casoIds = MensajeCorreo::query()
            ->where('cuenta_correo_id', $cuenta->id)
            ->where('gmail_thread_id', $threadId)
            ->whereNotNull('pqrsd_id')
            ->distinct()
            ->pluck('pqrsd_id')
            ->map(static fn ($id) => (int) $id)
            ->values();

        // No existe todavía un mensaje de ese hilo asociado.
        // Se permite continuar con la clasificación habitual.
        if ($casoIds->isEmpty()) {
            return null;
        }

        $pendiente = [
            'clasificacion' => 'pendiente_revision',
            'pqrsd_id' => null,
            'radicado_detectado' => $radicado,
            'motivo' => 'El hilo de Gmail tiene asociaciones múltiples o no permitidas; requiere revisión manual.',
            'thread_key' => $this->threadKey($data),
        ];

        // Un mismo hilo asociado a varios expedientes es ambiguo.
        if ($casoIds->count() !== 1) {
            return $pendiente;
        }

        $caso = Pqrsd::query()->find($casoIds->first());

        if (! $caso || ! $this->enlacePermitido($cuenta, $caso)) {
            return $pendiente;
        }

        return [
            'clasificacion' => 'respuesta_caso',
            'pqrsd_id' => $caso->id,
            'radicado_detectado' => $caso->radicado ?? $radicado,
            'motivo' => 'Asociado por Gmail thread_id al expediente existente.',
            'thread_key' => $this->threadKey($data),
        ];
    }
    private function buscarPorHeaders(InboundMessageData $data, ?CuentaCorreo $cuenta): ?MensajeCorreo
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
            ->when($cuenta, fn ($q) => $q->where('cuenta_correo_id', $cuenta->id))
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

    private function esAvisoPropio(InboundMessageData $data): bool
    {
        $cabeceras = strtolower(($data->rawHeaders ?? '').' '.json_encode($data->metadatos));

        return str_contains($cabeceras, 'x-pqrsd-origen: radicacion-web')
            || (($data->metadatos['origen'] ?? null) === 'radicacion-web');
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
