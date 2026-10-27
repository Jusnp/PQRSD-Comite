<?php

namespace App\Mail\Providers;

use App\Contracts\MailProvider;
use App\Mail\DTO\InboundMessageData;
use App\Mail\DTO\OutboundMessageData;
use App\Models\CuentaCorreo;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MockMailProvider implements MailProvider
{
    public function driver(): string
    {
        return 'mock';
    }

    public function fetchNew(CuentaCorreo $cuenta, ?string $cursor = null): Collection
    {
        $mensajes = collect($this->leerInbox())
            ->filter(function (array $item) use ($cuenta, $cursor) {
                $cuentaDir = strtolower($cuenta->direccion);
                $destino = strtolower((string) ($item['to'] ?? ''));
                $original = strtolower((string) ($item['original_recipient'] ?? ''));
                $account = strtolower((string) ($item['account'] ?? ''));

                $coincideCuenta = $destino === $cuentaDir
                    || $original === $cuentaDir
                    || $account === $cuentaDir
                    || ($cuenta->es_buzon_central && ($destino === $cuentaDir || $account === $cuentaDir));

                if (! $coincideCuenta) {
                    return false;
                }

                if ($cursor && isset($item['uid']) && (string) $item['uid'] <= (string) $cursor) {
                    return false;
                }

                return true;
            })
            ->map(fn (array $item) => $this->mapear($item))
            ->values();

        return $mensajes;
    }

    public function testConnection(CuentaCorreo $cuenta): array
    {
        if ($cuenta->tipo_integracion !== 'mock' && blank($cuenta->credential_ref)) {
            return ['ok' => false, 'detalle' => 'Cuenta mock lista; falta credential_ref para integración real.'];
        }

        return ['ok' => true, 'detalle' => 'Conexión simulada OK para '.$cuenta->direccion];
    }

    public function send(CuentaCorreo $cuenta, OutboundMessageData $mensaje): array
    {
        $messageId = '<mock-out-'.Str::uuid().'@menteplena.test>';

        $path = 'correo/mock_outbox/'.now()->format('YmdHis').'_'.Str::random(6).'.json';
        Storage::disk(config('pqrsd_correo.adjuntos_disk'))->put($path, json_encode([
            'from' => $cuenta->direccion,
            'to' => $mensaje->to,
            'subject' => $mensaje->subject,
            'body' => $mensaje->bodyText,
            'in_reply_to' => $mensaje->inReplyTo,
            'references' => $mensaje->references,
            'message_id' => $messageId,
            'sent_at' => now('America/Bogota')->toIso8601String(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return ['ok' => true, 'message_id' => $messageId, 'error' => null];
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function mapear(array $item): InboundMessageData
    {
        return new InboundMessageData(
            messageId: (string) ($item['message_id'] ?? ('<mock-'.Str::uuid().'@menteplena.test>')),
            inReplyTo: $item['in_reply_to'] ?? null,
            references: $item['references'] ?? null,
            fromAddress: (string) ($item['from'] ?? 'desconocido@example.test'),
            fromName: $item['from_name'] ?? null,
            toAddress: $item['to'] ?? null,
            cc: $item['cc'] ?? null,
            originalRecipient: $item['original_recipient'] ?? $item['to'] ?? null,
            subject: $item['subject'] ?? null,
            bodyText: $item['body_text'] ?? $item['body'] ?? null,
            bodyHtml: $item['body_html'] ?? null,
            rawHeaders: $item['raw_headers'] ?? null,
            rawEml: $item['raw_eml'] ?? null,
            date: Carbon::parse($item['date'] ?? now('America/Bogota'), 'America/Bogota'),
            adjuntos: $item['attachments'] ?? [],
            metadatos: [
                'uid' => $item['uid'] ?? null,
                'provider' => 'mock',
                'account' => $item['account'] ?? null,
            ],
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function leerInbox(): array
    {
        $path = config('pqrsd_correo.mock_inbox');

        if (! File::exists($path)) {
            return [];
        }

        $data = json_decode(File::get($path), true);

        return is_array($data) ? $data : [];
    }
}
