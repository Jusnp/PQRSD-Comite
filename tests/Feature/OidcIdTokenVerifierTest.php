<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Authentik\AuthentikApiClient;
use App\Services\Authentik\FakeAuthentikApiClient;
use App\Services\Authentik\OidcException;
use App\Services\Authentik\OidcIdTokenVerifier;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;
use Mockery;
use SocialiteProviders\Authentik\Provider as AuthentikProvider;
use SocialiteProviders\Manager\OAuth2\User as SocialiteUser;
use Tests\TestCase;

class OidcIdTokenVerifierTest extends TestCase
{
    use RefreshDatabase;

    private string $issuer = 'https://auth.example.com/application/o/pqrsd-comite/';

    /** @var resource|\OpenSSLAsymmetricKey */
    private $clave;

    private string $kid = 'clave-prueba';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Cache::flush();
        config([
            'authentik.base_url' => 'https://auth.example.com',
            'authentik.app_slug' => 'pqrsd-comite',
            'services.authentik.enabled' => true,
            'services.authentik.base_url' => 'https://auth.example.com',
            'services.authentik.client_id' => 'cliente-pqrsd',
            'services.authentik.client_secret' => 'secreto',
            'services.authentik.redirect' => 'http://127.0.0.1:8010/auth/authentik/callback',
        ]);
        $opciones = [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];
        $opensslConf = dirname(PHP_BINARY).DIRECTORY_SEPARATOR.'extras'.DIRECTORY_SEPARATOR.'ssl'.DIRECTORY_SEPARATOR.'openssl.cnf';
        if (is_file($opensslConf)) {
            $opciones['config'] = $opensslConf;
        }
        $clave = openssl_pkey_new($opciones);
        $this->assertNotFalse($clave);
        $this->clave = $clave;
        $this->simularDescubrimiento();
    }

    public function test_acepta_el_issuer_publicado_y_rechaza_uno_alterado(): void
    {
        $verificador = new OidcIdTokenVerifier;
        $claims = $verificador->verificar($this->token(), 'nonce-prueba');
        $this->assertSame($this->issuer, $claims['iss']);

        try {
            $verificador->verificar($this->token(['iss' => 'https://auth.example.com/application/o/otro/']), 'nonce-prueba');
            $this->fail('Debió rechazar el issuer alterado.');
        } catch (OidcException $excepcion) {
            $this->assertSame('issuer', $excepcion->codigo);
        }
    }

    public function test_rechaza_firma_y_audience_incorrectas(): void
    {
        $verificador = new OidcIdTokenVerifier;
        $token = $this->token();
        $token[strlen($token) - 2] = $token[strlen($token) - 2] === 'a' ? 'b' : 'a';

        try {
            $verificador->verificar($token, 'nonce-prueba');
            $this->fail('Debió rechazar la firma.');
        } catch (OidcException $excepcion) {
            $this->assertSame('firma', $excepcion->codigo);
        }

        try {
            $verificador->verificar($this->token(['aud' => 'otra-aplicacion']), 'nonce-prueba');
            $this->fail('Debió rechazar la audiencia.');
        } catch (OidcException $excepcion) {
            $this->assertSame('audience', $excepcion->codigo);
        }
    }

    public function test_usuario_no_autorizado_no_entra(): void
    {
        $this->presentar('uid-desconocido', 'nadie@example.test', 'desconocido');
        $this->withSession(['oidc_nonce' => 'nonce-prueba'])
            ->get(route('auth.authentik.callback'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_el_callback_rechaza_un_issuer_alterado(): void
    {
        $this->presentar('uid-juan-real', 'persona.prueba@example.test', 'juan.perez.prueba', issuer: 'https://auth.example.com/application/o/otro/');
        $this->withSession(['oidc_nonce' => 'nonce-prueba'])
            ->get(route('auth.authentik.callback'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => 'El emisor de la identidad no corresponde a Authentik institucional.']);
        $this->assertGuest();
    }

    public function test_la_vinculacion_usa_el_id_token_aunque_userinfo_no_traiga_issuer(): void
    {
        $api = new FakeAuthentikApiClient;
        $api->groupMembers[] = [
            'pk' => '45',
            'uid' => 'uid-juan-real',
            'email' => 'persona.prueba@example.test',
            'username' => 'juan.perez.prueba',
            'active' => true,
        ];
        $this->app->instance(AuthentikApiClient::class, $api);

        $funcionario = User::factory()->create([
            'name' => 'Juan Pérez Prueba',
            'email' => 'persona.prueba@example.test',
            'documento' => 'juan.perez.prueba',
            'activo' => false,
            'authentik_status' => 'pending_link',
            'authentik_user_pk' => '45',
            'authentik_authorization_hash' => hash('sha256', 'autorizada'),
        ]);
        $funcionario->assignRole('auxiliar_pqrsd');

        $this->presentar('uid-juan-real', 'persona.prueba@example.test', 'juan.perez.prueba', sinIssuerEnUserinfo: true);
        $this->withSession(['oidc_nonce' => 'nonce-prueba'])
            ->get(route('auth.authentik.callback'))
            ->assertRedirect();

        $funcionario->refresh();
        $this->assertTrue($funcionario->activo);
        $this->assertSame('uid-juan-real', $funcionario->authentik_sub);
        $this->assertNotSame('45', $funcionario->authentik_sub);
        $this->assertSame('45', $funcionario->authentik_user_pk);
        $this->assertSame($this->issuer, $funcionario->authentik_issuer);
        $this->assertSame('auxiliar_pqrsd', $funcionario->roles->first()?->name);
        $this->assertNull($funcionario->email_verified_at);
    }

    public function test_un_funcionario_ya_vinculado_sigue_entrando_con_el_mismo_issuer(): void
    {
        $user = User::factory()->create([
            'email' => 'persona.invitada@example.test',
            'documento' => 'persona.invitada',
            'activo' => true,
            'authentik_status' => 'active',
            'authentik_sub' => 'uid-invitada',
            'authentik_user_pk' => '44',
            'authentik_issuer' => $this->issuer,
        ]);
        $user->assignRole('auxiliar_pqrsd');

        $this->presentar('uid-invitada', 'persona.invitada@example.test', 'persona.invitada');
        $this->withSession(['oidc_nonce' => 'nonce-prueba'])
            ->get(route('auth.authentik.callback'))
            ->assertRedirect(route('pqrsd.index'));
        $this->assertAuthenticatedAs($user);
    }

    private function presentar(string $sub, string $email, string $username, bool $sinIssuerEnUserinfo = false, ?string $issuer = null): void
    {
        $claims = [
            'sub' => $sub,
            'preferred_username' => $username,
            'email' => $email,
            'email_verified' => false,
        ];
        if (! $sinIssuerEnUserinfo) {
            $claims['iss'] = $issuer ?? $this->issuer;
        }
        $socialiteUser = (new SocialiteUser)->setRaw($claims)->map([
            'id' => $sub,
            'nickname' => $username,
            'name' => 'Persona',
            'email' => $email,
        ]);
        $socialiteUser->setAccessTokenResponseBody([
            'id_token' => $this->token([
                'sub' => $sub,
                'preferred_username' => $username,
                'email' => $email,
                'iss' => $issuer ?? $this->issuer,
            ]),
        ]);

        $provider = Mockery::mock(AuthentikProvider::class);
        $provider->shouldReceive('user')->andReturn($socialiteUser);
        Socialite::shouldReceive('driver')->with('authentik')->andReturn($provider);
    }

    /**
     * @param  array<string, mixed>  $reemplazos
     */
    private function token(array $reemplazos = []): string
    {
        $claims = array_merge([
            'iss' => $this->issuer,
            'sub' => 'uid-juan-real',
            'aud' => 'cliente-pqrsd',
            'exp' => time() + 300,
            'iat' => time(),
            'nonce' => 'nonce-prueba',
            'preferred_username' => 'juan.perez.prueba',
            'email' => 'persona.prueba@example.test',
        ], $reemplazos);

        $encabezado = $this->base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $this->kid], JSON_THROW_ON_ERROR));
        $cuerpo = $this->base64Url(json_encode($claims, JSON_THROW_ON_ERROR));
        $firma = '';
        openssl_sign($encabezado.'.'.$cuerpo, $firma, $this->clave, OPENSSL_ALGO_SHA256);

        return $encabezado.'.'.$cuerpo.'.'.$this->base64Url($firma);
    }

    private function simularDescubrimiento(): void
    {
        $detalles = openssl_pkey_get_details($this->clave);
        $this->assertIsArray($detalles);
        Http::fake([
            'https://auth.example.com/application/o/pqrsd-comite/.well-known/openid-configuration' => Http::response([
                'issuer' => $this->issuer,
                'jwks_uri' => 'https://auth.example.com/application/o/pqrsd-comite/jwks/',
                'authorization_endpoint' => 'https://auth.example.com/application/o/authorize/',
                'token_endpoint' => 'https://auth.example.com/application/o/token/',
                'userinfo_endpoint' => 'https://auth.example.com/application/o/userinfo/',
                'id_token_signing_alg_values_supported' => ['RS256'],
            ]),
            'https://auth.example.com/application/o/pqrsd-comite/jwks/' => Http::response([
                'keys' => [[
                    'kty' => 'RSA',
                    'kid' => $this->kid,
                    'alg' => 'RS256',
                    'use' => 'sig',
                    'n' => rtrim(strtr(base64_encode($detalles['rsa']['n']), '+/', '-_'), '='),
                    'e' => rtrim(strtr(base64_encode($detalles['rsa']['e']), '+/', '-_'), '='),
                ]],
            ]),
        ]);
    }

    private function base64Url(string $valor): string
    {
        return rtrim(strtr(base64_encode($valor), '+/', '-_'), '=');
    }
}
