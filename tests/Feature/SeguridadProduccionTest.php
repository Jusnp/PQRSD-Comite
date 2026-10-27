<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class SeguridadProduccionTest extends TestCase
{
    public function test_check_production_detecta_debug_en_modo_strict_simulando_prod(): void
    {
        config([
            'app.env' => 'production',
            'app.debug' => true,
            'mail.default' => 'log',
            'pqrsd_correo.driver_default' => 'mock',
            'queue.default' => 'database',
            'session.encrypt' => false,
            'session.secure' => false,
        ]);

        $code = Artisan::call('pqrsd:check-production', ['--strict' => true]);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('APP_DEBUG=false', Artisan::output());
    }

    public function test_check_production_pasa_con_config_segura(): void
    {
        config([
            'app.env' => 'production',
            'app.debug' => false,
            'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'app.url' => 'https://pqrsd.menteplena.com.co',
            'mail.default' => 'smtp',
            'pqrsd_correo.driver_default' => 'google_api',
            'pqrsd_correo.gmail.oauth_client_id' => 'client-id',
            'pqrsd_correo.gmail.pilot_mailbox' => 'piloto@menteplena.com.co',
            'pqrsd_correo.gmail.pubsub_skip_oidc' => false,
            'pqrsd_backup.encryption_enabled' => true,
            'pqrsd_backup.encryption_key' => 'vault-key',
            'pqrsd_backup.external_driver' => 's3',
            'queue.default' => 'database',
            'session.encrypt' => true,
            'session.secure' => true,
        ]);

        $code = Artisan::call('pqrsd:check-production', ['--strict' => true]);
        $this->assertSame(0, $code);
    }
}
