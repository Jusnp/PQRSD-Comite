<?php

namespace Tests\Feature;

use App\Jobs\ProcesarSalidaCorreoJob;
use App\Mail\DTO\InboundMessageData;
use App\Mail\Gmail\FakeGmailApiClient;
use App\Mail\Gmail\GmailApiClient;
use App\Models\Aseguradora;
use App\Models\CanalIngreso;
use App\Models\CorreoSalida;
use App\Models\CuentaCorreo;
use App\Models\MensajeCorreo;
use App\Models\TipoPqrsd;
use App\Models\User;
use App\Services\Correo\AvisoRadicacionInstitucional;
use App\Services\Correo\EmailIngestionService;
use App\Services\Correo\EmailReplyService;
use App\Services\Correo\EmailReviewService;
use App\Services\PqrsdService;
use Carbon\Carbon;
use Database\Seeders\CatalogosSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BuzonesCorreoMultiplesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(CatalogosSeeder::class);
    }

    public function test_callcenter_queda_registrado_inactivo_y_sin_consultar_gmail(): void
    {
        $cuenta = CuentaCorreo::query()->where('direccion', 'callcenter@menteplena.com.co')->firstOrFail();

        $this->assertFalse($cuenta->activo);
        $this->assertTrue($cuenta->recibe_radicacion_web);
        $this->assertSame('desconocido', $cuenta->tipo_buzon);
        $this->assertSame('pendiente', $cuenta->verificacion_buzon);

        $this->artisan('pqrsd:verificar-tipo-buzon', ['direccion' => $cuenta->direccion])
            ->assertOk();

        $this->assertSame('pendiente', $cuenta->fresh()->verificacion_buzon);
    }

    public function test_la_radicacion_web_usa_el_buzon_de_la_organizacion_y_no_otro(): void
    {
        $eps = Aseguradora::query()->where('activo', true)->orderBy('id')->firstOrFail();
        $this->buzon('institucional@ejemplo.test', null);
        $propio = $this->buzon('eps@ejemplo.test', $eps->id);
        $caso = $this->caso($eps->id);

        $salida = app(AvisoRadicacionInstitucional::class)->encolar($caso);

        $this->assertSame($propio->id, $salida->cuenta_correo_id);
    }

    public function test_dos_buzones_institucionales_no_se_eligen_solos(): void
    {
        $this->buzon('uno@ejemplo.test', null);
        $this->buzon('dos@ejemplo.test', null);
        $falso = new FakeGmailApiClient;
        $this->app->instance(GmailApiClient::class, $falso);

        $salida = app(AvisoRadicacionInstitucional::class)->encolar($this->caso(null));

        $this->assertSame('pendiente_regla', $salida->estado);
        $this->assertNull($salida->cuenta_correo_id);
        $this->assertSame([], $falso->inserted);
    }

    public function test_un_fallo_parcial_no_vuelve_a_insertar_el_aviso(): void
    {
        $cuenta = $this->buzon('institucional@ejemplo.test', null);
        $falso = new FakeGmailApiClient;
        $this->app->instance(GmailApiClient::class, $falso);
        $salida = CorreoSalida::query()->create([
            'pqrsd_id' => $this->caso(null)->id,
            'cuenta_correo_id' => $cuenta->id,
            'tipo' => 'radicacion_web',
            'estado' => 'enviando',
            'idempotency_key' => 'radicacion-web:parcial',
            'payload' => ['radicado' => 'PQRSD-2026-000099', 'tipo' => 'Petición', 'origen' => 'radicacion-web'],
            'proveedor_invocado' => true,
        ]);

        (new ProcesarSalidaCorreoJob($salida->id))->handle(
            app(AvisoRadicacionInstitucional::class),
            app(\App\Mail\Gmail\GmailCredentialResolver::class),
        );

        $this->assertSame('requiere_revision', $salida->fresh()->estado);
        $this->assertSame([], $falso->inserted);
    }

    public function test_la_respuesta_del_hilo_queda_en_el_mismo_expediente_y_no_cruza_organizaciones(): void
    {
        $eps = Aseguradora::query()->where('activo', true)->orderBy('id')->firstOrFail();
        $otra = Aseguradora::query()->where('activo', true)->where('id', '!=', $eps->id)->firstOrFail();
        $cuenta = $this->buzon('eps@ejemplo.test', $eps->id);
        $ajena = $this->buzon('otra@ejemplo.test', $otra->id);
        $caso = $this->caso($eps->id);
        $original = MensajeCorreo::query()->create([
            'cuenta_correo_id' => $cuenta->id,
            'pqrsd_id' => $caso->id,
            'message_id' => '<hilo-original@ciudadano.test>',
            'remitente' => 'ciudadano@example.test',
            'destinatario' => $cuenta->direccion,
            'asunto' => 'Solicitud '.$caso->radicado,
            'fecha_mensaje' => now('America/Bogota'),
            'fecha_importacion' => now('America/Bogota'),
            'direccion' => 'entrante',
            'clasificacion' => 'respuesta_caso',
            'estado_procesamiento' => 'procesado',
            'radicado_detectado' => $caso->radicado,
        ]);

        $propia = app(EmailIngestionService::class)->ingest($cuenta, $this->respuesta('ciudadano-1@example.test', $original->message_id));
        $cruzada = app(EmailIngestionService::class)->ingest($ajena, $this->respuesta('ciudadano-2@example.test', $original->message_id));

        $this->assertSame($caso->id, $propia['mensaje']->pqrsd_id);
        $this->assertNull($cruzada['mensaje']->pqrsd_id);

        $antes = \App\Models\Pqrsd::query()->count();
        $repetido = app(EmailIngestionService::class)->ingest($ajena, new InboundMessageData(
            messageId: $original->message_id,
            inReplyTo: null,
            references: null,
            fromAddress: 'ciudadano@example.test',
            fromName: null,
            toAddress: $ajena->direccion,
            cc: null,
            originalRecipient: null,
            subject: 'Solicitud '.$caso->radicado,
            bodyText: 'Mismo identificador en otro buzón.',
            bodyHtml: null,
            rawHeaders: null,
            rawEml: null,
            date: Carbon::now('America/Bogota'),
        ));
        $this->assertTrue($repetido['duplicado']);
        $this->assertNull($repetido['mensaje']->pqrsd_id);
        $this->assertSame($antes, \App\Models\Pqrsd::query()->count());
        $this->expectException(\InvalidArgumentException::class);
        app(EmailReviewService::class)->asociarACaso($cruzada['mensaje'], $caso->id, User::factory()->create());
    }

    public function test_expediente_y_bandeja_comparten_el_envio_y_no_lo_repiten(): void
    {
        $cuenta = $this->buzon('institucional@ejemplo.test', null);
        $falso = new FakeGmailApiClient;
        $this->app->instance(GmailApiClient::class, $falso);
        $caso = $this->caso(null);
        $admin = User::factory()->create(['activo' => true]);
        $admin->assignRole('admin');
        $responsable = User::factory()->create(['activo' => true]);
        $responsable->assignRole('responsable_area');

        $this->actingAs($responsable)
            ->post(route('pqrsd.correo-responder', $caso), [
                'destinatario' => 'ciudadano@example.test',
                'cuerpo' => 'Respuesta autorizada de prueba.',
            ])
            ->assertForbidden();

        $this->actingAs($admin)
            ->post(route('pqrsd.correo-responder', $caso), [
                'destinatario' => 'ciudadano@example.test',
                'cuerpo' => 'Respuesta autorizada de prueba.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->actingAs($admin)
            ->post(route('pqrsd.correo-responder', $caso), [
                'destinatario' => 'ciudadano@example.test',
                'cuerpo' => 'Respuesta autorizada de prueba.',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('cuerpo');

        $this->assertSame(1, $falso->sendAttempts);
        $this->assertSame(1, MensajeCorreo::query()->where('direccion', 'saliente')->count());
        $this->assertSame($cuenta->id, MensajeCorreo::query()->where('direccion', 'saliente')->value('cuenta_correo_id'));

        $entrante = MensajeCorreo::query()->create([
            'cuenta_correo_id' => $cuenta->id,
            'pqrsd_id' => $caso->id,
            'message_id' => '<otra-entrada@ciudadano.test>',
            'remitente' => 'otro@example.test',
            'destinatario' => $cuenta->direccion,
            'asunto' => 'Seguimiento',
            'fecha_mensaje' => now('America/Bogota'),
            'fecha_importacion' => now('America/Bogota'),
            'direccion' => 'entrante',
            'clasificacion' => 'respuesta_caso',
            'estado_procesamiento' => 'procesado',
        ]);
        $primero = app(EmailReplyService::class)->responder($entrante, 'Respuesta de bandeja.', $admin);
        $segundo = app(EmailReplyService::class)->responder($entrante, 'Respuesta de bandeja.', $admin);

        $this->assertTrue($primero['ok']);
        $this->assertFalse($segundo['ok']);
        $this->assertSame(2, $falso->sendAttempts);
    }

    private function buzon(string $direccion, ?int $aseguradoraId): CuentaCorreo
    {
        return CuentaCorreo::query()->create([
            'direccion' => $direccion,
            'direccion_visible' => $direccion,
            'activo' => true,
            'recibe_radicacion_web' => true,
            'aseguradora_id' => $aseguradoraId,
            'tipo_buzon' => 'individual',
            'tipo_integracion' => 'google_api',
            'estado_conexion' => 'pendiente_credenciales',
            'verificacion_buzon' => 'usuario',
        ]);
    }

    private function caso(?int $aseguradoraId)
    {
        $user = User::factory()->create(['activo' => true]);
        $user->assignRole('admin');

        return app(PqrsdService::class)->radicarManual([
            'fecha_recepcion' => Carbon::parse('2026-11-08 11:00:00', 'America/Bogota'),
            'canal_ingreso_id' => CanalIngreso::where('codigo', 'MANUAL')->value('id'),
            'tipo_pqrsd_id' => TipoPqrsd::where('codigo', 'PETICION')->value('id'),
            'nivel_riesgo' => 'pendiente',
            'descripcion' => 'Relato que no debe copiarse al correo',
            'solicitante_nombre' => 'Persona de prueba',
            'solicitante_email' => 'ciudadano@example.test',
            'aseguradora_id' => $aseguradoraId,
        ], $user);
    }

    private function respuesta(string $messageId, string $inReplyTo): InboundMessageData
    {
        return new InboundMessageData(
            messageId: '<'.$messageId.'>',
            inReplyTo: $inReplyTo,
            references: $inReplyTo,
            fromAddress: 'ciudadano@example.test',
            fromName: null,
            toAddress: 'eps@ejemplo.test',
            cc: null,
            originalRecipient: null,
            subject: 'Re: solicitud',
            bodyText: 'Gracias, continúo en este hilo.',
            bodyHtml: null,
            rawHeaders: null,
            rawEml: null,
            date: Carbon::now('America/Bogota'),
        );
    }
}
