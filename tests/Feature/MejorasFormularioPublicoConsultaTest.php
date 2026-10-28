<?php

namespace Tests\Feature;

use App\Models\Pqrsd;
use App\Models\TipoPqrsd;
use App\Models\User;
use Database\Seeders\CatalogosSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\UsersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

class MejorasFormularioPublicoConsultaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(CatalogosSeeder::class);
        $this->seed(UsersSeeder::class);
    }

    private function base(array $extra = []): array
    {
        return array_merge([
            'idempotency_key' => (string) Str::uuid(),
            'tipo_pqrsd_id' => TipoPqrsd::where('codigo', 'PETICION')->value('id'),
            'quien_radica' => 'paciente',
            'asunto' => 'Asunto de prueba ciudadana',
            'descripcion' => 'Descripción de los hechos con longitud suficiente para la validación del formulario público.',
            'acepto_tratamiento_datos' => '1',
            'solicitante_telefono' => '3001234567',
            'medio_respuesta' => 'consulta_segura',
        ], $extra);
    }

    public function test_consulta_estado_solo_con_radicado(): void
    {
        $this->post(route('publico.store'), $this->base([
            'solicitante_nombre' => 'Paciente Solo',
            'solicitante_tipo_documento' => 'CC',
            'solicitante_documento' => '111',
        ]))->assertRedirect();
        $payload = session('publico_confirmacion');

        $this->get(route('publico.consulta'))
            ->assertOk()
            ->assertSee('Consultar estado de PQRSD')
            ->assertDontSee('Código de consulta', false);

        $this->post(route('publico.consulta.store'), ['radicado' => $payload['radicado']])
            ->assertOk()
            ->assertSee('Recibida')
            ->assertSee($payload['radicado']);
    }

    public function test_consulta_solo_radicado_no_expone_datos_privados(): void
    {
        $this->post(route('publico.store'), $this->base([
            'solicitante_nombre' => 'Nombre Sensible Privado',
            'solicitante_tipo_documento' => 'CC',
            'solicitante_documento' => '9988776655',
            'solicitante_email' => 'privado.sensibilidad@example.test',
            'solicitante_telefono' => '3001112233',
            'medio_respuesta' => 'email',
            'consentimiento_comunicacion_email' => '1',
        ]))->assertRedirect();
        $radicado = session('publico_confirmacion')['radicado'];

        $this->post(route('publico.consulta.store'), ['radicado' => $radicado])
            ->assertOk()
            ->assertDontSee('Nombre Sensible Privado')
            ->assertDontSee('9988776655')
            ->assertDontSee('privado.sensibilidad@example.test')
            ->assertDontSee('3001112233')
            ->assertSee('no muestra nombres');
    }

    public function test_proteccion_enumeracion_y_abuso_consulta_estado(): void
    {
        config(['pqrsd_publico.consulta_estado.max_intentos' => 3, 'pqrsd_publico.consulta_estado.ventana_segundos' => 300]);
        RateLimiter::clear('consulta-estado:127.0.0.1');

        for ($i = 0; $i < 3; $i++) {
            $this->from(route('publico.consulta'))
                ->post(route('publico.consulta.store'), ['radicado' => 'PQRSD-2099-'.str_pad((string) $i, 6, '0', STR_PAD_LEFT)])
                ->assertRedirect();
        }

        $this->from(route('publico.consulta'))
            ->post(route('publico.consulta.store'), ['radicado' => 'PQRSD-2099-000099'])
            ->assertRedirect()
            ->assertSessionHasErrors('radicado');

        $this->assertStringContainsString(
            'Demasiados intentos',
            session('errors')->first('radicado')
        );
    }

    public function test_paciente_presenta_su_propia_pqrsd_sin_duplicar(): void
    {
        $this->post(route('publico.store'), $this->base([
            'quien_radica' => 'paciente',
            'solicitante_nombre' => 'María Paciente',
            'solicitante_tipo_documento' => 'CC',
            'solicitante_documento' => '10203040',
            'paciente_fecha_nacimiento' => '1990-05-15',
            'paciente_nombre' => 'No Debe Usarse',
            'paciente_documento' => '999',
            'relacion_paciente' => 'No aplica',
        ]))->assertRedirect();

        $caso = Pqrsd::latest('id')->firstOrFail();
        $this->assertSame('María Paciente', $caso->solicitante_nombre);
        $this->assertSame('María Paciente', $caso->paciente_nombre);
        $this->assertSame('CC', $caso->paciente_tipo_documento);
        $this->assertSame('10203040', $caso->paciente_documento);
        $this->assertNull($caso->representante_nombre);
        $this->assertSame('1990-05-15', $caso->paciente_fecha_nacimiento?->toDateString());
    }

    public function test_tercero_radica_por_paciente(): void
    {
        $this->post(route('publico.store'), $this->base([
            'quien_radica' => 'tercero',
            'solicitante_nombre' => 'Acudiente Legal',
            'solicitante_tipo_documento' => 'CC',
            'solicitante_documento' => '555666',
            'relacion_paciente' => 'Madre',
            'paciente_nombre' => 'Paciente Menor',
            'paciente_tipo_documento' => 'TI',
            'paciente_documento' => '1234567',
            'paciente_fecha_nacimiento' => '2012-01-01',
        ]))->assertRedirect();

        $caso = Pqrsd::latest('id')->firstOrFail();
        $this->assertSame('Acudiente Legal', $caso->solicitante_nombre);
        $this->assertSame('Paciente Menor', $caso->paciente_nombre);
        $this->assertSame('TI', $caso->paciente_tipo_documento);
        $this->assertSame('Acudiente Legal', $caso->representante_nombre);
        $this->assertStringContainsString('Madre', (string) $caso->descripcion);
    }

    public function test_solicitud_anonima(): void
    {
        $tipo = TipoPqrsd::where('codigo', 'QUEJA')->firstOrFail();
        $this->post(route('publico.store'), $this->base([
            'tipo_pqrsd_id' => $tipo->id,
            'quien_radica' => 'paciente',
            'es_anonimo' => '1',
            'solicitante_nombre' => 'Debe Ignorarse',
            'solicitante_documento' => '000',
        ]))->assertRedirect();

        $caso = Pqrsd::latest('id')->firstOrFail();
        $this->assertTrue($caso->es_anonimo);
        $this->assertNull($caso->solicitante_nombre);
        $this->assertNull($caso->solicitante_documento);
        $this->assertNull($caso->paciente_nombre);
    }

    public function test_campos_ocultos_paciente_no_exigen_relacion(): void
    {
        $this->from(route('publico.formulario'))
            ->post(route('publico.store'), $this->base([
                'quien_radica' => 'paciente',
                'solicitante_nombre' => 'Sin Relación',
                'solicitante_tipo_documento' => 'CC',
                'solicitante_documento' => '7788',
                // sin relacion_paciente ni datos paciente separados
            ]))
            ->assertRedirect(route('publico.confirmacion'));
    }

    public function test_selectores_documento_rechazan_inventados(): void
    {
        $this->from(route('publico.formulario'))
            ->post(route('publico.store'), $this->base([
                'solicitante_nombre' => 'Doc Inválido',
                'solicitante_tipo_documento' => 'XYZ',
                'solicitante_documento' => '1',
            ]))
            ->assertRedirect(route('publico.formulario'))
            ->assertSessionHasErrors('solicitante_tipo_documento');

        $this->get(route('publico.formulario'))
            ->assertOk()
            ->assertSee('Cédula de ciudadanía (CC)')
            ->assertSee('Permiso por Protección Temporal (PPT)');
    }

    public function test_medios_email_correspondencia_y_consulta_segura(): void
    {
        $this->post(route('publico.store'), $this->base([
            'solicitante_nombre' => 'Por Correo',
            'solicitante_tipo_documento' => 'CC',
            'solicitante_documento' => '1',
            'medio_respuesta' => 'email',
            'solicitante_email' => 'respuesta@example.test',
            'consentimiento_comunicacion_email' => '1',
        ]))->assertRedirect();
        $this->assertSame('email', Pqrsd::latest('id')->first()->medio_respuesta);

        $this->post(route('publico.store'), $this->base([
            'solicitante_nombre' => 'Por Teléfono',
            'solicitante_tipo_documento' => 'CE',
            'solicitante_documento' => '2',
            'solicitante_telefono' => '6041234567',
            'medio_respuesta' => 'telefono',
        ]))->assertRedirect();
        $this->assertSame('telefono', Pqrsd::latest('id')->first()->medio_respuesta);
        $this->assertSame('6041234567', Pqrsd::latest('id')->first()->solicitante_telefono);

        $this->from(route('publico.formulario'))
            ->post(route('publico.store'), $this->base([
                'solicitante_nombre' => 'Carta Antigua',
                'solicitante_tipo_documento' => 'CC',
                'solicitante_documento' => '3',
                'medio_respuesta' => 'correspondencia',
                'solicitante_direccion' => 'Carrera 1 # 2-3',
            ]))
            ->assertSessionHasErrors('medio_respuesta');

        $this->post(route('publico.store'), $this->base([
            'solicitante_nombre' => 'Portal Seguro',
            'solicitante_tipo_documento' => 'PA',
            'solicitante_documento' => 'A123',
            'medio_respuesta' => 'consulta_segura',
        ]))->assertRedirect();
        $caso = Pqrsd::latest('id')->firstOrFail();
        $this->assertSame('consulta_segura', $caso->medio_respuesta);
        $this->assertNotNull($caso->consulta_token_hash);

        $this->get(route('publico.confirmacion'))
            ->assertOk()
            ->assertSee('Código privado');
    }

    public function test_validacion_telefono_rechaza_letras(): void
    {
        $this->from(route('publico.formulario'))
            ->post(route('publico.store'), $this->base([
                'solicitante_nombre' => 'Tel Malo',
                'solicitante_tipo_documento' => 'CC',
                'solicitante_documento' => '3',
                'solicitante_telefono' => 'ASDFGHJKL',
            ]))
            ->assertRedirect(route('publico.formulario'))
            ->assertSessionHasErrors('solicitante_telefono');

        $this->post(route('publico.store'), $this->base([
            'solicitante_nombre' => 'Tel Bueno',
            'solicitante_tipo_documento' => 'CC',
            'solicitante_documento' => '4',
            'solicitante_telefono' => '+57 300 123 4567',
        ]))->assertRedirect(route('publico.confirmacion'));
    }

    public function test_mensajes_error_en_espanol(): void
    {
        $this->from(route('publico.formulario'))
            ->post(route('publico.store'), [
                'idempotency_key' => (string) Str::uuid(),
                'acepto_tratamiento_datos' => '1',
            'solicitante_telefono' => '3001234567',
                'descripcion' => 'corta',
            ])
            ->assertRedirect(route('publico.formulario'))
            ->assertSessionHasErrors();

        $errors = session('errors');
        $this->assertNotNull($errors);
        $all = implode(' ', $errors->all());
        $this->assertStringNotContainsString('validation.', $all);
        $this->assertTrue(
            str_contains($all, 'medio') || str_contains($all, 'descripción') || str_contains($all, 'quién')
            || str_contains($all, 'Seleccione') || str_contains($all, 'Complete') || str_contains($all, 'caracteres')
        );
    }

    public function test_radicacion_exitosa_y_prevencion_doble(): void
    {
        $key = (string) Str::uuid();
        $payload = $this->base([
            'idempotency_key' => $key,
            'solicitante_nombre' => 'Idempotente',
            'solicitante_tipo_documento' => 'CC',
            'solicitante_documento' => '55',
        ]);

        $this->post(route('publico.store'), $payload)->assertRedirect(route('publico.confirmacion'));
        $this->post(route('publico.store'), $payload)->assertRedirect(route('publico.confirmacion'));
        $this->assertSame(1, Pqrsd::count());

        $this->get(route('publico.confirmacion'))
            ->assertOk()
            ->assertSee('Su PQRSD fue radicada correctamente')
            ->assertSee('btn-reproducir-sonido', false)
            ->assertSee('tonoConfirmacion', false);
    }

    public function test_confirmacion_incluye_sonido_progresivo_sin_bloquear(): void
    {
        $this->post(route('publico.store'), $this->base([
            'solicitante_nombre' => 'Con Sonido',
            'solicitante_tipo_documento' => 'CC',
            'solicitante_documento' => '66',
            'medio_respuesta' => 'telefono',
            'solicitante_telefono' => '3001234567',
        ]))->assertRedirect();

        $html = $this->get(route('publico.confirmacion'))->assertOk()->getContent();
        $this->assertStringContainsString('Su PQRSD fue radicada correctamente', $html);
        $this->assertStringContainsString('AudioContext', $html);
        $this->assertStringContainsString('Escuchar confirmación', $html);
        $this->assertStringContainsString('Consultar estado', $html);
        $this->assertStringContainsString('Nueva solicitud', $html);
    }

    public function test_consulta_segura_privada_conservada_y_compatible_antigua(): void
    {
        $this->post(route('publico.store'), $this->base([
            'solicitante_nombre' => 'Seguro',
            'solicitante_tipo_documento' => 'CC',
            'solicitante_documento' => '77',
        ]))->assertRedirect();
        $payload = session('publico_confirmacion');

        // Expediente antiguo: token histórico sigue válido en consulta segura.
        $caso = Pqrsd::where('radicado', $payload['radicado'])->firstOrFail();
        $this->assertNotNull($caso->consulta_token_hash);

        $this->post(route('publico.consulta.store'), ['radicado' => $payload['radicado']])
            ->assertOk()
            ->assertDontSee('Respuesta');

        $admin = User::where('email', 'admin@menteplena.test')->firstOrFail();
        $this->actingAs($admin)->post(route('pqrsd.responder', $caso), $this->conVersionExpediente($caso, [
            'detalle_respuesta' => 'Texto privado de respuesta completa para el ciudadano.',
            'poner_disposicion_consulta' => '1',
        ]))->assertRedirect();

        $this->post(route('publico.consulta.store'), ['radicado' => $payload['radicado']])
            ->assertOk()
            ->assertDontSee('Texto privado de respuesta completa');

        $this->post(route('publico.consulta.segura.store'), [
            'radicado' => $payload['radicado'],
            'token' => $payload['token'],
        ])->assertOk()->assertSee('Texto privado de respuesta completa');
    }

    public function test_formulario_y_consulta_usables_en_viewport_movil(): void
    {
        $this->get(route('publico.formulario'))
            ->assertOk()
            ->assertSee('width=device-width', false)
            ->assertSee('Soy el paciente')
            ->assertSee('¿Cómo desea recibir o consultar la respuesta?');

        $this->get(route('publico.consulta'))
            ->assertOk()
            ->assertSee('width=device-width', false)
            ->assertSee('Número de radicado');
    }

    public function test_email_exige_autorizacion_y_correspondencia_direccion(): void
    {
        $this->from(route('publico.formulario'))
            ->post(route('publico.store'), $this->base([
                'solicitante_nombre' => 'Sin Autorizar',
                'solicitante_tipo_documento' => 'CC',
                'solicitante_documento' => '8',
                'medio_respuesta' => 'email',
                'solicitante_email' => 'x@example.test',
            ]))
            ->assertSessionHasErrors('consentimiento_comunicacion_email');

        $this->from(route('publico.formulario'))
            ->post(route('publico.store'), $this->base([
                'solicitante_nombre' => 'Sin Dirección',
                'solicitante_tipo_documento' => 'CC',
                'solicitante_documento' => '9',
                'es_anonimo' => '1',
                'medio_respuesta' => 'telefono',
            ]))
            ->assertSessionHasErrors('medio_respuesta');
    }
}
