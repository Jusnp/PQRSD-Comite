<?php

namespace Tests\Feature;

use App\Mail\DTO\InboundMessageData;
use App\Models\CuentaCorreo;
use App\Models\MensajeCorreo;
use App\Models\Pqrsd;
use App\Models\User;
use App\Services\Correo\EmailClassifier;
use App\Services\Correo\EmailIngestionService;
use App\Services\Correo\EmailReplyService;
use App\Services\Correo\EmailReviewService;
use App\Services\Correo\EmailSyncService;
use Carbon\Carbon;
use Database\Seeders\CatalogosSeeder;
use Database\Seeders\CuentasCorreoSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class CorreoRecepcionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(CatalogosSeeder::class);
        $this->seed(CuentasCorreoSeeder::class);
    }

    public function test_sincroniza_fixtures_mock_sin_credenciales_en_bd(): void
    {
        $cuenta = CuentaCorreo::where('direccion', 'gestionsaviasalud@menteplena.com.co')->firstOrFail();
        $this->assertNotNull($cuenta->credential_ref);
        $this->assertDatabaseMissing('cuentas_correo', ['direccion' => $cuenta->direccion, 'credential_ref' => null]);

        $resultado = app(EmailSyncService::class)->syncCuenta($cuenta);

        $this->assertGreaterThanOrEqual(1, $resultado['nuevos']);
        $this->assertDatabaseHas('mensajes_correo', [
            'message_id' => '<mock-nueva-001@paciente.test>',
            'clasificacion' => 'pendiente_revision',
        ]);
        $this->assertTrue(
            ! \Schema::hasColumn('cuentas_correo', 'password')
        );
    }

    public function test_detecta_respuesta_por_radicado_y_headers(): void
    {
        $user = User::factory()->create(['activo' => true]);
        $user->assignRole('auxiliar_pqrsd');

        $canal = \App\Models\CanalIngreso::where('codigo', 'CORREO')->firstOrFail();
        $caso = app(\App\Services\PqrsdService::class)->radicarManual([
            'canal_ingreso_id' => $canal->id,
            'nivel_riesgo' => 'ordinario',
            'descripcion' => 'Caso base ficticio',
            'es_anonimo' => 1,
        ], $user);

        $cuenta = CuentaCorreo::where('es_buzon_central', true)->firstOrFail();
        $parentId = '<parent-thread@test>';

        MensajeCorreo::create([
            'cuenta_correo_id' => $cuenta->id,
            'pqrsd_id' => $caso->id,
            'message_id' => $parentId,
            'thread_key' => sha1(strtolower($parentId)),
            'remitente' => 'plataforma@menteplena.test',
            'destinatario' => 'ciudadano@example.test',
            'asunto' => 'Respuesta '.$caso->radicado,
            'cuerpo_texto' => 'Saliente',
            'fecha_mensaje' => now('America/Bogota')->subDay(),
            'fecha_importacion' => now('America/Bogota')->subDay(),
            'direccion' => 'saliente',
            'clasificacion' => 'respuesta_caso',
            'estado_procesamiento' => 'procesado',
            'radicado_detectado' => $caso->radicado,
        ]);

        $reply = new InboundMessageData(
            messageId: '<reply-1@test>',
            inReplyTo: $parentId,
            references: $parentId,
            fromAddress: 'ciudadano@example.test',
            fromName: 'Ciudadano',
            toAddress: $cuenta->direccion,
            cc: null,
            originalRecipient: null,
            subject: 'Re: seguimiento',
            bodyText: 'Gracias por la respuesta',
            bodyHtml: null,
            rawHeaders: null,
            rawEml: 'RAW EML PRESERVADO',
            date: Carbon::now('America/Bogota'),
        );

        $resultado = app(EmailIngestionService::class)->ingest($cuenta, $reply);
        $this->assertFalse($resultado['duplicado']);
        $this->assertSame('respuesta_caso', $resultado['mensaje']->clasificacion);
        $this->assertSame($caso->id, $resultado['mensaje']->pqrsd_id);
        $this->assertNotNull($resultado['mensaje']->raw_eml_path);
    }

    public function test_no_duplica_por_message_id(): void
    {
        $cuenta = CuentaCorreo::where('direccion', 'gestionsaviasalud@menteplena.com.co')->firstOrFail();
        $data = new InboundMessageData(
            messageId: '<dup-test@menteplena.test>',
            inReplyTo: null,
            references: null,
            fromAddress: 'a@example.test',
            fromName: null,
            toAddress: $cuenta->direccion,
            cc: null,
            originalRecipient: null,
            subject: 'Queja de prueba',
            bodyText: 'Contenido',
            bodyHtml: null,
            rawHeaders: null,
            rawEml: null,
            date: now('America/Bogota'),
        );

        $ingestion = app(EmailIngestionService::class);
        $primera = $ingestion->ingest($cuenta, $data);
        $segunda = $ingestion->ingest($cuenta, $data);

        $this->assertFalse($primera['duplicado']);
        $this->assertTrue($segunda['duplicado']);
        $this->assertSame(1, MensajeCorreo::where('message_id', '<dup-test@menteplena.test>')->count());
    }

    public function test_clasifica_ruido_comercial(): void
    {
        $data = new InboundMessageData(
            messageId: '<noise@test>',
            inReplyTo: null,
            references: null,
            fromAddress: 'noreply@marketing.test',
            fromName: null,
            toAddress: 'atencionalusuario@menteplena.com.co',
            cc: null,
            originalRecipient: null,
            subject: 'Newsletter — unsubscribe',
            bodyText: 'Oferta',
            bodyHtml: null,
            rawHeaders: null,
            rawEml: null,
            date: now('America/Bogota'),
        );

        $c = app(EmailClassifier::class)->classify($data);
        $this->assertSame('ruido', $c['clasificacion']);
    }

    public function test_bandeja_permite_confirmar_y_responder(): void
    {
        $user = User::factory()->create(['activo' => true]);
        $user->assignRole('lider_pqrsd');

        $cuenta = CuentaCorreo::where('direccion', 'gestionsaviasalud@menteplena.com.co')->firstOrFail();
        app(EmailSyncService::class)->syncCuenta($cuenta);

        $mensaje = MensajeCorreo::where('message_id', '<mock-nueva-001@paciente.test>')->firstOrFail();

        $this->actingAs($user)
            ->post(route('bandeja-correo.confirmar', $mensaje))
            ->assertRedirect();

        $mensaje->refresh();
        $this->assertSame('nueva_pqrsd', $mensaje->clasificacion);
        $this->assertNotNull($mensaje->pqrsd_id);

        $reply = app(EmailReplyService::class)->responder($mensaje, 'Respuesta de prueba institucional.', $user);
        $this->assertTrue($reply['ok']);
        $this->assertDatabaseHas('mensajes_correo', [
            'direccion' => 'saliente',
            'pqrsd_id' => $mensaje->pqrsd_id,
            'in_reply_to' => $mensaje->message_id,
        ]);
    }

    public function test_imap_no_guarda_password_y_exige_env(): void
    {
        $cuenta = CuentaCorreo::where('direccion', 'gestionsaviasalud@menteplena.com.co')->firstOrFail();
        $cuenta->update([
            'tipo_integracion' => 'imap',
            'host' => 'outlook.office365.com',
            'puerto' => 993,
            'credential_ref' => 'MAIL_CRED_GESTIONSAVIA',
        ]);

        $test = app(\App\Mail\MailProviderManager::class)->for($cuenta)->testConnection($cuenta);
        $this->assertFalse($test['ok']);
        $this->assertStringContainsString('MAIL_CRED_GESTIONSAVIA', $test['detalle']);
    }
}
