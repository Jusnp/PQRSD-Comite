<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get('/profile');

        $response->assertOk();
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_user_can_delete_their_account(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->delete('/profile', [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertNull($user->fresh());
    }

    public function test_correct_password_must_be_provided_to_delete_account(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->delete('/profile', [
                'password' => 'wrong-password',
            ]);

        $response
            ->assertSessionHasErrorsIn('userDeletion', 'password')
            ->assertRedirect('/profile');

        $this->assertNotNull($user->fresh());
    }

    public function test_funcionario_authentik_no_puede_eliminar_su_cuenta(): void
    {
        $user = User::factory()->create([
            'name' => 'Funcionario Perfil',
            'authentik_sub' => 'sub-perfil',
            'authentik_user_pk' => '90',
            'authentik_status' => 'active',
            'activo' => true,
        ]);

        $pagina = $this->actingAs($user)->get(route('profile.edit'));
        $pagina->assertOk()
            ->assertSee('Mi perfil')
            ->assertSee('Datos del funcionario')
            ->assertSee('Esta cuenta entra con Authentik')
            ->assertSee('no elimina la identidad de Authentik')
            ->assertDontSee('Eliminar cuenta')
            ->assertDontSee('name="password"', false)
            ->assertDontSee('Current Password')
            ->assertDontSee('Delete Account');

        $this->actingAs($user)
            ->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertForbidden();

        $user->refresh();
        $this->assertNotNull($user);
        $this->assertSame('sub-perfil', $user->authentik_sub);
        $this->assertSame('90', $user->authentik_user_pk);
        $this->assertTrue($user->activo);
    }
}
