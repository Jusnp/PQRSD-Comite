<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class OpsHealthPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_panel_ops_requiere_permiso_y_renderiza(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        Artisan::call('pqrsd:actualizar-cumplimiento');

        $ajeno = User::factory()->create(['activo' => true]);
        $ajeno->assignRole('responsable_area');
        $this->actingAs($ajeno)->get(route('ops.health'))->assertForbidden();

        $lider = User::factory()->create(['activo' => true]);
        $lider->assignRole('lider_pqrsd');
        $this->actingAs($lider)->get(route('ops.health'))
            ->assertOk()
            ->assertSee('Salud operativa');
    }

    public function test_gmail_pilot_status_bloqueado_sin_credenciales(): void
    {
        $code = Artisan::call('pqrsd:gmail-pilot-status');
        $this->assertSame(1, $code);
        $this->assertStringContainsString('BLOQUEADO', Artisan::output());
    }
}
