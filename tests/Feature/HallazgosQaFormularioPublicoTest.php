<?php

namespace Tests\Feature;

use App\Models\AdjuntoPqrsd;
use App\Models\Aseguradora;
use App\Models\MotivoPqrsd;
use App\Models\Pqrsd;
use App\Models\ReglaSla;
use App\Models\Sede;
use App\Models\TipoPqrsd;
use App\Support\CorreccionTildesTiposPqrsd;
use Database\Seeders\CatalogosSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class HallazgosQaFormularioPublicoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(CatalogosSeeder::class);
    }

    public function test_el_formulario_anuncia_rechazo_de_extensiones_y_errores_en_espanol(): void
    {
        $html = $this->get(route('publico.formulario'))->assertOk()->getContent();

        $this->assertStringContainsString('novalidate', $html);
        $this->assertStringContainsString('Extensión no permitida:', $html);
        $this->assertStringNotContainsString('mimesPermitidos', $html);
        $this->assertStringContainsString('El teléfono es obligatorio cuando la solicitud no es anónima.', $html);
        $this->assertStringContainsString('Ingrese un teléfono móvil o fijo de Colombia.', $html);
        $this->assertStringContainsString('Indique el asunto de la manifestación.', $html);
        $this->assertStringContainsString('El asunto debe tener al menos 5 caracteres.', $html);
        $this->assertStringContainsString('La descripción debe tener al menos 20 caracteres.', $html);
        $this->assertStringContainsString('Debe aceptar el tratamiento de datos personales (Ley 1581 de 2012).', $html);
        $this->assertStringContainsString('errores-cliente-publico', $html);
        foreach (config('pqrsd_publico.adjuntos.extensions') as $extension) {
            $this->assertStringContainsString($extension, $html);
        }
        $this->assertStringNotContainsString('"exe"', $html);
    }

    public function test_el_encabezado_publico_puede_ajustarse_en_movil(): void
    {
        $css = file_get_contents(public_path('css/pqrsd-app.css'));

        $this->assertIsString($css);
        $this->assertStringContainsString('.publico-body .app-header-inner', $css);
        $this->assertStringContainsString('flex-wrap: wrap', $css);
        $this->assertStringContainsString('max-width: 768px', $css);
    }

    public function test_el_formulario_oculta_sedes_y_aseguradoras_inactivas(): void
    {
        Sede::query()->update(['activo' => false]);
        Aseguradora::query()->update(['activo' => false]);

        $this->get(route('publico.formulario'))
            ->assertOk()
            ->assertDontSee('Sede Urabá', false)
            ->assertDontSee('Savia Salud', false)
            ->assertSee('No aplica / no sé', false);

        Sede::query()->where('codigo', 'URABA')->update(['activo' => true]);
        Aseguradora::query()->where('codigo', 'SAVIA')->update(['activo' => true]);

        $this->get(route('publico.formulario'))
            ->assertOk()
            ->assertSee('Sede Urabá', false)
            ->assertSee('Savia Salud', false)
            ->assertDontSee('Sede Medellín', false);
    }

    public function test_la_correccion_de_tildes_es_idempotente_y_no_toca_sla(): void
    {
        $peticion = TipoPqrsd::query()->where('codigo', 'PETICION')->firstOrFail();
        $felicitacion = TipoPqrsd::query()->where('codigo', 'FELICITACION')->firstOrFail();
        $peticionId = $peticion->id;
        $felicitacionId = $felicitacion->id;
        $slaAntes = ReglaSla::query()->orderBy('id')->get(['id', 'nombre', 'cantidad', 'unidad', 'activo'])->toArray();

        $peticion->update(['nombre' => 'Peticion']);
        $felicitacion->update(['nombre' => 'Felicitacion']);
        $peticion->motivos()->where('codigo', 'GENERAL')->update(['nombre' => 'Motivo general - Peticion']);
        $felicitacion->motivos()->where('codigo', 'GENERAL')->update(['nombre' => 'Motivo general - Felicitacion']);

        $primero = CorreccionTildesTiposPqrsd::aplicar();
        $segundo = CorreccionTildesTiposPqrsd::aplicar();

        $this->assertSame(['tipos' => 2, 'motivos' => 2], $primero);
        $this->assertSame(['tipos' => 0, 'motivos' => 0], $segundo);
        $this->assertSame($peticionId, TipoPqrsd::query()->where('codigo', 'PETICION')->value('id'));
        $this->assertSame($felicitacionId, TipoPqrsd::query()->where('codigo', 'FELICITACION')->value('id'));
        $this->assertSame('Petición', TipoPqrsd::query()->where('codigo', 'PETICION')->value('nombre'));
        $this->assertSame('Felicitación', TipoPqrsd::query()->where('codigo', 'FELICITACION')->value('nombre'));
        $this->assertSame('PETICION', TipoPqrsd::query()->find($peticionId)->codigo);
        $this->assertSame(
            'Motivo general - Petición',
            $peticion->motivos()->where('codigo', 'GENERAL')->value('nombre')
        );
        $this->assertSame($slaAntes, ReglaSla::query()->orderBy('id')->get(['id', 'nombre', 'cantidad', 'unidad', 'activo'])->toArray());

        $this->get(route('publico.formulario'))
            ->assertOk()
            ->assertSee('Petición', false)
            ->assertSee('Felicitación', false);
    }

    public function test_el_cliente_no_rechaza_un_docx_por_el_tipo_informado_por_el_navegador(): void
    {
        $html = $this->get(route('publico.formulario'))->assertOk()->getContent();
        $inicio = strpos($html, 'function motivoRechazoAdjunto');
        $fin = strpos($html, 'function digitosTelefono');
        $this->assertNotFalse($inicio);
        $this->assertNotFalse($fin);
        $funcion = substr($html, $inicio, $fin - $inicio);

        $this->assertStringContainsString('application/octet-stream', $funcion);
        $this->assertStringContainsString('application/zip', $funcion);
        $this->assertStringContainsString('extensionesPermitidas.indexOf(extension)', $funcion);
        $this->assertStringContainsString('Extensión no permitida:', $funcion);
        $this->assertStringNotContainsString('.type', $funcion);
        $this->assertStringNotContainsString('Tipo de archivo no permitido', $funcion);
    }

    public function test_el_servidor_acepta_docx_valido_y_conserva_el_rechazo_de_contenido(): void
    {
        Storage::fake(config('pqrsd_correo.adjuntos_disk', 'local'));
        $tipo = TipoPqrsd::query()->where('codigo', 'PETICION')->firstOrFail();
        $motivo = MotivoPqrsd::query()
            ->where('tipo_pqrsd_id', $tipo->id)
            ->where('codigo', 'GENERAL')
            ->firstOrFail();

        $docx = UploadedFile::fake()->createWithContent('carta.docx', "PK\x03\x04".str_repeat('x', 40));
        $this->post(route('publico.store'), $this->solicitudPublica($tipo->id, $motivo->id, [$docx]))
            ->assertRedirect();

        $caso = Pqrsd::query()->latest('id')->firstOrFail();
        $adjunto = AdjuntoPqrsd::query()->where('pqrsd_id', $caso->id)->firstOrFail();
        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            $adjunto->mime
        );

        $this->from(route('publico.formulario'))
            ->post(route('publico.store'), $this->solicitudPublica($tipo->id, $motivo->id, [
                UploadedFile::fake()->createWithContent('nota.exe', 'MZ'.str_repeat('A', 80)),
            ]))
            ->assertRedirect(route('publico.formulario'))
            ->assertSessionHasErrors('adjuntos');

        $this->from(route('publico.formulario'))
            ->post(route('publico.store'), $this->solicitudPublica($tipo->id, $motivo->id, [
                UploadedFile::fake()->createWithContent('falso.docx', 'esto no es un documento'),
            ]))
            ->assertRedirect(route('publico.formulario'))
            ->assertSessionHasErrors('adjuntos');

        $this->assertSame(1, Pqrsd::query()->count());
    }

    private function solicitudPublica(int $tipoId, int $motivoId, array $adjuntos): array
    {
        return [
            'idempotency_key' => (string) Str::uuid(),
            'quien_radica' => 'paciente',
            'tipo_pqrsd_id' => $tipoId,
            'motivo_pqrsd_id' => $motivoId,
            'solicitante_nombre' => 'Ciudadano Prueba',
            'solicitante_tipo_documento' => 'CC',
            'solicitante_documento' => '1234567890',
            'solicitante_telefono' => '3001234567',
            'medio_respuesta' => 'consulta_segura',
            'asunto' => 'Asunto de prueba',
            'descripcion' => 'Descripción suficiente para la solicitud de prueba aislada.',
            'acepto_tratamiento_datos' => '1',
            'adjuntos' => $adjuntos,
        ];
    }
}
