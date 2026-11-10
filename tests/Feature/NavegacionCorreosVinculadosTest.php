<?php

namespace Tests\Feature;

use App\Mail\DTO\InboundMessageData;
use App\Mail\Gmail\FakeGmailApiClient;
use App\Mail\Gmail\GmailCredentialResolver;
use App\Mail\Providers\GoogleGmailMailProvider;
use App\Models\Aseguradora;
use App\Models\CanalIngreso;
use App\Models\CuentaCorreo;
use App\Models\MensajeCorreo;
use App\Models\Pqrsd;
use App\Models\User;
use App\Services\Correo\EmailIngestionService;
use App\Services\PqrsdService;
use Carbon\Carbon;
use Database\Seeders\CatalogosSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NavegacionCorreosVinculadosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(CatalogosSeeder::class);
    }

    public function test_el_expediente_enlaza_la_conversacion_y_el_correo_regresa_al_radicado(): void
    {
        $cuenta = $this->buzon(true);
        $mensaje = $this->importar($cuenta, 'g-nav', 'Asunto navegable 771', 'Relato que solo se abre en la conversacion.');
        $auxiliar = $this->usuario('auxiliar_pqrsd');

        $this->actingAs($auxiliar)
            ->post(route('bandeja-correo.radicar.store', $mensaje), [
                'solicitante_nombre' => 'Ciudadano de prueba',
                'solicitante_email' => 'ciudadano@example.test',
                'descripcion' => 'Hechos confirmados para abrir el expediente desde el correo.',
                'nivel_riesgo' => 'pendiente',
            ])
            ->assertRedirect();

        $mensaje->refresh();
        $this->assertNotNull($mensaje->pqrsd_id);
        $this->assertSame('g-nav', $mensaje->gmail_message_id);
        $this->assertSame(1, Pqrsd::query()->count());

        $expediente = $this->actingAs($auxiliar)
            ->get(route('pqrsd.show', $mensaje->pqrsd))
            ->assertOk();
        $expediente->assertSee('Correos electrónicos vinculados', false);
        $expediente->assertSee('Asunto navegable 771', false);
        $expediente->assertSee('ciudadano@example.test', false);
        $expediente->assertSee($cuenta->direccion, false);
        $expediente->assertSee('Radicado', false);
        $expediente->assertSee('Ver conversación y responder', false);
        $expediente->assertSee('/bandeja-correo/'.$mensaje->id, false);
        $expediente->assertDontSee('Relato que solo se abre en la conversacion.', false);

        $conversacion = $this->actingAs($auxiliar)
            ->get(route('bandeja-correo.show', $mensaje))
            ->assertOk();
        $conversacion->assertSee('Expediente '.$mensaje->pqrsd->radicado, false);
        $conversacion->assertSee(route('pqrsd.show', $mensaje->pqrsd), false);
        $conversacion->assertDontSee('Envío deshabilitado: cuenta de correo inactiva', false);

        $this->actingAs($auxiliar)
            ->get(route('pqrsd.index'))
            ->assertOk()
            ->assertDontSee('Asunto navegable 771', false)
            ->assertSee($mensaje->pqrsd->radicado, false);

        $this->actingAs($this->usuario('auditor'))
            ->get(route('pqrsd.show', $mensaje->pqrsd))
            ->assertOk()
            ->assertDontSee('Correos electrónicos vinculados', false)
            ->assertDontSee('Asunto navegable 771', false);

        $mensaje->update(['asunto' => '<script>nav(1)</script>']);
        $html = $this->actingAs($auxiliar)
            ->get(route('pqrsd.show', $mensaje->pqrsd))
            ->assertOk()
            ->getContent();
        $this->assertStringNotContainsString('<script>nav(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;nav(1)&lt;/script&gt;', $html);
    }

    public function test_los_filtros_separan_pendientes_y_radicados_sin_duplicar_el_expediente(): void
    {
        $cuenta = $this->buzon(true);
        $pendiente = $this->importar($cuenta, 'g-pend', 'Asunto todavia pendiente', 'Texto pendiente de revision humana.');
        $radicado = $this->importar($cuenta, 'g-rad', 'Asunto ya radicado', 'Texto que ya tiene expediente.');
        $auxiliar = $this->usuario('auxiliar_pqrsd');
        $caso = $this->caso($auxiliar, null);

        $radicado->update([
            'pqrsd_id' => $caso->id,
            'clasificacion' => 'nueva_pqrsd',
        ]);

        $this->actingAs($auxiliar)
            ->get(route('bandeja-correo.index', ['filtro' => 'pendientes']))
            ->assertOk()
            ->assertSee('Asunto todavia pendiente', false)
            ->assertDontSee('Asunto ya radicado', false)
            ->assertSee('Correos pendientes de radicación', false);

        $this->actingAs($auxiliar)
            ->get(route('bandeja-correo.index', ['filtro' => 'radicados']))
            ->assertOk()
            ->assertSee('Asunto ya radicado', false)
            ->assertDontSee('Asunto todavia pendiente', false)
            ->assertSee('Ver conversación y responder', false)
            ->assertSee($caso->radicado, false);

        $todos = $this->actingAs($auxiliar)
            ->get(route('bandeja-correo.index', ['filtro' => 'todos']))
            ->assertOk();
        $todos->assertSee('Asunto todavia pendiente', false);
        $todos->assertSee('Asunto ya radicado', false);
        $this->assertSame(1, Pqrsd::query()->count());
        $this->assertSame($pendiente->id, MensajeCorreo::query()->where('gmail_message_id', 'g-pend')->value('id'));
    }

    public function test_la_lista_de_correos_se_pagina(): void
    {
        $cuenta = $this->buzon(true);
        $auxiliar = $this->usuario('auxiliar_pqrsd');
        foreach (range(1, 21) as $numero) {
            MensajeCorreo::query()->create([
                'cuenta_correo_id' => $cuenta->id,
                'message_id' => '<pagina-'.$numero.'@example.test>',
                'remitente' => 'ciudadano@example.test',
                'destinatario' => $cuenta->direccion,
                'asunto' => sprintf('Lista-A-%02d', $numero),
                'cuerpo_texto' => 'Contenido de lista.',
                'fecha_mensaje' => Carbon::parse('2026-11-01 08:00:00', 'America/Bogota')->addMinutes($numero),
                'fecha_importacion' => now('America/Bogota'),
                'direccion' => 'entrante',
                'clasificacion' => 'pendiente_revision',
                'estado_procesamiento' => 'procesado',
            ]);
        }

        $primera = $this->actingAs($auxiliar)
            ->get(route('bandeja-correo.index', ['filtro' => 'pendientes']))
            ->assertOk();
        $primera->assertSee('Lista-A-21', false);
        $primera->assertDontSee('Lista-A-01', false);

        $this->actingAs($auxiliar)
            ->get(route('bandeja-correo.index', ['filtro' => 'pendientes', 'page' => 2]))
            ->assertOk()
            ->assertSee('Lista-A-01', false)
            ->assertDontSee('Lista-A-21', false);
    }

    public function test_un_revisor_limitado_no_ve_el_correo_de_otra_organizacion(): void
    {
        $orgA = Aseguradora::query()->create(['codigo' => 'ORG-A', 'nombre' => 'Organizacion A', 'activo' => true]);
        $orgB = Aseguradora::query()->create(['codigo' => 'ORG-B', 'nombre' => 'Organizacion B', 'activo' => true]);
        $limitado = User::factory()->create(['activo' => true]);
        $limitado->givePermissionTo(['correos.review', 'correos.reply', 'pqrsd.view']);
        $otro = $this->usuario('auxiliar_pqrsd');
        $casoA = $this->caso($limitado, $orgA->id);
        $casoB = $this->caso($otro, $orgB->id);
        $cuentaA = $this->buzon(true, $orgA->id, 'org-a@ejemplo.test');
        $cuentaB = $this->buzon(true, $orgB->id, 'org-b@ejemplo.test');
        $propio = $this->vincular($cuentaA, $casoA, 'Asunto de la organizacion A', 'hilo-a');
        $ajeno = $this->vincular($cuentaB, $casoB, 'Asunto de la organizacion B', 'hilo-a');

        $this->actingAs($limitado)
            ->get(route('pqrsd.show', $casoA))
            ->assertOk()
            ->assertSee('Asunto de la organizacion A', false)
            ->assertDontSee('Asunto de la organizacion B', false);

        $this->actingAs($limitado)
            ->get(route('bandeja-correo.index', ['filtro' => 'radicados']))
            ->assertOk()
            ->assertSee('Asunto de la organizacion A', false)
            ->assertDontSee('Asunto de la organizacion B', false);

        $this->actingAs($limitado)
            ->get(route('bandeja-correo.show', $ajeno))
            ->assertNotFound();

        $this->actingAs($limitado)
            ->get(route('bandeja-correo.show', $propio))
            ->assertOk()
            ->assertSee('Asunto de la organizacion A', false)
            ->assertDontSee('Asunto de la organizacion B', false);

        $conversacion = $this->actingAs($otro)
            ->get(route('bandeja-correo.show', $propio))
            ->assertOk()
            ->getContent();
        $this->assertStringNotContainsString('Asunto de la organizacion B', $conversacion);
        $this->assertSame($cuentaA->id, $propio->cuenta_correo_id);
        $this->assertSame($cuentaB->id, $ajeno->cuenta_correo_id);
    }

    public function test_la_cuenta_inactiva_bloquea_la_respuesta_en_pantalla_y_en_el_servidor(): void
    {
        $cuenta = $this->buzon(false);
        $mensaje = $this->importar($cuenta, 'g-off', 'Asunto sin envio', 'Texto que no debe salir del servidor.');
        $auxiliar = $this->usuario('auxiliar_pqrsd');
        $falso = new FakeGmailApiClient;
        $this->app->instance(
            GoogleGmailMailProvider::class,
            new GoogleGmailMailProvider(new GmailCredentialResolver, $falso)
        );

        $this->actingAs($auxiliar)
            ->get(route('bandeja-correo.show', $mensaje))
            ->assertOk()
            ->assertSee('Envío deshabilitado: cuenta de correo inactiva', false)
            ->assertSee('disabled', false);

        $this->actingAs($auxiliar)
            ->post(route('bandeja-correo.responder', $mensaje), [
                'cuerpo' => 'Esta respuesta no debe enviarse.',
            ])
            ->assertSessionHasErrors('cuerpo');

        $this->assertSame(0, $falso->sendAttempts);
        $this->assertFalse($cuenta->fresh()->activo);
        $this->assertSame(0, MensajeCorreo::query()->where('direccion', 'saliente')->count());

        $caso = $this->caso($auxiliar, null);
        $mensaje->update([
            'pqrsd_id' => $caso->id,
            'clasificacion' => 'nueva_pqrsd',
        ]);
        $caso->update(['solicitante_email' => 'ciudadano@example.test']);

        $this->actingAs($auxiliar)
            ->get(route('pqrsd.show', $caso))
            ->assertOk()
            ->assertSee('Envío deshabilitado: cuenta de correo inactiva', false)
            ->assertDontSee('name="cuerpo"', false);
    }

    public function test_una_cuenta_activa_conserva_el_hilo_al_responder_desde_la_conversacion(): void
    {
        $cuenta = $this->buzon(true);
        $mensaje = $this->importar($cuenta, 'g-on', 'Asunto con hilo', 'Texto original del hilo.');
        $mensaje->update([
            'gmail_thread_id' => 'hilo-original-gmail',
            'thread_key' => 'hilo-original-gmail',
        ]);
        $auxiliar = $this->usuario('auxiliar_pqrsd');
        $falso = new FakeGmailApiClient;
        $this->app->instance(
            GoogleGmailMailProvider::class,
            new GoogleGmailMailProvider(new GmailCredentialResolver, $falso)
        );
        putenv('MAIL_CRED_GMAIL_TEST=x');
        $_ENV['MAIL_CRED_GMAIL_TEST'] = 'x';

        $this->actingAs($auxiliar)
            ->get(route('bandeja-correo.show', $mensaje))
            ->assertOk()
            ->assertDontSee('Envío deshabilitado: cuenta de correo inactiva', false)
            ->assertSee('Enviar respuesta', false);

        $this->actingAs($auxiliar)
            ->post(route('bandeja-correo.responder', $mensaje), [
                'cuerpo' => 'Respuesta de prueba dentro del mismo hilo.',
            ])
            ->assertRedirect();

        $saliente = MensajeCorreo::query()->where('direccion', 'saliente')->first();
        $this->assertNotNull($saliente);
        $this->assertSame(1, $falso->sendAttempts);
        $this->assertSame($mensaje->message_id, $saliente->in_reply_to);
        $this->assertSame($cuenta->id, $saliente->cuenta_correo_id);
        $this->assertSame('hilo-original-gmail', $saliente->thread_key);
        $this->assertSame('g-on', $mensaje->fresh()->gmail_message_id);
        $this->assertTrue($cuenta->fresh()->activo);
    }

    private function usuario(string $rol): User
    {
        $usuario = User::factory()->create(['activo' => true]);
        $usuario->assignRole($rol);

        return $usuario;
    }

    private function buzon(bool $activo, ?int $aseguradoraId = null, ?string $direccion = null): CuentaCorreo
    {
        return CuentaCorreo::query()->create([
            'direccion' => $direccion ?: ('nav-'.($activo ? 'on' : 'off').'-'.($aseguradoraId ?: 'inst').'@ejemplo.test'),
            'activo' => $activo,
            'tipo_integracion' => 'google_api',
            'credential_ref' => 'MAIL_CRED_GMAIL_TEST',
            'aseguradora_id' => $aseguradoraId,
            'es_buzon_central' => false,
        ]);
    }

    private function caso(User $usuario, ?int $aseguradoraId): Pqrsd
    {
        return app(PqrsdService::class)->radicarManual([
            'canal_ingreso_id' => CanalIngreso::query()->where('codigo', 'CORREO')->value('id'),
            'nivel_riesgo' => 'pendiente',
            'descripcion' => 'Expediente de prueba para la navegacion de correos.',
            'solicitante_nombre' => 'Ciudadano de prueba',
            'solicitante_email' => 'ciudadano@example.test',
            'aseguradora_id' => $aseguradoraId,
        ], $usuario);
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
        $this->assertNull($resultado['error']);

        return $resultado['mensaje'];
    }

    private function vincular(CuentaCorreo $cuenta, Pqrsd $caso, string $asunto, string $hilo): MensajeCorreo
    {
        return MensajeCorreo::query()->create([
            'cuenta_correo_id' => $cuenta->id,
            'pqrsd_id' => $caso->id,
            'message_id' => '<'.$asunto.'@example.test>',
            'thread_key' => $hilo,
            'gmail_message_id' => 'g-'.$caso->id.'-'.$cuenta->id,
            'remitente' => 'ciudadano@example.test',
            'destinatario' => $cuenta->direccion,
            'asunto' => $asunto,
            'cuerpo_texto' => 'Contenido vinculado al expediente.',
            'fecha_mensaje' => now('America/Bogota'),
            'fecha_importacion' => now('America/Bogota'),
            'direccion' => 'entrante',
            'clasificacion' => 'nueva_pqrsd',
            'estado_procesamiento' => 'procesado',
        ]);
    }
}
