<?php

namespace Tests\Feature;

use App\Models\AdjuntoPqrsd;
use App\Models\CanalIngreso;
use App\Models\DescargaAdjuntoPqrsd;
use App\Models\TipoPqrsd;
use App\Models\User;
use App\Services\PqrsdService;
use Carbon\Carbon;
use Database\Seeders\CatalogosSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdjuntoDescargaAutorizadaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(CatalogosSeeder::class);
        Storage::fake(config('pqrsd_correo.adjuntos_disk', 'local'));
    }

    private function casoConAdjunto(User $lider, User $responsable): array
    {
        $caso = app(PqrsdService::class)->radicarManual([
            'fecha_recepcion' => Carbon::parse('2026-10-14 08:00:00', 'America/Bogota'),
            'canal_ingreso_id' => CanalIngreso::where('codigo', 'MANUAL')->value('id'),
            'tipo_pqrsd_id' => TipoPqrsd::where('codigo', 'QUEJA')->value('id'),
            'nivel_riesgo' => 'simple',
            'descripcion' => 'Caso con adjunto de prueba',
            'solicitante_nombre' => 'Demo',
            'responsable_id' => $responsable->id,
        ], $lider);

        $ruta = 'pqrsd/'.$caso->id.'/evidencia.txt';
        Storage::disk(config('pqrsd_correo.adjuntos_disk', 'local'))->put($ruta, 'contenido-ficticio');

        $adjunto = AdjuntoPqrsd::create([
            'pqrsd_id' => $caso->id,
            'subido_por' => $lider->id,
            'nombre_original' => 'evidencia.txt',
            'ruta' => $ruta,
            'mime' => 'text/plain',
            'tamano' => 18,
            'tipo' => 'soporte',
        ]);

        return [$caso, $adjunto];
    }

    public function test_responsable_asignado_puede_descargar_y_se_audita(): void
    {
        $lider = User::factory()->create(['activo' => true]);
        $lider->assignRole('lider_pqrsd');
        $responsable = User::factory()->create(['activo' => true]);
        $responsable->assignRole('responsable_area');

        [$caso, $adjunto] = $this->casoConAdjunto($lider, $responsable);

        $this->actingAs($responsable)
            ->get(route('pqrsd.adjuntos.download', [$caso, $adjunto]))
            ->assertOk();

        $this->assertDatabaseHas('descargas_adjunto_pqrsd', [
            'adjunto_pqrsd_id' => $adjunto->id,
            'usuario_id' => $responsable->id,
            'pqrsd_id' => $caso->id,
        ]);
        $this->assertSame(1, DescargaAdjuntoPqrsd::count());
    }

    public function test_responsable_ajeno_recibe_403(): void
    {
        $lider = User::factory()->create(['activo' => true]);
        $lider->assignRole('lider_pqrsd');
        $responsable = User::factory()->create(['activo' => true]);
        $responsable->assignRole('responsable_area');
        $ajeno = User::factory()->create(['activo' => true]);
        $ajeno->assignRole('responsable_area');

        [$caso, $adjunto] = $this->casoConAdjunto($lider, $responsable);

        $this->actingAs($ajeno)
            ->get(route('pqrsd.adjuntos.download', [$caso, $adjunto]))
            ->assertForbidden();
    }

    public function test_auditor_con_view_all_puede_descargar(): void
    {
        $lider = User::factory()->create(['activo' => true]);
        $lider->assignRole('lider_pqrsd');
        $responsable = User::factory()->create(['activo' => true]);
        $responsable->assignRole('responsable_area');
        $auditor = User::factory()->create(['activo' => true]);
        $auditor->assignRole('auditor');

        [$caso, $adjunto] = $this->casoConAdjunto($lider, $responsable);

        $this->actingAs($auditor)
            ->get(route('pqrsd.adjuntos.download', [$caso, $adjunto]))
            ->assertOk();
    }

    public function test_invitado_es_redirigido_al_login(): void
    {
        $lider = User::factory()->create(['activo' => true]);
        $lider->assignRole('lider_pqrsd');
        $responsable = User::factory()->create(['activo' => true]);
        $responsable->assignRole('responsable_area');

        [$caso, $adjunto] = $this->casoConAdjunto($lider, $responsable);

        $this->get(route('pqrsd.adjuntos.download', [$caso, $adjunto]))
            ->assertRedirect(route('login'));
    }

    public function test_adjunto_inexistente_o_ruta_manipulada_da_404(): void
    {
        $lider = User::factory()->create(['activo' => true]);
        $lider->assignRole('lider_pqrsd');
        $responsable = User::factory()->create(['activo' => true]);
        $responsable->assignRole('responsable_area');

        [$caso, $adjunto] = $this->casoConAdjunto($lider, $responsable);

        $adjunto->update(['ruta' => '../etc/passwd']);
        $this->actingAs($lider)
            ->get(route('pqrsd.adjuntos.download', [$caso, $adjunto]))
            ->assertNotFound();

        $otro = AdjuntoPqrsd::create([
            'pqrsd_id' => $caso->id,
            'nombre_original' => 'fantasma.txt',
            'ruta' => 'pqrsd/'.$caso->id.'/no-existe.txt',
            'mime' => 'text/plain',
            'tamano' => 0,
        ]);

        $this->actingAs($lider)
            ->get(route('pqrsd.adjuntos.download', [$caso, $otro]))
            ->assertNotFound();
    }

    public function test_no_permite_cruzar_adjunto_de_otro_caso(): void
    {
        $lider = User::factory()->create(['activo' => true]);
        $lider->assignRole('lider_pqrsd');
        $responsable = User::factory()->create(['activo' => true]);
        $responsable->assignRole('responsable_area');

        [$casoA, $adjuntoA] = $this->casoConAdjunto($lider, $responsable);

        $casoB = app(PqrsdService::class)->radicarManual([
            'fecha_recepcion' => Carbon::parse('2026-10-14 09:00:00', 'America/Bogota'),
            'canal_ingreso_id' => CanalIngreso::where('codigo', 'MANUAL')->value('id'),
            'tipo_pqrsd_id' => TipoPqrsd::where('codigo', 'PETICION')->value('id'),
            'nivel_riesgo' => 'simple',
            'descripcion' => 'Otro caso',
            'solicitante_nombre' => 'Demo 2',
            'responsable_id' => $responsable->id,
        ], $lider);

        $this->actingAs($lider)
            ->get(route('pqrsd.adjuntos.download', [$casoB, $adjuntoA]))
            ->assertNotFound();
    }
}
