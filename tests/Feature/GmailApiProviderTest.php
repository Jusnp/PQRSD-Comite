<?php

namespace Tests\Feature;

use App\Mail\Gmail\FakeGmailApiClient;
use App\Mail\Gmail\GmailApiException;
use App\Mail\Gmail\GmailCredentialResolver;
use App\Mail\Providers\GoogleGmailMailProvider;
use App\Models\CuentaCorreo;
use App\Services\Correo\EmailSyncService;
use Database\Seeders\CatalogosSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class GmailApiProviderTest extends TestCase
{
    use RefreshDatabase;

    private function cuenta(array $extra = []): CuentaCorreo
    {
        return CuentaCorreo::create(array_merge([
            'direccion' => 'pqrsd@menteplena.test',
            'descripcion' => 'Gmail test',
            'activo' => true,
            'tipo_integracion' => 'google_api',
            'credential_ref' => 'MAIL_CRED_GMAIL_TEST',
            'cursor_sincronizacion' => '50',
            'config_extra' => ['delegated_user' => 'pqrsd@menteplena.test', 'pubsub_topic' => 'projects/x/topics/gmail'],
        ], $extra));
    }

    private function sampleMessage(string $id = 'm1'): array
    {
        return [
            'id' => $id,
            'threadId' => 't1',
            'internalDate' => (string) (now('America/Bogota')->getTimestamp() * 1000),
            'payload' => [
                'mimeType' => 'text/plain',
                'headers' => [
                    ['name' => 'From', 'value' => 'ciudadano@example.test'],
                    ['name' => 'To', 'value' => 'pqrsd@menteplena.test'],
                    ['name' => 'Subject', 'value' => 'Queja de prueba'],
                    ['name' => 'Message-ID', 'value' => '<'.$id.'@example.test>'],
                    ['name' => 'Date', 'value' => now('America/Bogota')->toRfc2822String()],
                ],
                'body' => ['data' => rtrim(strtr(base64_encode('Cuerpo de queja PQRSD'), '+/', '-_'), '=')],
            ],
        ];
    }

    public function test_recepcion_nueva_y_dedupe(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(CatalogosSeeder::class);
        putenv('MAIL_CRED_GMAIL_TEST=fake-token');
        $_ENV['MAIL_CRED_GMAIL_TEST'] = 'fake-token';

        $fake = new FakeGmailApiClient;
        $fake->historyMessages = [['id' => 'm1']];
        $fake->messagesById['m1'] = $this->sampleMessage('m1');

        $provider = new GoogleGmailMailProvider(new GmailCredentialResolver, $fake);
        $this->app->instance(GoogleGmailMailProvider::class, $provider);

        $cuenta = $this->cuenta();
        $sync = app(EmailSyncService::class);

        $r1 = $sync->syncCuenta($cuenta->fresh());
        $this->assertSame(1, $r1['nuevos']);
        $this->assertSame(0, $r1['errores']);
        $this->assertNotNull($cuenta->fresh()->cursor_sincronizacion);

        $fake->historyMessages = [['id' => 'm1']];
        $r2 = $sync->syncCuenta($cuenta->fresh());
        $this->assertSame(0, $r2['nuevos']);
        $this->assertSame(1, $r2['duplicados']);
    }

    public function test_falla_autenticacion(): void
    {
        $fake = new FakeGmailApiClient;
        $fake->authOk = false;
        $provider = new GoogleGmailMailProvider(new GmailCredentialResolver, $fake);
        $cuenta = $this->cuenta(['credential_ref' => 'MAIL_CRED_GMAIL_TEST']);
        putenv('MAIL_CRED_GMAIL_TEST=x');
        $_ENV['MAIL_CRED_GMAIL_TEST'] = 'x';

        $this->expectException(GmailApiException::class);
        $provider->fetchNew($cuenta, '10');
    }

    public function test_falla_temporal_gmail(): void
    {
        $fake = new FakeGmailApiClient;
        $fake->temporaryFailure = true;
        $provider = new GoogleGmailMailProvider(new GmailCredentialResolver, $fake);
        putenv('MAIL_CRED_GMAIL_TEST=x');
        $_ENV['MAIL_CRED_GMAIL_TEST'] = 'x';

        $this->expectException(GmailApiException::class);
        $provider->fetchNew($this->cuenta(), '10');
    }

    public function test_recuperacion_history_expirado(): void
    {
        $fake = new FakeGmailApiClient;
        $fake->historyExpired = true;
        $fake->recentMessages = [['id' => 'm2']];
        $fake->messagesById['m2'] = $this->sampleMessage('m2');
        $fake->profileHistoryId = '200';

        $provider = new GoogleGmailMailProvider(new GmailCredentialResolver, $fake);
        putenv('MAIL_CRED_GMAIL_TEST=x');
        $_ENV['MAIL_CRED_GMAIL_TEST'] = 'x';

        $msgs = $provider->fetchNew($this->cuenta(), '1');
        $this->assertCount(1, $msgs);
        $this->assertSame('200', $provider->nextSyncCursor());
    }

    public function test_error_y_confirmacion_envio(): void
    {
        $fake = new FakeGmailApiClient;
        $provider = new GoogleGmailMailProvider(new GmailCredentialResolver, $fake);
        putenv('MAIL_CRED_GMAIL_TEST=x');
        $_ENV['MAIL_CRED_GMAIL_TEST'] = 'x';
        $cuenta = $this->cuenta();

        $ok = $provider->send($cuenta, new \App\Mail\DTO\OutboundMessageData(
            to: 'ciudadano@example.test',
            subject: 'Respuesta',
            bodyText: 'Texto de respuesta institucional',
        ));
        $this->assertTrue($ok['ok']);
        $this->assertNotNull($ok['message_id']);

        $fake->sendOk = false;
        $fail = $provider->send($cuenta, new \App\Mail\DTO\OutboundMessageData(
            to: 'ciudadano@example.test',
            subject: 'Respuesta',
            bodyText: 'Texto',
        ));
        $this->assertFalse($fail['ok']);
    }

    public function test_no_avanza_cursor_si_sync_incompleta(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(CatalogosSeeder::class);
        putenv('MAIL_CRED_GMAIL_TEST=x');
        $_ENV['MAIL_CRED_GMAIL_TEST'] = 'x';

        $fake = new FakeGmailApiClient;
        $fake->historyMessages = [['id' => 'bad']];
        // getMessage fallará → excepción → errores
        $provider = new GoogleGmailMailProvider(new GmailCredentialResolver, $fake);
        $this->app->instance(GoogleGmailMailProvider::class, $provider);

        $cuenta = $this->cuenta(['cursor_sincronizacion' => '50']);
        $antes = $cuenta->cursor_sincronizacion;
        $r = app(EmailSyncService::class)->syncCuenta($cuenta->fresh());
        $this->assertGreaterThan(0, $r['errores']);
        $this->assertSame($antes, $cuenta->fresh()->cursor_sincronizacion);
    }
}
