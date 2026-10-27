<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\CatalogosSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\UsersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NavegacionPublicaYQrOperadoresTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(CatalogosSeeder::class);
        $this->seed(UsersSeeder::class);
    }

    public function test_navegacion_publica_sin_boton_personal(): void
    {
        foreach ([route('publico.formulario'), route('publico.consulta')] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertSee('Radicar PQRSD')
                ->assertSee('Consultar radicado')
                ->assertDontSee('>Personal</a>', false)
                ->assertDontSee(route('login'));
        }

        $this->get(route('login'))->assertOk();
    }

    public function test_admin_y_auxiliares_ven_y_descargan_qr(): void
    {
        $operadores = [
            User::where('email', 'admin@menteplena.test')->firstOrFail(),
            User::where('email', 'auxiliar1@menteplena.test')->firstOrFail(),
            User::where('email', 'auxiliar2@menteplena.test')->firstOrFail(),
        ];

        foreach ($operadores as $user) {
            $this->actingAs($user)
                ->get(route('pqrsd.index'))
                ->assertOk()
                ->assertSee('Formulario público / Código QR');

            $page = $this->actingAs($user)->get(route('publico.qr'));
            $page->assertOk()
                ->assertSee('Vista previa')
                ->assertSee('Abrir formulario')
                ->assertSee('Copiar enlace')
                ->assertSee('Descargar QR en PNG')
                ->assertSee('Descargar QR en SVG')
                ->assertSee('Formulario PQRSD — Comité de Estudios Médicos')
                ->assertSee('solicitudes PQRSD dirigidas al Comité de Estudios Médicos');

            $svg = $this->actingAs($user)->get(route('publico.qr.download', ['format' => 'svg']));
            $svg->assertOk();
            $this->assertStringContainsString('image/svg+xml', (string) $svg->headers->get('Content-Type'));
            $this->assertFileExists(public_path('qrs/formulario-pqrsd.svg'));
            $this->assertStringContainsString('<svg', (string) file_get_contents(public_path('qrs/formulario-pqrsd.svg')));

            $png = $this->actingAs($user)->get(route('publico.qr.download', ['format' => 'png']));
            $png->assertOk();
            $this->assertStringContainsString('image/png', (string) $png->headers->get('Content-Type'));
            $this->assertFileExists(public_path('qrs/formulario-pqrsd.png'));
            $this->assertSame("\x89PNG", substr((string) file_get_contents(public_path('qrs/formulario-pqrsd.png')), 0, 4));
        }
    }
}
