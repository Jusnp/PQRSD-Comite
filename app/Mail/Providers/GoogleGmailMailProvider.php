<?php

namespace App\Mail\Providers;

use App\Contracts\MailProvider;
use App\Mail\DTO\InboundMessageData;
use App\Mail\DTO\OutboundMessageData;
use App\Mail\Gmail\GmailApiClient;
use App\Mail\Gmail\GmailApiException;
use App\Mail\Gmail\GmailCredentialResolver;
use App\Mail\Gmail\HttpGmailApiClient;
use App\Models\CuentaCorreo;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class GoogleGmailMailProvider implements MailProvider
{
    private ?string $nextSyncCursor = null;

    private bool $syncIncomplete = false;

    public function __construct(
        private readonly GmailCredentialResolver $credentials,
        private readonly ?GmailApiClient $client = null,
    ) {}

    public function driver(): string
    {
        return 'google_api';
    }

    public function nextSyncCursor(): ?string
    {
        return $this->nextSyncCursor;
    }

    public function syncIncomplete(): bool
    {
        return $this->syncIncomplete;
    }

    public function fetchNew(CuentaCorreo $cuenta, ?string $cursor = null): Collection
    {
        $this->nextSyncCursor = null;
        $this->syncIncomplete = false;
        $this->assertMailboxAllowed($cuenta);

        $client = $this->clientFor($cuenta);
        $userId = $this->credentials->delegatedUser($cuenta);

        try {
            if ($cursor) {
                try {
                    $batch = $client->listHistory($userId, $cursor);
                } catch (GmailApiException $e) {
                    if ($e->historyExpired) {
                        Log::channel('correo_sync')->warning('Gmail historyId expirado; recuperación deduplicada', [
                            'cuenta' => $cuenta->direccion,
                        ]);
                        if (! $cuenta->sincronizar_desde) {
                            $profile = $client->getProfile($userId);
                            $this->nextSyncCursor = ($profile['ok'] ?? false) ? ($profile['historyId'] ?? null) : null;

                            return collect();
                        }
                        $batch = $client->listRecentMessages($userId, $this->consultaDesde($cuenta));
                    } else {
                        throw $e;
                    }
                }
            } else {
                $profile = $client->getProfile($userId);
                if (! ($profile['ok'] ?? false)) {
                    throw GmailApiException::authFailed($profile['error'] ?? 'perfil no disponible');
                }
                $this->nextSyncCursor = $profile['historyId'] ?? null;
                if (! $cuenta->sincronizar_desde) {
                    return collect();
                }
                $batch = $client->listRecentMessages($userId, $this->consultaDesde($cuenta));
                $this->nextSyncCursor = $profile['historyId'] ?? $batch['historyId'] ?? $this->nextSyncCursor;
            }

            $this->assertListaCompleta($batch);

            $mensajes = collect();
            foreach ($batch['messages'] as $ref) {
                $id = $ref['id'] ?? null;
                if (! $id) {
                    continue;
                }
                $raw = $client->getMessage($userId, $id, 'full');
                if (! $this->dentroDelInicio($raw, $cuenta)) {
                    continue;
                }
                if (! $this->debeImportar($raw)) {
                    continue;
                }
                $mensajes->push($this->mapGmailMessage($raw, $cuenta));
            }

            if ($mensajes->isNotEmpty() || $cursor) {
                $this->nextSyncCursor = $batch['historyId'] ?? $this->nextSyncCursor;
            }

            return $mensajes->values();
        } catch (GmailApiException $e) {
            $this->syncIncomplete = true;
            $this->nextSyncCursor = null;
            Log::channel('correo_sync')->warning('Gmail fetchNew falló', [
                'cuenta' => $cuenta->direccion,
                'status' => $e->statusCode,
                // sin token ni cuerpo
            ]);
            throw $e;
        }
    }

    public function testConnection(CuentaCorreo $cuenta): array
    {
        try {
            $client = $this->clientFor($cuenta);
            $userId = $this->credentials->delegatedUser($cuenta);
            $profile = $client->getProfile($userId);
            if (! ($profile['ok'] ?? false)) {
                return ['ok' => false, 'detalle' => $profile['error'] ?? 'sin perfil'];
            }

            return [
                'ok' => true,
                'detalle' => 'Gmail API OK para '.($profile['email'] ?? $userId).' historyId='.($profile['historyId'] ?? 'n/a'),
            ];
        } catch (GmailApiException $e) {
            return ['ok' => false, 'detalle' => $e->getMessage()];
        }
    }

    public function send(CuentaCorreo $cuenta, OutboundMessageData $mensaje): array
    {
        try {
            $this->assertMailboxAllowed($cuenta);
            $client = $this->clientFor($cuenta);
            $userId = $this->credentials->delegatedUser($cuenta);
            $raw = $this->buildRfc822($cuenta, $mensaje);
            $result = $client->sendRaw($userId, $raw, $mensaje->gmailThreadId);

            if (! ($result['ok'] ?? false)) {
                return [
                    'ok' => false,
                    'message_id' => null,
                    'error' => $result['error'] ?? 'envío fallido',
                ];
            }

            return [
                'ok' => true,
                'message_id' => $mensaje->rfcMessageId ?: ($result['id'] ? '<'.$result['id'].'@gmail.googleapis.com>' : null),
                'gmail_message_id' => $result['id'] ?? null,
                'gmail_thread_id' => $result['threadId'] ?? null,
                'error' => null,
            ];
        } catch (GmailApiException $e) {
            return [
                'ok' => false,
                'message_id' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Coloca el aviso en INBOX. messages.send a la misma cuenta no lo garantiza.
     *
     * @param  array<int, string>  $labelIds
     * @return array{ok:bool,id:?string,threadId:?string,error:?string}
     */
    public function insertarEnBandeja(CuentaCorreo $cuenta, string $rawRfc822, array $labelIds = ['INBOX', 'UNREAD']): array
    {
        try {
            $this->assertMailboxAllowed($cuenta);
            $client = $this->clientFor($cuenta);
            $userId = $this->credentials->delegatedUser($cuenta);
            if (! in_array('INBOX', $labelIds, true)) {
                array_unshift($labelIds, 'INBOX');
            }

            return $client->insertRaw($userId, $rawRfc822, $labelIds);
        } catch (GmailApiException $e) {
            return [
                'ok' => false,
                'id' => null,
                'threadId' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function renewWatch(CuentaCorreo $cuenta): array
    {
        $topic = $cuenta->config_extra['pubsub_topic'] ?? config('pqrsd_correo.gmail.pubsub_topic');
        if (blank($topic)) {
            return ['ok' => false, 'error' => 'Falta pubsub_topic (config_extra o GMAIL_PUBSUB_TOPIC)'];
        }

        try {
            $client = $this->clientFor($cuenta);
            $userId = $this->credentials->delegatedUser($cuenta);
            $result = $client->watch($userId, (string) $topic);

            if ($result['ok'] && ! empty($result['historyId'])) {
                $extra = $cuenta->config_extra ?? [];
                $extra['watch_expiration'] = $result['expiration'] ?? null;
                $extra['watch_renewed_at'] = now('America/Bogota')->toIso8601String();
                $expira = null;
                if (! empty($result['expiration']) && is_numeric($result['expiration'])) {
                    // Gmail watch expiration es epoch ms
                    $expira = Carbon::createFromTimestampMs((int) $result['expiration'], 'America/Bogota');
                }
                $cuenta->update([
                    'config_extra' => $extra,
                    'cursor_sincronizacion' => $cuenta->cursor_sincronizacion ?: $result['historyId'],
                    'ultimo_history_id' => $result['historyId'],
                    'watch_expira_en' => $expira,
                ]);
            }

            return $result;
        } catch (GmailApiException $e) {
            return ['ok' => false, 'historyId' => null, 'expiration' => null, 'error' => $e->getMessage()];
        }
    }

    private function clientFor(CuentaCorreo $cuenta): GmailApiClient
    {
        if ($this->client) {
            return $this->client;
        }

        $token = $this->credentials->accessToken($cuenta);

        return new HttpGmailApiClient($token);
    }

    private function consultaDesde(CuentaCorreo $cuenta): string
    {
        $dia = $cuenta->sincronizar_desde
            ->copy()
            ->timezone('America/Bogota')
            ->subDay()
            ->format('Y/m/d');

        return 'after:'.$dia.' -in:spam -in:trash -in:drafts -in:chats';
    }

    /**
     * @param  array<string, mixed>  $batch
     */
    private function assertListaCompleta(array $batch): void
    {
        if (filled($batch['nextPageToken'] ?? null)) {
            throw GmailApiException::temporary('Gmail devolvió páginas sin terminar. El cursor no avanza.');
        }
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function debeImportar(array $raw): bool
    {
        $etiquetas = array_map(fn ($etiqueta) => strtoupper((string) $etiqueta), $raw['labelIds'] ?? []);
        foreach (['SPAM', 'TRASH', 'DRAFT', 'CHAT'] as $excluida) {
            if (in_array($excluida, $etiquetas, true)) {
                return false;
            }
        }

        return ! (in_array('SENT', $etiquetas, true) && ! in_array('INBOX', $etiquetas, true));
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function dentroDelInicio(array $raw, CuentaCorreo $cuenta): bool
    {
        if (! $cuenta->sincronizar_desde) {
            return true;
        }

        $ms = (int) ($raw['internalDate'] ?? 0);
        if ($ms <= 0) {
            return false;
        }

        return Carbon::createFromTimestampMs($ms, 'America/Bogota')
            ->greaterThanOrEqualTo($cuenta->sincronizar_desde);
    }

    private function assertMailboxAllowed(CuentaCorreo $cuenta): void
    {
        $pilot = config('pqrsd_correo.gmail.pilot_mailbox');
        if (filled($pilot) && strtolower($cuenta->direccion) !== strtolower((string) $pilot)) {
            throw new GmailApiException('Buzón fuera del piloto configurado.');
        }

        $allowed = config('pqrsd_correo.gmail.allowed_mailboxes', []);
        if ($allowed === [] || $allowed === null) {
            if (app()->environment('production')) {
                throw new GmailApiException('GMAIL_ALLOWED_MAILBOXES está vacío');
            }

            return;
        }

        $dir = strtolower($cuenta->direccion);
        $ok = collect($allowed)->map(fn ($e) => strtolower(trim((string) $e)))->contains($dir);
        if (! $ok) {
            throw new GmailApiException('Buzón no autorizado: '.$cuenta->direccion);
        }
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function mapGmailMessage(array $raw, CuentaCorreo $cuenta): InboundMessageData
    {
        $headers = collect($raw['payload']['headers'] ?? [])
            ->mapWithKeys(fn ($h) => [strtolower($h['name'] ?? '') => $h['value'] ?? '']);

        $bodyText = $this->extractBody($raw['payload'] ?? [], 'text/plain');
        $bodyHtml = $this->extractBody($raw['payload'] ?? [], 'text/html');
        $adjuntos = $this->extractAttachments($raw['payload'] ?? []);

        $dateHeader = $headers->get('date');
        $date = $dateHeader
            ? Carbon::parse($dateHeader)->timezone('America/Bogota')
            : Carbon::createFromTimestampMs((int) ($raw['internalDate'] ?? now()->getTimestampMs()), 'America/Bogota');

        return new InboundMessageData(
            messageId: (string) ($headers->get('message-id') ?: ('<gmail-'.$raw['id'].'@gmail.googleapis.com>')),
            inReplyTo: $headers->get('in-reply-to'),
            references: $headers->get('references'),
            fromAddress: $this->parseAddress($headers->get('from', 'desconocido@example.test')),
            fromName: null,
            toAddress: $headers->get('to') ?: $cuenta->direccion,
            cc: $headers->get('cc'),
            originalRecipient: $cuenta->direccion,
            subject: $headers->get('subject'),
            bodyText: $bodyText,
            bodyHtml: $bodyHtml,
            rawHeaders: $headers->map(fn ($v, $k) => "$k: $v")->implode("\n"),
            rawEml: null,
            date: $date,
            adjuntos: $adjuntos,
            metadatos: [
                'uid' => $raw['id'] ?? null,
                'thread_id' => $raw['threadId'] ?? null,
                'provider' => 'google_api',
                'history_id' => $raw['historyId'] ?? null,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractBody(array $payload, string $mime): ?string
    {
        if (($payload['mimeType'] ?? '') === $mime && ! empty($payload['body']['data'])) {
            return $this->decodeBody($payload['body']['data']);
        }

        foreach ($payload['parts'] ?? [] as $part) {
            $found = $this->extractBody($part, $mime);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, array{nombre:string,contenido:string,mime:?string}>
     */
    private function extractAttachments(array $payload): array
    {
        $out = [];
        $filename = $payload['filename'] ?? null;
        if ($filename && ! empty($payload['body']['data'])) {
            $out[] = [
                'nombre' => $filename,
                'contenido' => $this->decodeBody($payload['body']['data']),
                'mime' => $payload['mimeType'] ?? null,
            ];
        }
        foreach ($payload['parts'] ?? [] as $part) {
            $out = array_merge($out, $this->extractAttachments($part));
        }

        return $out;
    }

    private function decodeBody(string $data): string
    {
        $raw = strtr($data, '-_', '+/');

        return (string) base64_decode($raw, true);
    }

    private function parseAddress(string $from): string
    {
        if (preg_match('/<([^>]+)>/', $from, $m)) {
            return strtolower($m[1]);
        }

        return strtolower(trim($from));
    }

    private function buildRfc822(CuentaCorreo $cuenta, OutboundMessageData $mensaje): string
    {
        $headers = [
            'From: '.($cuenta->direccion_visible ?: $cuenta->direccion),
            'To: '.$mensaje->to,
            'Subject: '.$mensaje->subject,
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
        ];
        if ($mensaje->rfcMessageId) {
            $headers[] = 'Message-ID: '.$mensaje->rfcMessageId;
        }
        if ($mensaje->inReplyTo) {
            $headers[] = 'In-Reply-To: '.$mensaje->inReplyTo;
        }
        if ($mensaje->references) {
            $headers[] = 'References: '.$mensaje->references;
        }

        return implode("\r\n", $headers)."\r\n\r\n".($mensaje->bodyText ?? '');
    }
}
