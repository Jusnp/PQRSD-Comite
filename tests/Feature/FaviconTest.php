<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FaviconTest extends TestCase
{
    use RefreshDatabase;

    public function test_favicon_svg_es_publico_y_valido(): void
    {
        $path = public_path('favicon.svg');
        $this->assertFileExists($path);
        $this->assertFileDoesNotExist(public_path('favicon.ico'));

        $body = file_get_contents($path);
        $this->assertIsString($body);
        $this->assertStringContainsString('<svg', $body);
        $this->assertStringContainsString('xmlns="http://www.w3.org/2000/svg"', $body);
        $this->assertStringContainsString('aria-label="Comité de Estudios Médicos"', $body);
        $this->assertStringContainsString('<title>Comité de Estudios Médicos</title>', $body);
        $this->assertStringNotContainsString('PQRSD Mente Plena', $body);
        $this->assertStringNotContainsString('Mente Plena', $body);
    }

    public function test_layouts_principales_declaran_favicon_svg(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create(['activo' => true]);
        $user->assignRole('auxiliar_pqrsd');

        $needle = 'favicon.svg';

        $this->get(route('login'))
            ->assertOk()
            ->assertSee($needle, false)
            ->assertSee('type="image/svg+xml"', false);

        $this->get(route('publico.formulario'))
            ->assertOk()
            ->assertSee($needle, false);

        $this->actingAs($user)
            ->get(route('pqrsd.index'))
            ->assertOk()
            ->assertSee($needle, false);
    }
}
