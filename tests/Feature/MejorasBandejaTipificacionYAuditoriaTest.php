<?php

namespace Tests\Feature;

use App\Models\AdjuntoPqrsd;
use App\Models\AuditoriaPqrsd;
use App\Models\CategoriaPqrsd;
use App\Models\EncuestaSatisfaccionPqrsd;
use App\Models\HistorialEstadoPqrsd;
use App\Models\NotificacionPqrsd;
use App\Models\Pqrsd;
use App\Models\SubcategoriaPqrsd;
use App\Models\TipoPqrsd;
use App\Models\User;
use App\Services\IndicadoresPqrsdService;
use App\Services\ReporteService;
use Carbon\Carbon;
use Database\Seeders\CatalogosSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\UsersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

class MejorasBandejaTipificacionYAuditoriaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(CatalogosSeeder::class);
        $this->seed(UsersSeeder::class);
        Storage::fake(config('pqrsd_correo.adjuntos_disk', 'local'));
        Carbon::setTestNow(Carbon::parse('2026-10-28 09:00:00', 'America/Bogota'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_filtra_por_documento_sin_mostrarlo_en_la_consulta_publica(): void
    {
        $admin = User::where('email', 'admin@menteplena.test')->firstOrFail();
        $uno = $this->radicar(['solicitante_documento' => '1010101010', 'solicitante_tipo_documento' => 'CC']);
        $dos = $this->radicar(['solicitante_documento' => '2020202020']);
        $dos->update(['paciente_documento' => '3030303030', 'paciente_tipo_documento' => 'TI']);

        $this->actingAs($admin)
            ->get(route('pqrsd.index', ['documento' => '1010 101010', 'tipo_documento' => 'CC']))
            ->assertOk()
            ->assertSee($uno->radicado)
            ->assertDontSee($dos->radicado);

        $pagina = $this->actingAs($admin)
            ->get(route('pqrsd.index', ['documento' => '3030303030', 'tipo_documento' => 'TI', 'page' => 1]));
        $pagina->assertOk()->assertSee($dos->radicado)->assertDontSee($uno->radicado);
        $this->assertStringContainsString('documento=3030303030', $pagina->getContent());

        $this->post(route('publico.consulta.store'), ['radicado' => $uno->radicado])
            ->assertOk()
            ->assertDontSee('1010101010')
            ->assertDontSee('3001234567');
    }

    public function test_reclasificacion_conserva_la_recepcion_y_audita_el_cambio(): void
    {
        $admin = User::where('email', 'admin@menteplena.test')->firstOrFail();
        $caso = $this->radicar();
        $recepcion = $caso->fecha_recepcion->copy();
        $categoria = CategoriaPqrsd::where('codigo', 'CALIDAD')->firstOrFail();
        $sub = SubcategoriaPqrsd::where('codigo', 'TRATO')->firstOrFail();
        $tipo = TipoPqrsd::where('codigo', 'QUEJA')->firstOrFail();

        Carbon::setTestNow(now('America/Bogota')->addDays(3));

        $this->actingAs($admin)->post(route('pqrsd.clasificar', $caso), [
            'nivel_riesgo' => 'priorizado',
            'tipo_pqrsd_id' => $tipo->id,
            'categoria_pqrsd_id' => $categoria->id,
            'subcategoria_pqrsd_id' => $sub->id,
            'justificacion' => 'El relato corresponde a trato, no a un riesgo simple automático.',
            'expediente_version' => $caso->fresh()->versionToken(),
        ])->assertRedirect();

        $caso->refresh();
        $this->assertTrue($caso->fecha_recepcion->equalTo($recepcion));
        $this->assertTrue($caso->fecha_limite->equalTo($recepcion->copy()->addHours(48)));
        $this->assertSame($sub->id, $caso->subcategoria_pqrsd_id);
        $this->assertTrue(HistorialEstadoPqrsd::query()
            ->where('pqrsd_id', $caso->id)
            ->where('campo', 'subcategoria_pqrsd_id')
            ->where('valor_nuevo', (string) $sub->id)
            ->where('usuario_id', $admin->id)
            ->exists());
    }

    public function test_sin_clasificar_conserva_radicado_y_alerta_vital_no_fija_el_sla(): void
    {
        $admin = User::where('email', 'admin@menteplena.test')->firstOrFail();
        $this->get(route('publico.formulario'))
            ->assertOk()
            ->assertDontSee('¿Esta situación puede poner en riesgo la vida o la salud?', false)
            ->assertDontSee('name="alerta_vital_ciudadano"', false);

        $respuesta = $this->post(route('publico.store'), $this->payloadPublico([
            'ciudadano_no_clasifica' => '1',
            'tipo_pqrsd_id' => '',
            'alerta_vital_ciudadano' => 'si',
            'es_anonimo' => '0',
        ]));
        $respuesta->assertRedirect(route('publico.confirmacion'));

        $caso = Pqrsd::latest('id')->firstOrFail();
        $this->assertNotNull($caso->radicado);
        $this->assertTrue($caso->ciudadano_no_clasifica);
        $this->assertFalse($caso->alerta_vital_preventiva);
        $this->assertSame('pendiente', $caso->nivel_riesgo);
        $this->assertSame('pendiente_clasificacion', $caso->estado_operativo);
        $this->assertNull($caso->fecha_limite);
        $this->assertFalse(NotificacionPqrsd::query()->where('pqrsd_id', $caso->id)->where('tipo', 'alerta_vital_preventiva')->exists());

        $caso->update(['alerta_vital_preventiva' => true]);
        app(\App\Services\SlaNotificationService::class)->alertaVitalPreventiva($caso->fresh());
        $this->assertNull($caso->fresh()->fecha_limite);

        $this->actingAs($admin)
            ->get(route('pqrsd.index', ['clasificacion' => 'sin_clasificar']))
            ->assertOk()
            ->assertSee('Pendiente de clasificación')
            ->assertSee('Revisión prioritaria')
            ->assertSee($caso->radicado);
    }

    public function test_indicadores_distinguen_proximo_vencido_y_no_inventan_satisfaccion(): void
    {
        $caso = $this->radicar();
        $inicio = now('America/Bogota')->subHours(70);
        $caso->update([
            'nivel_riesgo' => 'simple',
            'estado_operativo' => 'en_gestion',
            'estado_cumplimiento' => 'dentro_plazo',
            'fecha_recepcion' => $inicio,
            'fecha_inicio_conteo' => $inicio,
            'fecha_limite' => now('America/Bogota')->addHours(2),
        ]);

        $tablero = app(IndicadoresPqrsdService::class)->construir(Request::create('/reportes/indicadores', 'GET'));
        $this->assertSame(1, $tablero['generales']['proximas_vencer']);
        $this->assertSame(0, $tablero['generales']['vencidas']);
        $this->assertNull($tablero['generales']['satisfaccion_promedio']);
        $this->assertSame(95.0, $tablero['meta_oportunidad_pct']);

        $caso->update(['fecha_limite' => now('America/Bogota')->subHour(), 'estado_cumplimiento' => 'dentro_plazo']);
        $tablero = app(IndicadoresPqrsdService::class)->construir(Request::create('/reportes/indicadores', 'GET'));
        $this->assertSame(1, $tablero['generales']['vencidas']);
        $this->assertSame(1, collect($tablero['vencidos_por_sede'])->sum('total'));
        $this->assertSame($tablero['generales']['recibidas'], collect($tablero['por_categoria'])->sum('total'));
        $this->assertSame($tablero['generales']['recibidas'], collect($tablero['por_subcategoria'])->sum('total'));
        $this->assertTrue(collect($tablero['satisfaccion_por_sede'])->every(fn ($fila) => $fila['promedio'] === null));
        $this->assertTrue(collect($tablero['satisfaccion_por_tipo'])->every(fn ($fila) => $fila['promedio'] === null));
    }

    public function test_encuesta_unica_despues_del_cierre_y_alerta_baja_sin_reabrir(): void
    {
        $caso = $this->radicar();
        $caso->update(['estado_operativo' => 'cerrado', 'fecha_cierre' => now('America/Bogota')]);
        $token = 'consulta-privada-de-prueba-1234567890';
        $caso->update(['consulta_token_hash' => hash('sha256', $token)]);

        $this->post(route('publico.encuesta'), [
            'radicado' => $caso->radicado,
            'token' => 'token-ajeno-que-no-coincide-123456',
            'puntuacion' => 5,
            'atendida' => 'si',
        ])->assertSessionHasErrors('radicado');
        $this->assertSame(0, EncuestaSatisfaccionPqrsd::count());

        $this->post(route('publico.encuesta'), [
            'radicado' => $caso->radicado,
            'token' => $token,
            'puntuacion' => 1,
            'atendida' => 'no',
            'comentario' => 'La respuesta no resolvió el trámite.',
        ])->assertOk()->assertDontSee($caso->solicitante_telefono);

        $this->post(route('publico.encuesta'), [
            'radicado' => $caso->radicado,
            'token' => $token,
            'puntuacion' => 5,
            'atendida' => 'si',
        ])->assertOk()->assertSee('ya tiene una encuesta');

        $caso->refresh();
        $this->assertSame('cerrado', $caso->estado_operativo);
        $this->assertSame(1, EncuestaSatisfaccionPqrsd::count());
        $this->assertTrue(NotificacionPqrsd::query()->where('tipo', 'satisfaccion_baja')->exists());
    }

    public function test_exportaciones_autorizadas_y_restriccion_de_rol(): void
    {
        $admin = User::where('email', 'admin@menteplena.test')->firstOrFail();
        $caso = $this->radicar();
        Storage::disk(config('pqrsd_correo.adjuntos_disk', 'local'))->put('pqrsd/prueba/soporte.pdf', 'contenido-ficticio');
        AdjuntoPqrsd::create([
            'pqrsd_id' => $caso->id,
            'nombre_original' => '../../otro.pdf',
            'ruta' => 'pqrsd/prueba/soporte.pdf',
            'mime' => 'application/pdf',
            'tamano' => 18,
            'tipo' => 'soporte',
        ]);

        $filas = app(ReporteService::class)->filasExportacion(collect([$caso->fresh(['tipo', 'categoria', 'encuesta', 'actuaciones.usuario'])]));
        $this->assertArrayHasKey('Categoría', $filas->first());
        $this->assertSame('1010101010', trim(explode(' ', $filas->first()['Identificación'])[1] ?? $filas->first()['Identificación']));

        $this->actingAs($admin)->get(route('reportes.excel'))->assertOk();
        $pdf = $this->actingAs($admin)->get(route('reportes.expediente.pdf', $caso));
        $pdf->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        $zipRespuesta = $this->actingAs($admin)->get(route('reportes.expediente.zip', $caso));
        $zipRespuesta->assertOk();
        $ruta = $zipRespuesta->baseResponse->getFile()->getPathname();
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($ruta) === true);
        $this->assertNotFalse($zip->locateName('manifiesto.json'));
        $manifiesto = json_decode($zip->getFromName('manifiesto.json'), true);
        $this->assertFalse($manifiesto['firma_digital_certificada']);
        $soporte = collect($manifiesto['archivos'])->firstWhere('tipo', 'soporte');
        $this->assertSame(hash('sha256', 'contenido-ficticio'), $soporte['sha256']);
        $this->assertStringStartsWith('soportes/', $soporte['nombre']);
        $this->assertStringNotContainsString('..', $soporte['nombre']);
        $this->assertSame(1, collect($manifiesto['archivos'])->where('tipo', 'soporte')->count());
        $zip->close();
        $this->assertTrue(AuditoriaPqrsd::query()->where('accion', 'exportacion_expediente_zip')->where('usuario_id', $admin->id)->exists());

        $ajeno = User::create([
            'name' => 'Area Restringida',
            'email' => 'area.restringida@example.test',
            'password' => 'no-se-usa',
            'activo' => true,
        ]);
        $ajeno->assignRole('responsable_area');
        $this->actingAs($ajeno)->get(route('reportes.expediente.pdf', $caso))->assertForbidden();
    }

    public function test_telefono_obligatorio_identificado_y_opcional_si_es_anonimo(): void
    {
        $this->from(route('publico.formulario'))
            ->post(route('publico.store'), $this->payloadPublico(['solicitante_telefono' => '']))
            ->assertSessionHasErrors('solicitante_telefono');

        $this->from(route('publico.formulario'))
            ->post(route('publico.store'), $this->payloadPublico(['solicitante_telefono' => '123']))
            ->assertSessionHasErrors('solicitante_telefono');

        $telefonoDescartado = '3115556677';
        $this->post(route('publico.store'), $this->payloadPublico([
            'es_anonimo' => '1',
            'solicitante_telefono' => $telefonoDescartado,
            'solicitante_nombre' => 'No Debe Guardarse',
            'solicitante_documento' => '9999999999',
            'tipo_pqrsd_id' => TipoPqrsd::where('codigo', 'QUEJA')->value('id'),
        ]))->assertRedirect(route('publico.confirmacion'));

        $anonimo = Pqrsd::latest('id')->firstOrFail();
        $this->assertTrue($anonimo->es_anonimo);
        $this->assertNull($anonimo->solicitante_telefono);
        $this->assertNull($anonimo->solicitante_nombre);
        $this->assertFalse(AuditoriaPqrsd::query()->where('despues', 'like', '%'.$telefonoDescartado.'%')->exists());

        $identificado = $this->radicar(['solicitante_telefono' => '3001234567']);
        $this->assertSame('3001234567', $identificado->solicitante_telefono);

        $this->get(route('publico.formulario'))
            ->assertOk()
            ->assertSee('no se almacena el teléfono', false)
            ->assertSee('Política de Tratamiento de Datos Personales')
            ->assertSee('pendiente de publicación oficial')
            ->assertDontSee('name="acepto_tratamiento_datos"', false)
            ->assertDontSee('menteplena.com');
    }

    public function test_sin_politica_oficial_no_registra_aceptacion_y_con_version_si(): void
    {
        config(['identidad.politica_tratamiento_url' => '', 'identidad.politica_tratamiento_version' => '']);

        $this->post(route('publico.store'), $this->payloadPublico(['acepto_tratamiento_datos' => '1']))->assertRedirect();
        $sinPolitica = Pqrsd::latest('id')->firstOrFail();
        $this->assertFalse($sinPolitica->acepto_tratamiento_datos);
        $this->assertNull($sinPolitica->tratamiento_aceptado_en);
        $this->assertNull($sinPolitica->tratamiento_politica_version);

        config([
            'identidad.politica_tratamiento_url' => 'https://ejemplo.invalid/politica-comite',
            'identidad.politica_tratamiento_version' => '2026-institucional',
        ]);

        $this->from(route('publico.formulario'))
            ->post(route('publico.store'), $this->payloadPublico(['acepto_tratamiento_datos' => '0']))
            ->assertSessionHasErrors('acepto_tratamiento_datos');

        $this->get(route('publico.formulario'))
            ->assertSee('https://ejemplo.invalid/politica-comite', false);

        $this->post(route('publico.store'), $this->payloadPublico(['acepto_tratamiento_datos' => '1']))->assertRedirect();
        $conPolitica = Pqrsd::latest('id')->firstOrFail();
        $this->assertTrue($conPolitica->acepto_tratamiento_datos);
        $this->assertNotNull($conPolitica->tratamiento_aceptado_en);
        $this->assertSame('2026-institucional', $conPolitica->tratamiento_politica_version);

        config([
            'identidad.politica_tratamiento_url' => 'https://comitedeestudiosmedicos.com.co/wp-content/uploads/2026/08/PO-GJ-JUR-001-Politica-para-el-tratamiento-de-datos-personales-V01-1-1.pdf',
            'identidad.politica_tratamiento_version' => '01',
            'identidad.politica_tratamiento_codigo' => 'PO-GJ-JUR-001',
            'identidad.politica_tratamiento_fecha' => '01/08/2026',
        ]);

        $this->get(route('publico.formulario'))
            ->assertOk()
            ->assertSee('Consultar Política de Tratamiento de Datos Personales', false)
            ->assertSee('target="_blank"', false)
            ->assertSee('PO-GJ-JUR-001', false)
            ->assertDontSee('pendiente de publicación oficial', false);

        $this->post(route('publico.store'), $this->payloadPublico([
            'es_anonimo' => '1',
            'acepto_tratamiento_datos' => '1',
            'medio_respuesta' => 'consulta_segura',
        ]))->assertRedirect();
        $anonimo = Pqrsd::latest('id')->firstOrFail();
        $this->assertTrue($anonimo->es_anonimo);
        $this->assertFalse($anonimo->acepto_tratamiento_datos);
        $this->assertNull($anonimo->tratamiento_politica_version);

        $this->post(route('publico.store'), $this->payloadPublico([
            'solicitante_documento' => '2020202020',
            'acepto_tratamiento_datos' => '1',
        ]))->assertRedirect();
        $identificado = Pqrsd::latest('id')->firstOrFail();
        $this->assertSame('PO-GJ-JUR-001 V01', $identificado->tratamiento_politica_version);
        $this->assertNotNull($identificado->tratamiento_aceptado_en);
    }

    public function test_sin_clasificar_muestra_espera_sin_reiniciar_plazo(): void
    {
        $admin = User::where('email', 'admin@menteplena.test')->firstOrFail();
        $caso = $this->radicar(['ciudadano_no_clasifica' => '1', 'alerta_vital_ciudadano' => 'no']);
        $recepcion = $caso->fecha_recepcion->copy();
        Carbon::setTestNow(Carbon::parse('2026-10-28 18:00:00', 'America/Bogota'));

        $this->actingAs($admin)
            ->get(route('pqrsd.index', ['clasificacion' => 'sin_clasificar']))
            ->assertOk()
            ->assertSee($caso->radicado)
            ->assertSee('Sin clasificación: 9 h')
            ->assertSee('plazo más corto vigente');

        $caso->refresh();
        $this->assertNull($caso->fecha_limite);
        $this->assertTrue($recepcion->equalTo($caso->fecha_recepcion));
    }

    private function radicar(array $extra = []): Pqrsd
    {
        $this->post(route('publico.store'), $this->payloadPublico($extra))->assertRedirect();

        return Pqrsd::latest('id')->firstOrFail();
    }

    private function payloadPublico(array $extra = []): array
    {
        return array_merge([
            'idempotency_key' => (string) Str::uuid(),
            'tipo_pqrsd_id' => TipoPqrsd::where('codigo', 'PETICION')->value('id'),
            'quien_radica' => 'paciente',
            'solicitante_nombre' => 'Ciudadano Ficticio',
            'solicitante_tipo_documento' => 'CC',
            'solicitante_documento' => '1010101010',
            'solicitante_telefono' => '3001234567',
            'medio_respuesta' => 'consulta_segura',
            'asunto' => 'Demora en un trámite de prueba',
            'descripcion' => 'Descripción ficticia con longitud suficiente para validar el formulario público.',
            'acepto_tratamiento_datos' => '1',
        ], $extra);
    }
}
