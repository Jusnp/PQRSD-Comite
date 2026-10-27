<?php

namespace Tests\Feature;

use App\Models\AreaInstitucional;
use App\Models\Aseguradora;
use App\Models\CanalIngreso;
use App\Models\MotivoPqrsd;
use App\Models\Pqrsd;
use App\Models\RemisionInterna;
use App\Models\Sede;
use App\Models\TipoPqrsd;
use App\Models\User;
use App\Services\PqrsdService;
use Carbon\Carbon;
use Database\Seeders\CatalogosSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\UsersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Verificación final: permisos efectivos + flujo operativo auxiliar sin tocar catálogos.
 */
class VerificacionFinalPermisosExpedientesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(CatalogosSeeder::class);
        $this->seed(UsersSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-10-14 12:00:00', 'America/Bogota'));
        Storage::fake(config('pqrsd_correo.adjuntos_disk', 'local'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_migraciones_y_tablas_criticas(): void
    {
        $this->assertTrue(Schema::hasTable('remisiones_internas'));
        $this->assertTrue(Schema::hasTable('areas_institucionales'));
        $this->assertTrue(Schema::hasColumn('adjuntos_pqrsd', 'remision_interna_id'));
    }

    public function test_reseed_roles_no_restaura_permisos_admin_al_auxiliar(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $aux = User::where('email', 'auxiliar1@menteplena.test')->firstOrFail();
        $this->assertFalse($aux->can('sedes.manage'));
        $this->assertFalse($aux->can('motivos.manage'));
        $this->assertFalse($aux->can('pqrsd.create_manual'));
        $this->assertTrue($aux->can('pqrsd.update'));
    }

    #[DataProvider('auxiliaresProvider')]
    public function test_auxiliar_403_en_todas_rutas_administrativas(string $email): void
    {
        $aux = User::where('email', $email)->firstOrFail();
        $sede = Sede::where('activo', true)->firstOrFail();
        $eps = Aseguradora::where('activo', true)->firstOrFail();
        $canal = CanalIngreso::where('activo', true)->firstOrFail();
        $tipo = TipoPqrsd::where('activo', true)->firstOrFail();
        $motivo = MotivoPqrsd::where('activo', true)->firstOrFail();
        $area = AreaInstitucional::where('activo', true)->firstOrFail();
        $regla = \App\Models\ReglaSla::query()->firstOrFail();

        $gets = [
            route('sedes.index'),
            route('sedes.create'),
            route('sedes.edit', $sede),
            route('aseguradoras.index'),
            route('aseguradoras.create'),
            route('aseguradoras.edit', $eps),
            route('canales.index'),
            route('canales.create'),
            route('canales.edit', $canal),
            route('motivos.index'),
            route('motivos.create'),
            route('motivos.edit', $motivo),
            route('tipos.index'),
            route('tipos.create'),
            route('tipos.edit', $tipo),
            route('areas.index'),
            route('areas.create'),
            route('areas.edit', $area),
            route('reglas.index'),
            route('reglas.create'),
            route('reglas.edit', $regla),
            route('usuarios.index'),
            route('usuarios.create'),
            route('cuentas-correo.index'),
            route('ops.health'),
            route('pqrsd.create'),
        ];

        foreach ($gets as $url) {
            $this->actingAs($aux)->get($url)->assertForbidden();
        }

        $this->actingAs($aux)->post(route('sedes.store'), [
            'codigo' => 'X', 'nombre' => 'Hack', 'activo' => 1,
        ])->assertForbidden();
        $this->actingAs($aux)->put(route('sedes.update', $sede), [
            'codigo' => $sede->codigo, 'nombre' => 'Hack', 'activo' => 1,
        ])->assertForbidden();
        $this->actingAs($aux)->post(route('motivos.store'), [
            'codigo' => 'X', 'nombre' => 'Hack', 'tipo_pqrsd_id' => $tipo->id, 'activo' => 1,
        ])->assertForbidden();
        $this->actingAs($aux)->put(route('motivos.update', $motivo), [
            'codigo' => $motivo->codigo, 'nombre' => 'Hack', 'tipo_pqrsd_id' => $motivo->tipo_pqrsd_id, 'activo' => 1,
        ])->assertForbidden();
        $this->actingAs($aux)->post(route('pqrsd.store'), [
            'canal_ingreso_id' => $canal->id,
            'nivel_riesgo' => 'ordinario',
            'descripcion' => 'Intento de radicación manual no autorizada.',
            'es_anonimo' => 1,
        ])->assertForbidden();
    }

    public static function auxiliaresProvider(): array
    {
        return [
            'auxiliar1' => ['auxiliar1@menteplena.test'],
            'auxiliar2' => ['auxiliar2@menteplena.test'],
        ];
    }

    public function test_admin_conserva_acceso_administrativo_sin_alterar_datos(): void
    {
        $admin = User::where('email', 'admin@menteplena.test')->firstOrFail();

        foreach ([
            route('sedes.index'),
            route('aseguradoras.index'),
            route('canales.index'),
            route('motivos.index'),
            route('tipos.index'),
            route('areas.index'),
            route('reglas.index'),
            route('usuarios.index'),
            route('pqrsd.create'),
            route('ops.health'),
        ] as $url) {
            $this->actingAs($admin)->get($url)->assertOk();
        }

        // Solo lectura de formularios de edición — no POST/PUT de mutación
        $sede = Sede::where('activo', true)->firstOrFail();
        $this->actingAs($admin)->get(route('sedes.edit', $sede))->assertOk();
    }

    public function test_flujo_operativo_completo_auxiliar_sobre_expediente(): void
    {
        $admin = User::where('email', 'admin@menteplena.test')->firstOrFail();
        $aux1 = User::where('email', 'auxiliar1@menteplena.test')->firstOrFail();
        $aux2 = User::where('email', 'auxiliar2@menteplena.test')->firstOrFail();
        $tipo = TipoPqrsd::where('codigo', 'QUEJA')->firstOrFail();
        $motivo = MotivoPqrsd::where('tipo_pqrsd_id', $tipo->id)->where('activo', true)->firstOrFail();
        $area = AreaInstitucional::where('activo', true)->firstOrFail();

        $caso = app(PqrsdService::class)->radicarManual([
            'fecha_recepcion' => Carbon::parse('2026-10-10 09:00:00', 'America/Bogota'),
            'canal_ingreso_id' => CanalIngreso::where('codigo', 'WEB')->value('id')
                ?? CanalIngreso::where('codigo', 'MANUAL')->value('id'),
            'tipo_pqrsd_id' => $tipo->id,
            'nivel_riesgo' => 'pendiente',
            'descripcion' => 'Caso ficticio verificación final permisos y expediente',
            'solicitante_nombre' => 'Solicitante Verificación Final',
            'sede_id' => Sede::where('activo', true)->value('id'),
            'aseguradora_id' => Aseguradora::where('activo', true)->value('id'),
        ], $admin);

        $this->actingAs($aux1)->get(route('pqrsd.index'))->assertOk()->assertSee($caso->radicado, false);
        $this->actingAs($aux2)->get(route('pqrsd.show', $caso))->assertOk();

        $this->actingAs($aux1)->post(route('pqrsd.clasificar', $caso), $this->conVersionExpediente($caso, [
            'tipo_pqrsd_id' => $tipo->id,
            'nivel_riesgo' => 'simple',
            'justificacion' => 'Clasificación ficticia de verificación final con motivo de catálogo.',
        ]))->assertRedirect();

        $this->actingAs($aux1)->post(route('pqrsd.asignar', $caso), [
            'responsable_id' => $aux2->id,
            'motivo' => 'Intento de asignación deshabilitada en verificación final.',
        ])->assertForbidden();

        $this->actingAs($aux2)->post(route('pqrsd.actuaciones.store', $caso), [
            'titulo' => 'Actuación de verificación',
            'detalle' => 'Registro ficticio de actuación interna para verificación final.',
            'es_interna' => 1,
        ])->assertForbidden();

        $pdf = UploadedFile::fake()->createWithContent('evidencia-verif.pdf', "%PDF-1.4\nverif\n");
        $this->actingAs($aux2)->post(route('pqrsd.adjuntos.store', $caso), [
            'adjuntos' => [$pdf],
        ])->assertRedirect();

        $this->actingAs($aux2)->post(route('pqrsd.remisiones.store', $caso), [
            'area_institucional_id' => $area->id,
            'motivo' => 'Solicitud de información ficticia verificación final al área.',
        ])->assertForbidden();

        $this->assertSame(0, RemisionInterna::where('pqrsd_id', $caso->id)->count());

        $this->actingAs($aux2)->post(route('pqrsd.responder', $caso), $this->conVersionExpediente($caso, [
            'detalle_respuesta' => 'Respuesta de PQRS ficticia al solicitante tras verificación final.',
            'respuesta_estado_destino' => 'puesta_disposicion',
            'poner_disposicion_consulta' => '1',
        ]))->assertRedirect();

        $this->actingAs($aux2)->post(route('pqrsd.cerrar', $caso), $this->conVersionExpediente($caso, [
            'motivo_cierre' => 'Cierre ficticio de verificación final con evidencia registrada en expediente.',
        ]))->assertRedirect();

        $caso->refresh();
        $this->assertSame('cerrado', $caso->estado_operativo);

        foreach ([$aux1, $aux2] as $aux) {
            $this->actingAs($aux)->get(route('vencimientos.proximos'))->assertOk();
            $this->actingAs($aux)->get(route('reportes.indicadores'))->assertOk();
            $this->actingAs($aux)->get(route('reportes.index'))->assertOk();
            $this->actingAs($aux)->get(route('publico.qr'))->assertOk();
            $this->actingAs($aux)->get(route('sedes.index'))->assertForbidden();
        }

        // Motivo de catálogo usable en filtros (sin administrar catálogo)
        $this->actingAs($aux1)
            ->get(route('pqrsd.index', ['motivo_pqrsd_id' => $motivo->id]))
            ->assertOk();
    }
}
