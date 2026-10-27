<?php

namespace Tests\Feature;

use App\Models\ActuacionPqrsd;
use App\Models\HistorialEstadoPqrsd;
use App\Models\Pqrsd;
use App\Models\TipoPqrsd;
use App\Models\User;
use App\Services\IndicadoresPqrsdService;
use App\Services\ReporteService;
use App\Support\PublicUrl;
use Carbon\Carbon;
use Database\Seeders\CatalogosSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\UsersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class RecorridoIntegralPqrsdTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $ahora;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(CatalogosSeeder::class);
        $this->seed(UsersSeeder::class);
        Storage::fake(config('pqrsd_correo.adjuntos_disk', 'local'));
        $this->ahora = Carbon::parse('2026-10-23 09:30:00', 'America/Bogota');
        Carbon::setTestNow($this->ahora);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_recorrido_desde_el_formulario_publico_hasta_indicadores(): void
    {
        config([
            'app.url' => 'http://127.0.0.1:8010',
            'pqrsd_publico.base_url' => null,
            'pqrsd_publico.dev_url' => 'http://127.0.0.1:8010',
        ]);

        $this->get(route('publico.formulario'))
            ->assertOk()
            ->assertSee('Petición')
            ->assertSee('Queja')
            ->assertSee('Reclamo')
            ->assertSee('Sugerencia')
            ->assertSee('Denuncia')
            ->assertSee('Felicitación');

        $this->assertFalse(PublicUrl::isDistributable(PublicUrl::formulario(true)));
        $this->get(route('publico.qr'))
            ->assertOk()
            ->assertSee('No apto para distribuir a pacientes')
            ->assertSee('127.0.0.1:8010');

        $pdf = UploadedFile::fake()->createWithContent('soporte-prueba.pdf', "%PDF-1.4\nprueba\n");
        $clave = (string) Str::uuid();
        $this->post(route('publico.store'), [
            'idempotency_key' => $clave,
            'tipo_pqrsd_id' => TipoPqrsd::where('codigo', 'PETICION')->value('id'),
            'quien_radica' => 'paciente',
            'solicitante_nombre' => 'Ciudadano Prueba Recorrido',
            'solicitante_tipo_documento' => 'CC',
            'solicitante_documento' => '1010101010',
            'solicitante_email' => 'recorrido@example.test',
            'medio_respuesta' => 'email',
            'consentimiento_comunicacion_email' => '1',
            'asunto' => 'PRUEBA FICTICIA de recorrido',
            'descripcion' => 'PRUEBA FICTICIA. No corresponde a un paciente real ni a una atención clínica.',
            'acepto_tratamiento_datos' => '1',
            'paciente_fecha_nacimiento' => '1990-04-12',
            'adjuntos' => [$pdf],
        ])->assertRedirect(route('publico.confirmacion'));

        $caso = Pqrsd::query()->firstOrFail();
        $this->assertMatchesRegularExpression('/^PQRSD-\d{4}-\d{6}$/', $caso->radicado);
        $this->assertSame('pendiente', $caso->nivel_riesgo);
        $this->assertStringContainsString('PRUEBA FICTICIA', $caso->descripcion);

        $this->post(route('publico.consulta.store'), ['radicado' => $caso->radicado])
            ->assertOk()
            ->assertSee($caso->radicado);

        $admin = User::where('email', 'admin@menteplena.test')->firstOrFail();
        $auxiliar = User::where('email', 'auxiliar1@menteplena.test')->firstOrFail();
        $ajeno = User::factory()->create(['activo' => true]);

        $this->actingAs($admin)
            ->get(route('pqrsd.index'))
            ->assertOk()
            ->assertSee($caso->radicado);

        $this->actingAs($ajeno)
            ->get(route('pqrsd.show', $caso))
            ->assertForbidden();

        $this->actingAs($auxiliar)
            ->post(route('pqrsd.actuaciones.store', $caso), [
                'detalle' => 'Gestión manual que el flujo actual no debe aceptar.',
            ])->assertForbidden();

        $this->actingAs($auxiliar)
            ->post(route('pqrsd.clasificar', $caso), $this->conVersionExpediente($caso, [
                'nivel_riesgo' => 'simple',
                'justificacion' => 'Clasificación de prueba ficticia en bandeja compartida.',
            ]))->assertRedirect();

        $caso->refresh();
        $this->assertSame('simple', $caso->nivel_riesgo);
        $this->assertTrue(
            $caso->fecha_limite->equalTo($this->ahora->copy()->addHours(72))
        );
        $this->assertTrue(
            HistorialEstadoPqrsd::query()->where('pqrsd_id', $caso->id)->where('campo', 'nivel_riesgo')->exists()
        );

        $this->actingAs($auxiliar)
            ->post(route('pqrsd.responder', $caso), $this->conVersionExpediente($caso, [
                'detalle_respuesta' => 'Respuesta de prueba ficticia puesta a disposición del solicitante.',
                'respuesta_estado_destino' => 'puesta_disposicion',
                'poner_disposicion_consulta' => '1',
            ]))->assertRedirect();

        $this->assertTrue(
            ActuacionPqrsd::query()->where('pqrsd_id', $caso->id)->where('tipo', 'respuesta')->exists()
        );

        $caso->refresh();
        $this->actingAs($auxiliar)
            ->post(route('pqrsd.cerrar', $caso), $this->conVersionExpediente($caso, [
                'motivo_cierre' => 'Cierre de la prueba ficticia dentro del plazo.',
            ]))->assertRedirect();

        $caso->refresh();
        $this->assertSame('cerrado', $caso->estado_operativo);
        $this->assertNotNull($caso->fecha_cierre);
        $this->assertNotNull($caso->fecha_respuesta);
        $this->assertSame('resuelto_oportuno', $caso->cumplimientoVigente());

        $this->actingAs($auxiliar)
            ->post(route('pqrsd.cerrar', $caso), $this->conVersionExpediente($caso, [
                'motivo_cierre' => 'Segundo cierre que no debe repetirse.',
            ]))->assertForbidden();
        $this->assertSame(1, ActuacionPqrsd::query()->where('pqrsd_id', $caso->id)->where('tipo', 'cierre')->count());

        $tablero = app(IndicadoresPqrsdService::class)->construir(Request::create('/', 'GET', [
            'anio' => 2026,
            'mes' => 10,
        ]));
        $this->assertSame(1, $tablero['generales']['recibidas']);
        $this->assertSame(1, $tablero['generales']['oportunas']);
        $this->assertSame(100.0, $tablero['generales']['pct_oportunidad']);

        $reporte = app(ReporteService::class)->indicadores(
            app(ReporteService::class)->filtrar(Request::create('/', 'GET', [
                'desde' => '2026-10-01',
                'hasta' => '2026-10-31',
            ]))
        );
        $this->assertSame(100.0, $reporte['pct_oportunidad']);

        $this->actingAs($auxiliar)->get(route('reportes.indicadores'))->assertOk()->assertSee('100%');
        $this->actingAs($auxiliar)->get(route('usuarios.index'))->assertForbidden();
        $this->actingAs($admin)->get(route('usuarios.index'))->assertOk();
    }
}
