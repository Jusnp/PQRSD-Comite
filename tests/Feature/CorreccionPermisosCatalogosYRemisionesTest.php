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
use App\Services\RemisionInternaService;
use Carbon\Carbon;
use Database\Seeders\CatalogosSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\UsersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class CorreccionPermisosCatalogosYRemisionesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(CatalogosSeeder::class);
        $this->seed(UsersSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-10-14 12:00:00', 'America/Bogota'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_tablas_de_remisiones_existen_tras_migraciones(): void
    {
        $this->assertTrue(Schema::hasTable('areas_institucionales'));
        $this->assertTrue(Schema::hasTable('remisiones_internas'));
        $this->assertTrue(Schema::hasColumn('adjuntos_pqrsd', 'remision_interna_id'));
    }

    public function test_abrir_expediente_sin_remisiones(): void
    {
        $admin = User::where('email', 'admin@menteplena.test')->firstOrFail();
        $caso = $this->crearCaso($admin);

        $this->actingAs($admin)
            ->get(route('pqrsd.show', $caso))
            ->assertOk()
            ->assertSee($caso->radicado, false)
            ->assertDontSee('Solicitar información a otra área', false)
            ->assertDontSee('Registrar gestión', false);
    }

    public function test_abrir_expediente_con_remisiones(): void
    {
        $admin = User::where('email', 'admin@menteplena.test')->firstOrFail();
        $auxiliar = User::where('email', 'auxiliar1@menteplena.test')->firstOrFail();
        $caso = $this->crearCaso($admin);
        $area = AreaInstitucional::where('activo', true)->firstOrFail();

        app(RemisionInternaService::class)->crear($caso, $auxiliar, [
            'area_institucional_id' => $area->id,
            'motivo' => 'Solicitud de información clínica',
            'informacion_solicitada' => 'Se requiere copia de la historia clínica del episodio.',
            'responsable_seguimiento_id' => $auxiliar->id,
        ]);

        $this->assertSame(1, RemisionInterna::where('pqrsd_id', $caso->id)->count());

        $this->actingAs($auxiliar)
            ->get(route('pqrsd.show', $caso))
            ->assertOk()
            ->assertSee($caso->radicado, false)
            ->assertSee('Solicitud de información clínica', false)
            ->assertSee('Consultar remisiones históricas', false)
            ->assertDontSee('Solicitar información a otra área', false);
    }

    public function test_admin_accede_y_crud_catalogos(): void
    {
        $admin = User::where('email', 'admin@menteplena.test')->firstOrFail();

        $this->actingAs($admin)->get(route('sedes.index'))->assertOk()->assertSee('Nueva sede', false);
        $this->actingAs($admin)->get(route('aseguradoras.index'))->assertOk();
        $this->actingAs($admin)->get(route('canales.index'))->assertOk();
        $this->actingAs($admin)->get(route('motivos.index'))->assertOk();
        $this->actingAs($admin)->get(route('tipos.index'))->assertOk();
        $this->actingAs($admin)->get(route('areas.index'))->assertOk();
        $this->actingAs($admin)->get(route('reglas.index'))->assertOk();
        $this->actingAs($admin)->get(route('usuarios.index'))->assertOk();

        $this->actingAs($admin)->post(route('sedes.store'), [
            'codigo' => 'TESTSED',
            'nombre' => 'Sede Prueba Catálogo',
            'activo' => 1,
        ])->assertRedirect(route('sedes.index'));

        $sede = Sede::where('codigo', 'TESTSED')->firstOrFail();
        $this->actingAs($admin)->put(route('sedes.update', $sede), [
            'codigo' => 'TESTSED',
            'nombre' => 'Sede Prueba Actualizada',
            'activo' => 1,
        ])->assertRedirect(route('sedes.index'));

        $this->assertSame('Sede Prueba Actualizada', $sede->fresh()->nombre);
    }

    public function test_auxiliar_403_en_catalogos_get_post_put(): void
    {
        $auxiliar = User::where('email', 'auxiliar1@menteplena.test')->firstOrFail();
        $sede = Sede::where('activo', true)->firstOrFail();
        $eps = Aseguradora::where('activo', true)->firstOrFail();
        $canal = CanalIngreso::where('activo', true)->firstOrFail();
        $motivo = MotivoPqrsd::where('activo', true)->firstOrFail();

        foreach ([
            route('sedes.index'),
            route('aseguradoras.index'),
            route('canales.index'),
            route('motivos.index'),
            route('tipos.index'),
            route('areas.index'),
            route('reglas.index'),
            route('usuarios.index'),
            route('sedes.create'),
            route('motivos.create'),
            route('sedes.edit', $sede),
            route('motivos.edit', $motivo),
        ] as $url) {
            $this->actingAs($auxiliar)->get($url)->assertForbidden();
        }

        $this->actingAs($auxiliar)->post(route('sedes.store'), [
            'codigo' => 'HACK',
            'nombre' => 'No debe crearse',
            'activo' => 1,
        ])->assertForbidden();

        $this->actingAs($auxiliar)->post(route('aseguradoras.store'), [
            'codigo' => 'HACK',
            'nombre' => 'No debe crearse',
            'activo' => 1,
        ])->assertForbidden();

        $this->actingAs($auxiliar)->post(route('canales.store'), [
            'codigo' => 'HACK',
            'nombre' => 'No debe crearse',
            'activo' => 1,
        ])->assertForbidden();

        $this->actingAs($auxiliar)->post(route('motivos.store'), [
            'codigo' => 'HACK',
            'nombre' => 'No debe crearse',
            'tipo_pqrsd_id' => TipoPqrsd::where('activo', true)->value('id'),
            'activo' => 1,
        ])->assertForbidden();

        $this->actingAs($auxiliar)->put(route('sedes.update', $sede), [
            'codigo' => $sede->codigo,
            'nombre' => 'Nombre alterado ilegalmente',
            'activo' => 1,
        ])->assertForbidden();

        $this->actingAs($auxiliar)->put(route('aseguradoras.update', $eps), [
            'codigo' => $eps->codigo,
            'nombre' => 'Nombre alterado ilegalmente',
            'activo' => 1,
        ])->assertForbidden();

        $this->actingAs($auxiliar)->put(route('canales.update', $canal), [
            'codigo' => $canal->codigo,
            'nombre' => 'Nombre alterado ilegalmente',
            'activo' => 1,
        ])->assertForbidden();

        $this->actingAs($auxiliar)->put(route('motivos.update', $motivo), [
            'codigo' => $motivo->codigo,
            'nombre' => 'Nombre alterado ilegalmente',
            'tipo_pqrsd_id' => $motivo->tipo_pqrsd_id,
            'activo' => 1,
        ])->assertForbidden();

        $this->assertNull(Sede::where('codigo', 'HACK')->first());
        $this->assertNotSame('Nombre alterado ilegalmente', $sede->fresh()->nombre);
    }

    public function test_auxiliar_no_ve_administracion_en_menu(): void
    {
        $auxiliar = User::where('email', 'auxiliar1@menteplena.test')->firstOrFail();
        $html = $this->actingAs($auxiliar)->get(route('pqrsd.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('>Administración</summary>', $html);
        $this->assertStringNotContainsString(route('sedes.index', absolute: false), $html);
        $this->assertStringNotContainsString(route('motivos.index', absolute: false), $html);
        $this->assertStringNotContainsString('Nueva PQRSD', $html);
        $this->assertStringContainsString('Bandeja de PQRSD', $html);
    }

    public function test_auxiliar_filtra_y_usa_catalogos_en_operacion(): void
    {
        $admin = User::where('email', 'admin@menteplena.test')->firstOrFail();
        $auxiliar = User::where('email', 'auxiliar1@menteplena.test')->firstOrFail();
        $tipo = TipoPqrsd::where('codigo', 'QUEJA')->firstOrFail();
        $motivo = MotivoPqrsd::where('tipo_pqrsd_id', $tipo->id)->where('activo', true)->firstOrFail();
        $sede = Sede::where('activo', true)->firstOrFail();
        $eps = Aseguradora::where('activo', true)->firstOrFail();

        $caso = $this->crearCaso($admin, [
            'tipo_pqrsd_id' => $tipo->id,
            'motivo_pqrsd_id' => $motivo->id,
            'sede_id' => $sede->id,
            'aseguradora_id' => $eps->id,
            'nivel_riesgo' => 'pendiente',
        ]);

        $html = $this->actingAs($auxiliar)
            ->get(route('pqrsd.index', [
                'sede_id' => $sede->id,
                'aseguradora_id' => $eps->id,
                'motivo_pqrsd_id' => $motivo->id,
            ]))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString($caso->radicado, $html);

        $this->actingAs($auxiliar)
            ->post(route('pqrsd.clasificar', $caso), $this->conVersionExpediente($caso, [
                'tipo_pqrsd_id' => $tipo->id,
                'nivel_riesgo' => 'simple',
                'justificacion' => 'Clasificación operativa usando catálogos existentes.',
            ]))
            ->assertRedirect();

        $this->actingAs($auxiliar)
            ->post(route('pqrsd.responder', $caso), $this->conVersionExpediente($caso, [
                'detalle_respuesta' => 'Respuesta del auxiliar tras usar filtros y clasificación.',
                'respuesta_estado_destino' => 'puesta_disposicion',
                'poner_disposicion_consulta' => '1',
            ]))
            ->assertRedirect();

        $this->assertSame('respondido', $caso->fresh()->estado_operativo);
    }

    public function test_auxiliar_gestiona_remisiones_internas(): void
    {
        $admin = User::where('email', 'admin@menteplena.test')->firstOrFail();
        $auxiliar = User::where('email', 'auxiliar1@menteplena.test')->firstOrFail();
        $caso = $this->crearCaso($admin);
        $area = AreaInstitucional::where('activo', true)->firstOrFail();

        $this->actingAs($auxiliar)
            ->post(route('pqrsd.remisiones.store', $caso), [
                'area_institucional_id' => $area->id,
                'motivo' => 'Remisión de prueba auxiliar',
                'informacion_solicitada' => 'Detalle solicitado al área para gestión del caso.',
                'responsable_seguimiento_id' => $auxiliar->id,
            ])
            ->assertForbidden();

        $remision = app(RemisionInternaService::class)->crear($caso, $auxiliar, [
            'area_institucional_id' => $area->id,
            'motivo' => 'Remisión histórica de prueba auxiliar',
            'informacion_solicitada' => 'Detalle histórico solicitado al área.',
            'responsable_seguimiento_id' => $auxiliar->id,
        ], []);

        $this->actingAs($auxiliar)
            ->get(route('pqrsd.remisiones.show', [$caso, $remision]))
            ->assertOk()
            ->assertSee('Remisión histórica de prueba auxiliar', false)
            ->assertSee('solo lectura', false);
    }

    public function test_radicacion_publica_y_sla_intactos(): void
    {
        $tipo = TipoPqrsd::where('codigo', 'PETICION')->firstOrFail();

        $this->post(route('publico.store'), [
            'idempotency_key' => (string) Str::uuid(),
            'tipo_pqrsd_id' => $tipo->id,
            'quien_radica' => 'paciente',
            'asunto' => 'Radicación pública post corrección',
            'descripcion' => 'Descripción ficticia suficiente para validar radicación pública tras corrección.',
            'solicitante_nombre' => 'Ciudadano Catálogo',
            'solicitante_tipo_documento' => 'CC',
            'solicitante_documento' => '100200300',
            'solicitante_email' => 'ciudadano.catalogo@example.com',
            'medio_respuesta' => 'email',
            'consentimiento_comunicacion_email' => '1',
            'acepto_tratamiento_datos' => '1',
        ])->assertRedirect();

        $this->assertDatabaseHas('pqrsd', ['solicitante_nombre' => 'Ciudadano Catálogo']);

        $regla = \App\Models\ReglaSla::where('codigo', 'SIMPLE_72H')->first();
        $this->assertNotNull($regla);
        $this->assertSame(95.0, (float) config('pqrsd_sla.meta_oportunidad_pct'));
    }

    /**
     * @param  array<string, mixed>  $opts
     */
    private function crearCaso(User $actor, array $opts = []): Pqrsd
    {
        return app(PqrsdService::class)->radicarManual([
            'fecha_recepcion' => Carbon::parse($opts['fecha_recepcion'] ?? '2026-10-10 09:00:00', 'America/Bogota'),
            'canal_ingreso_id' => CanalIngreso::where('codigo', 'MANUAL')->value('id'),
            'tipo_pqrsd_id' => $opts['tipo_pqrsd_id'] ?? TipoPqrsd::where('codigo', 'QUEJA')->value('id'),
            'motivo_pqrsd_id' => $opts['motivo_pqrsd_id'] ?? null,
            'nivel_riesgo' => $opts['nivel_riesgo'] ?? 'simple',
            'descripcion' => $opts['descripcion'] ?? 'Caso ficticio corrección permisos y remisiones',
            'solicitante_nombre' => 'Demo Catálogo',
            'sede_id' => $opts['sede_id'] ?? Sede::where('activo', true)->value('id'),
            'aseguradora_id' => $opts['aseguradora_id'] ?? Aseguradora::where('activo', true)->value('id'),
        ], $actor)->fresh();
    }
}
