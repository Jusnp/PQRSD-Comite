<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Authentik\OidcIdTokenVerifier;
use Database\Seeders\RolesAndPermissionsSeeder;
use Tests\Support\FakeOidcIdTokenVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Mockery;
use SocialiteProviders\Authentik\Provider as AuthentikProvider;
use SocialiteProviders\Manager\OAuth2\User as SocialiteUser;
use Tests\TestCase;

class AuthentikLoginTest extends TestCase
{
    use RefreshDatabase;

    private FakeOidcIdTokenVerifier $oidcTokens;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->oidcTokens = new FakeOidcIdTokenVerifier;
        $this->app->instance(OidcIdTokenVerifier::class, $this->oidcTokens);

        config([
            'services.authentik.enabled' => true,
            'services.authentik.base_url' => 'https://auth.example.com',
            'services.authentik.client_id' => 'pqrsd',
            'services.authentik.client_secret' => 'secreto',
            'services.authentik.redirect' => 'http://127.0.0.1:8010/auth/authentik/callback',
        ]);
    }

    private function authentikDevuelve(?string $preferredUsername, ?string $email = 'persona@example.com', array $grupos = []): void
    {
        $raw = [
            'iss' => 'https://auth.example.com/application/o/pqrsd-comite/',
            'sub' => 'abc123',
            'preferred_username' => $preferredUsername,
            'email' => $email,
            'groups' => $grupos,
        ];
        $this->oidcTokens->claims = $raw;
        $socialiteUser = (new SocialiteUser)->setRaw($raw)->map([
            'id' => 'abc123',
            'nickname' => $preferredUsername,
            'name' => 'Persona',
            'email' => $email,
        ]);

        $provider = Mockery::mock(AuthentikProvider::class);
        $provider->shouldReceive('user')->andReturn($socialiteUser);
        $provider->shouldReceive('redirect')->andReturn(redirect('https://auth.example.com/application/o/authorize/'));

        Socialite::shouldReceive('driver')->with('authentik')->andReturn($provider);
    }

    public function test_login_muestra_boton_authentik_cuando_esta_habilitado(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Iniciar sesión con Authentik')
            ->assertSee('Acceso exclusivo para funcionarios autorizados')
            ->assertSee(route('auth.authentik.redirect'), false)
            ->assertDontSee('Entrar temporalmente')
            ->assertDontSee('Recuperación temporal')
            ->assertDontSee('name="email"', false)
            ->assertDontSee('name="password"', false);
    }

    public function test_login_oculta_boton_si_authentik_deshabilitado(): void
    {
        config([
            'services.authentik.enabled' => false,
            'authentik.enabled' => false,
        ]);

        $this->get(route('login'))
            ->assertOk()
            ->assertDontSee('Iniciar sesión con Authentik');
    }

    public function test_usuario_vinculado_por_sub_ingresa(): void
    {
        $user = User::factory()->create([
            'documento' => '1000000000',
            'email' => 'otro@example.com',
            'activo' => true,
            'authentik_sub' => 'abc123',
            'authentik_status' => User::AUTHENTIK_ACTIVE,
            'theme_preference' => 'dark',
        ]);
        $user->assignRole('auxiliar_pqrsd');
        $this->authentikDevuelve('1000000000', 'persona@example.com', ['pqrsd-usuarios']);

        $this->get(route('auth.authentik.callback'))
            ->assertRedirect(route('pqrsd.index'))
            ->assertCookie('pqrsd_theme', 'dark');

        $this->assertAuthenticatedAs($user);
        $this->assertTrue($user->fresh()->hasRole('auxiliar_pqrsd'));
        $this->assertFalse($user->fresh()->hasRole('admin'));
    }

    public function test_documento_o_correo_coincidente_no_vincula_cuenta_historica(): void
    {
        $user = User::factory()->create([
            'documento' => '1000000000',
            'email' => 'persona@example.com',
            'activo' => true,
            'authentik_status' => User::AUTHENTIK_LOCAL,
        ]);
        $user->assignRole('admin');
        $this->authentikDevuelve('1000000000');

        $this->get(route('auth.authentik.callback'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertNull($user->fresh()->authentik_sub);
    }

    public function test_login_exclusivo_cuando_el_administrador_esta_vinculado(): void
    {
        $admin = User::factory()->create([
            'activo' => true,
            'authentik_sub' => 'sub-admin',
            'authentik_status' => User::AUTHENTIK_ACTIVE,
        ]);
        $admin->assignRole('admin');

        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Iniciar sesión con Authentik')
            ->assertSee('Acceso exclusivo para funcionarios autorizados')
            ->assertDontSee('name="email"', false)
            ->assertDontSee('name="password"', false)
            ->assertDontSee('Remember me')
            ->assertDontSee('Forgot your password?');

        $this->post(route('login'), [
            'email' => $admin->email,
            'password' => 'password',
        ])->assertNotFound();
        $this->assertGuest();
    }

    public function test_usuario_inexistente_es_rechazado(): void
    {
        $this->authentikDevuelve('9999999999');

        $this->get(route('auth.authentik.callback'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_usuario_desactivado_es_rechazado(): void
    {
        User::factory()->create([
            'documento' => '2000000000',
            'email' => 'persona@example.com',
            'activo' => false,
            'authentik_status' => 'deactivated',
            'authentik_sub' => 'old-sub',
        ]);
        $this->authentikDevuelve('2000000000');

        $this->get(route('auth.authentik.callback'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }
}
