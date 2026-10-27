<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\UsersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NavegacionBandejaPrincipalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(UsersSeeder::class);
    }

    public function test_admin_inicia_sesion_en_bandeja(): void
    {
        $admin = User::where('email', 'admin@menteplena.test')->firstOrFail();

        $this->actingAs($admin)
            ->get(route('pqrsd.index'))
            ->assertOk()
            ->assertSee('Bandeja de PQRSD', false)
            ->assertSee('images/logo-comite-estudios-medicos.png', false)
            ->assertSee('alt="Comité de Estudios Médicos"', false)
            ->assertDontSee('brand-wordmark', false)
            ->assertSee('id="pqrsd-theme-toggle"', false)
            ->assertDontSee('>Dashboard</a>', false)
            ->assertDontSee('Dashboard PQRSD', false);
    }

    public function test_auxiliar1_inicia_sesion_en_bandeja(): void
    {
        $auxiliar = User::where('email', 'auxiliar1@menteplena.test')->firstOrFail();

        $this->actingAs($auxiliar)
            ->get(route('pqrsd.index'))
            ->assertOk()
            ->assertSee('Bandeja de PQRSD', false)
            ->assertDontSee('>Dashboard</a>', false);
    }

    public function test_auxiliar2_inicia_sesion_en_bandeja(): void
    {
        $auxiliar = User::where('email', 'auxiliar2@menteplena.test')->firstOrFail();

        $this->actingAs($auxiliar)
            ->get(route('pqrsd.index'))
            ->assertOk()
            ->assertSee('Bandeja de PQRSD', false)
            ->assertDontSee('>Dashboard</a>', false);
    }

    public function test_ruta_historica_dashboard_redirige_a_bandeja(): void
    {
        $admin = User::where('email', 'admin@menteplena.test')->firstOrFail();

        $this->actingAs($admin)
            ->get('/dashboard')
            ->assertRedirect(route('pqrsd.index'));

        $this->actingAs($admin)
            ->get('/')
            ->assertRedirect(route('pqrsd.index'));
    }

    public function test_vencimientos_y_reportes_siguen_disponibles(): void
    {
        $auxiliar = User::where('email', 'auxiliar1@menteplena.test')->firstOrFail();

        $this->actingAs($auxiliar)->get(route('vencimientos.proximos'))->assertOk();
        $this->actingAs($auxiliar)->get(route('vencimientos.vencidos'))->assertOk();
        $this->actingAs($auxiliar)->get(route('vencimientos.vitales'))->assertOk();
        $this->actingAs($auxiliar)->get(route('reportes.index'))->assertOk()->assertSee('Reportes', false);

        $html = $this->actingAs($auxiliar)->get(route('pqrsd.index'))->assertOk()->getContent();
        $this->assertStringContainsString(route('vencimientos.proximos', absolute: false), $html);
        $this->assertStringContainsString(route('reportes.index', absolute: false), $html);
        $this->assertStringNotContainsString('Dashboard PQRSD', $html);
    }

    public function test_titulo_cambia_segun_seccion(): void
    {
        $admin = User::where('email', 'admin@menteplena.test')->firstOrFail();

        $this->actingAs($admin)
            ->get(route('pqrsd.index'))
            ->assertOk()
            ->assertSee('<h1>Bandeja de PQRSD</h1>', false);

        $this->actingAs($admin)
            ->get(route('pqrsd.create'))
            ->assertOk()
            ->assertSee('<h1>Nueva PQRSD</h1>', false);

        $this->actingAs($admin)
            ->get(route('reportes.index'))
            ->assertOk()
            ->assertSee('<h1>Reportes e indicadores</h1>', false);
    }
}
