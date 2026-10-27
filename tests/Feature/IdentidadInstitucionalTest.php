<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\UsersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IdentidadInstitucionalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(UsersSeeder::class);
    }

    public function test_configuracion_central_de_identidad(): void
    {
        $this->assertSame('Comité de Estudios Médicos', config('identidad.nombre_institucional'));
        $this->assertSame('Sistema PQRSD — Comité de Estudios Médicos', config('identidad.nombre_sistema'));
        $this->assertSame('Comité de Estudios Médicos', config('identidad.nombre_corto'));
        $this->assertStringContainsString('Comité de Estudios Médicos', (string) config('app.name'));
    }

    public function test_login_muestra_identidad_comite(): void
    {
        $this->assertFileExists(public_path('images/logo-comite-estudios-medicos.png'));
        $this->assertSame('images/logo-comite-estudios-medicos.png', config('identidad.logo_principal'));
        $this->assertSame('images/logo-comite-estudios-medicos.png', config('identidad.logo_header'));

        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Acceso PQRSD', false)
            ->assertSee('Personal autorizado — Comité de Estudios Médicos', false)
            ->assertSee('images/logo-comite-estudios-medicos.png', false)
            ->assertDontSee('brand-wordmark', false)
            ->assertDontSee('Mente Plena', false)
            ->assertDontSee('images/mente-plena-compact.png', false)
            ->assertDontSee('images/comite-estudios-medicos-header.png', false);
    }

    public function test_layouts_admin_publico_y_qr_usan_nueva_marca(): void
    {
        $admin = User::where('email', 'admin@menteplena.test')->firstOrFail();

        $this->actingAs($admin)
            ->get(route('pqrsd.index'))
            ->assertOk()
            ->assertSee('images/logo-comite-estudios-medicos.png', false)
            ->assertSee('alt="Comité de Estudios Médicos"', false)
            ->assertSee('Sistema PQRSD — Comité de Estudios Médicos', false)
            ->assertDontSee('brand-wordmark', false)
            ->assertDontSee('Mente Plena', false)
            ->assertDontSee('images/comite-estudios-medicos-header.png', false);

        $this->get(route('publico.formulario'))
            ->assertOk()
            ->assertSee('Formulario público PQRSD', false)
            ->assertSee('images/logo-comite-estudios-medicos.png', false)
            ->assertDontSee('Mente Plena', false);

        $this->get(route('publico.consulta'))
            ->assertOk()
            ->assertSee('images/logo-comite-estudios-medicos.png', false)
            ->assertDontSee('Mente Plena', false);

        $this->actingAs($admin)
            ->get(route('publico.qr'))
            ->assertOk()
            ->assertSee('Formulario PQRSD — Comité de Estudios Médicos', false)
            ->assertSee('images/logo-comite-estudios-medicos.png', false)
            ->assertDontSee('PQRSD Mente Plena', false);
    }

    public function test_permisos_operativos_intactos_tras_cambio_de_marca(): void
    {
        $aux = User::where('email', 'auxiliar1@menteplena.test')->firstOrFail();
        $this->assertTrue($aux->can('pqrsd.view_all'));
        $this->assertTrue($aux->can('pqrsd.update'));
        $this->assertFalse($aux->can('usuarios.manage'));

        $this->actingAs($aux)
            ->get(route('pqrsd.index'))
            ->assertOk()
            ->assertSee('Bandeja de PQRSD', false);
    }
}
