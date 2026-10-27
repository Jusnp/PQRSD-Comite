<?php

namespace Tests\Feature;

use App\Models\ActuacionPqrsd;
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

/**
 * Recorrido integral ficticio: radicación pública → gestión interna → consulta → cierre.
 * No usa Gmail ni envío automático.
 */
class GestionInternaFlujoCompletoTest extends TestCase
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

    public function test_recorrido_completo_admin_y_auxiliares_sin_riesgo_simple_automatico(): void
    {
        $tipo = TipoPqrsd::where('codigo', 'PETICION')->firstOrFail();

        // 1) Radicación pública identificada
        $response = $this->post(route('publico.store'), [
            'idempotency_key' => (string) Str::uuid(),
            'tipo_pqrsd_id' => $tipo->id,
            'quien_radica' => 'paciente',
            'asunto' => 'Solicitud ficticia de validación interna',
            'descripcion' => 'Expediente ficticio para comprobar bandeja, clasificación, respuesta y cierre operativo.',
            'solicitante_nombre' => 'Ciudadano Ficticio Validación',
            'solicitante_tipo_documento' => 'CC',
            'solicitante_documento' => '1099001122',
            'solicitante_email' => 'ciudadano.ficticio@example.test',
            'medio_respuesta' => 'consulta_segura',
            'acepto_tratamiento_datos' => '1',
            'consentimiento_comunicacion_email' => '1',
        ]);
        $response->assertRedirect(route('publico.confirmacion'));

        $confirmacion = session('publico_confirmacion');
        $this->assertIsArray($confirmacion);
        $radicado = $confirmacion['radicado'];
        $token = $confirmacion['token'];
        $this->assertNotEmpty($radicado);
        $this->assertGreaterThanOrEqual(20, strlen($token));

        $this->get(route('publico.confirmacion'))
            ->assertOk()
            ->assertSee($radicado)
            ->assertSee($token)
            ->assertSee('Copiar radicado')
            ->assertSee('Copiar código')
            ->assertSee('Su PQRSD fue radicada correctamente');

        $caso = Pqrsd::where('radicado', $radicado)->firstOrFail();
        $this->assertSame('pendiente', $caso->nivel_riesgo);
        $this->assertSame('pendiente_clasificacion', $caso->estado_operativo);
        $this->assertSame('sin_clasificar', $caso->estado_cumplimiento);
        $this->assertNull($caso->fecha_limite);

        $admin = User::where('email', 'admin@menteplena.test')->firstOrFail();
        $aux1 = User::where('email', 'auxiliar1@menteplena.test')->firstOrFail();
        $aux2 = User::where('email', 'auxiliar2@menteplena.test')->firstOrFail();

        // 2) Aparición en bandeja con pendiente de evaluación
        foreach ([$admin, $aux1, $aux2] as $operador) {
            $this->actingAs($operador)
                ->get(route('pqrsd.index', ['estado_operativo' => 'pendiente_clasificacion']))
                ->assertOk()
                ->assertSee($radicado)
                ->assertSee('Pendiente evaluación')
                ->assertSee('Consulta segura');
        }

        // 3) Clasificación por auxiliar (sin auto-simple)
        $this->actingAs($aux1)
            ->get(route('pqrsd.show', $caso))
            ->assertOk()
            ->assertSee('Clasificar riesgo')
            ->assertSee('Pendiente de evaluación clínica')
            ->assertSee('Respuesta de PQRS');

        $this->actingAs($aux1)->post(route('pqrsd.clasificar', $caso), $this->conVersionExpediente($caso, [
            'nivel_riesgo' => 'ordinario',
            'tipo_pqrsd_id' => $tipo->id,
            'justificacion' => 'Evaluación clínica ficticia: no hay criterios de priorización ni vital.',
        ]))->assertRedirect();

        $caso->refresh();
        $this->assertSame('ordinario', $caso->nivel_riesgo);
        $this->assertNotSame('simple', $caso->nivel_riesgo);
        $this->assertNotNull($caso->fecha_limite);
        $this->assertNotNull($caso->fecha_inicio_conteo);
        $this->assertTrue($caso->fecha_inicio_conteo->equalTo($caso->fecha_recepcion));
        $this->assertSame('radicado', $caso->estado_operativo);

        $inicio = $caso->fecha_inicio_conteo->toDateTimeString();
        $limite = $caso->fecha_limite->toDateTimeString();

        // 4-5) Bandeja compartida: asignación HTTP deshabilitada; auxiliar 2 gestiona sin responsable.
        $this->actingAs($aux1)->post(route('pqrsd.asignar', $caso), [
            'responsable_id' => $aux2->id,
            'motivo' => 'Intento de asignación operativa deshabilitada.',
        ])->assertForbidden();

        $caso->refresh();
        $this->assertSame($inicio, $caso->fecha_inicio_conteo->toDateTimeString());
        $this->assertSame($limite, $caso->fecha_limite->toDateTimeString());

        $this->actingAs($aux2)->post(route('pqrsd.actuaciones.store', $caso), [
            'titulo' => 'Revisión documental inicial',
            'detalle' => 'Se revisó la descripción y se confirmó que no hay urgencia vital.',
            'es_interna' => '1',
        ])->assertForbidden();

        $caso->refresh();
        $this->assertContains($caso->estado_operativo, ['radicado', 'en_gestion', 'asignado', 'pendiente_clasificacion']);

        // 6) Soportes internos (desde respuesta)
        $pdf = UploadedFile::fake()->createWithContent('soporte-interno.pdf', "%PDF-1.4\nficticio\n");
        $this->actingAs($aux2)->post(route('pqrsd.adjuntos.store', $caso), [
            'adjuntos' => [$pdf],
        ])->assertRedirect();

        $this->assertSame(1, AdjuntoPqrsd::where('pqrsd_id', $caso->id)->where('tipo', 'soporte_interno')->count());

        // 7) Elaboración de respuesta (registrada, no enviada)
        $this->actingAs($aux2)->post(route('pqrsd.responder', $caso), $this->conVersionExpediente($caso, [
            'detalle_respuesta' => 'Respuesta de PQRS ficticia: se informa el resultado de la gestión interna.',
            'respuesta_estado_destino' => 'elaborada',
        ]))->assertRedirect();

        $caso->refresh();
        $this->assertSame('elaborada', $caso->respuesta_estado);
        $this->assertNull($caso->respuesta_enviada_en);
        $this->assertNull($caso->respuesta_consultada_en);
        $this->assertNull($caso->fecha_respuesta);
        $this->assertNotSame('respondido', $caso->estado_operativo);

        // 8) Puesta a disposición
        $this->actingAs($aux2)->post(route('pqrsd.responder', $caso), $this->conVersionExpediente($caso, [
            'detalle_respuesta' => 'Respuesta de PQRS ficticia: se informa el resultado de la gestión interna.',
            'respuesta_estado_destino' => 'puesta_disposicion',
            'poner_disposicion_consulta' => '1',
        ]))->assertRedirect();

        $caso->refresh();
        $this->assertSame('puesta_disposicion', $caso->respuesta_estado);
        $this->assertNotNull($caso->respuesta_puesta_disposicion_en);
        $this->assertNotNull($caso->fecha_respuesta);
        $this->assertSame('respondido', $caso->estado_operativo);

        // 9) Consulta de estado (solo radicado) no abre respuesta; consulta segura sí
        $this->post(route('publico.consulta.store'), [
            'radicado' => $radicado,
        ])->assertOk()
            ->assertSee($radicado)
            ->assertSee('En trámite')
            ->assertDontSee('Respuesta de PQRS ficticia');

        $this->post(route('publico.consulta.segura.store'), [
            'radicado' => $radicado,
            'token' => $token,
        ])->assertOk()
            ->assertSee($radicado)
            ->assertSee('Respuesta de PQRS ficticia');

        $caso->refresh();
        $this->assertSame('consultada', $caso->respuesta_estado);
        $this->assertNotNull($caso->respuesta_consultada_en);

        // 10) Evidencia de envío manual (canal verificable, sin Gmail)
        $this->actingAs($aux1)->post(route('pqrsd.responder', $caso), $this->conVersionExpediente($caso, [
            'detalle_respuesta' => 'Respuesta de PQRS ficticia: se informa el resultado de la gestión interna. Evidencia de comunicación manual registrada.',
            'marcar_envio_verificable' => '1',
            'respuesta_canal_comunicacion' => 'Correo institucional',
            'respuesta_evidencia_comunicacion' => 'Correo enviado el 19/10/2026 con asunto Respuesta PQRSD demo.',
        ]))->assertRedirect();

        $caso->refresh();
        $this->assertSame('enviada', $caso->respuesta_estado);
        $this->assertNotNull($caso->respuesta_enviada_en);
        $this->assertSame('Correo institucional', $caso->respuesta_canal_comunicacion);

        // 11) Cierre autorizado
        $this->actingAs($aux1)->post(route('pqrsd.cerrar', $caso), $this->conVersionExpediente($caso, [
            'motivo_cierre' => 'Cierre autorizado del expediente ficticio con respuesta y evidencia.',
        ]))->assertRedirect();

        $caso->refresh();
        $this->assertSame('cerrado', $caso->estado_operativo);
        $this->assertSame($inicio, $caso->fecha_inicio_conteo->toDateTimeString());
        $this->assertSame($limite, $caso->fecha_limite->toDateTimeString());

        // 12) Historial reconstruible
        $this->actingAs($admin)
            ->get(route('pqrsd.show', $caso))
            ->assertOk()
            ->assertSee('PQRSD clasificada')
            ->assertSee('Soporte adjunto')
            ->assertSee('Respuesta de PQRS')
            ->assertSee('Comunicada por canal verificable')
            ->assertDontSee('Gestiones y soportes', false)
            ->assertSee('Estado y finalización', false)
            ->assertSee('Historial automático', false)
            ->assertSee('exp-section-title');

        $this->assertTrue(ActuacionPqrsd::where('pqrsd_id', $caso->id)->where('tipo', 'clasificacion_sla')->exists());
        $this->assertFalse(ActuacionPqrsd::where('pqrsd_id', $caso->id)->where('tipo', 'actuacion_operativa')->exists());
        $this->assertTrue(
            ActuacionPqrsd::where('pqrsd_id', $caso->id)
                ->where('tipo', 'clasificacion_sla')
                ->where('titulo', 'Clasificación / plazo aplicado')
                ->exists()
        );

        // Acceso incorrecto a consulta segura
        $this->from(route('publico.consulta.segura'))->post(route('publico.consulta.segura.store'), [
            'radicado' => $radicado,
            'token' => 'token-incorrecto-validacion-interna-xxxx',
        ])->assertRedirect();

        // Auxiliar no administra usuarios
        $this->actingAs($aux2)->get(route('usuarios.index'))->assertForbidden();
        $this->actingAs($admin)->get(route('usuarios.index'))->assertOk();
    }

    public function test_bandeja_filtros_sede_riesgo_anonimo_y_medio(): void
    {
        $tipo = TipoPqrsd::where('codigo', 'QUEJA')->firstOrFail();
        $this->post(route('publico.store'), [
            'idempotency_key' => (string) Str::uuid(),
            'tipo_pqrsd_id' => $tipo->id,
            'quien_radica' => 'paciente',
            'asunto' => 'Caso anónimo filtro',
            'descripcion' => 'Descripción suficiente para radicación anónima de prueba de filtros de bandeja.',
            'es_anonimo' => '1',
            'medio_respuesta' => 'consulta_segura',
            'acepto_tratamiento_datos' => '1',
        ])->assertRedirect();

        $caso = Pqrsd::latest('id')->firstOrFail();
        $aux = User::where('email', 'auxiliar1@menteplena.test')->firstOrFail();

        $this->actingAs($aux)
            ->get(route('pqrsd.index', [
                'es_anonimo' => '1',
                'medio_respuesta' => 'consulta_segura',
                'nivel_riesgo' => 'pendiente',
            ]))
            ->assertOk()
            ->assertSee($caso->radicado)
            ->assertSee('Anónimo')
            ->assertSee('Pendiente evaluación');
    }
}
