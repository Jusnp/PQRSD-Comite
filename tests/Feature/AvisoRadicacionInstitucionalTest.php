<?php

namespace Tests\Feature;

use App\Jobs\ProcesarSalidaCorreoJob;
use App\Mail\DTO\InboundMessageData;
use App\Mail\Gmail\FakeGmailApiClient;
use App\Mail\Gmail\GmailApiClient;
use App\Models\CanalIngreso;
use App\Models\CorreoSalida;
use App\Models\CuentaCorreo;
use App\Models\TipoPqrsd;
use App\Models\User;
use App\Services\Correo\AvisoRadicacionInstitucional;
use App\Services\Correo\EmailClassifier;
use App\Services\PqrsdService;
use Carbon\Carbon;
use Database\Seeders\CatalogosSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AvisoRadicacionInstitucionalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(CatalogosSeeder::class);
    }

    public function test_encola_un_aviso_sin_relato_y_no_contacta_gmail_sin_credencial(): void
    {
        $caso = $this->caso('Dolor torácico y número de documento 123456');

        $primera = app(AvisoRadicacionInstitucional::class)->encolar($caso);
        $segunda = app(AvisoRadicacionInstitucional::class)->encolar($caso);

        $this->assertSame($primera->id, $segunda->id);
        $this->assertSame(1, CorreoSalida::query()->count());
        $this->assertSame('pendiente_regla', $primera->fresh()->estado);
        $this->assertStringNotContainsString('Dolor torácico', json_encode($primera->payload));
        $this->assertStringNotContainsString('123456', json_encode($primera->payload));
        $this->assertSame($caso->radicado, $primera->payload['radicado']);
    }

    public function test_inserta_en_inbox_con_cliente_falso_y_no_abre_otro_expediente(): void
    {
        $caso = $this->caso('Relato clínico que no debe viajar');
        $cuenta = CuentaCorreo::query()->create([
            'direccion' => 'pqrsd@institucion.test',
            'activo' => true,
            'es_buzon_central' => true,
            'recibe_radicacion_web' => true,
            'tipo_integracion' => 'google_api',
            'estado_conexion' => 'pendiente_credenciales',
        ]);
        $falso = new FakeGmailApiClient;
        $this->app->instance(GmailApiClient::class, $falso);

        $salida = app(AvisoRadicacionInstitucional::class)->encolar($caso);
        (new ProcesarSalidaCorreoJob($salida->id))->handle(
            app(AvisoRadicacionInstitucional::class),
            app(\App\Mail\Gmail\GmailCredentialResolver::class),
        );

        $salida->refresh();
        $this->assertSame('enviado', $salida->estado);
        $this->assertSame('inbox-1', $salida->gmail_message_id);
        $this->assertNotNull($salida->gmail_thread_id);
        $this->assertContains('INBOX', $falso->inserted[0]['labelIds']);
        $this->assertStringContainsString($caso->radicado, $falso->inserted[0]['raw']);
        $this->assertStringContainsString('X-PQRSD-Origen: radicacion-web', $falso->inserted[0]['raw']);
        $this->assertStringNotContainsString('Relato clínico', $falso->inserted[0]['raw']);
        $this->assertSame($cuenta->id, $salida->cuenta_correo_id);

        $clasificacion = app(EmailClassifier::class)->classify(new InboundMessageData(
            messageId: $salida->rfc_message_id,
            inReplyTo: null,
            references: null,
            fromAddress: $cuenta->direccion,
            fromName: null,
            toAddress: $cuenta->direccion,
            cc: null,
            originalRecipient: $cuenta->direccion,
            subject: 'PQRSD '.$caso->radicado,
            bodyText: 'Radicado: '.$caso->radicado,
            bodyHtml: null,
            rawHeaders: "x-pqrsd-origen: radicacion-web\n",
            rawEml: null,
            date: Carbon::now('America/Bogota'),
        ));

        $this->assertSame('respuesta_caso', $clasificacion['clasificacion']);
        $this->assertSame($caso->id, $clasificacion['pqrsd_id']);
        $this->assertNotSame('nueva_pqrsd', $clasificacion['clasificacion']);
    }

    public function test_el_expediente_muestra_el_aviso_y_rechaza_reenviarlo_si_ya_tiene_identificador(): void
    {
        $caso = $this->caso('Texto que permanece en el expediente');
        $admin = User::factory()->create(['activo' => true]);
        $admin->assignRole('admin');
        app(AvisoRadicacionInstitucional::class)->encolar($caso);

        $this->actingAs($admin)
            ->get(route('pqrsd.show', $caso))
            ->assertOk()
            ->assertSee('Correo institucional')
            ->assertSee('pendiente_regla')
            ->assertSee('sin identificador');

        $salida = CorreoSalida::query()->firstOrFail();
        $salida->update(['estado' => 'enviado', 'gmail_message_id' => 'ya-entregado']);

        $this->actingAs($admin)
            ->from(route('pqrsd.show', $caso))
            ->post(route('pqrsd.correo-salidas.reintentar', [$caso, $salida]), [
                '_token' => session()->token(),
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame('ya-entregado', $salida->fresh()->gmail_message_id);
        $this->assertSame('enviado', $salida->fresh()->estado);
    }

    private function caso(string $descripcion)
    {
        $user = User::factory()->create(['activo' => true]);
        $user->assignRole('admin');

        return app(PqrsdService::class)->radicarManual([
            'fecha_recepcion' => Carbon::parse('2026-11-08 10:00:00', 'America/Bogota'),
            'canal_ingreso_id' => CanalIngreso::where('codigo', 'MANUAL')->value('id'),
            'tipo_pqrsd_id' => TipoPqrsd::where('codigo', 'PETICION')->value('id'),
            'nivel_riesgo' => 'pendiente',
            'descripcion' => $descripcion,
            'solicitante_nombre' => 'Persona de prueba',
            'solicitante_documento' => '123456',
        ], $user);
    }
}
