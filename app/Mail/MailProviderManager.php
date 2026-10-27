<?php

namespace App\Mail;

use App\Contracts\MailProvider;
use App\Mail\Providers\GoogleGmailMailProvider;
use App\Mail\Providers\ImapMailProvider;
use App\Mail\Providers\MicrosoftGraphMailProvider;
use App\Mail\Providers\MockMailProvider;
use App\Models\CuentaCorreo;
use InvalidArgumentException;

class MailProviderManager
{
    public function for(CuentaCorreo $cuenta): MailProvider
    {
        $tipo = $cuenta->tipo_integracion ?: config('pqrsd_correo.driver_default', 'mock');

        return match ($tipo) {
            'mock', 'central_forward' => app(MockMailProvider::class),
            'imap' => app(ImapMailProvider::class),
            'microsoft_graph' => app(MicrosoftGraphMailProvider::class),
            'google_api' => app(GoogleGmailMailProvider::class),
            default => throw new InvalidArgumentException("Proveedor de correo no soportado: {$tipo}"),
        };
    }
}
