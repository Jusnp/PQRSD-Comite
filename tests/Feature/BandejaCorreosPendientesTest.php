<?php

namespace Tests\Feature;

use App\Mail\DTO\InboundMessageData;
use App\Mail\Gmail\FakeGmailApiClient;
use App\Mail\Gmail\GmailCredentialResolver;
use App\Mail\Providers\GoogleGmailMailProvider;
use App\Models\AdjuntoCorreo;
use App\Models\Aseguradora;
use App\Models\AuditoriaPqrsd;
use App\Models\CuentaCorreo;
use App\Models\MensajeCorreo;
use App\Models\Pqrsd;
use App\Models\User;
use App\Services\Correo\EmailIngestionService;
use App\Services\Correo\EmailReplyService;
use Carbon\Carbon;
use Database\Seeders\CatalogosSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BandejaCorreosPendientesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(CatalogosSeeder::class);
    }

    public function test_un_correo_importado_queda_pendiente_y_visible_en_la_bandeja(): void
    {
        $cuenta = $this->buzon();
        $mensaje = $this->importar($cuenta, 'g-100', 'Solicitud de prueba 44821', 'Relato reservado de la prueba 44821');

        $this->assertSame('pendiente_revision', $mensaje->clasificacion);
        $this->assertNull($mensaje->pqrsd_id);
        $this->assertSame(0, Pqrsd::query()->count());

        $auxiliar = $this->usuario('auxiliar_pqrsd');
        $html = $this->actingAs($auxiliar)->get(route('pqrsd.index'))->assertOk()->getContent();
        $this->assertStringContainsString('Correos pendientes de radicación', $html);
        $this->assertStringContainsString('Solicitud de prueba 44821', $html);
        $this->assertStringContainsString('ciudadano@example.test', $html);
        $this->assertStringContainsString($cuenta->direccion, $html);
        $this->assertStringContainsString('Pendiente de radicación', $html);
        $this->assertStringNotContainsString('Relato reservado de la prueba 44821', $html);
        $this->assertStringContainsString('<strong>1</strong> por revisar', $html);
        $this->assertMatchesRegularExpression('/Recibidas<\/div>\s*<div class="value">0<\/div>/', $html);

        $this->actingAs($this->usuario('auditor'))
            ->get(route('pqrsd.index'))
            ->assertOk()
            ->assertDontSee('Correos pendientes de radicación');

        $this->actingAs($this->usuario('auditor'))
            ->get(route('bandeja-correo.show', $mensaje))
            ->assertForbidden();
    }

    public function test_radicar_exige_datos_conserva_gmail_y_no_duplica(): void
    {
        $cuenta = $this->buzon();
        $mensaje = $this->importar($cuenta, 'g-200', 'Asunto revisable', 'Contenido recibido para revision humana.');
        $fecha = $mensaje->fecha_mensaje->timezone('America/Bogota')->format('Y-m-d H:i:s');
        $messageId = $mensaje->message_id;
        $cuentaId = $mensaje->cuenta_correo_id;
        $auxiliar = $this->usuario('auxiliar_pqrsd');

        $this->actingAs($auxiliar)
            ->get(route('bandeja-correo.show', $mensaje))
            ->assertOk()
            ->assertSee('Contenido recibido para revision humana.', false)
            ->assertSee('Radicar correo', false);

        $this->actingAs($auxiliar)
            ->post(route('bandeja-correo.radicar.store', $mensaje), [
                'nivel_riesgo' => 'pendiente',
                'descripcion' => 'corto',
            ])
            ->assertSessionHasErrors(['solicitante_nombre', 'descripcion']);
        $this->assertSame(0, Pqrsd::query()->count());

        $this->actingAs($auxiliar)
            ->post(route('bandeja-correo.radicar.store', $mensaje), [
                'solicitante_nombre' => 'Ciudadano de prueba',
                'solicitante_email' => 'ciudadano@example.test',
                'descripcion' => 'Hechos confirmados por el revisor antes de radicar.',
                'nivel_riesgo' => 'pendiente',
            ])
            ->assertRedirect();

        $mensaje->refresh();
        $this->assertNotNull($mensaje->pqrsd_id);
        $this->assertSame('g-200', $mensaje->gmail_message_id);
        $this->assertSame($messageId, $mensaje->message_id);
        $this->assertSame($cuentaId, $mensaje->cuenta_correo_id);
        $this->assertSame($fecha, $mensaje->fecha_mensaje->timezone('America/Bogota')->format('Y-m-d H:i:s'));
        $this->assertSame($fecha, $mensaje->pqrsd->fecha_recepcion->timezone('America/Bogota')->format('Y-m-d H:i:s'));
        $this->assertSame('nueva_pqrsd', $mensaje->clasificacion);
        $this->assertSame(1, Pqrsd::query()->count());
        $this->assertDatabaseHas('auditoria_pqrsd', [
            'accion' => 'correo_radicado',
            'pqrsd_id' => $mensaje->pqrsd_id,
            'entidad' => 'mensaje_correo',
            'entidad_id' => $mensaje->id,
        ]);

        $bandeja = $this->actingAs($auxiliar)->get(route('pqrsd.index'))->assertOk();
        $bandeja->assertSee($mensaje->pqrsd->radicado, false);
        $bandeja->assertDontSee('Asunto revisable', false);

        $this->actingAs($auxiliar)
            ->post(route('bandeja-correo.radicar.store', $mensaje), [
                'solicitante_nombre' => 'Ciudadano de prueba',
                'descripcion' => 'Un segundo intento no debe abrir otro expediente.',
                'nivel_riesgo' => 'pendiente',
            ])
            ->assertSessionHasErrors('descripcion');
        $this->assertSame(1, Pqrsd::query()->count());
    }

    public function test_no_radica_en_otra_organizacion_y_la_respuesta_usa_el_buzon_original(): void
    {
        $propia = Aseguradora::query()->create(['codigo' => 'EPS-A', 'nombre' => 'EPS Prueba A', 'activo' => true]);
        $ajena = Aseguradora::query()->create(['codigo' => 'EPS-B', 'nombre' => 'EPS Prueba B', 'activo' => true]);
        $cuenta = $this->buzon($propia->id);
        $mensaje = $this->importar($cuenta, 'g-300', 'Caso de una organizacion', 'Texto suficiente para la revision.');
        $auxiliar = $this->usuario('auxiliar_pqrsd');

        $this->actingAs($auxiliar)
            ->post(route('bandeja-correo.radicar.store', $mensaje), [
                'solicitante_nombre' => 'Ciudadano de prueba',
                'solicitante_email' => 'ciudadano@example.test',
                'descripcion' => 'Intento de radicar el correo en otra organizacion.',
                'nivel_riesgo' => 'pendiente',
                'aseguradora_id' => $ajena->id,
            ])
            ->assertSessionHasErrors('descripcion');
        $this->assertSame(0, Pqrsd::query()->count());

        $this->actingAs($auxiliar)
            ->post(route('bandeja-correo.radicar.store', $mensaje), [
                'solicitante_nombre' => 'Ciudadano de prueba',
                'solicitante_email' => 'ciudadano@example.test',
                'descripcion' => 'Hechos confirmados dentro de la organizacion del buzon.',
                'nivel_riesgo' => 'ordinario',
                'aseguradora_id' => $propia->id,
            ])
            ->assertRedirect();

        $mensaje->refresh();
        $this->assertSame($propia->id, $mensaje->pqrsd->aseguradora_id);

        $falso = new FakeGmailApiClient;
        $this->app->instance(
            GoogleGmailMailProvider::class,
            new GoogleGmailMailProvider(new GmailCredentialResolver, $falso)
        );
        putenv('MAIL_CRED_GMAIL_TEST=x');
        $_ENV['MAIL_CRED_GMAIL_TEST'] = 'x';

        $this->actingAs($auxiliar)
            ->post(route('pqrsd.correo-responder', $mensaje->pqrsd), [
                'destinatario' => 'ciudadano@example.test',
                'cuerpo' => 'Respuesta de prueba desde el buzon original.',
            ])
            ->assertRedirect();

        $this->assertSame(1, $falso->sendAttempts);
        $this->assertDatabaseHas('mensajes_correo', [
            'direccion' => 'saliente',
            'pqrsd_id' => $mensaje->pqrsd_id,
            'cuenta_correo_id' => $cuenta->id,
            'in_reply_to' => $mensaje->message_id,
        ]);
        $this->assertSame(1, Pqrsd::query()->count());
    }

    public function test_el_adjunto_solo_lo_descarga_quien_puede_revisar_correos(): void
    {
        Storage::fake('local');
        $mensaje = $this->importar($this->buzon(), 'g-400', 'Correo con soporte', 'Texto del mensaje con soporte.');
        Storage::disk('local')->put('correo/adjuntos/prueba.txt', 'contenido de prueba');
        $adjunto = AdjuntoCorreo::query()->create([
            'mensaje_correo_id' => $mensaje->id,
            'nombre_original' => 'soporte.txt',
            'ruta' => 'correo/adjuntos/prueba.txt',
            'mime' => 'text/plain',
            'tamano' => 18,
            'checksum' => hash('sha256', 'contenido de prueba'),
        ]);

        $this->actingAs($this->usuario('auditor'))
            ->get(route('bandeja-correo.adjunto', [$mensaje, $adjunto]))
            ->assertForbidden();

        $this->actingAs($this->usuario('auxiliar_pqrsd'))
            ->get(route('bandeja-correo.adjunto', [$mensaje, $adjunto]))
            ->assertOk();

        $this->assertTrue(AuditoriaPqrsd::query()->where('accion', 'correo_adjunto_descargado')->where('entidad_id', $adjunto->id)->exists());
    }

    public function test_el_contenido_html_no_se_interpreta_como_script(): void
    {
        $mensaje = $this->importar(
            $this->buzon(),
            'g-xss',
            '<script>alert(1)</script>',
            '<img src=x onerror=alert(1)>'
        );

        $html = $this->actingAs($this->usuario('auxiliar_pqrsd'))
            ->get(route('bandeja-correo.show', $mensaje))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<img src=x onerror=alert(1)>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
    }

    public function test_un_revisor_sin_vista_global_no_ve_correos_de_otra_cola(): void
    {
        $mensaje = $this->importar($this->buzon(), 'g-oculto', 'Asunto de otra cola', 'Texto que no debe ver un revisor limitado.');
        $limitado = User::factory()->create(['activo' => true]);
        $limitado->givePermissionTo(['correos.review', 'pqrsd.view']);

        $this->actingAs($limitado)
            ->get(route('pqrsd.index'))
            ->assertOk()
            ->assertDontSee('Asunto de otra cola', false);

        $this->actingAs($limitado)
            ->get(route('bandeja-correo.show', $mensaje))
            ->assertNotFound();
    }

    public function test_un_adjunto_fuera_del_directorio_no_se_descarga(): void
    {
        Storage::fake('local');
        $mensaje = $this->importar($this->buzon(), 'g-ruta', 'Correo con ruta', 'Texto suficiente del mensaje.');
        Storage::disk('local')->put('.env', 'SECRETO');
        $adjunto = AdjuntoCorreo::query()->create([
            'mensaje_correo_id' => $mensaje->id,
            'nombre_original' => 'secreto.txt',
            'ruta' => 'correo/adjuntos/../../.env',
            'mime' => 'text/plain',
            'tamano' => 7,
            'checksum' => hash('sha256', 'SECRETO'),
        ]);

        $this->actingAs($this->usuario('auxiliar_pqrsd'))
            ->get(route('bandeja-correo.adjunto', [$mensaje, $adjunto]))
            ->assertNotFound();
    }

    public function test_un_buzon_inactivo_no_envia_la_respuesta(): void
    {
        $cuenta = CuentaCorreo::query()->where('direccion', 'callcenter@menteplena.com.co')->firstOrFail();
        $this->assertFalse($cuenta->activo);
        $mensaje = $this->importar($cuenta, 'g-inactivo', 'Correo sin envio', 'Texto que no debe generar un correo saliente.');
        $falso = new FakeGmailApiClient;
        $this->app->instance(
            GoogleGmailMailProvider::class,
            new GoogleGmailMailProvider(new GmailCredentialResolver, $falso)
        );

        $resultado = app(EmailReplyService::class)->responder($mensaje, 'Esta respuesta no debe salir.', $this->usuario('auxiliar_pqrsd'));

        $this->assertFalse($resultado['ok']);
        $this->assertSame(0, $falso->sendAttempts);
        $this->assertFalse($cuenta->fresh()->activo);
        $this->assertSame(0, MensajeCorreo::query()->where('direccion', 'saliente')->count());
    }

    private function usuario(string $rol): User
    {
        $usuario = User::factory()->create(['activo' => true]);
        $usuario->assignRole($rol);

        return $usuario;
    }

    private function buzon(?int $aseguradoraId = null): CuentaCorreo
    {
        return CuentaCorreo::query()->create([
            'direccion' => 'buzon-'.($aseguradoraId ?: 'inst').'@ejemplo.test',
            'activo' => true,
            'tipo_integracion' => 'google_api',
            'credential_ref' => 'MAIL_CRED_GMAIL_TEST',
            'aseguradora_id' => $aseguradoraId,
            'es_buzon_central' => false,
        ]);
    }

    private function importar(CuentaCorreo $cuenta, string $gmailId, string $asunto, string $cuerpo): MensajeCorreo
    {
        $resultado = app(EmailIngestionService::class)->ingest($cuenta, new InboundMessageData(
            messageId: '<'.$gmailId.'@example.test>',
            inReplyTo: null,
            references: null,
            fromAddress: 'ciudadano@example.test',
            fromName: 'Ciudadano de prueba',
            toAddress: $cuenta->direccion,
            cc: null,
            originalRecipient: null,
            subject: $asunto,
            bodyText: $cuerpo,
            bodyHtml: null,
            rawHeaders: null,
            rawEml: null,
            date: Carbon::now('America/Bogota'),
            metadatos: ['gmail_message_id' => $gmailId],
        ));

        $this->assertFalse($resultado['duplicado']);
        $this->assertNull($resultado['error']);

        return $resultado['mensaje'];
    }
}
