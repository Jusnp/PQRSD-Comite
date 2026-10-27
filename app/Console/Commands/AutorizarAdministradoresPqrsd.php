<?php

namespace App\Console\Commands;

use App\Services\Authentik\AuthentikProvisionService;
use Illuminate\Console\Command;

class AutorizarAdministradoresPqrsd extends Command
{
    protected $signature = 'pqrsd:autorizar-administradores';

    protected $description = 'Actualiza el administrador institucional y vincula akadmin por sub e issuer, sin tocar AdminSispam';

    public function handle(AuthentikProvisionService $provision): int
    {
        $base = rtrim((string) config('authentik.base_url'), '/');
        if ($base === '' || ! str_starts_with($base, 'https://')) {
            $this->error('El emisor Authentik no está configurado en HTTPS. No se vinculó ninguna cuenta.');

            return self::FAILURE;
        }

        $resultado = $provision->autorizarAdministradores();
        foreach ($resultado['mensajes'] as $mensaje) {
            $this->line($mensaje);
        }

        return $resultado['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
