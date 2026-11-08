<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\CatalogosSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\UsersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HallazgosAuditoriaVisualTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(CatalogosSeeder::class);
        $this->seed(UsersSeeder::class);
    }

    public function test_los_indicadores_limitan_el_ancho_de_graficos_y_tablas(): void
    {
        $admin = User::query()->where('email', 'admin@menteplena.test')->firstOrFail();
        $html = $this->actingAs($admin)->get(route('reportes.indicadores'))->assertOk()->getContent();

        $this->assertStringContainsString('chart-frame', $html);
        $this->assertStringContainsString('id="chartBarras"', $html);
        $this->assertStringContainsString('id="chartLineas"', $html);
        $this->assertStringContainsString('class="table-wrap"', $html);
    }

    public function test_el_enlace_del_qr_puede_partirse_sin_cambiar_la_url(): void
    {
        $vista = file_get_contents(resource_path('views/publico/qr.blade.php'));
        $css = file_get_contents(public_path('css/pqrsd-app.css'));

        $this->assertIsString($vista);
        $this->assertIsString($css);
        $this->assertStringContainsString('class="qr-enlace"', $vista);
        $this->assertStringContainsString('{{ $url }}', $vista);
        $this->assertStringContainsString('#url-publico-qr', $css);
        $this->assertStringContainsString('overflow-wrap: anywhere', $css);
        $this->assertStringContainsString('.chart-frame', $css);

        $url = 'https://pqrsd.comitedeestudiosmedicos.com.co/publico/pqrsd?qr=1';
        view()->share('errors', new \Illuminate\Support\ViewErrorBag);
        $qr = view('publico.qr', [
            'url' => $url,
            'distributable' => true,
            'qrDisponible' => true,
            'urlSource' => 'PQRSD_PUBLIC_URL',
            'qrSvgAsset' => asset('qrs/formulario-pqrsd.svg'),
            'qrPngAsset' => asset('qrs/formulario-pqrsd.png'),
        ])->render();
        $this->assertStringContainsString($url, $qr);
    }

    public function test_el_menu_lateral_cierra_con_escape_y_administra_el_foco(): void
    {
        $admin = User::query()->where('email', 'admin@menteplena.test')->firstOrFail();
        $html = $this->actingAs($admin)->get(route('pqrsd.index'))->assertOk()->getContent();

        $this->assertStringContainsString('aria-expanded="false"', $html);
        $this->assertStringContainsString("event.key === 'Escape'", $html);
        $this->assertStringContainsString("setAttribute('inert'", $html);
        $this->assertStringContainsString('aria-modal', $html);
        $this->assertStringContainsString('getClientRects()', $html);
        $this->assertStringContainsString('ticket !== retornoPendiente', $html);
        $this->assertStringContainsString("nav.classList.contains('is-open')", $html);
    }
}
