<?php

namespace Tests\Feature;

use App\Jobs\ProcessGmailPubSubNotificationJob;
use App\Models\CuentaCorreo;
use App\Models\GmailOauthToken;
use App\Models\GmailPubsubEvent;
use App\Models\User;
use App\Services\Gmail\GmailOAuthService;
use App\Services\Gmail\PubSubOidcVerifier;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GmailPubSubAndOAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_pubsub_rechaza_sin_oidc_valido(): void
    {
        config([
            'pqrsd_correo.gmail.pubsub_skip_oidc' => false,
            'pqrsd_correo.gmail.pubsub_push_audience' => 'https://example.test/webhooks/gmail-pubsub',
        ]);

        $this->postJson(route('webhooks.gmail-pubsub'), [
            'message' => ['messageId' => 'x1', 'data' => base64_encode('{}')],
        ])->assertUnauthorized();
    }

    public function test_pubsub_idempotente_y_encola_job(): void
    {
        Queue::fake();
        config([
            'app.env' => 'local',
            'pqrsd_correo.gmail.pubsub_skip_oidc' => true,
            'pqrsd_correo.gmail.pubsub_dev_token' => 'dev-token',
            'pqrsd_correo.gmail.pubsub_push_audience' => 'https://example.test/webhooks/gmail-pubsub',
        ]);

        $payload = base64_encode(json_encode([
            'emailAddress' => 'piloto@menteplena.com.co',
            'historyId' => 12345,
        ]));

        $this->withToken('dev-token')
            ->postJson(route('webhooks.gmail-pubsub'), [
                'message' => ['messageId' => 'msg-unique-1', 'data' => $payload],
            ])
            ->assertOk();

        Queue::assertPushed(ProcessGmailPubSubNotificationJob::class);
        $this->assertSame(1, GmailPubsubEvent::count());

        // Duplicado
        $this->withToken('dev-token')
            ->postJson(route('webhooks.gmail-pubsub'), [
                'message' => ['messageId' => 'msg-unique-1', 'data' => $payload],
            ])
            ->assertOk();

        $this->assertSame(1, GmailPubsubEvent::count());
    }

    public function test_oauth_callback_almacena_token_cifrado(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        config([
            'pqrsd_correo.gmail.oauth_client_id' => 'client',
            'pqrsd_correo.gmail.oauth_client_secret' => 'secret',
            'pqrsd_correo.gmail.oauth_redirect_uri' => 'https://example.test/oauth/gmail/callback',
            'pqrsd_correo.gmail.pilot_mailbox' => 'piloto@menteplena.com.co',
            'pqrsd_correo.gmail.allowed_mailboxes' => ['piloto@menteplena.com.co'],
            'pqrsd_correo.gmail.oauth_scopes' => ['https://www.googleapis.com/auth/gmail.readonly'],
        ]);

        $cuenta = CuentaCorreo::create([
            'direccion' => 'piloto@menteplena.com.co',
            'activo' => true,
            'tipo_integracion' => 'mock',
            'tipo_buzon' => 'individual',
        ]);

        $user = User::factory()->create(['activo' => true]);
        $user->assignRole('lider_pqrsd');

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'atk',
                'refresh_token' => 'rtk',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ], 200),
        ]);

        $oauth = app(GmailOAuthService::class);
        $this->actingAs($user);
        $auth = $oauth->authorizationUrl($cuenta);

        $this->get(route('oauth.gmail.callback', [
            'code' => 'auth-code',
            'state' => $auth['state'],
        ]))->assertRedirect();

        $token = GmailOauthToken::where('cuenta_correo_id', $cuenta->id)->first();
        $this->assertNotNull($token);
        $this->assertSame('atk', $token->payload()['access_token']);
        $this->assertStringNotContainsString('atk', $token->payload_encrypted);
        $this->assertSame('google_api', $cuenta->fresh()->tipo_integracion);
    }

    public function test_job_pubsub_sin_cuenta_marca_error_sin_avanzar(): void
    {
        $event = GmailPubsubEvent::create([
            'message_id' => 'm2',
            'email_address' => 'noexiste@menteplena.com.co',
            'history_id' => '9',
            'estado' => 'encolado',
        ]);

        (new ProcessGmailPubSubNotificationJob($event->id))
            ->handle(app(\App\Services\Correo\EmailSyncService::class));

        $this->assertSame('error', $event->fresh()->estado);
    }
}
