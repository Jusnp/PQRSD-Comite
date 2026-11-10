<?php

namespace Tests\Feature;

use App\Mail\DTO\OutboundMessageData;
use App\Mail\Gmail\FakeGmailApiClient;
use App\Mail\Gmail\GmailApiException;
use App\Mail\Gmail\GmailCredentialResolver;
use App\Mail\Providers\GoogleGmailMailProvider;
use App\Models\CuentaCorreo;
use App\Models\MensajeCorreo;
use App\Models\Pqrsd;
use App\Services\Correo\EmailSyncService;
use Database\Seeders\CatalogosSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    private function sampleMessage(string $id = 'm1', array $headers = [], array $labels = []): array
    {
        $cabeceras = [
            ['name' => 'From', 'value' => 'ciudadano@example.test'],
            ['name' => 'To', 'value' => 'pqrsd@menteplena.test'],
            ['name' => 'Subject', 'value' => 'Queja de prueba'],
            ['name' => 'Message-ID', 'value' => '<'.$id.'@example.test>'],
            ['name' => 'Date', 'value' => now('America/Bogota')->toRfc2822String()],
        ];
        foreach ($headers as $nombre => $valor) {
            $cabeceras[] = ['name' => $nombre, 'value' => $valor];
        }

        $mensaje = [
            'id' => $id,
            'threadId' => 't1',
            'internalDate' => (string) (now('America/Bogota')->getTimestamp() * 1000),
            'payload' => [
                'mimeType' => 'text/plain',
                'headers' => $cabeceras,
                'body' => ['data' => rtrim(strtr(base64_encode('Cuerpo de queja PQRSD'), '+/', '-_'), '=')],
            ],
        ];
        if ($labels !== []) {
            $mensaje['labelIds'] = $labels;
        }

        return $mensaje;
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

        $msgs = $provider->fetchNew($this->cuenta([
            'sincronizar_desde' => now('America/Bogota')->subDay(),
        ]), '1');
        $this->assertCount(1, $msgs);
        $this->assertSame('200', $provider->nextSyncCursor());
    }

    public function test_la_primera_sincronizacion_no_trae_historial(): void
    {
        $fake = new FakeGmailApiClient;
        $fake->recentMessages = [['id' => 'viejo']];
        $fake->messagesById['viejo'] = $this->sampleMessage('viejo');
        $provider = new GoogleGmailMailProvider(new GmailCredentialResolver, $fake);

        $msgs = $provider->fetchNew($this->cuenta([
            'cursor_sincronizacion' => null,
            'sincronizar_desde' => null,
        ]), null);

        $this->assertCount(0, $msgs);
        $this->assertSame('100', $provider->nextSyncCursor());
    }

    public function test_un_mensaje_anterior_al_inicio_no_entra(): void
    {
        $fake = new FakeGmailApiClient;
        $antiguo = $this->sampleMessage('antiguo');
        $antiguo['internalDate'] = (string) (now('America/Bogota')->subYear()->getTimestamp() * 1000);
        $fake->recentMessages = [['id' => 'antiguo']];
        $fake->messagesById['antiguo'] = $antiguo;
        $provider = new GoogleGmailMailProvider(new GmailCredentialResolver, $fake);

        $msgs = $provider->fetchNew($this->cuenta([
            'cursor_sincronizacion' => null,
            'sincronizar_desde' => now('America/Bogota')->subDay(),
        ]), null);

        $this->assertCount(0, $msgs);
    }

    public function test_error_y_confirmacion_envio(): void
    {
        $fake = new FakeGmailApiClient;
        $provider = new GoogleGmailMailProvider(new GmailCredentialResolver, $fake);
        putenv('MAIL_CRED_GMAIL_TEST=x');
        $_ENV['MAIL_CRED_GMAIL_TEST'] = 'x';
        $cuenta = $this->cuenta();

        $ok = $provider->send($cuenta, new OutboundMessageData(
            to: 'ciudadano@example.test',
            subject: 'Respuesta',
            bodyText: 'Texto de respuesta institucional',
        ));
        $this->assertTrue($ok['ok']);
        $this->assertNotNull($ok['message_id']);

        $fake->sendOk = false;
        $fail = $provider->send($cuenta, new OutboundMessageData(
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

    public function test_mas_de_cincuenta_mensajes_se_importan_y_el_cursor_avanza(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(CatalogosSeeder::class);
        putenv('MAIL_CRED_GMAIL_TEST=x');
        $_ENV['MAIL_CRED_GMAIL_TEST'] = 'x';

        $fake = new FakeGmailApiClient;
        for ($i = 1; $i <= 51; $i++) {
            $id = 'lote-'.$i;
            $fake->historyMessages[] = ['id' => $id];
            $fake->messagesById[$id] = $this->sampleMessage($id, [], ['INBOX']);
        }
        $provider = new GoogleGmailMailProvider(new GmailCredentialResolver, $fake);
        $this->app->instance(GoogleGmailMailProvider::class, $provider);

        $cuenta = $this->cuenta(['cursor_sincronizacion' => '50']);
        $resultado = app(EmailSyncService::class)->syncCuenta($cuenta->fresh());

        $this->assertSame(51, $resultado['nuevos']);
        $this->assertSame(0, $resultado['errores']);
        $this->assertSame('102', $cuenta->fresh()->cursor_sincronizacion);
        $this->assertSame(0, Pqrsd::query()->count());
    }

    public function test_pagina_pendiente_no_avanza_el_cursor_ni_la_fecha_de_inicio(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        putenv('MAIL_CRED_GMAIL_TEST=x');
        $_ENV['MAIL_CRED_GMAIL_TEST'] = 'x';

        $inicio = now('America/Bogota')->subDay();
        $fake = new FakeGmailApiClient;
        $fake->historyNextPageToken = 'pagina-2';
        $fake->historyMessages = [['id' => 'm1']];
        $fake->messagesById['m1'] = $this->sampleMessage('m1', [], ['INBOX']);
        $provider = new GoogleGmailMailProvider(new GmailCredentialResolver, $fake);
        $this->app->instance(GoogleGmailMailProvider::class, $provider);

        $cuenta = $this->cuenta([
            'cursor_sincronizacion' => '50',
            'sincronizar_desde' => $inicio,
        ])->fresh();
        $fechaGuardada = $cuenta->sincronizar_desde->format('Y-m-d H:i:s');
        $resultado = app(EmailSyncService::class)->syncCuenta($cuenta);

        $this->assertGreaterThan(0, $resultado['errores']);
        $this->assertSame('50', $cuenta->fresh()->cursor_sincronizacion);
        $this->assertSame(0, MensajeCorreo::query()->count());
        $this->assertSame($fechaGuardada, $cuenta->fresh()->sincronizar_desde->format('Y-m-d H:i:s'));
    }

    public function test_un_fallo_intermedio_no_guarda_los_mensajes_ya_listados(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        putenv('MAIL_CRED_GMAIL_TEST=x');
        $_ENV['MAIL_CRED_GMAIL_TEST'] = 'x';

        $fake = new FakeGmailApiClient;
        $fake->historyMessages = [['id' => 'ok-1'], ['id' => 'falta']];
        $fake->messagesById['ok-1'] = $this->sampleMessage('ok-1', [], ['INBOX']);
        $provider = new GoogleGmailMailProvider(new GmailCredentialResolver, $fake);
        $this->app->instance(GoogleGmailMailProvider::class, $provider);

        $cuenta = $this->cuenta(['cursor_sincronizacion' => '50']);
        $resultado = app(EmailSyncService::class)->syncCuenta($cuenta->fresh());

        $this->assertGreaterThan(0, $resultado['errores']);
        $this->assertSame('50', $cuenta->fresh()->cursor_sincronizacion);
        $this->assertSame(0, MensajeCorreo::query()->count());
    }

    public function test_no_importa_enviados_ni_avisos_propios_como_expediente(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(CatalogosSeeder::class);
        putenv('MAIL_CRED_GMAIL_TEST=x');
        $_ENV['MAIL_CRED_GMAIL_TEST'] = 'x';

        $fake = new FakeGmailApiClient;
        $fake->historyMessages = [['id' => 'recibido'], ['id' => 'enviado'], ['id' => 'aviso']];
        $fake->messagesById['recibido'] = $this->sampleMessage('recibido', [], ['INBOX']);
        $fake->messagesById['enviado'] = $this->sampleMessage('enviado', [], ['SENT']);
        $fake->messagesById['aviso'] = $this->sampleMessage('aviso', [
            'X-PQRSD-Origen' => 'radicacion-web',
            'X-PQRSD-Radicado' => 'PQRSD-2026-000001',
        ], ['INBOX']);
        $provider = new GoogleGmailMailProvider(new GmailCredentialResolver, $fake);
        $this->app->instance(GoogleGmailMailProvider::class, $provider);

        $cuenta = $this->cuenta(['cursor_sincronizacion' => '50']);
        $resultado = app(EmailSyncService::class)->syncCuenta($cuenta->fresh());

        $this->assertSame(2, $resultado['nuevos']);
        $this->assertSame(0, Pqrsd::query()->count());
        $this->assertDatabaseMissing('mensajes_correo', ['gmail_message_id' => 'enviado']);
        $this->assertDatabaseHas('mensajes_correo', [
            'gmail_message_id' => 'aviso',
            'clasificacion' => 'ruido',
        ]);
    }

    public function test_la_consulta_de_recuperacion_excluye_spam_borradores_y_papelera(): void
    {
        $fake = new FakeGmailApiClient;
        $provider = new GoogleGmailMailProvider(new GmailCredentialResolver, $fake);
        $provider->fetchNew($this->cuenta([
            'cursor_sincronizacion' => null,
            'sincronizar_desde' => now('America/Bogota')->subDay(),
        ]), null);

        $this->assertStringContainsString('after:', (string) $fake->lastListQuery);
        $this->assertStringContainsString('-in:spam', (string) $fake->lastListQuery);
        $this->assertStringContainsString('-in:trash', (string) $fake->lastListQuery);
        $this->assertStringContainsString('-in:drafts', (string) $fake->lastListQuery);
        $this->assertStringContainsString('-in:chats', (string) $fake->lastListQuery);
    }
}
