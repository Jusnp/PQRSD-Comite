<?php

namespace Tests\Feature;

use App\Models\AdjuntoPqrsd;
use App\Models\Pqrsd;
use App\Models\TipoPqrsd;
use App\Models\User;
use App\Support\PublicUrl;
use Database\Seeders\CatalogosSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\UsersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class FormularioPublicoAdjuntosYPermisosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(CatalogosSeeder::class);
        $this->seed(UsersSeeder::class);
        Storage::fake(config('pqrsd_correo.adjuntos_disk', 'local'));
    }

    private function payloadBase(array $extra = []): array
    {
        return array_merge([
            'idempotency_key' => (string) Str::uuid(),
            'tipo_pqrsd_id' => TipoPqrsd::where('codigo', 'PETICION')->value('id'),
            'quien_radica' => 'paciente',
            'solicitante_nombre' => 'Ciudadano Prueba',
            'solicitante_tipo_documento' => 'CC',
            'solicitante_documento' => '1234567890',
            'solicitante_email' => 'ciudadano@example.test',
            'medio_respuesta' => 'email',
            'consentimiento_comunicacion_email' => '1',
            'asunto' => 'Asunto de prueba pública',
            'descripcion' => 'Descripción ficticia de prueba pública con longitud suficiente para validar.',
            'acepto_tratamiento_datos' => '1',
            'solicitante_telefono' => '3001234567',
            'paciente_fecha_nacimiento' => '2015-06-01',
        ], $extra);
    }

    public function test_admin_gestiona_usuarios_y_auxiliar_esta_restringido(): void
    {
        $admin = User::where('email', 'admin@menteplena.test')->firstOrFail();
        $auxiliar = User::where('email', 'auxiliar1@menteplena.test')->firstOrFail();

        $this->actingAs($admin)->get(route('usuarios.index'))->assertOk()->assertSee('Usuarios');
        $this->actingAs($admin)->get(route('usuarios.create'))->assertOk();
        $this->actingAs($admin)->get(route('sedes.index'))->assertOk();
        $this->actingAs($admin)->get(route('reglas.index'))->assertOk();
        $this->actingAs($admin)->get(route('pqrsd.index'))->assertOk();

        $this->actingAs($auxiliar)->get(route('usuarios.index'))->assertForbidden();
        $this->actingAs($auxiliar)->get(route('reglas.index'))->assertForbidden();
        $this->actingAs($auxiliar)->get(route('sedes.index'))->assertForbidden();
        $this->actingAs($auxiliar)->get(route('cuentas-correo.index'))->assertForbidden();
        $this->actingAs($auxiliar)->get(route('pqrsd.index'))->assertOk();
        $this->actingAs($auxiliar)->get(route('publico.qr'))->assertOk()->assertSee('Formulario público / Código QR');
    }

    public function test_admin_puede_crear_usuario_auxiliar(): void
    {
        $admin = User::where('email', 'admin@menteplena.test')->firstOrFail();

        $this->actingAs($admin)->post(route('usuarios.store'), [
            'name' => 'Nuevo Auxiliar',
            'email' => 'nuevo.auxiliar@menteplena.test',
            'role' => 'auxiliar_pqrsd',
        ])->assertRedirect();

        $user = User::where('email', 'nuevo.auxiliar@menteplena.test')->firstOrFail();
        $this->assertTrue($user->hasRole('auxiliar_pqrsd'));
        $this->assertFalse($user->can('usuarios.manage'));
        $this->assertTrue($user->can('pqrsd.view_all'));
        $this->assertFalse($user->activo);
        $this->assertNotSame('active', $user->authentik_status);
    }

    public function test_formulario_publico_sin_login_y_seis_tipos(): void
    {
        $response = $this->get(route('publico.formulario'));
        $response->assertOk();
        foreach (['Petición', 'Queja', 'Reclamo', 'Sugerencia', 'Denuncia', 'Felicitación'] as $nombre) {
            $response->assertSee($nombre);
        }
        $response->assertSee('Documentos de soporte');
    }

    public function test_radicacion_exitosa_aparece_en_bandeja_con_riesgo_pendiente(): void
    {
        $tipo = TipoPqrsd::where('codigo', 'QUEJA')->firstOrFail();
        $response = $this->post(route('publico.store'), $this->payloadBase([
            'tipo_pqrsd_id' => $tipo->id,
        ]));

        $response->assertRedirect();
        $caso = Pqrsd::latest('id')->first();
        $this->assertNotNull($caso);
        $this->assertSame('pendiente', $caso->nivel_riesgo);
        $this->assertSame('pendiente_clasificacion', $caso->estado_operativo);
        $this->assertSame(2015, (int) substr((string) $caso->paciente_fecha_nacimiento, 0, 4));
        $this->assertNull($caso->fecha_limite); // SLA no fijado hasta clasificar riesgo

        $admin = User::where('email', 'admin@menteplena.test')->firstOrFail();
        $this->actingAs($admin)
            ->get(route('pqrsd.index'))
            ->assertOk()
            ->assertSee($caso->radicado)
            ->assertSee('Ciudadano Prueba');
    }

    public function test_radicacion_con_pdf_word_e_imagenes(): void
    {
        $pdf = UploadedFile::fake()->createWithContent('soporte.pdf', "%PDF-1.4\n%fake\n");
        $docx = UploadedFile::fake()->createWithContent('carta.docx', "PK\x03\x04".str_repeat('x', 40));
        $jpg = UploadedFile::fake()->image('foto.jpg', 40, 40);
        $png = UploadedFile::fake()->image('captura.png', 32, 32);

        $response = $this->post(route('publico.store'), $this->payloadBase([
            'adjuntos' => [$pdf, $docx, $jpg, $png],
        ]));

        $response->assertRedirect();
        $caso = Pqrsd::latest('id')->firstOrFail();
        $this->assertSame(4, AdjuntoPqrsd::where('pqrsd_id', $caso->id)->count());

        foreach (AdjuntoPqrsd::where('pqrsd_id', $caso->id)->get() as $adj) {
            Storage::disk(config('pqrsd_correo.adjuntos_disk', 'local'))->assertExists($adj->ruta);
            $this->assertStringStartsWith('pqrsd/'.$caso->id.'/', $adj->ruta);
        }

        $admin = User::where('email', 'admin@menteplena.test')->firstOrFail();
        $adj = AdjuntoPqrsd::where('pqrsd_id', $caso->id)->firstOrFail();
        $this->actingAs($admin)
            ->get(route('pqrsd.adjuntos.download', [$caso, $adj]))
            ->assertOk();
    }

    public function test_archivo_invalido_y_demasiado_grande(): void
    {
        $exe = UploadedFile::fake()->createWithContent('malware.exe', 'MZ'.str_repeat('A', 100));
        $this->from(route('publico.formulario'))
            ->post(route('publico.store'), $this->payloadBase(['adjuntos' => [$exe]]))
            ->assertRedirect(route('publico.formulario'))
            ->assertSessionHasErrors('adjuntos');

        config(['pqrsd_publico.adjuntos.max_kb' => 1]); // 1 KB
        $grande = UploadedFile::fake()->createWithContent('grande.pdf', "%PDF-1.4\n".str_repeat('A', 2048));
        $this->from(route('publico.formulario'))
            ->post(route('publico.store'), $this->payloadBase(['adjuntos' => [$grande]]))
            ->assertSessionHasErrors();
    }

    public function test_descarga_denegada_a_tercero_sin_permiso(): void
    {
        $pdf = UploadedFile::fake()->createWithContent('a.pdf', "%PDF-1.4\nok\n");
        $this->post(route('publico.store'), $this->payloadBase(['adjuntos' => [$pdf]]))->assertRedirect();
        $caso = Pqrsd::latest('id')->firstOrFail();
        $adj = AdjuntoPqrsd::where('pqrsd_id', $caso->id)->firstOrFail();

        $tercero = User::factory()->create(['activo' => true]);
        $this->actingAs($tercero)
            ->get(route('pqrsd.adjuntos.download', [$caso, $adj]))
            ->assertForbidden();
    }

    public function test_doble_envio_con_misma_clave_no_duplica(): void
    {
        $key = (string) Str::uuid();
        $payload = $this->payloadBase(['idempotency_key' => $key]);

        $this->post(route('publico.store'), $payload)->assertRedirect();
        $this->post(route('publico.store'), $payload)->assertRedirect();

        $this->assertSame(1, Pqrsd::count());
    }

    public function test_reintento_con_el_mismo_adjunto_no_duplica_la_referencia(): void
    {
        $key = (string) Str::uuid();
        $contenido = "%PDF-1.4\nmismo-adjunto\n";
        $payload = $this->payloadBase([
            'idempotency_key' => $key,
            'adjuntos' => [UploadedFile::fake()->createWithContent('soporte.pdf', $contenido)],
        ]);

        $this->post(route('publico.store'), $payload)->assertRedirect();
        $this->post(route('publico.store'), $this->payloadBase([
            'idempotency_key' => $key,
            'adjuntos' => [UploadedFile::fake()->createWithContent('soporte.pdf', $contenido)],
        ]))->assertRedirect();

        $this->assertSame(1, Pqrsd::count());
        $this->assertSame(1, AdjuntoPqrsd::count());
        $disco = Storage::disk(config('pqrsd_correo.adjuntos_disk', 'local'));
        $this->assertCount(1, $disco->allFiles());
    }

    public function test_archivos_distintos_con_el_mismo_nombre_se_conservan(): void
    {
        $this->post(route('publico.store'), $this->payloadBase([
            'adjuntos' => [
                UploadedFile::fake()->createWithContent('informe.pdf', "%PDF-1.4\nuno\n"),
                UploadedFile::fake()->createWithContent('informe.pdf', "%PDF-1.4\ndos-distinto\n"),
            ],
        ]))->assertRedirect();

        $this->assertSame(1, Pqrsd::count());
        $adjuntos = AdjuntoPqrsd::query()->get();
        $this->assertCount(2, $adjuntos);
        $this->assertCount(2, $adjuntos->pluck('ruta')->unique());
        $this->assertSame(['informe.pdf', 'informe.pdf'], $adjuntos->pluck('nombre_original')->all());
    }

    public function test_sin_tabla_de_idempotencia_no_crea_expediente(): void
    {
        \Illuminate\Support\Facades\Schema::drop('pqrsd_idempotencia_publica');

        $this->post(route('publico.store'), $this->payloadBase())
            ->assertStatus(500);

        $this->assertSame(0, Pqrsd::count());
    }

    public function test_radicacion_simultanea_con_la_misma_clave_no_crea_otro_expediente(): void
    {
        $key = (string) Str::uuid();
        $bloqueo = \Illuminate\Support\Facades\Cache::lock('publico-idem-lock:'.$key, 30);
        $this->assertTrue($bloqueo->get());

        $this->post(route('publico.store'), $this->payloadBase(['idempotency_key' => $key]))
            ->assertSessionHasErrors('descripcion');
        $this->assertSame(0, Pqrsd::count());

        $bloqueo->release();

        $this->post(route('publico.store'), $this->payloadBase(['idempotency_key' => $key]))
            ->assertRedirect();
        $this->assertSame(1, Pqrsd::count());
    }

    public function test_la_misma_clave_con_otro_solicitante_no_reutiliza_el_radicado(): void
    {
        $key = (string) Str::uuid();
        $this->post(route('publico.store'), $this->payloadBase(['idempotency_key' => $key]))
            ->assertRedirect();

        $this->post(route('publico.store'), $this->payloadBase([
            'idempotency_key' => $key,
            'solicitante_nombre' => 'Otra Persona Distinta',
            'solicitante_documento' => '9999999999',
        ]))->assertSessionHasErrors('idempotency_key');

        $this->assertSame(1, Pqrsd::count());
        $this->assertSame('Ciudadano Prueba', Pqrsd::query()->value('solicitante_nombre'));
    }

    public function test_reintento_sin_cache_recupera_el_mismo_radicado(): void
    {
        $key = (string) Str::uuid();
        $payload = $this->payloadBase(['idempotency_key' => $key]);

        $this->post(route('publico.store'), $payload)->assertRedirect();
        $radicado = Pqrsd::query()->value('radicado');

        \Illuminate\Support\Facades\Cache::flush();

        $this->post(route('publico.store'), $payload)
            ->assertRedirect(route('publico.confirmacion'));

        $this->assertSame(1, Pqrsd::count());
        $this->assertSame($radicado, Pqrsd::query()->value('radicado'));
    }

    public function test_un_error_de_creacion_no_deja_el_bloqueo_tomado(): void
    {
        $key = (string) Str::uuid();
        $exe = UploadedFile::fake()->createWithContent('malware.exe', 'MZ'.str_repeat('A', 80));

        $this->post(route('publico.store'), $this->payloadBase([
            'idempotency_key' => $key,
            'adjuntos' => [$exe],
        ]))->assertSessionHasErrors();

        $this->assertSame(0, Pqrsd::count());

        $bloqueo = \Illuminate\Support\Facades\Cache::lock('publico-idem-lock:'.$key, 90);
        $this->assertTrue($bloqueo->get());
        $bloqueo->release();

        $this->post(route('publico.store'), $this->payloadBase(['idempotency_key' => $key]))
            ->assertRedirect();
        $this->assertSame(1, Pqrsd::count());
    }

    public function test_qr_marca_prueba_en_localhost_y_ok_con_url_publica(): void
    {
        config(['app.url' => 'http://127.0.0.1:8000', 'pqrsd_publico.base_url' => null]);
        $this->assertFalse(PublicUrl::isDistributable(PublicUrl::formulario(true)));
        $this->get(route('publico.qr'))
            ->assertOk()
            ->assertSee('QR de prueba — No apto para distribuir a pacientes')
            ->assertSee('Descargar QR en PNG')
            ->assertSee('Descargar QR en SVG');

        config(['pqrsd_publico.base_url' => 'https://pqrsd.menteplena.com.co']);
        $this->assertTrue(PublicUrl::isDistributable(PublicUrl::formulario(true)));
        $this->get(route('publico.qr'))
            ->assertOk()
            ->assertSee('pqrsd.menteplena.com.co')
            ->assertDontSee('QR de prueba — No apto para distribuir a pacientes');
    }

    public function test_consulta_publica_solo_radicado_no_expone_privados(): void
    {
        $pdf = UploadedFile::fake()->createWithContent('privado.pdf', "%PDF-1.4\nsecret\n");
        $response = $this->post(route('publico.store'), $this->payloadBase(['adjuntos' => [$pdf]]));
        $response->assertRedirect(route('publico.confirmacion'));
        $payload = session('publico_confirmacion');

        $this->post(route('publico.consulta.store'), [
            'radicado' => $payload['radicado'],
        ])->assertOk()
            ->assertSee($payload['radicado'])
            ->assertSee('Recibida')
            ->assertDontSee('privado.pdf')
            ->assertDontSee('Ciudadano Prueba')
            ->assertDontSee('1234567890')
            ->assertDontSee('ciudadano@example.test')
            ->assertSee('no muestra nombres');

        $this->from(route('publico.consulta'))->post(route('publico.consulta.store'), [
            'radicado' => 'PQRSD-2099-999999',
        ])->assertRedirect();
    }

    public function test_menues_operadores_muestran_enlace_qr(): void
    {
        $auxiliar = User::where('email', 'auxiliar1@menteplena.test')->firstOrFail();
        $this->actingAs($auxiliar)
            ->get(route('pqrsd.index'))
            ->assertOk()
            ->assertSee('Formulario público / Código QR')
            ->assertDontSee(route('usuarios.index'));
    }
}
