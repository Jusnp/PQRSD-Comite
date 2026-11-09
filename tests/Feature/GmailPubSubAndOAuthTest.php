<?php

namespace Tests\Feature;

use App\Jobs\ProcessGmailPubSubNotificationJob;
use App\Mail\Gmail\GmailApiException;
use App\Models\CorreoSalida;
use App\Models\CuentaCorreo;
use App\Models\GmailOauthToken;
use App\Models\GmailPubsubEvent;
use App\Models\MensajeCorreo;
use App\Models\User;
use App\Services\Correo\EmailReplyService;
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
            'pqrsd_correo.gmail.oauth_scopes' => ['https://www.googleapis.com/auth/gmail.modify'],
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
                'scope' => 'https://www.googleapis.com/auth/gmail.modify',
            ], 200),
            'gmail.googleapis.com/*' => Http::response([
                'emailAddress' => 'piloto@menteplena.com.co',
                'historyId' => '15',
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
        $this->assertTrue($cuenta->fresh()->activo);
        $this->assertNotNull($cuenta->fresh()->sincronizar_desde);
        $this->assertStringContainsString('gmail.modify', (string) $token->scopes);
    }

    public function test_oauth_exige_state_lista_y_no_activa_callcenter(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        config([
            'pqrsd_correo.gmail.oauth_client_id' => 'client',
            'pqrsd_correo.gmail.oauth_client_secret' => 'secret',
            'pqrsd_correo.gmail.oauth_redirect_uri' => 'https://pqrsd.comitedeestudiosmedicos.com.co/oauth/gmail/callback',
            'pqrsd_correo.gmail.allowed_mailboxes' => ['callcenter@menteplena.com.co'],
            'pqrsd_correo.gmail.oauth_scopes' => config('pqrsd_correo.gmail.oauth_scopes'),
        ]);

        $cuenta = CuentaCorreo::query()->where('direccion', 'callcenter@menteplena.com.co')->firstOrFail();
        $this->assertFalse($cuenta->activo);
        $this->assertSame('desconocido', $cuenta->tipo_buzon);

        $user = User::factory()->create(['activo' => true]);
        $user->assignRole('admin');
        $this->actingAs($user);

        $auth = app(GmailOAuthService::class)->authorizationUrl($cuenta);
        $this->assertStringContainsString('access_type=offline', $auth['url']);
        $this->assertStringContainsString('gmail.modify', $auth['url']);
        $this->assertStringNotContainsString('gmail.send', $auth['url']);
        $this->assertStringContainsString(
            rawurlencode('https://pqrsd.comitedeestudiosmedicos.com.co/oauth/gmail/callback'),
            $auth['url']
        );

        $this->get(route('oauth.gmail.callback', [
            'code' => 'auth-code',
            'state' => 'otro-state',
        ]))->assertRedirect();
        $this->assertSame(0, GmailOauthToken::count());
        $this->assertFalse($cuenta->fresh()->activo);

        config(['pqrsd_correo.gmail.allowed_mailboxes' => []]);
        $this->expectException(\App\Mail\Gmail\GmailApiException::class);
        app(GmailOAuthService::class)->authorizationUrl($cuenta);
    }

    public function test_oauth_de_buzon_inactivo_no_sincroniza_ni_envia(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        config([
            'pqrsd_correo.gmail.oauth_client_id' => 'client',
            'pqrsd_correo.gmail.oauth_client_secret' => 'secret',
            'pqrsd_correo.gmail.oauth_redirect_uri' => 'https://pqrsd.comitedeestudiosmedicos.com.co/oauth/gmail/callback',
            'pqrsd_correo.gmail.pilot_mailbox' => 'callcenter@menteplena.com.co',
            'pqrsd_correo.gmail.allowed_mailboxes' => ['callcenter@menteplena.com.co'],
            'pqrsd_correo.gmail.oauth_scopes' => config('pqrsd_correo.gmail.oauth_scopes'),
        ]);

        $this->assertSame('/oauth/gmail/callback', route('oauth.gmail.callback', absolute: false));

        $cuenta = CuentaCorreo::query()->where('direccion', 'callcenter@menteplena.com.co')->firstOrFail();
        $this->assertFalse($cuenta->activo);

        $admin = User::factory()->create(['activo' => true]);
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $inicio = $this->get(route('cuentas-correo.oauth', $cuenta));
        $inicio->assertRedirect();
        $destino = (string) $inicio->headers->get('Location');
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $destino);
        $this->assertStringContainsString('access_type=offline', $destino);
        $this->assertStringContainsString('gmail.modify', $destino);
        $this->assertStringNotContainsString('gmail.send', $destino);
        $this->assertStringContainsString(
            rawurlencode('https://pqrsd.comitedeestudiosmedicos.com.co/oauth/gmail/callback'),
            $destino
        );

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'atk-callcenter',
                'refresh_token' => 'rtk-callcenter',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
                'scope' => 'https://www.googleapis.com/auth/gmail.modify',
            ], 200),
            'gmail.googleapis.com/*' => Http::response([
                'emailAddress' => 'callcenter@menteplena.com.co',
                'historyId' => '40',
            ], 200),
        ]);

        $this->get(route('oauth.gmail.callback', [
            'code' => 'auth-code',
            'state' => session('gmail_oauth_state'),
        ]))->assertRedirect(route('cuentas-correo.index'));

        $cuenta->refresh();
        $token = GmailOauthToken::query()->where('cuenta_correo_id', $cuenta->id)->first();
        $this->assertNotNull($token);
        $this->assertSame('atk-callcenter', $token->payload()['access_token']);
        $this->assertStringNotContainsString('atk-callcenter', $token->payload_encrypted);
        $this->assertFalse($cuenta->activo);
        $this->assertNotNull($cuenta->sincronizar_desde);
        $this->assertSame('usuario', $cuenta->verificacion_buzon);
        $this->assertSame(0, MensajeCorreo::query()->count());

        $otroInicio = app(GmailOAuthService::class)->authorizationUrl($cuenta);
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'atk-amplio',
                'refresh_token' => 'rtk-amplio',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
                'scope' => 'https://www.googleapis.com/auth/gmail.modify https://mail.google.com/',
            ], 200),
        ]);
        $this->get(route('oauth.gmail.callback', [
            'code' => 'auth-code-amplio',
            'state' => $otroInicio['state'],
        ]))->assertRedirect(route('cuentas-correo.index'));
        $this->assertSame(1, GmailOauthToken::query()->count());
        $this->assertSame('atk-callcenter', GmailOauthToken::query()->first()->payload()['access_token']);
        $this->assertFalse($cuenta->fresh()->activo);

        $otra = CuentaCorreo::query()->create([
            'direccion' => 'otra@menteplena.com.co',
            'activo' => false,
            'tipo_integracion' => 'google_api',
            'tipo_buzon' => 'individual',
        ]);
        try {
            app(GmailOAuthService::class)->authorizationUrl($otra);
            $this->fail('El piloto no debía aceptar otro buzón.');
        } catch (GmailApiException $e) {
            $this->assertStringContainsString('piloto', $e->getMessage());
        }

        Http::fake();
        $this->post(route('cuentas-correo.sincronizar', $cuenta))
            ->assertRedirect()
            ->assertSessionHasErrors('sync');
        $this->post(route('cuentas-correo.probar', $cuenta))
            ->assertRedirect()
            ->assertSessionHasErrors('conexion');
        Http::assertNothingSent();
        $this->assertDatabaseCount('sincronizaciones_correo', 0);

        $entrante = MensajeCorreo::query()->create([
            'cuenta_correo_id' => $cuenta->id,
            'message_id' => '<entrada-inactiva@example.test>',
            'remitente' => 'ciudadano@example.test',
            'destinatario' => $cuenta->direccion,
            'asunto' => 'Seguimiento',
            'fecha_mensaje' => now('America/Bogota'),
            'fecha_importacion' => now('America/Bogota'),
            'direccion' => 'entrante',
            'clasificacion' => 'pendiente_revision',
            'estado_procesamiento' => 'recibido',
        ]);
        $respuesta = app(EmailReplyService::class)->responder($entrante, 'Respuesta que no debe salir.', $admin);
        $this->assertFalse($respuesta['ok']);
        $this->assertSame(0, CorreoSalida::query()->count());

        $funcionarioInactivo = User::factory()->create(['activo' => false]);
        $funcionarioInactivo->assignRole('admin');
        $this->actingAs($funcionarioInactivo)
            ->get(route('cuentas-correo.oauth', $cuenta))
            ->assertRedirect(route('login'));

        $sinPermiso = User::factory()->create(['activo' => true]);
        $sinPermiso->assignRole('auxiliar_pqrsd');
        $this->actingAs($sinPermiso)
            ->get(route('cuentas-correo.oauth', $cuenta))
            ->assertForbidden();
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
