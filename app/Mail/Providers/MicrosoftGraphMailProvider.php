<?php

namespace App\Mail\Providers;

use App\Contracts\MailProvider;
use App\Mail\DTO\InboundMessageData;
use App\Mail\DTO\OutboundMessageData;
use App\Models\CuentaCorreo;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Stub OAuth / Microsoft Graph para cuando se confirme Exchange Online / M365.
 * credential_ref debe apuntar a un secreto de aplicación (client secret / cert),
 * nunca a una contraseña de usuario almacenada en BD.
 */
class MicrosoftGraphMailProvider implements MailProvider
{
    public function driver(): string
    {
        return 'microsoft_graph';
    }

    public function fetchNew(CuentaCorreo $cuenta, ?string $cursor = null): Collection
    {
        $this->assertConfigurado($cuenta);

        throw new RuntimeException('Microsoft Graph no habilitado hasta confirmar tenant y permisos Mail.Read.');
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
            'detalle' => 'OAuth listo a nivel de referencia; falta registrar la app en el tenant institucional.',
        ];
    }

    public function send(CuentaCorreo $cuenta, OutboundMessageData $mensaje): array
    {
        return [
            'ok' => false,
            'message_id' => null,
            'error' => 'Envío Graph no habilitado. Use mock en pruebas.',
        ];
    }

    private function assertConfigurado(CuentaCorreo $cuenta): void
    {
        if (blank($cuenta->credential_ref) || blank(env($cuenta->credential_ref))) {
            throw new RuntimeException(
                'Defina credential_ref hacia una variable de entorno con el secreto de aplicación (no password de buzón en BD).'
            );
        }
    }
}
