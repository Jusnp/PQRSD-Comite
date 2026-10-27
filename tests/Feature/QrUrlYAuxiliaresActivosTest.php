<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\PublicUrl;
use Database\Seeders\CatalogosSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\UsersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class QrUrlYAuxiliaresActivosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(CatalogosSeeder::class);
        $this->seed(UsersSeeder::class);
    }

    public function test_url_formulario_usa_dev_url_configurada_y_coincide_con_qr(): void
    {
        config([
            'pqrsd_publico.base_url' => null,
            'pqrsd_publico.dev_url' => 'http://127.0.0.1:8000',
            'app.url' => 'http://pqrsd.test',
        ]);

        $expected = 'http://127.0.0.1:8000/publico/pqrsd?qr=1';
        $this->assertSame($expected, PublicUrl::formulario(true));
        $this->assertSame('PQRSD_DEV_URL', PublicUrl::source());
        $this->assertFalse(PublicUrl::isDistributable($expected));

        $this->get(route('publico.qr'))
            ->assertOk()
            ->assertSee($expected, false)
            ->assertSee('QR de prueba — No apto para distribuir a pacientes');

        $this->assertFileExists(public_path('qrs/formulario-pqrsd.url.txt'));
        $this->assertSame($expected, trim((string) file_get_contents(public_path('qrs/formulario-pqrsd.url.txt'))));

        $this->get(route('publico.qr.download', ['format' => 'svg']))->assertOk();
        $this->get(route('publico.qr.download', ['format' => 'png']))->assertOk();
        $this->assertSame("\x89PNG", substr((string) file_get_contents(public_path('qrs/formulario-pqrsd.png')), 0, 4));
        $this->assertStringContainsString('<svg', (string) file_get_contents(public_path('qrs/formulario-pqrsd.svg')));
    }

    public function test_qr_se_regenera_al_cambiar_la_url(): void
    {
        config([
            'pqrsd_publico.base_url' => null,
            'pqrsd_publico.dev_url' => 'http://127.0.0.1:8000',
            'app.url' => 'http://127.0.0.1:8000',
        ]);

        $this->get(route('publico.qr'))->assertOk();
        $first = trim((string) file_get_contents(public_path('qrs/formulario-pqrsd.url.txt')));
        $this->assertSame('http://127.0.0.1:8000/publico/pqrsd?qr=1', $first);

        config(['pqrsd_publico.dev_url' => 'http://127.0.0.1:8767']);
        $this->get(route('publico.qr'))->assertOk()->assertSee('http://127.0.0.1:8767/publico/pqrsd?qr=1', false);

        $second = trim((string) file_get_contents(public_path('qrs/formulario-pqrsd.url.txt')));
        $this->assertSame('http://127.0.0.1:8767/publico/pqrsd?qr=1', $second);
        $this->assertNotSame($first, $second);
    }

    public function test_auxiliares_operativos_inician_sesion_y_legacy_inactivo_no(): void
    {
        $a1 = User::where('email', 'auxiliar1@menteplena.test')->firstOrFail();
        $a2 = User::where('email', 'auxiliar2@menteplena.test')->firstOrFail();
        $legacy = User::where('email', 'auxiliar@menteplena.test')->first();

        $this->assertTrue($a1->activo);
        $this->assertTrue($a2->activo);
        $this->assertTrue($a1->hasRole('auxiliar_pqrsd'));
        $this->assertTrue($a2->hasRole('auxiliar_pqrsd'));
        $this->assertFalse($a1->can('usuarios.manage'));
        $this->assertFalse($a1->can('reglas_sla.manage'));

        if ($legacy) {
            $this->assertFalse($legacy->activo);
            $this->post('/login', [
                'email' => 'auxiliar@menteplena.test',
                'password' => (string) env('PQRSD_LAB_PASSWORD'),
            ])->assertNotFound();
            $this->assertGuest();
        }

        foreach (['auxiliar1@menteplena.test', 'auxiliar2@menteplena.test'] as $email) {
            $operador = User::where('email', $email)->firstOrFail();
            $this->actingAs($operador)
                ->get(route('pqrsd.index'))->assertOk()->assertSee('Bandeja de PQRSD');
            $this->get(route('usuarios.index'))->assertForbidden();
            $this->post('/logout');
            $this->assertGuest();
        }
    }

    public function test_formulario_publico_sigue_disponible_sin_login(): void
    {
        $this->get(route('publico.formulario'))
            ->assertOk()
            ->assertSee('Petición')
            ->assertSee('Felicitación')
            ->assertSee('Documentos de soporte')
            ->assertDontSee(route('login'));
    }

    public function test_usuario_inactivo_sigue_rechazado(): void
    {
        $user = User::factory()->create([
            'email' => 'bloqueado@menteplena.test',
            'password' => Hash::make((string) env('PQRSD_LAB_PASSWORD')),
            'activo' => false,
        ]);
        $user->assignRole('auxiliar_pqrsd');

        $this->post('/login', [
            'email' => 'bloqueado@menteplena.test',
            'password' => (string) env('PQRSD_LAB_PASSWORD'),
        ])->assertNotFound();
        $this->assertGuest();
    }
}
