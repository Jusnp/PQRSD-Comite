<?php

namespace Tests\Feature;

use App\Models\Aseguradora;
use App\Models\CanalIngreso;
use App\Models\Sede;
use App\Models\TipoPqrsd;
use App\Models\User;
use App\Services\PqrsdService;
use Carbon\Carbon;
use Database\Seeders\CatalogosSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\UsersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OperadoresYTemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_crea_admin_y_dos_auxiliares_operativos(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(UsersSeeder::class);

        $admin = User::where('email', 'admin@menteplena.test')->first();
        $a1 = User::where('email', 'auxiliar1@menteplena.test')->first();
        $a2 = User::where('email', 'auxiliar2@menteplena.test')->first();

        $this->assertNotNull($admin);
        $this->assertTrue($admin->hasRole('admin'));
        $this->assertTrue($a1->hasRole('auxiliar_pqrsd'));
        $this->assertTrue($a2->hasRole('auxiliar_pqrsd'));
        $this->assertTrue($a1->can('pqrsd.view_all'));
        $this->assertTrue($a1->can('pqrsd.classify'));
        $this->assertTrue($a1->can('pqrsd.assign'));
        $this->assertTrue($a1->can('pqrsd.update'));
        $this->assertTrue($a1->can('pqrsd.close'));
        $this->assertTrue($a1->can('correos.review'));
        $this->assertTrue($a1->can('correos.reply'));
        $this->assertTrue($a1->can('reportes.view'));
        $this->assertFalse($a1->can('usuarios.manage'));
        $this->assertFalse($a1->can('correos.manage'));
        $this->assertFalse($a1->can('reglas_sla.manage'));
        $this->assertFalse($a1->can('sedes.manage'));
        $this->assertTrue($admin->can('usuarios.manage'));
        $this->assertTrue($admin->can('correos.manage'));
    }

    public function test_seeder_no_desactiva_usuarios_fuera_de_lista_demo(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $real = User::factory()->create([
            'email' => 'operaciones@mentepena.com.co',
            'activo' => true,
            'password' => Hash::make('SecretReal99!'),
        ]);
        $real->assignRole('auxiliar_pqrsd');

        $legacy = User::factory()->create([
            'email' => 'lider@menteplena.test',
            'activo' => true,
            'password' => Hash::make((string) env('PQRSD_LAB_PASSWORD')),
        ]);
        $legacy->assignRole('lider_pqrsd');

        $this->seed(UsersSeeder::class);

        $this->assertTrue($real->fresh()->activo);
        $this->assertTrue(Hash::check('SecretReal99!', $real->fresh()->password));
        $this->assertFalse($legacy->fresh()->activo);
    }

    public function test_seeder_omitido_en_produccion(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $previous = $this->app['env'];
        $this->app['env'] = 'production';

        try {
            // Invocación directa: evita el confirm de Artisan db:seed en producción.
            (new UsersSeeder)->run();
        } finally {
            $this->app['env'] = $previous;
        }

        $this->assertNull(User::where('email', 'admin@menteplena.test')->first());
        $this->assertNull(User::where('email', 'auxiliar1@menteplena.test')->first());
    }

    public function test_cuenta_inactiva_no_puede_iniciar_sesion(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create([
            'email' => 'inactivo@menteplena.test',
            'password' => Hash::make((string) env('PQRSD_LAB_PASSWORD')),
            'activo' => false,
        ]);
        $user->assignRole('auxiliar_pqrsd');

        $this->post('/login', [
            'email' => 'inactivo@menteplena.test',
            'password' => (string) env('PQRSD_LAB_PASSWORD'),
        ])->assertNotFound();

        $this->assertGuest();
    }

    public function test_auxiliar_opera_casos_de_cualquier_sede_y_aseguradora(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(CatalogosSeeder::class);

        $admin = User::factory()->create(['activo' => true]);
        $admin->assignRole('admin');
        $auxiliar = User::factory()->create(['activo' => true]);
        $auxiliar->assignRole('auxiliar_pqrsd');

        $sede = Sede::query()->where('activo', true)->first();
        $aseguradora = Aseguradora::query()->where('activo', true)->first();

        $caso = app(PqrsdService::class)->radicarManual([
            'fecha_recepcion' => Carbon::parse('2026-10-14 08:00:00', 'America/Bogota'),
            'canal_ingreso_id' => CanalIngreso::where('codigo', 'MANUAL')->value('id'),
            'tipo_pqrsd_id' => TipoPqrsd::where('codigo', 'QUEJA')->value('id'),
            'nivel_riesgo' => 'simple',
            'descripcion' => 'Caso multi-sede para auxiliar',
            'solicitante_nombre' => 'Demo',
            'sede_id' => $sede?->id,
            'aseguradora_id' => $aseguradora?->id,
            'responsable_id' => $admin->id,
        ], $admin);

        $this->actingAs($auxiliar)
            ->get(route('pqrsd.show', $caso))
            ->assertOk()
            ->assertSee('Caso multi-sede para auxiliar')
            ->assertSee('images/logo-comite-estudios-medicos.png', false)
            ->assertDontSee('brand-wordmark', false)
            ->assertSee('aria-label="Cambiar a tema oscuro"', false)
            ->assertSee('🌙', false);

        $this->actingAs($auxiliar)
            ->post(route('pqrsd.responder', $caso), $this->conVersionExpediente($caso, [
                'detalle_respuesta' => 'Respuesta operativa del auxiliar sobre el caso.',
                'respuesta_estado_destino' => 'puesta_disposicion',
                'poner_disposicion_consulta' => '1',
            ]))
            ->assertRedirect();

        $caso->refresh();
        $this->assertNotNull($caso->fecha_respuesta);
    }

    public function test_roles_legacy_no_tienen_permisos_operativos_inesperados(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $auditor = User::factory()->create(['activo' => true]);
        $auditor->assignRole('auditor');
        $responsable = User::factory()->create(['activo' => true]);
        $responsable->assignRole('responsable_area');

        $this->assertFalse($auditor->can('pqrsd.create'));
        $this->assertFalse($auditor->can('pqrsd.classify'));
        $this->assertFalse($auditor->can('pqrsd.assign'));
        $this->assertFalse($auditor->can('pqrsd.update'));
        $this->assertFalse($auditor->can('usuarios.manage'));
        $this->assertTrue($auditor->can('pqrsd.view_all'));

        $this->assertFalse($responsable->can('pqrsd.view_all'));
        $this->assertFalse($responsable->can('pqrsd.create'));
        $this->assertFalse($responsable->can('pqrsd.classify'));
        $this->assertFalse($responsable->can('usuarios.manage'));
        $this->assertFalse($responsable->can('correos.review'));
        $this->assertTrue($responsable->can('pqrsd.view'));
    }

    public function test_tema_persiste_en_usuario_autenticado(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create(['activo' => true, 'theme_preference' => 'light']);
        $user->assignRole('auxiliar_pqrsd');

        $this->actingAs($user)
            ->postJson(route('preferencias.tema'), ['theme' => 'dark'])
            ->assertOk()
            ->assertJson(['theme' => 'dark']);

        $this->assertSame('dark', $user->fresh()->theme_preference);

        $this->actingAs($user)
            ->get(route('pqrsd.index'))
            ->assertOk()
            ->assertSee('data-theme="dark"', false)
            ->assertSee('aria-label="Cambiar a tema claro"', false)
            ->assertSee('☀️', false)
            ->assertSee('Bandeja de PQRSD', false)
            ->assertDontSee('>Dashboard</a>', false);
    }

    public function test_login_restaura_cookie_de_tema_del_usuario(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create([
            'email' => 'tema@menteplena.test',
            'password' => Hash::make((string) env('PQRSD_LAB_PASSWORD')),
            'activo' => true,
            'theme_preference' => 'dark',
        ]);
        $user->assignRole('auxiliar_pqrsd');

        $this->post('/login', [
            'email' => 'tema@menteplena.test',
            'password' => (string) env('PQRSD_LAB_PASSWORD'),
        ])->assertNotFound();

        $this->assertGuest();
    }

    public function test_invitado_puede_cambiar_tema_por_cookie(): void
    {
        $this->postJson(route('preferencias.tema'), ['theme' => 'dark'])
            ->assertOk()
            ->assertCookie('pqrsd_theme', 'dark');

        $this->withCookie('pqrsd_theme', 'dark')
            ->get(route('login'))
            ->assertOk()
            ->assertSee('data-theme="dark"', false);
    }

    public function test_pantalla_publica_incluye_toggle_accesible_y_marca(): void
    {
        $this->get(route('publico.formulario'))
            ->assertOk()
            ->assertSee('images/logo-comite-estudios-medicos.png', false)
            ->assertDontSee('brand-wordmark', false)
            ->assertDontSee('images/mente-plena-compact.png', false)
            ->assertSee('id="pqrsd-theme-toggle"', false)
            ->assertSee('aria-pressed=', false);
    }
}
