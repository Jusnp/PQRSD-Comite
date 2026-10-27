<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class CheckProductionCommand extends Command
{
    protected $signature = 'pqrsd:check-production {--strict : Fallar si algún check rojo}';

    protected $description = 'Detecta configuraciones inseguras antes de habilitar producción';

    public function handle(): int
    {
        $isProd = config('app.env') === 'production';
        $checks = [];

        $checks[] = $this->check('APP_ENV=production', $isProd);
        $checks[] = $this->check('APP_DEBUG=false', config('app.debug') === false);
        $checks[] = $this->check('APP_KEY definido', filled(config('app.key')));
        $checks[] = $this->check('APP_URL https (prod)', ! str_starts_with((string) config('app.url'), 'http://') || ! $isProd);
        $checks[] = $this->check('MAIL_MAILER no es log', config('mail.default') !== 'log' || ! $isProd);
        $checks[] = $this->check(
            'PQRSD_MAIL_DRIVER no es mock en prod',
            config('pqrsd_correo.driver_default') !== 'mock' || ! $isProd
        );
        $checks[] = $this->check('QUEUE_CONNECTION no sync', config('queue.default') !== 'sync');
        $checks[] = $this->check('SESSION_ENCRYPT', (bool) config('session.encrypt') || ! $isProd);
        $checks[] = $this->check('SESSION_SECURE_COOKIE (prod)', (bool) config('session.secure') || ! $isProd);
        $checks[] = $this->check('storage/logs escribible', is_writable(storage_path('logs')));
        $checks[] = $this->check(
            'Disco adjuntos privado',
            config('filesystems.disks.local.root') === storage_path('app/private')
                || config('pqrsd_correo.adjuntos_disk') !== 'local'
        );
        $checks[] = $this->check(
            'Gmail OAuth o DWD preparado (prod)',
            ! $isProd
                || filled(config('pqrsd_correo.gmail.oauth_client_id'))
                || (bool) config('pqrsd_correo.gmail.service_account_enabled')
        );
        $checks[] = $this->check(
            'Lista blanca o piloto Gmail (prod)',
            ! $isProd
                || filled(config('pqrsd_correo.gmail.pilot_mailbox'))
                || ! empty(config('pqrsd_correo.gmail.allowed_mailboxes'))
        );
        $checks[] = $this->check(
            'Pub/Sub OIDC no omitido en prod',
            ! $isProd || ! (bool) config('pqrsd_correo.gmail.pubsub_skip_oidc')
        );
        $checks[] = $this->check(
            'Backup encrypt key si encrypt=on',
            ! config('pqrsd_backup.encryption_enabled') || filled(config('pqrsd_backup.encryption_key'))
        );
        $checks[] = $this->check(
            'Destino externo backup != null (prod)',
            ! $isProd || config('pqrsd_backup.external_driver') !== 'null'
        );

        $failed = collect($checks)->where('ok', false)->count();

        $this->table(['Check', 'OK'], collect($checks)->map(fn ($c) => [$c['name'], $c['ok'] ? 'sí' : 'NO'])->all());

        if ($failed > 0) {
            $this->error("Checks fallidos: {$failed}");
            if ($this->option('strict') || $isProd) {
                return self::FAILURE;
            }
        } else {
            $this->info('Configuración segura según checklist.');
        }

        return self::SUCCESS;
    }

    /**
     * @return array{name:string,ok:bool}
     */
    private function check(string $name, bool $ok): array
    {
        return compact('name', 'ok');
    }
}
