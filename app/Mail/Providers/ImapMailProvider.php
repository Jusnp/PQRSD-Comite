<?php

namespace App\Mail\Providers;

use App\Contracts\MailProvider;
use App\Mail\DTO\InboundMessageData;
use App\Mail\DTO\OutboundMessageData;
use App\Models\CuentaCorreo;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Adaptador IMAP seguro. No se conecta a buzones reales en esta fase:
 * exige credential_ref y deja el cableado listo para cuando TI confirme
 * host/puerto/OAuth del proveedor institucional.
 */
class ImapMailProvider implements MailProvider
{
    public function driver(): string
    {
        return 'imap';
    }

    public function fetchNew(CuentaCorreo $cuenta, ?string $cursor = null): Collection
    {
        $this->assertConfigurado($cuenta);

        throw new RuntimeException(
            'IMAP real no habilitado. Configure el proveedor institucional y desactive el modo mock antes de sincronizar cuentas productivas.'
        );
    }

    public function testConnection(CuentaCorreo $cuenta): array
    {
        try {
            $this->assertConfigurado($cuenta);
        } catch (RuntimeException $e) {
            return ['ok' => false, 'detalle' => $e->getMessage()];
        }

        return [
            'ok' => false,
            'detalle' => 'Credencial referenciada encontrada, pero la conexión IMAP productiva está bloqueada hasta validación institucional.',
        ];
    }

    public function send(CuentaCorreo $cuenta, OutboundMessageData $mensaje): array
    {
        return [
            'ok' => false,
            'message_id' => null,
            'error' => 'Envío IMAP/SMTP real no habilitado. Use el driver mock en pruebas.',
        ];
    }

    private function assertConfigurado(CuentaCorreo $cuenta): void
    {
        if (blank($cuenta->credential_ref)) {
            throw new RuntimeException('La cuenta no tiene credential_ref. Defina la variable de entorno correspondiente.');
        }

        if (blank(env($cuenta->credential_ref))) {
            throw new RuntimeException(
                "La variable de entorno {$cuenta->credential_ref} no está definida. No se almacenan secretos en BD."
            );
        }

        if (blank($cuenta->host) || blank($cuenta->puerto)) {
            throw new RuntimeException('Faltan host/puerto IMAP. Pendiente confirmar proveedor (M365/Google/otro).');
        }
    }
}
