<?php

namespace Tests\Feature;

use App\Models\AdjuntoPqrsd;
use App\Models\Pqrsd;
use App\Models\TipoPqrsd;
use App\Models\User;
use Database\Seeders\CatalogosSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\UsersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class AnonimoYMediosRespuestaTest extends TestCase
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

    private function postPublico(array $extra = [])
    {
        return $this->post(route('publico.store'), array_merge([
            'idempotency_key' => (string) Str::uuid(),
            'quien_radica' => 'paciente',
            'asunto' => 'Asunto de manifestación',
            'descripcion' => 'Descripción de los hechos con longitud suficiente para la validación del formulario.',
            'acepto_tratamiento_datos' => '1',
            'solicitante_telefono' => '3001234567',
        ], $extra));
    }

    public function test_radicacion_identificada_normal(): void
    {
        $tipo = TipoPqrsd::where('codigo', 'PETICION')->firstOrFail();
        $this->postPublico([
            'tipo_pqrsd_id' => $tipo->id,
            'quien_radica' => 'paciente',
            'solicitante_nombre' => 'Ana Identificada',
            'solicitante_tipo_documento' => 'CC',
            'solicitante_documento' => '111222333',
            'solicitante_email' => 'ana@example.test',
            'medio_respuesta' => 'email',
            'consentimiento_comunicacion_email' => '1',
        ])->assertRedirect();

        $caso = Pqrsd::latest('id')->firstOrFail();
        $this->assertFalse($caso->es_anonimo);
        $this->assertSame('Ana Identificada', $caso->solicitante_nombre);
        $this->assertSame('email', $caso->medio_respuesta);
        $this->assertTrue($caso->consentimiento_comunicacion_email);
        $this->assertSame('pendiente', $caso->nivel_riesgo);
        $this->assertNotNull($caso->consulta_token_hash);
    }

    public function test_radicacion_anonima_admitida_sin_identidad(): void
    {
        $tipo = TipoPqrsd::where('codigo', 'QUEJA')->firstOrFail();
        $this->postPublico([
            'tipo_pqrsd_id' => $tipo->id,
            'es_anonimo' => '1',
            'medio_respuesta' => 'consulta_segura',
        ])->assertRedirect();

        $caso = Pqrsd::latest('id')->firstOrFail();
        $this->assertTrue($caso->es_anonimo);
        $this->assertNull($caso->solicitante_nombre);
        $this->assertNull($caso->solicitante_documento);
        $this->assertSame('consulta_segura', $caso->medio_respuesta);
        $this->assertFalse($caso->anonimo_pendiente_validacion_juridica);
    }

    public function test_radicacion_anonima_con_correo_y_direccion(): void
    {
        $queja = TipoPqrsd::where('codigo', 'QUEJA')->firstOrFail();
        $this->postPublico([
            'tipo_pqrsd_id' => $queja->id,
            'es_anonimo' => '1',
            'medio_respuesta' => 'email',
            'solicitante_email' => 'anonimo.contacto@example.test',
            'consentimiento_comunicacion_email' => '1',
        ])->assertRedirect();

        $c1 = Pqrsd::latest('id')->firstOrFail();
        $this->assertTrue($c1->es_anonimo);
        $this->assertNull($c1->solicitante_nombre);
        $this->assertSame('anonimo.contacto@example.test', $c1->solicitante_email);

        $denuncia = TipoPqrsd::where('codigo', 'DENUNCIA')->firstOrFail();
        $this->postPublico([
            'tipo_pqrsd_id' => $denuncia->id,
            'es_anonimo' => '1',
            'medio_respuesta' => 'consulta_segura',
        ])->assertRedirect();

        $c2 = Pqrsd::latest('id')->firstOrFail();
        $this->assertSame('consulta_segura', $c2->medio_respuesta);
        $this->assertNull($c2->solicitante_direccion);
        $this->assertNull($c2->solicitante_telefono);
        $this->assertNull($c2->solicitante_documento);

        $this->postPublico([
            'tipo_pqrsd_id' => $denuncia->id,
            'es_anonimo' => '1',
            'medio_respuesta' => 'telefono',
            'solicitante_telefono' => '3001234567',
        ])->assertSessionHasErrors('medio_respuesta');
    }

    public function test_consulta_estado_solo_radicado_y_consulta_segura_protegida(): void
    {
        $tipo = TipoPqrsd::where('codigo', 'DENUNCIA')->firstOrFail();
        $response = $this->postPublico([
            'tipo_pqrsd_id' => $tipo->id,
            'es_anonimo' => '1',
            'medio_respuesta' => 'consulta_segura',
        ]);
        $response->assertRedirect(route('publico.confirmacion'));
        $payload = session('publico_confirmacion');
        $this->assertIsArray($payload);
        $this->assertNotEmpty($payload['radicado'] ?? null);
        $this->assertNotEmpty($payload['token'] ?? null);
        $this->assertGreaterThanOrEqual(20, strlen($payload['token']));

        $this->post(route('publico.consulta.store'), [
            'radicado' => $payload['radicado'],
        ])->assertOk()
            ->assertSee($payload['radicado'])
            ->assertSee('Recibida')
            ->assertDontSee($payload['token']);

        $this->post(route('publico.consulta.segura.store'), [
            'radicado' => $payload['radicado'],
            'token' => $payload['token'],
        ])->assertOk()->assertSee($payload['radicado']);

        $this->from(route('publico.consulta.segura'))->post(route('publico.consulta.segura.store'), [
            'radicado' => $payload['radicado'],
            'token' => 'token-incorrecto-para-enumeracion-xxxx',
        ])->assertRedirect();

        $this->from(route('publico.consulta'))->post(route('publico.consulta.store'), [
            'radicado' => 'PQRSD-2099-999999',
        ])->assertRedirect();

        $this->get(route('publico.consulta', [
            'radicado' => $payload['radicado'],
            'token' => $payload['token'],
        ]))->assertRedirect(route('publico.consulta'));

        $this->get(route('publico.consulta.segura', [
            'radicado' => $payload['radicado'],
            'token' => $payload['token'],
        ]))->assertRedirect(route('publico.consulta.segura'));
    }

    public function test_respuesta_anonima_en_consulta_segura_y_bandeja(): void
    {
        $tipo = TipoPqrsd::where('codigo', 'QUEJA')->firstOrFail();
        $pdf = UploadedFile::fake()->createWithContent('soporte.pdf', "%PDF-1.4\nok\n");
        $response = $this->postPublico([
            'tipo_pqrsd_id' => $tipo->id,
            'es_anonimo' => '1',
            'medio_respuesta' => 'consulta_segura',
            'adjuntos' => [$pdf],
        ]);
        $payload = session('publico_confirmacion');
        $caso = Pqrsd::where('radicado', $payload['radicado'])->firstOrFail();
        $this->assertSame(1, AdjuntoPqrsd::where('pqrsd_id', $caso->id)->count());

        $admin = User::where('email', 'admin@menteplena.test')->firstOrFail();
        $this->actingAs($admin)
            ->get(route('pqrsd.index'))
            ->assertOk()
            ->assertSee($caso->radicado)
            ->assertSee('Anónimo');

        $this->actingAs($admin)->post(route('pqrsd.responder', $caso), $this->conVersionExpediente($caso, [
            'detalle_respuesta' => 'Respuesta de PQRS para solicitante anónimo, sin datos clínicos.',
            'poner_disposicion_consulta' => '1',
        ]))->assertRedirect();

        $caso->refresh();
        $this->assertSame('puesta_disposicion', $caso->respuesta_estado);
        $this->assertNotNull($caso->respuesta_puesta_disposicion_en);
        $this->assertNull($caso->respuesta_consultada_en);

        $this->post(route('publico.consulta.store'), [
            'radicado' => $payload['radicado'],
        ])->assertOk()
            ->assertSee('En trámite')
            ->assertDontSee('Respuesta de PQRS para solicitante anónimo')
            ->assertDontSee('soporte.pdf');

        $this->post(route('publico.consulta.segura.store'), [
            'radicado' => $payload['radicado'],
            'token' => $payload['token'],
        ])->assertOk()
            ->assertSee('Respuesta de PQRS para solicitante anónimo')
            ->assertDontSee('soporte.pdf');

        $caso->refresh();
        $this->assertSame('consultada', $caso->respuesta_estado);
        $this->assertNotNull($caso->respuesta_consultada_en);

        $ajeno = User::factory()->create(['activo' => true]);
        $adj = AdjuntoPqrsd::where('pqrsd_id', $caso->id)->firstOrFail();
        $this->actingAs($ajeno)
            ->get(route('pqrsd.adjuntos.download', [$caso, $adj]))
            ->assertForbidden();
    }

    public function test_formulario_sin_urgencia_vital_y_sla_intactos(): void
    {
        $this->get(route('publico.formulario'))
            ->assertOk()
            ->assertSee('Deseo presentar esta solicitud sin revelar mi identidad')
            ->assertDontSee('Creo que esta situación implica urgencia vital')
            ->assertDontSee('name="posible_urgencia"', false);

        $this->assertSame(95, (int) config('pqrsd_sla.meta_oportunidad_porcentaje', 95));
        $this->assertDatabaseHas('reglas_sla', ['codigo' => 'DENUNCIA_10DH', 'activo' => 1]);
    }

    public function test_auxiliares_gestionan_anonimos_sin_admin(): void
    {
        $tipo = TipoPqrsd::where('codigo', 'DENUNCIA')->firstOrFail();
        $this->postPublico([
            'tipo_pqrsd_id' => $tipo->id,
            'es_anonimo' => '1',
            'medio_respuesta' => 'consulta_segura',
        ])->assertRedirect();
        $caso = Pqrsd::latest('id')->firstOrFail();

        $aux = User::where('email', 'auxiliar1@menteplena.test')->firstOrFail();
        $this->actingAs($aux)->get(route('pqrsd.show', $caso))->assertOk()->assertSee('sin revelar identidad');
        $this->actingAs($aux)->post(route('pqrsd.responder', $caso), $this->conVersionExpediente($caso, [
            'detalle_respuesta' => 'Respuesta elaborada por auxiliar para caso anónimo.',
            'respuesta_estado_destino' => 'elaborada',
        ]))->assertRedirect();
        $this->assertSame('elaborada', $caso->fresh()->respuesta_estado);
        $this->actingAs($aux)->get(route('usuarios.index'))->assertForbidden();
    }
}
