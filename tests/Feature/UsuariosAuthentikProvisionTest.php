<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UsuarioAuditoria;
use App\Services\Authentik\AuthentikApiClient;
use App\Services\Authentik\AuthentikProvisionService;
use App\Services\Authentik\FakeAuthentikApiClient;
use App\Services\Authentik\OidcIdTokenVerifier;
use Illuminate\Support\Facades\Cache;
use Tests\Support\FakeOidcIdTokenVerifier;
use Database\Seeders\CatalogosSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\UsersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Mockery;
use SocialiteProviders\Authentik\Provider as AuthentikProvider;
use SocialiteProviders\Manager\OAuth2\User as SocialiteUser;
use Tests\TestCase;

class UsuariosAuthentikProvisionTest extends TestCase
{
    use RefreshDatabase;

    private FakeAuthentikApiClient $fake;

    private FakeOidcIdTokenVerifier $oidcTokens;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(CatalogosSeeder::class);
        $this->seed(UsersSeeder::class);

        $this->fake = new FakeAuthentikApiClient;
        $this->app->instance(AuthentikApiClient::class, $this->fake);
        $this->oidcTokens = new FakeOidcIdTokenVerifier;
        $this->app->instance(OidcIdTokenVerifier::class, $this->oidcTokens);

        config([
            'authentik.enabled' => true,
            'authentik.local_login_enabled' => true,
            'authentik.provisioning_enabled' => true,
            'authentik.base_url' => 'https://auth.example.com',
            'authentik.api_token' => 'token-prueba',
            'authentik.enrollment_flow_slug' => 'pqrsd-invitation-enrollment',
            'authentik.enrollment_flow_pk' => '11111111-1111-1111-1111-111111111111',
            'authentik.group_name' => 'pqrsd-usuarios',
            'services.authentik.enabled' => true,
            'services.authentik.base_url' => 'https://auth.example.com',
            'services.authentik.client_id' => 'pqrsd',
            'services.authentik.client_secret' => 'secreto',
            'services.authentik.redirect' => 'http://127.0.0.1:8010/auth/authentik/callback',
        ]);
    }

    private function admin(): User
    {
        return User::where('email', 'admin@menteplena.test')->firstOrFail();
    }

    private function auxiliar(): User
    {
        return User::where('email', 'auxiliar1@menteplena.test')->firstOrFail();
    }

    private function oidc(string $email, string $sub = 'sub-123', ?string $username = null, ?bool $correoVerificado = null): void
    {
        $raw = [
            'iss' => 'https://auth.example.com/application/o/pqrsd-comite/',
            'sub' => $sub,
            'preferred_username' => $username ?? (strstr($email, '@', true) ?: 'user'),
            'email' => $email,
            'groups' => ['pqrsd-usuarios', 'otro-grupo'],
        ];
        $this->oidcTokens->claims = $raw;
        if ($correoVerificado !== null) {
            $raw['email_verified'] = $correoVerificado;
        }
        $socialiteUser = (new SocialiteUser)->setRaw($raw)->map([
            'id' => $sub,
            'nickname' => 'user.'.$sub,
            'name' => 'Persona',
            'email' => $email,
        ]);

        $provider = Mockery::mock(AuthentikProvider::class);
        $provider->shouldReceive('user')->andReturn($socialiteUser);
        Socialite::shouldReceive('driver')->with('authentik')->andReturn($provider);
    }

    public function test_admin_crea_funcionario_y_prepara_invitacion(): void
    {
        $this->fake->emailConfigured = true;

        $this->actingAs($this->admin())->post(route('usuarios.store'), [
            'name' => 'Auxiliar Nuevo',
            'email' => 'auxiliar.nuevo@example.test',
            'role' => 'auxiliar_pqrsd',
        ])->assertRedirect();

        $user = User::where('email', 'auxiliar.nuevo@example.test')->firstOrFail();
        $this->assertFalse($user->activo);
        $this->assertSame('invitation_prepared', $user->authentik_status);
        $this->assertSame('private', $user->authentik_invitation_delivery);
        $this->assertTrue($user->hasRole('auxiliar_pqrsd'));
        $this->assertCount(1, $this->fake->invitationsCreated);
        $this->assertCount(0, $this->fake->emailsSent);
        $this->assertNull($user->email_verified_at);
        $almacenado = \Illuminate\Support\Facades\DB::table('users')->where('id', $user->id)->value('authentik_invitation_url');
        $this->assertIsString($almacenado);
        $this->assertStringNotContainsString('itoken', $almacenado);
        $this->assertStringNotContainsString(
            $this->fake->invitationsCreated[0]['pk'],
            (string) json_encode($user->auditoriasUsuario()->first()?->detalle)
        );
        $this->assertNull($user->authentik_sub);
        $this->assertNull($user->authentik_user_pk);
        $this->assertCount(0, $this->fake->identities);
        $this->assertSame('auxiliar.nuevo', $this->fake->invitationsCreated[0]['fixed_data']['username']);
        $this->assertTrue($this->fake->invitationsCreated[0]['single_use']);
        $this->assertAuthenticatedAs($this->admin());
    }

    public function test_usuario_authentik_existente_no_recibe_invitacion(): void
    {
        $this->fake->identities[] = [
            'pk' => '9',
            'uid' => 'uid-ocupado',
            'email' => 'otra-persona@example.test',
            'username' => 'auxiliar.nuevo',
        ];

        $this->actingAs($this->admin())->post(route('usuarios.store'), [
            'name' => 'Auxiliar Nuevo',
            'email' => 'auxiliar.nuevo@example.test',
            'role' => 'auxiliar_pqrsd',
        ])->assertRedirect();

        $user = User::where('email', 'auxiliar.nuevo@example.test')->firstOrFail();
        $this->assertSame('auxiliar.nuevo.2', $user->documento);
        $this->assertSame('invitation_prepared', $user->authentik_status);
        $this->assertSame('auxiliar.nuevo.2', $this->fake->invitationsCreated[0]['fixed_data']['username']);
        $this->assertCount(1, $this->fake->invitationsCreated);
    }

    public function test_reintento_no_crea_segunda_invitacion(): void
    {
        $this->fake->emailConfigured = false;
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('usuarios.store'), [
            'name' => 'Auxiliar Nuevo',
            'email' => 'auxiliar.nuevo@example.test',
            'role' => 'auxiliar_pqrsd',
        ]);
        $user = User::where('email', 'auxiliar.nuevo@example.test')->firstOrFail();

        $this->actingAs($admin)->post(route('usuarios.reinvitar', $user))->assertRedirect();

        $this->assertCount(1, $this->fake->invitationsCreated);
        $this->assertNull($user->fresh()->authentik_sub);
    }

    public function test_auxiliar_no_puede_crear_funcionarios(): void
    {
        $this->actingAs($this->auxiliar())->post(route('usuarios.store'), [
            'name' => 'X',
            'email' => 'x@example.test',
            'role' => 'auxiliar_pqrsd',
        ])->assertForbidden();
    }

    public function test_correo_duplicado_no_crea_identidad(): void
    {
        $this->actingAs($this->admin())->post(route('usuarios.store'), [
            'name' => 'Dup',
            'email' => 'auxiliar1@menteplena.test',
            'role' => 'auxiliar_pqrsd',
        ])->assertSessionHasErrors('email');
    }

    public function test_fallo_authentik_deja_pendiente_no_activo(): void
    {
        $this->fake->failNextInvitation = true;

        $this->actingAs($this->admin())->post(route('usuarios.store'), [
            'name' => 'Fallo API',
            'email' => 'fallo.api@example.test',
            'role' => 'admin',
        ])->assertRedirect();

        $user = User::where('email', 'fallo.api@example.test')->firstOrFail();
        $this->assertFalse($user->activo);
        $this->assertSame('invitation_failed', $user->authentik_status);
        $this->assertTrue($user->hasRole('admin'));
    }

    public function test_sin_correo_authentik_estado_preparada_pendiente_entrega(): void
    {
        $this->fake->emailConfigured = false;

        $this->actingAs($this->admin())->post(route('usuarios.store'), [
            'name' => 'Sin Mail',
            'email' => 'sin.mail@example.test',
            'role' => 'auxiliar_pqrsd',
        ])->assertRedirect();

        $user = User::where('email', 'sin.mail@example.test')->firstOrFail();
        $this->assertSame('invitation_prepared', $user->authentik_status);
        $this->assertSame('private', $user->authentik_invitation_delivery);
        $this->assertCount(0, $this->fake->emailsSent);
    }

    public function test_oidc_vincula_y_activa_conservando_rol_laravel(): void
    {
        $this->fake->emailConfigured = false;
        $this->actingAs($this->admin())->post(route('usuarios.store'), [
            'name' => 'Invitado OIDC',
            'email' => 'invitado.oidc@example.test',
            'role' => 'auxiliar_pqrsd',
        ]);

        $user = User::where('email', 'invitado.oidc@example.test')->firstOrFail();
        $this->assertFalse($user->activo);

        $this->post(route('logout'));
        $this->fake->groupMembers[] = [
            'pk' => '70',
            'uid' => 'sub-invitado-1',
            'email' => 'invitado.oidc@example.test',
            'username' => $user->documento,
            'active' => true,
        ];
        $this->oidc('invitado.oidc@example.test', 'sub-invitado-1', $user->documento);
        $this->get(route('auth.authentik.callback'))->assertRedirect();

        $user->refresh();
        $this->assertTrue($user->activo);
        $this->assertSame('active', $user->authentik_status);
        $this->assertSame('sub-invitado-1', $user->authentik_sub);
        $this->assertTrue($user->hasRole('auxiliar_pqrsd'));
        $this->assertFalse($user->hasRole('admin'));
        $this->assertAuthenticatedAs($user);
        $this->assertNotEmpty($this->fake->groupAdditions);
    }

    public function test_correo_ya_existente_en_authentik_no_se_apropia(): void
    {
        $this->fake->usersByEmail['ocupado@example.test'] = '55555555-5555-5555-5555-555555555555';

        $this->actingAs($this->admin())->post(route('usuarios.store'), [
            'name' => 'Ocupado',
            'email' => 'ocupado@example.test',
            'role' => 'auxiliar_pqrsd',
        ])->assertRedirect();

        $user = User::where('email', 'ocupado@example.test')->firstOrFail();
        $this->assertFalse($user->activo);
        $this->assertNull($user->authentik_invitation_pk);
        $this->assertCount(0, $this->fake->invitationsCreated);
    }

    public function test_comando_vincula_administrador_y_cierra_login_local(): void
    {
        $admin = $this->admin();
        $this->fake->identities[] = [
            'pk' => '66666666-6666-6666-6666-666666666666',
            'uid' => 'uid-admin-1',
            'email' => $admin->email,
            'username' => 'admin.pqrsd',
        ];

        $this->artisan('pqrsd:vincular-administrador', ['email' => $admin->email])
            ->assertSuccessful();

        $admin->refresh();
        $this->assertSame('uid-admin-1', $admin->authentik_sub);
        $this->assertSame('active', $admin->authentik_status);

        $this->post(route('login'), [
            'email' => $admin->email,
            'password' => (string) env('PQRSD_LAB_PASSWORD'),
        ])->assertNotFound();
        $this->assertGuest();
    }

    public function test_comando_rechaza_akadmin(): void
    {
        $admin = $this->admin();
        $this->fake->identities[] = [
            'pk' => '77777777-7777-7777-7777-777777777777',
            'uid' => 'uid-akadmin',
            'email' => $admin->email,
            'username' => 'akadmin',
        ];

        $this->artisan('pqrsd:vincular-administrador', ['email' => $admin->email])
            ->assertFailed();

        $this->assertNull($admin->fresh()->authentik_sub);
    }

    public function test_identidad_desconocida_no_entra(): void
    {
        $this->oidc('desconocido@example.test', 'sub-x');
        $this->get(route('auth.authentik.callback'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_invitacion_vencida_se_rechaza(): void
    {
        $user = User::factory()->create([
            'email' => 'vencido@example.test',
            'activo' => false,
            'authentik_status' => 'invitation_sent',
            'authentik_invitation_expires_at' => now()->subDay(),
        ]);
        $user->assignRole('auxiliar_pqrsd');

        $this->oidc('vencido@example.test', 'sub-vencido');
        $this->get(route('auth.authentik.callback'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_desactivacion_bloquea_y_retira_grupo(): void
    {
        $user = User::factory()->create([
            'email' => 'off@example.test',
            'activo' => true,
            'authentik_status' => 'active',
            'authentik_sub' => 'sub-off',
            'authentik_user_pk' => '33333333-3333-3333-3333-333333333333',
        ]);
        $user->assignRole('auxiliar_pqrsd');
        $this->fake->usersBySub['sub-off'] = '33333333-3333-3333-3333-333333333333';
        \Illuminate\Support\Facades\DB::table('sessions')->insert([
            'id' => 'sesion-funcionario-activa',
            'user_id' => $user->id,
            'payload' => 'sesion',
            'last_activity' => time(),
        ]);

        $this->actingAs($this->admin())
            ->post(route('usuarios.desactivar', $user))
            ->assertRedirect()
            ->assertSessionHas('success', 'El funcionario fue desactivado correctamente.')
            ->assertSessionMissing('error');

        $user->refresh();
        $this->assertFalse($user->activo);
        $this->assertSame('deactivated', $user->authentik_status);
        $this->assertDatabaseMissing('sessions', ['id' => 'sesion-funcionario-activa']);
        $this->assertNotEmpty($this->fake->groupRemovals);

        $this->post(route('logout'));
        $this->oidc('off@example.test', 'sub-off');
        $this->get(route('auth.authentik.callback'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_servicios_publicos_siguen_sin_login(): void
    {
        $this->get(route('publico.formulario'))->assertOk();
        $this->get(route('publico.consulta'))->assertOk();
        $this->get(route('publico.qr'))->assertOk();
    }

    public function test_cuentas_historicas_locales_conservadas(): void
    {
        $this->assertDatabaseHas('users', ['email' => 'admin@menteplena.test']);
        $this->assertDatabaseHas('users', ['email' => 'auxiliar1@menteplena.test']);
    }

    public function test_login_local_permanece_cerrado_sin_administrador_vinculado(): void
    {
        config(['authentik.local_login_enabled' => true]);
        $this->assertNull($this->admin()->authentik_sub);

        $this->get(route('login'))
            ->assertOk()
            ->assertDontSee('name="password"', false)
            ->assertDontSee('Entrar temporalmente');

        $this->post(route('login'), [
            'email' => 'admin@menteplena.test',
            'password' => (string) env('PQRSD_LAB_PASSWORD'),
        ])->assertNotFound();
        $this->assertGuest();
    }

    public function test_provisioning_deshabilitado_no_deja_activo(): void
    {
        config(['authentik.provisioning_enabled' => false]);

        $this->actingAs($this->admin())->post(route('usuarios.store'), [
            'name' => 'Sin Prov',
            'email' => 'sin.prov@example.test',
            'role' => 'auxiliar_pqrsd',
        ])->assertRedirect();

        $user = User::where('email', 'sin.prov@example.test')->firstOrFail();
        $this->assertFalse($user->activo);
        $this->assertSame('pending_invitation', $user->authentik_status);
        $this->assertSame('Pendiente de invitación', $user->etiquetaEstadoAuthentik());
        $this->assertNull($user->authentik_last_error);
        $this->assertSame('sin.prov', $user->documento);
        $this->assertCount(0, $this->fake->invitationsCreated);
    }

    public function test_autoriza_dos_administradores_y_rechaza_adminsispam(): void
    {
        $historico = $this->admin();
        $this->fake->identities[] = [
            'pk' => '8',
            'uid' => 'uid-akadmin-exacto',
            'email' => 'compartido@example.test',
            'username' => 'akadmin',
        ];
        $this->fake->identities[] = [
            'pk' => '9',
            'uid' => 'uid-adminsispam',
            'email' => 'compartido@example.test',
            'username' => 'AdminSispam',
        ];

        $this->artisan('pqrsd:autorizar-administradores')->assertSuccessful();

        $principal = User::query()->findOrFail($historico->id);
        $this->assertSame(AuthentikProvisionService::CORREO_PRINCIPAL, $principal->email);
        $this->assertSame('uid-practicantesistemas', $principal->authentik_sub);
        $this->assertTrue($principal->hasRole('admin'));
        $this->assertNull(User::query()->where('email', 'admin@menteplena.test')->first());

        $akadmin = User::query()->where('authentik_sub', 'uid-akadmin-exacto')->firstOrFail();
        $this->assertTrue($akadmin->hasRole('admin'));
        $this->assertSame('compartido@example.test', $akadmin->email);
        $this->assertNull(User::query()->where('authentik_sub', 'uid-adminsispam')->first());
        $this->assertTrue(UsuarioAuditoria::query()->where('accion', 'akadmin_autorizado_expresamente')->exists());
    }

    public function test_akadmin_entra_y_adminsispam_queda_fuera(): void
    {
        $this->fake->identities[] = [
            'pk' => '8',
            'uid' => 'uid-akadmin-exacto',
            'email' => 'compartido@example.test',
            'username' => 'akadmin',
        ];
        $this->fake->identities[] = [
            'pk' => '9',
            'uid' => 'uid-adminsispam',
            'email' => 'compartido@example.test',
            'username' => 'AdminSispam',
        ];
        $this->artisan('pqrsd:autorizar-administradores')->assertSuccessful();

        $this->oidc('compartido@example.test', 'uid-adminsispam', 'AdminSispam');
        $this->get(route('auth.authentik.callback'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_administrador_principal_entra_por_sub(): void
    {
        $historico = $this->admin();
        $this->artisan('pqrsd:autorizar-administradores')->assertFailed();

        $principal = User::query()->findOrFail($historico->id);
        $this->assertSame(AuthentikProvisionService::CORREO_PRINCIPAL, $principal->email);
        $this->assertSame('uid-practicantesistemas', $principal->authentik_sub);

        $this->oidc(AuthentikProvisionService::CORREO_PRINCIPAL, 'uid-practicantesistemas', 'practicantesistemas');
        $this->get(route('auth.authentik.callback'))->assertRedirect();
        $this->assertAuthenticatedAs($principal);
    }

    public function test_acepta_correo_externo_y_no_lo_marca_verificado_sin_authentik(): void
    {
        $this->fake->emailConfigured = false;
        $this->actingAs($this->admin())->post(route('usuarios.store'), [
            'name' => 'Auxiliar Hotmail',
            'email' => 'auxiliar.piloto@example.test',
            'documento' => 'auxiliar.hotmail',
            'role' => 'auxiliar_pqrsd',
        ])->assertRedirect()->assertSessionDoesntHaveErrors();

        $user = User::where('email', 'auxiliar.piloto@example.test')->firstOrFail();
        $this->assertFalse($user->activo);
        $this->assertSame('invitation_prepared', $user->authentik_status);
        $this->assertNull($user->email_verified_at);
        $this->assertCount(1, $this->fake->invitationsCreated);

        $this->post(route('logout'));
        $this->fake->groupMembers[] = [
            'pk' => '71',
            'uid' => 'sub-hotmail',
            'email' => 'auxiliar.piloto@example.test',
            'username' => $user->documento,
            'active' => true,
        ];
        $this->oidc('auxiliar.piloto@example.test', 'sub-hotmail', $user->documento, false);
        $this->get(route('auth.authentik.callback'))->assertRedirect();

        $user->refresh();
        $this->assertTrue($user->activo);
        $this->assertSame('auxiliar_pqrsd', $user->roles->first()?->name);
        $this->assertFalse($user->hasRole('admin'));
        $this->assertNull($user->email_verified_at);
        $this->assertSame('unverified', $user->authentik_invitation_delivery);
        $this->assertNull($user->authentik_invitation_url);
    }

    public function test_reclamacion_de_correo_verificado_no_marca_el_correo(): void
    {
        $this->actingAs($this->admin())->post(route('usuarios.store'), [
            'name' => 'Correo Externo',
            'email' => 'persona.externa@example.test',
            'role' => 'auxiliar_pqrsd',
        ]);
        $user = User::where('email', 'persona.externa@example.test')->firstOrFail();

        $this->post(route('logout'));
        $this->fake->groupMembers[] = [
            'pk' => '72',
            'uid' => 'sub-yahoo',
            'email' => 'persona.externa@example.test',
            'username' => $user->documento,
            'active' => true,
        ];
        $this->oidc('persona.externa@example.test', 'sub-yahoo', $user->documento, true);
        $this->get(route('auth.authentik.callback'))->assertRedirect();

        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertCount(0, $this->fake->emailsSent);
    }

    public function test_otra_identidad_no_toma_la_cuenta_autorizada(): void
    {
        $this->actingAs($this->admin())->post(route('usuarios.store'), [
            'name' => 'Cuenta Autorizada',
            'email' => 'autorizada@example.test',
            'role' => 'auxiliar_pqrsd',
        ]);

        $this->post(route('logout'));
        $this->oidc('autorizada@example.test', 'sub-ajeno', 'otra.persona');
        $this->get(route('auth.authentik.callback'))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertNull(User::where('email', 'autorizada@example.test')->firstOrFail()->authentik_sub);
    }

    public function test_revocar_elimina_el_enlace_y_la_autorizacion(): void
    {
        $this->actingAs($this->admin())->post(route('usuarios.store'), [
            'name' => 'Para Revocar',
            'email' => 'revocar@example.test',
            'role' => 'auxiliar_pqrsd',
        ]);
        $user = User::where('email', 'revocar@example.test')->firstOrFail();

        $this->actingAs($this->admin())
            ->post(route('usuarios.revocar', $user))
            ->assertRedirect()
            ->assertSessionHas('success', 'La invitación fue revocada correctamente.');

        $user->refresh();
        $this->assertSame('invitation_revoked', $user->authentik_status);
        $this->assertNull($user->authentik_invitation_url);
        $this->assertNull($user->authentik_authorization_hash);
        $this->assertCount(1, $this->fake->deletedInvitations);
        $this->assertSame('invitation_revoked', $user->auditoriasUsuario()->latest('id')->value('accion'));

        $vigente = User::factory()->create([
            'name' => 'Modal Revocacion',
            'email' => 'modal.revocacion@example.test',
            'documento' => 'modal.revocacion',
            'activo' => false,
            'authentik_status' => 'invitation_prepared',
            'authentik_invitation_pk' => 'inv-modal',
            'authentik_invitation_url' => 'https://auth.example.test/if/flow/pqrsd-invitation-enrollment/?itoken=secreto-modal',
            'authentik_invitation_expires_at' => now()->addDay(),
        ]);
        $pagina = $this->actingAs($this->admin())->get(route('usuarios.show', $vigente));
        $pagina->assertSee('¿Revocar invitación?', false)
            ->assertSee('Estás a punto de revocar la invitación privada de Modal Revocacion', false)
            ->assertSee('Cancelar', false)
            ->assertSee('btn-red', false)
            ->assertDontSee('¿Revocar esta invitación?', false)
            ->assertDontSee('window.confirm', false);

        $this->post(route('logout'));
        $this->oidc('revocar@example.test', 'sub-revocado', 'revocar');
        $this->get(route('auth.authentik.callback'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_enlace_nuevo_solo_si_vencio_y_no_hay_identidad(): void
    {
        $this->actingAs($this->admin())->post(route('usuarios.store'), [
            'name' => 'Reponer',
            'email' => 'reponer@example.test',
            'role' => 'auxiliar_pqrsd',
        ]);
        $user = User::where('email', 'reponer@example.test')->firstOrFail();
        $user->update(['authentik_invitation_expires_at' => now()->subHour()]);

        $this->actingAs($this->admin())
            ->post(route('usuarios.reinvitar', $user))
            ->assertRedirect();

        $this->assertCount(2, $this->fake->invitationsCreated);
        $this->assertCount(0, $this->fake->emailsSent);
    }

    public function test_no_reemplaza_invitacion_si_la_identidad_ya_existe(): void
    {
        $this->actingAs($this->admin())->post(route('usuarios.store'), [
            'name' => 'Eduar',
            'email' => 'persona.invitada@example.test',
            'documento' => 'persona.invitada',
            'role' => 'auxiliar_pqrsd',
        ]);
        $user = User::where('email', 'persona.invitada@example.test')->firstOrFail();
        $user->update(['authentik_invitation_expires_at' => now()->subHour()]);
        $this->fake->identities[] = [
            'pk' => '91',
            'uid' => 'uid-invitada',
            'email' => 'persona.invitada@example.test',
            'username' => 'eduar',
        ];
        $this->fake->usersByEmail['persona.invitada@example.test'] = '91';

        $this->actingAs($this->admin())
            ->post(route('usuarios.reinvitar', $user))
            ->assertRedirect();

        $this->assertCount(1, $this->fake->invitationsCreated);
        $this->assertNull($user->fresh()->authentik_sub);
    }

    public function test_recuperacion_exige_comprobacion_y_no_crea_otra_cuenta(): void
    {
        $user = User::factory()->create([
            'name' => 'Eduar',
            'email' => 'persona.invitada@example.test',
            'documento' => 'persona.invitada',
            'activo' => false,
            'authentik_status' => 'invitation_prepared',
        ]);
        $user->assignRole('auxiliar_pqrsd');
        $this->fake->identities[] = [
            'pk' => '91',
            'uid' => 'uid-invitada',
            'email' => 'persona.invitada@example.test',
            'username' => 'persona.invitada',
        ];

        $this->actingAs($this->admin())
            ->post(route('usuarios.recuperar', $user), [])
            ->assertSessionHasErrors('identidad_verificada');
        $this->assertCount(0, $this->fake->recoveryLinks);
        $this->assertCount(0, $this->fake->invitationsCreated);

        config(['authentik.provisioning_enabled' => false]);
        $this->actingAs($this->admin())
            ->post(route('usuarios.recuperar', $user), ['identidad_verificada' => '1'])
            ->assertRedirect();

        $user->refresh();
        $this->assertSame('91', $user->authentik_user_pk);
        $this->assertNull($user->authentik_sub);
        $this->assertNull($user->email_verified_at);
        $this->assertFalse($user->activo);
        $this->assertSame('recovery', $user->authentik_invitation_delivery);
        $this->assertSame([['91', true]], $this->fake->activated);
        $this->assertCount(1, $this->fake->recoveryLinks);
        $this->assertCount(0, $this->fake->emailsSent);
        $this->assertDatabaseHas('usuario_auditorias', [
            'user_id' => $user->id,
            'accion' => 'identidad_comprobada_por_administrador',
        ]);
    }

    public function test_403_no_activa_y_autoriza_la_identidad_existente(): void
    {
        $user = User::factory()->create([
            'name' => 'Eduar',
            'email' => 'persona.invitada@example.test',
            'documento' => 'persona.invitada',
            'activo' => false,
            'authentik_status' => 'invitation_prepared',
            'authentik_invitation_expires_at' => now()->subDay(),
        ]);
        $user->assignRole('auxiliar_pqrsd');
        $this->fake->identities[] = [
            'pk' => '44',
            'uid' => 'uid-invitada',
            'email' => 'persona.invitada@example.test',
            'username' => 'persona.invitada',
            'active' => true,
        ];
        $this->fake->groupMembers[] = [
            'pk' => '44',
            'uid' => 'uid-invitada',
            'email' => 'persona.invitada@example.test',
            'username' => 'persona.invitada',
            'active' => true,
        ];
        $this->fake->failRecovery = true;

        $this->actingAs($this->admin())
            ->from(route('usuarios.show', $user))
            ->post(route('usuarios.recuperar', $user), ['identidad_verificada' => '1'])
            ->assertRedirect(route('usuarios.show', $user))
            ->assertSessionHas('error');
        $pagina = $this->actingAs($this->admin())->get(route('usuarios.show', $user));
        $pagina->assertOk();
        $pagina->assertSee('Authentik negó el enlace por la API', false);
        $pagina->assertDontSee('alert-success', false);
        $this->assertSame(1, substr_count($pagina->getContent(), 'Authentik negó el enlace por la API'));
        $this->assertSame([], $this->fake->activated);
        $this->assertCount(0, $this->fake->recoveryLinks);
        $this->assertCount(0, $this->fake->invitationsCreated);

        $user->refresh();
        $this->assertSame('44', $user->authentik_user_pk);
        $this->assertFalse($user->activo);
        $this->assertNull($user->email_verified_at);
        $this->assertNull($user->authentik_sub);
        $this->assertSame('recovery_admin', $user->authentik_invitation_delivery);

        $this->post(route('logout'));
        $this->fake->usersBySub['uid-invitada'] = '44';
        $this->oidc('persona.invitada@example.test', 'uid-invitada', 'persona.invitada');
        $this->get(route('auth.authentik.callback'))->assertRedirect();
        $user->refresh();
        $this->assertTrue($user->activo);
        $this->assertSame('uid-invitada', $user->authentik_sub);
        $this->assertSame('auxiliar_pqrsd', $user->roles->first()?->name);
        $this->assertNull($user->email_verified_at);
    }

    public function test_una_identidad_autorizada_no_toma_otra_cuenta(): void
    {
        $otro = User::factory()->create([
            'email' => 'otra.recuperacion@example.test',
            'documento' => 'otra.persona',
            'activo' => false,
            'authentik_status' => 'invitation_prepared',
            'authentik_user_pk' => '44',
            'authentik_authorization_hash' => hash('sha256', 'otra'),
        ]);
        $this->fake->usersBySub['uid-ajena'] = '99';
        $this->oidc('otra.recuperacion@example.test', 'uid-ajena', 'otra.persona');
        $this->get(route('auth.authentik.callback'))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertNull($otro->fresh()->authentik_sub);
    }

    public function test_ficha_muestra_copia_y_el_listado_no_muestra_el_enlace(): void
    {
        $this->actingAs($this->admin())->post(route('usuarios.store'), [
            'name' => 'Visible',
            'email' => 'visible@example.test',
            'role' => 'auxiliar_pqrsd',
        ]);
        $user = User::where('email', 'visible@example.test')->firstOrFail();

        $this->actingAs($this->admin())
            ->get(route('usuarios.show', $user))
            ->assertSee('Copiar enlace de invitación')
            ->assertSee('No verificado')
            ->assertSee('itoken=', false);

        $this->actingAs($this->auxiliar())
            ->get(route('usuarios.show', $user))
            ->assertForbidden();

        $this->actingAs($this->admin())
            ->get(route('usuarios.index'))
            ->assertDontSee('itoken=', false);

        $this->get('/register')->assertNotFound();
    }

    public function test_formulario_no_pide_identificador_y_lo_genera(): void
    {
        $this->actingAs($this->admin())
            ->get(route('usuarios.create'))
            ->assertOk()
            ->assertDontSee('Identificador de usuario')
            ->assertSee('Nombre completo')
            ->assertSee('Correo electrónico')
            ->assertSee('Rol PQRSD');

        $this->actingAs($this->admin())->post(route('usuarios.store'), [
            'name' => 'Juan Pérez Prueba',
            'email' => 'persona.prueba@example.test',
            'documento' => '000000',
            'role' => 'auxiliar_pqrsd',
        ])->assertRedirect()
            ->assertSessionHas('success', 'Funcionario registrado. La invitación privada está lista para copiar. No se envía correo.');

        $user = User::where('email', 'persona.prueba@example.test')->firstOrFail();
        $this->assertSame('juan.perez.prueba', $user->documento);
        $this->assertNull($user->email_verified_at);
        $this->assertSame('auxiliar_pqrsd', $user->roles->first()?->name);
        $this->assertSame('juan.perez.prueba', $this->fake->invitationsCreated[0]['fixed_data']['username']);

        $pagina = $this->actingAs($this->admin())->get(route('usuarios.show', $user));
        $this->assertSame(1, substr_count($pagina->getContent(), 'Funcionario registrado. La invitación privada está lista para copiar.'));
    }

    public function test_reanudar_con_aprovisionamiento_apagado_crea_una_sola_invitacion(): void
    {
        config(['authentik.provisioning_enabled' => false]);
        $this->actingAs($this->admin())->post(route('usuarios.store'), [
            'name' => 'Juan Pérez Prueba',
            'email' => 'persona.prueba@example.test',
            'role' => 'auxiliar_pqrsd',
        ])->assertSessionHas('success', 'Funcionario registrado. Pendiente de generar invitación de Authentik.');

        $user = User::where('email', 'persona.prueba@example.test')->firstOrFail();
        $this->assertSame('pending_invitation', $user->authentik_status);
        $this->assertFalse((bool) config('authentik.provisioning_enabled'));

        $this->actingAs($this->admin())->post(route('usuarios.emitir', $user))->assertRedirect();
        $this->actingAs($this->admin())->post(route('usuarios.emitir', $user))->assertRedirect();

        $this->assertCount(1, $this->fake->invitationsCreated);
        $this->assertCount(0, $this->fake->emailsSent);
        $this->assertSame('invitation_prepared', $user->fresh()->authentik_status);
        $this->assertSame('Invitación disponible', $user->fresh()->etiquetaEstadoAuthentik());
        $this->assertFalse((bool) config('authentik.provisioning_enabled'));
    }

    public function test_una_invitacion_en_curso_no_se_duplica(): void
    {
        config(['authentik.provisioning_enabled' => false]);
        $user = User::factory()->create([
            'name' => 'Persona Concurrente',
            'email' => 'persona.concurrente@example.test',
            'documento' => 'persona.concurrente',
            'activo' => false,
            'authentik_status' => 'pending_invitation',
        ]);
        $user->assignRole('auxiliar_pqrsd');
        $bloqueo = Cache::lock('pqrsd-invitacion-'.$user->id, 20);
        $this->assertTrue($bloqueo->get());

        $this->actingAs($this->admin())->post(route('usuarios.emitir', $user))->assertRedirect();
        $this->assertCount(0, $this->fake->invitationsCreated);

        $bloqueo->release();
        $this->actingAs($this->admin())->post(route('usuarios.emitir', $user))->assertRedirect();
        $this->assertCount(1, $this->fake->invitationsCreated);
        $this->assertTrue($this->fake->invitationsCreated[0]['single_use']);
        $this->assertFalse((bool) config('authentik.provisioning_enabled'));
    }

    public function test_auxiliar_entra_a_bandeja_y_no_a_usuarios(): void
    {
        $this->actingAs($this->auxiliar())
            ->get(route('pqrsd.index'))
            ->assertOk();
        $this->actingAs($this->auxiliar())
            ->get(route('usuarios.index'))
            ->assertForbidden();
        $this->actingAs($this->auxiliar())
            ->get(route('usuarios.create'))
            ->assertForbidden();
    }

    public function test_identidad_existente_no_se_vincula_sin_autorizacion_explicita(): void
    {
        config(['authentik.provisioning_enabled' => false]);
        $user = User::factory()->create([
            'name' => 'Juan Pérez Prueba',
            'email' => 'persona.prueba@example.test',
            'documento' => 'juan.perez.prueba',
            'activo' => false,
            'authentik_status' => 'invitation_failed',
            'authentik_last_error' => 'POST /api/v3/stages/invitation/invitations/ falló (HTTP 403).',
        ]);
        $user->assignRole('auxiliar_pqrsd');
        $this->fake->identities[] = [
            'pk' => '55',
            'uid' => 'uid-juan',
            'email' => 'persona.prueba@example.test',
            'username' => 'juan.perez.prueba',
            'active' => true,
        ];
        $this->fake->groupMembers[] = $this->fake->identities[0];

        $this->oidc('persona.prueba@example.test', 'uid-juan', 'juan.perez.prueba');
        $this->get(route('auth.authentik.callback'))->assertRedirect(route('login'));
        $this->assertNull($user->fresh()->authentik_sub);

        $this->actingAs($this->admin())
            ->get(route('usuarios.show', $user))
            ->assertSee('Pendiente de autorización')
            ->assertSee('Autorizar vinculación')
            ->assertDontSee('Error de aprovisionamiento')
            ->assertDontSee('Generar invitación');

        $this->actingAs($this->admin())
            ->post(route('usuarios.vincular', $user), [])
            ->assertSessionHasErrors('identidad_verificada');
        $this->assertNull($user->fresh()->authentik_user_pk);
        $this->assertCount(0, $this->fake->invitationsCreated);

        $this->actingAs($this->admin())
            ->from(route('usuarios.show', $user))
            ->post(route('usuarios.vincular', $user), ['identidad_verificada' => '1'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $user->refresh();
        $this->assertSame('55', $user->authentik_user_pk);
        $this->assertSame('pending_link', $user->authentik_status);
        $this->assertFalse($user->activo);
        $this->assertNull($user->email_verified_at);
        $this->assertNull($user->authentik_sub);
        $this->assertCount(0, $this->fake->invitationsCreated);
        $this->assertFalse((bool) config('authentik.provisioning_enabled'));

        $this->post(route('logout'));
        $this->fake->usersBySub['uid-juan'] = '55';
        $this->oidc('persona.prueba@example.test', 'uid-juan', 'juan.perez.prueba');
        $this->get(route('auth.authentik.callback'))->assertRedirect();
        $user->refresh();
        $this->assertTrue($user->activo);
        $this->assertSame('uid-juan', $user->authentik_sub);
        $this->assertSame('auxiliar_pqrsd', $user->roles->first()?->name);
        $this->assertNull($user->email_verified_at);
    }

    public function test_invitacion_ausente_no_se_muestra_como_error_ni_se_reemite(): void
    {
        config(['authentik.provisioning_enabled' => false]);
        $this->fake->invitationExists = false;
        $user = User::factory()->create([
            'email' => 'persona.prueba@example.test',
            'documento' => 'juan.perez.prueba',
            'activo' => false,
            'authentik_status' => 'invitation_prepared',
            'authentik_invitation_pk' => 'token-viejo',
            'authentik_invitation_url' => 'https://auth.example.test/if/flow/pqrsd-invitation-enrollment/?itoken=token-viejo',
            'authentik_authorization_hash' => hash('sha256', 'token-viejo'),
            'authentik_invitation_delivery' => 'private',
            'authentik_invitation_expires_at' => now()->addDay(),
        ]);

        $this->actingAs($this->admin())
            ->get(route('usuarios.show', $user))
            ->assertSee('Invitación ausente en Authentik')
            ->assertDontSee('Error de aprovisionamiento')
            ->assertDontSee('Generar invitación')
            ->assertDontSee('token-viejo');

        $user->refresh();
        $this->assertNull($user->authentik_invitation_pk);
        $this->assertNull($user->authentik_invitation_url);
        $this->assertSame('ausente', $user->authentik_invitation_delivery);
        $this->assertNotNull($user->authentik_authorization_hash);
        $this->assertFalse($user->activo);
        $this->assertCount(0, $this->fake->invitationsCreated);
        $this->assertFalse((bool) config('authentik.provisioning_enabled'));
    }

    public function test_otra_identidad_no_usa_la_autorizacion_de_juan(): void
    {
        $user = User::factory()->create([
            'email' => 'persona.prueba@example.test',
            'documento' => 'juan.perez.prueba',
            'activo' => false,
            'authentik_status' => 'pending_link',
            'authentik_user_pk' => '55',
            'authentik_authorization_hash' => hash('sha256', 'vinculacion|10|55'),
        ]);
        $user->assignRole('auxiliar_pqrsd');
        $this->fake->usersBySub['uid-ajeno'] = '99';

        $this->oidc('persona.prueba@example.test', 'uid-ajeno', 'juan.perez.prueba');
        $this->get(route('auth.authentik.callback'))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertNull($user->fresh()->authentik_sub);
    }

    public function test_miembro_de_grupo_no_visible_en_el_listado_puede_autorizarse(): void
    {
        config(['authentik.provisioning_enabled' => false]);
        $user = User::factory()->create([
            'name' => 'Juan Pérez Prueba',
            'email' => 'persona.prueba@example.test',
            'documento' => 'juan.perez.prueba',
            'activo' => false,
            'authentik_status' => 'invitation_prepared',
            'authentik_invitation_delivery' => 'ausente',
            'authentik_authorization_hash' => hash('sha256', 'invitacion-consumida'),
            'authentik_invitation_expires_at' => now()->addDay(),
        ]);
        $user->assignRole('auxiliar_pqrsd');
        $this->fake->groupMembers[] = [
            'pk' => '45',
            'uid' => 'uid-juan-real',
            'email' => 'persona.prueba@example.test',
            'username' => 'juan.perez.prueba',
            'active' => true,
        ];

        $this->actingAs($this->admin())
            ->get(route('usuarios.show', $user))
            ->assertSee('Autorizar vinculación')
            ->assertDontSee('no demuestra que la persona no exista');

        $this->post(route('logout'));
        $this->oidc('persona.prueba@example.test', 'uid-juan-real', 'juan.perez.prueba');
        $this->get(route('auth.authentik.callback'))->assertRedirect(route('login'));
        $user->refresh();
        $this->assertFalse($user->activo);
        $this->assertNull($user->authentik_sub);
        $this->assertNull($user->authentik_user_pk);
        $this->assertCount(0, $this->fake->invitationsCreated);
        $this->assertFalse((bool) config('authentik.provisioning_enabled'));
    }

    public function test_primer_ingreso_no_trata_el_pk_como_sub(): void
    {
        config(['authentik.provisioning_enabled' => false]);
        $user = User::factory()->create([
            'email' => 'persona.prueba@example.test',
            'documento' => 'juan.perez.prueba',
            'activo' => false,
            'authentik_status' => 'invitation_prepared',
            'authentik_invitation_delivery' => 'ausente',
            'authentik_authorization_hash' => hash('sha256', 'invitacion-consumida'),
        ]);
        $user->assignRole('auxiliar_pqrsd');
        $this->fake->groupMembers[] = [
            'pk' => '45',
            'uid' => 'uid-juan-real',
            'email' => 'persona.prueba@example.test',
            'username' => 'juan.perez.prueba',
            'active' => true,
        ];

        $this->actingAs($this->admin())
            ->post(route('usuarios.vincular', $user), ['identidad_verificada' => '1'])
            ->assertRedirect()
            ->assertSessionHas('success');
        $this->assertSame('45', $user->fresh()->authentik_user_pk);
        $this->assertNull($user->fresh()->authentik_sub);
        $this->assertFalse($user->fresh()->activo);
        $this->assertCount(0, $this->fake->invitationsCreated);

        $this->post(route('logout'));
        $this->oidc('persona.prueba@example.test', '45', 'juan.perez.prueba');
        $this->get(route('auth.authentik.callback'))->assertRedirect(route('login'));
        $this->assertNull($user->fresh()->authentik_sub);
        $this->assertFalse($user->fresh()->activo);
    }

    public function test_primer_ingreso_guarda_el_sub_real_y_el_rol_de_auxiliar(): void
    {
        $user = User::factory()->create([
            'email' => 'persona.prueba@example.test',
            'documento' => 'juan.perez.prueba',
            'activo' => false,
            'authentik_status' => 'pending_link',
            'authentik_user_pk' => '45',
            'authentik_authorization_hash' => hash('sha256', 'vinculacion|10|45'),
            'authentik_invitation_delivery' => 'vinculacion_autorizada',
        ]);
        $user->assignRole('auxiliar_pqrsd');
        $this->fake->groupMembers[] = [
            'pk' => '45',
            'uid' => 'uid-juan-real',
            'email' => 'persona.prueba@example.test',
            'username' => 'juan.perez.prueba',
            'active' => true,
        ];

        $this->oidc('persona.prueba@example.test', 'uid-juan-real', 'juan.perez.prueba');
        $this->get(route('auth.authentik.callback'))->assertRedirect();
        $user->refresh();
        $this->assertTrue($user->activo);
        $this->assertSame('uid-juan-real', $user->authentik_sub);
        $this->assertNotSame('45', $user->authentik_sub);
        $this->assertSame('45', $user->authentik_user_pk);
        $this->assertSame('auxiliar_pqrsd', $user->roles->first()?->name);
        $this->assertNull($user->email_verified_at);
        $this->assertSame(
            'https://auth.example.com/application/o/pqrsd-comite/',
            $user->authentik_issuer,
        );
    }

    public function test_grupo_403_identidad_duplicada_o_ajena_no_muestra_autorizacion(): void
    {
        $user = User::factory()->create([
            'email' => 'persona.prueba@example.test',
            'documento' => 'juan.perez.prueba',
            'activo' => false,
            'authentik_status' => 'invitation_prepared',
            'authentik_invitation_delivery' => 'ausente',
        ]);
        $this->fake->groupHttpStatus = 403;

        $this->actingAs($this->admin())
            ->get(route('usuarios.show', $user))
            ->assertDontSee('Autorizar vinculación')
            ->assertSee('no demuestra que la persona no exista');

        $this->fake->groupHttpStatus = null;
        $this->fake->groupMembers = [
            [
                'pk' => '45',
                'uid' => 'uid-juan-real',
                'email' => 'persona.prueba@example.test',
                'username' => 'juan.perez.prueba',
                'active' => true,
            ],
            [
                'pk' => '46',
                'uid' => 'uid-otro',
                'email' => 'persona.prueba@example.test',
                'username' => 'juan.perez.prueba',
                'active' => true,
            ],
        ];
        $this->actingAs($this->admin())
            ->get(route('usuarios.show', $user))
            ->assertDontSee('Autorizar vinculación');

        $this->fake->groupMembers = [[
            'pk' => '45',
            'uid' => 'uid-juan-real',
            'email' => 'persona.prueba@example.test',
            'username' => 'juan.perez.prueba',
            'active' => true,
        ]];
        User::factory()->create([
            'email' => 'otro.funcionario@example.test',
            'authentik_user_pk' => '45',
        ]);
        $this->actingAs($this->admin())
            ->get(route('usuarios.show', $user))
            ->assertDontSee('Autorizar vinculación');
        $this->assertCount(0, $this->fake->invitationsCreated);
    }

    public function test_consulta_insuficiente_no_crea_invitacion(): void
    {
        config(['authentik.provisioning_enabled' => true]);
        $this->fake->groupHttpStatus = 403;

        $this->actingAs($this->admin())->post(route('usuarios.store'), [
            'name' => 'Persona Nueva',
            'email' => 'persona.nueva@example.test',
            'role' => 'auxiliar_pqrsd',
        ])->assertRedirect();

        $user = User::where('email', 'persona.nueva@example.test')->firstOrFail();
        $this->assertSame('revision_requerida', $user->authentik_status);
        $this->assertFalse($user->activo);
        $this->assertNull($user->authentik_invitation_url);
        $this->assertCount(0, $this->fake->invitationsCreated);
        $this->actingAs($this->admin())
            ->get(route('usuarios.show', $user))
            ->assertSee('Revisión administrativa')
            ->assertSee('No se pudo comprobar de forma segura si esta persona ya existe en Authentik. Se requiere revisión administrativa antes de generar una invitación.')
            ->assertDontSee('Error de aprovisionamiento');
    }

    public function test_directorio_incompleto_no_trata_un_vacio_como_persona_nueva(): void
    {
        config(['authentik.provisioning_enabled' => false]);
        $this->fake->lecturaGlobalUsuarios = false;

        $this->actingAs($this->admin())->post(route('usuarios.store'), [
            'name' => 'Persona Piloto',
            'email' => 'persona.piloto@example.test',
            'role' => 'auxiliar_pqrsd',
        ])->assertSessionHas('success');

        $user = User::where('email', 'persona.piloto@example.test')->firstOrFail();
        $this->assertSame('pending_invitation', $user->authentik_status);

        $this->actingAs($this->admin())->post(route('usuarios.emitir', $user))
            ->assertSessionHas('error', 'No se pudo comprobar de forma segura si esta persona ya existe en Authentik. Se requiere revisión administrativa antes de generar una invitación.');
        $this->actingAs($this->admin())->post(route('usuarios.emitir', $user));

        $user->refresh();
        $this->assertSame('revision_requerida', $user->authentik_status);
        $this->assertSame('Revisión administrativa', $user->etiquetaEstadoAuthentik());
        $this->assertNull($user->authentik_invitation_pk);
        $this->assertCount(0, $this->fake->invitationsCreated);
        $this->assertFalse((bool) config('authentik.provisioning_enabled'));
    }

    public function test_identidad_global_de_grupo_o_externa_no_genera_invitacion(): void
    {
        config(['authentik.provisioning_enabled' => true]);
        $this->fake->lecturaGlobalUsuarios = true;
        $this->fake->identities[] = [
            'pk' => '90',
            'uid' => 'uid-fuera',
            'email' => 'fuera.grupo@example.test',
            'username' => 'fuera.grupo',
            'active' => true,
        ];

        $this->actingAs($this->admin())->post(route('usuarios.store'), [
            'name' => 'Fuera Grupo',
            'email' => 'fuera.grupo@example.test',
            'role' => 'admin',
        ]);
        $this->assertCount(0, $this->fake->invitationsCreated);

        $this->fake->identities = [];
        $this->fake->lecturaGlobalUsuarios = false;
        $this->fake->groupMembers[] = [
            'pk' => '91',
            'uid' => 'uid-solo-grupo',
            'email' => 'solo.grupo@example.test',
            'username' => 'solo.grupo',
            'active' => true,
        ];
        $this->actingAs($this->admin())->post(route('usuarios.store'), [
            'name' => 'Solo Grupo',
            'email' => 'solo.grupo@example.test',
            'role' => 'auxiliar_pqrsd',
        ]);
        $this->assertCount(0, $this->fake->invitationsCreated);

        $this->fake->groupMembers = [];
        $this->fake->directorioHttp = 403;
        $this->actingAs($this->admin())->post(route('usuarios.store'), [
            'name' => 'Consulta Prohibida',
            'email' => 'consulta.prohibida@example.test',
            'role' => 'auxiliar_pqrsd',
        ]);
        $this->fake->directorioHttp = 404;
        $this->actingAs($this->admin())->post(route('usuarios.store'), [
            'name' => 'Consulta Oculta',
            'email' => 'consulta.oculta@example.test',
            'role' => 'auxiliar_pqrsd',
        ]);

        $this->assertSame('revision_requerida', User::where('email', 'consulta.prohibida@example.test')->value('authentik_status'));
        $this->assertSame('revision_requerida', User::where('email', 'consulta.oculta@example.test')->value('authentik_status'));
        $this->assertCount(0, $this->fake->invitationsCreated);
    }

    public function test_sin_identidad_comprobada_el_primer_ingreso_no_activa(): void
    {
        $user = User::factory()->create([
            'email' => 'pendiente.oidc@example.test',
            'documento' => 'pendiente.oidc',
            'activo' => false,
            'authentik_status' => 'invitation_prepared',
            'authentik_authorization_hash' => hash('sha256', 'autorizada'),
            'authentik_invitation_pk' => 'inv-1',
            'authentik_invitation_expires_at' => now()->addDay(),
        ]);
        $user->assignRole('auxiliar_pqrsd');

        $this->oidc('pendiente.oidc@example.test', 'sub-pendiente', 'pendiente.oidc');
        $this->get(route('auth.authentik.callback'))->assertRedirect(route('login'));
        $this->assertFalse($user->fresh()->activo);
        $this->assertNull($user->fresh()->authentik_sub);
    }

    public function test_ficha_activa_no_muestra_invitacion_ni_acciones_de_alta(): void
    {
        $user = User::factory()->create([
            'email' => 'activo.ficha@example.test',
            'documento' => 'activo.ficha',
            'activo' => true,
            'authentik_status' => 'active',
            'authentik_sub' => 'uid-activo',
            'authentik_user_pk' => '80',
            'authentik_invitation_expires_at' => now()->addDay(),
            'last_login_at' => now(),
            'email_verified_at' => null,
        ]);
        $user->assignRole('admin');

        $this->actingAs($this->admin())
            ->get(route('usuarios.show', $user))
            ->assertSee('Activo')
            ->assertSee('No verificado')
            ->assertDontSee('Vence invitación')
            ->assertDontSee('Copiar enlace de invitación')
            ->assertDontSee('Generar invitación')
            ->assertDontSee('Autorizar vinculación')
            ->assertDontSee('Generar enlace de recuperación');

        $this->actingAs($user)->get(route('usuarios.index'))->assertOk();
        $this->actingAs($this->auxiliar())->get(route('usuarios.index'))->assertForbidden();

        $this->actingAs($this->admin())->post(route('usuarios.desactivar', $user));
        $this->assertFalse($user->fresh()->activo);
    }

    public function test_revocacion_rechazada_o_incierta_no_borra_el_enlace_ni_habilita_otra(): void
    {
        $rechazada = $this->invitacionLocal('rechazada.revocacion@example.test');
        $this->fake->deleteHttpStatus = 403;

        $this->actingAs($this->admin())
            ->post(route('usuarios.revocar', $rechazada))
            ->assertRedirect()
            ->assertSessionHas('error', 'No se pudo revocar la invitación. Authentik rechazó la operación.')
            ->assertSessionMissing('success');

        $rechazada->refresh();
        $this->assertSame('invitation_prepared', $rechazada->authentik_status);
        $this->assertNotNull($rechazada->authentik_invitation_url);
        $this->assertSame('invitation_revoke_failed', $rechazada->auditoriasUsuario()->latest('id')->value('accion'));
        $this->assertStringNotContainsString('itoken', (string) json_encode($rechazada->auditoriasUsuario()->latest('id')->first()?->detalle));
        $this->actingAs($this->admin())
            ->get(route('usuarios.show', $rechazada))
            ->assertDontSee('Generar invitación')
            ->assertDontSee('La invitación fue revocada correctamente');

        $ausente = $this->invitacionLocal('ausente.revocacion@example.test');
        $this->fake->deleteHttpStatus = 404;
        $this->actingAs($this->admin())
            ->post(route('usuarios.revocar', $ausente))
            ->assertSessionHas('error', 'No se pudo confirmar el estado de la invitación. Se requiere revisión administrativa.');
        $ausente->refresh();
        $this->assertSame('revocation_unverified', $ausente->authentik_status);
        $this->assertNotNull($ausente->authentik_invitation_pk);
        $this->actingAs($this->admin())->post(route('usuarios.emitir', $ausente));
        $this->assertCount(0, $this->fake->invitationsCreated);

        $caida = $this->invitacionLocal('caida.revocacion@example.test');
        $this->fake->failDeleteConnection = true;
        $this->actingAs($this->admin())->post(route('usuarios.revocar', $caida));
        $this->assertSame('revocation_unverified', $caida->fresh()->authentik_status);
        $this->assertNotNull($caida->fresh()->authentik_invitation_url);

        $doble = $this->invitacionLocal('doble.revocacion@example.test');
        $this->actingAs($this->admin())->post(route('usuarios.revocar', $doble));
        $this->actingAs($this->admin())->post(route('usuarios.revocar', $doble));
        $this->assertCount(1, $this->fake->deletedInvitations);
        $this->assertSame('invitation_revoked', $doble->fresh()->authentik_status);

        $bloqueo = \Illuminate\Support\Facades\Cache::lock('pqrsd-revocar-'.$doble->id, 20);
        $this->assertTrue($bloqueo->get());
        $otra = $this->invitacionLocal('bloqueo.revocacion@example.test');
        $bloqueoAjeno = \Illuminate\Support\Facades\Cache::lock('pqrsd-revocar-'.$otra->id, 20);
        $this->assertTrue($bloqueoAjeno->get());
        $this->actingAs($this->admin())->post(route('usuarios.revocar', $otra));
        $this->assertSame('invitation_prepared', $otra->fresh()->authentik_status);
        $this->assertCount(1, $this->fake->deletedInvitations);
        $bloqueoAjeno->release();
        $bloqueo->release();

        $css = file_get_contents(public_path('css/pqrsd-theme.css'));
        $this->assertIsString($css);
        $this->assertStringContainsString('html[data-theme="light"] .pqrsd-modal::backdrop', $css);
        $this->assertStringContainsString('background: var(--bg-card);', $css);
    }

    public function test_modal_navegacion_errores_y_bloqueo_de_cuenta(): void
    {
        $funcionario = User::factory()->create([
            'name' => 'Funcionario Modal',
            'email' => 'modal.funcionario@example.test',
            'documento' => 'modal.funcionario',
            'activo' => true,
            'authentik_status' => 'active',
            'authentik_sub' => 'sub-modal',
            'authentik_user_pk' => '81',
        ]);
        $funcionario->assignRole('auxiliar_pqrsd');

        $ficha = $this->actingAs($this->admin())->get(route('usuarios.show', $funcionario));
        $ficha->assertOk()
            ->assertSee('← Volver a usuarios', false)
            ->assertSee(route('usuarios.index'), false)
            ->assertSee('¿Desactivar funcionario?', false)
            ->assertSee('Estás a punto de desactivar el acceso de Funcionario Modal a PQRSD', false)
            ->assertSee('Desactivar funcionario', false)
            ->assertSee('data-pqrsd-confirmar', false)
            ->assertDontSee('confirm(', false)
            ->assertDontSee('history.back', false);

        $this->actingAs($this->admin())->get(route('usuarios.index'))
            ->assertOk()
            ->assertSee('← Volver a la bandeja', false);
        $this->actingAs($this->admin())->get(route('usuarios.create'))
            ->assertOk()
            ->assertSee('← Volver a usuarios', false);
        $this->actingAs($this->admin())->get(route('usuarios.edit', $funcionario))
            ->assertOk()
            ->assertSee('← Volver a usuarios', false)
            ->assertSee('Volver a la ficha', false)
            ->assertSee(route('usuarios.show', $funcionario), false);

        foreach ([
            'usuarios/show.blade.php',
            'usuarios/index.blade.php',
            'usuarios/form.blade.php',
            'pqrsd/show.blade.php',
            'publico/qr.blade.php',
            'publico/confirmacion.blade.php',
            'publico/formulario.blade.php',
        ] as $vista) {
            $contenido = file_get_contents(resource_path('views/'.$vista));
            $this->assertIsString($contenido);
            $this->assertDoesNotMatchRegularExpression('/\b(alert|confirm|prompt)\s*\(/', $contenido, $vista);
        }
        $formulario = $this->get(route('publico.formulario'))->assertOk();
        $qr = $this->get(route('publico.qr'))->assertOk();
        $formulario->assertSee('pqrsd-dialogo', false);
        $qr->assertSee('id="pqrsd-aviso"', false);
        $this->assertSame(1, substr_count($formulario->getContent(), 'id="pqrsd-aviso"'));
        $this->assertSame(1, substr_count($qr->getContent(), 'id="pqrsd-dialogo"'));
        $this->assertSame(1, substr_count($ficha->getContent(), 'id="pqrsd-aviso"'));

        $admin = $this->admin();
        $this->actingAs($admin)->post(route('usuarios.desactivar', $admin))
            ->assertSessionHasErrors('email');
        $this->assertTrue($admin->fresh()->activo);

        $this->fake->failGroupRemoval = true;
        $this->actingAs($admin)
            ->post(route('usuarios.desactivar', $funcionario))
            ->assertSessionHas('error', 'El acceso en PQRSD quedó bloqueado, pero Authentik no confirmó el retiro del grupo pqrsd-usuarios.')
            ->assertSessionMissing('success');
        $this->assertFalse($funcionario->fresh()->activo);
        $this->assertSame([], $this->fake->groupRemovals);
        $this->assertSame('group_revoke_failed', $funcionario->auditoriasUsuario()->latest('id')->value('accion'));

        $this->fake->failGroupAddition = true;
        $this->actingAs($admin)
            ->post(route('usuarios.reactivar', $funcionario))
            ->assertSessionHas('error', 'No fue posible reactivar el acceso. El estado del funcionario no se modificó.');
        $this->assertFalse($funcionario->fresh()->activo);
        $this->assertSame('deactivated', $funcionario->fresh()->authentik_status);

        $this->actingAs($admin)
            ->post(route('usuarios.reactivar', $funcionario))
            ->assertSessionHas('success', 'El funcionario fue reactivado correctamente.');
        $this->assertTrue($funcionario->fresh()->activo);
        $this->assertNotEmpty($this->fake->groupAdditions);

        $pagina = $this->actingAs($admin)->followingRedirects()->post(route('usuarios.desactivar', $funcionario));
        $pagina->assertSee('El funcionario fue desactivado correctamente');
        $this->assertSame(1, substr_count($pagina->getContent(), 'El funcionario fue desactivado correctamente'));

        $auxiliar = $this->auxiliar();
        $this->actingAs($auxiliar);
        $auxiliar->update(['activo' => false, 'authentik_status' => 'deactivated']);
        $this->get(route('pqrsd.index'))
            ->assertRedirect(route('login'));
        $this->assertGuest();

        $auxiliar->update(['activo' => true, 'authentik_status' => 'active']);
        $this->actingAs($auxiliar->fresh())->get(route('usuarios.index'))
            ->assertForbidden()
            ->assertSee('Acceso restringido')
            ->assertSee('No tienes permisos para acceder a esta sección.')
            ->assertSee('Volver a la bandeja')
            ->assertDontSee('User does not have the right permissions');

        $this->get('/ruta-que-no-existe-en-pqrsd')
            ->assertNotFound()
            ->assertSee('Página no encontrada')
            ->assertDontSee('exception');

        config(['app.debug' => false]);
        \Illuminate\Support\Facades\Route::get('/__sesion-vencida-prueba', fn () => abort(419));
        $this->get('/__sesion-vencida-prueba')
            ->assertStatus(419)
            ->assertSee('Sesión vencida')
            ->assertSee('Iniciar sesión');

        \Illuminate\Support\Facades\Route::get('/__error-interno-prueba', fn () => abort(500));
        $this->get('/__error-interno-prueba')
            ->assertStatus(500)
            ->assertSee('Error interno')
            ->assertDontSee('Stack trace');

        $ajena = $this->invitacionLocal('ajena.invitacion@example.test');
        $this->fake->invitationFichas[array_key_last($this->fake->invitationFichas)]['fixed_data']['email'] = 'otra.persona@example.test';
        $this->actingAs($this->admin())
            ->post(route('usuarios.revocar', $ajena))
            ->assertSessionHas('error', 'No se revocó la invitación porque no corresponde a este funcionario.');
        $this->assertSame('invitation_prepared', $ajena->fresh()->authentik_status);
        $this->assertNotContains('pk-ajena.invitacion@example.test', $this->fake->deletedInvitations);
    }

    private function invitacionLocal(string $email): User
    {
        $usuario = User::factory()->create([
            'name' => 'Invitacion Local',
            'email' => $email,
            'documento' => strstr($email, '@', true),
            'activo' => false,
            'authentik_status' => 'invitation_prepared',
            'authentik_invitation_pk' => 'pk-'.$email,
            'authentik_invitation_url' => 'https://auth.example.test/if/flow/pqrsd-invitation-enrollment/?itoken=secreto-'.$email,
            'authentik_authorization_hash' => hash('sha256', $email),
            'authentik_invitation_delivery' => 'private',
            'authentik_invitation_expires_at' => now()->addDay(),
        ]);
        $this->fake->invitationFichas[] = [
            'pk' => 'pk-'.$email,
            'flow' => '11111111-1111-1111-1111-111111111111',
            'fixed_data' => [
                'username' => (string) $usuario->documento,
                'email' => $email,
                'name' => 'Invitacion Local',
            ],
        ];

        return $usuario;
    }
}
