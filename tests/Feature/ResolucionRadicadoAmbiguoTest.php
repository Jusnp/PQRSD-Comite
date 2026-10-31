<?php

namespace Tests\Feature;

use App\Exceptions\RadicacionSuspendidaException;
use App\Models\ActuacionPqrsd;
use App\Models\ContactoRegularizacionRadicado;
use App\Models\Pqrsd;
use App\Models\SecuenciaRadicado;
use App\Models\TipoPqrsd;
use App\Models\User;
use App\Services\RadicadoGenerator;
use App\Services\RegularizacionRadicado;
use Carbon\Carbon;
use Database\Seeders\CatalogosSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\UsersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

class ResolucionRadicadoAmbiguoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(CatalogosSeeder::class);
        $this->seed(UsersSeeder::class);
        RateLimiter::clear('consulta-estado:127.0.0.1');
        RateLimiter::clear('consulta-segura:127.0.0.1');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_numero_compartido_no_revela_datos_y_el_token_abre_solo_su_expediente(): void
    {
        $nuevoPlano = $this->radicar('Titular Nuevo Prueba', '101');
        $historicoPlano = $this->radicar('Titular Historico Prueba', '202');

        $nuevo = Pqrsd::query()->where('radicado', $nuevoPlano['radicado'])->firstOrFail();
        $historico = Pqrsd::query()->where('radicado', $historicoPlano['radicado'])->firstOrFail();
        $hashNuevo = $nuevo->consulta_token_hash;
        $hashHistorico = $historico->consulta_token_hash;
        $textos = ActuacionPqrsd::query()->where('pqrsd_id', $nuevo->id)->pluck('detalle', 'id')->all();
        $recepcion = '2026-10-30 10:25:20';
        $limite = '2027-12-31 10:25:20';

        $nuevo->forceFill([
            'solicitante_nombre' => 'Titular Nuevo Prueba',
            'nivel_riesgo' => 'vital_general',
            'fecha_recepcion' => $recepcion,
            'fecha_limite' => $limite,
            'estado_cumplimiento' => 'dentro_plazo',
            'respuesta_estado' => 'puesta_disposicion',
            'respuesta_consulta_texto' => 'RESPUESTA SOLO NUEVO',
        ])->save();
        $historico->forceFill([
            'solicitante_nombre' => 'Titular Historico Prueba',
            'estado_operativo' => 'cerrado',
            'respuesta_estado' => 'puesta_disposicion',
            'respuesta_consulta_texto' => 'RESPUESTA SOLO HISTORICO',
        ])->save();

        $admin = User::query()->where('email', 'admin@menteplena.test')->firstOrFail();
        $oficial = 'PQRSD-2026-000099';
        $nuevo = app(RegularizacionRadicado::class)->aplicar(
            $nuevo->fresh(),
            $oficial,
            $admin,
            'colision_serie_historica'
        );

        $this->assertSame($oficial, $nuevo->radicado);
        $this->assertSame($recepcion, $nuevo->fecha_recepcion?->format('Y-m-d H:i:s'));
        $this->assertSame($limite, $nuevo->fecha_limite?->format('Y-m-d H:i:s'));
        $this->assertSame('vital_general', $nuevo->nivel_riesgo);
        $this->assertSame($hashNuevo, $nuevo->consulta_token_hash);
        $this->assertSame(0, ContactoRegularizacionRadicado::query()->count());
        $this->assertSame(
            $textos,
            ActuacionPqrsd::query()->where('pqrsd_id', $nuevo->id)->pluck('detalle', 'id')->all()
        );
        $this->assertStringContainsString($nuevoPlano['radicado'], (string) array_values($textos)[0]);

        $historico->forceFill(['radicado' => $nuevoPlano['radicado']])->save();
        $compartido = $nuevoPlano['radicado'];

        $this->post(route('publico.consulta.store'), ['radicado' => $compartido])
            ->assertOk()
            ->assertSee('código privado')
            ->assertDontSee('Titular Nuevo Prueba')
            ->assertDontSee('Titular Historico Prueba')
            ->assertDontSee('RESPUESTA SOLO NUEVO')
            ->assertDontSee('RESPUESTA SOLO HISTORICO')
            ->assertDontSee('Finalizada')
            ->assertDontSee('Recibida')
            ->assertDontSee($oficial);

        $this->post(route('publico.consulta.segura.store'), [
            'radicado' => $compartido,
            'token' => $nuevoPlano['token'],
        ])->assertOk()
            ->assertSee('RESPUESTA SOLO NUEVO')
            ->assertSee($oficial)
            ->assertDontSee('RESPUESTA SOLO HISTORICO')
            ->assertDontSee('Titular Historico Prueba');

        $this->post(route('publico.consulta.segura.store'), [
            'radicado' => $oficial,
            'token' => $nuevoPlano['token'],
        ])->assertOk()
            ->assertSee('RESPUESTA SOLO NUEVO')
            ->assertDontSee('RESPUESTA SOLO HISTORICO');

        $this->post(route('publico.consulta.segura.store'), [
            'radicado' => $compartido,
            'token' => $historicoPlano['token'],
        ])->assertOk()
            ->assertSee('RESPUESTA SOLO HISTORICO')
            ->assertDontSee('RESPUESTA SOLO NUEVO')
            ->assertDontSee($oficial)
            ->assertDontSee('Titular Nuevo Prueba');

        $this->from(route('publico.consulta.segura'))
            ->post(route('publico.consulta.segura.store'), [
                'radicado' => $oficial,
                'token' => $historicoPlano['token'],
            ])
            ->assertRedirect(route('publico.consulta.segura'))
            ->assertSessionHasErrors('radicado');

        $this->from(route('publico.consulta.segura'))
            ->post(route('publico.consulta.segura.store'), [
                'radicado' => $compartido,
                'token' => str_repeat('a', 40),
            ])
            ->assertRedirect(route('publico.consulta.segura'))
            ->assertSessionHasErrors('radicado');

        $this->from(route('publico.consulta.segura'))
            ->post(route('publico.consulta.segura.store'), [
                'radicado' => 'PQRSD-2026-000088',
                'token' => $nuevoPlano['token'],
            ])
            ->assertRedirect(route('publico.consulta.segura'))
            ->assertSessionHasErrors('radicado');

        $this->assertSame($hashNuevo, $nuevo->fresh()->consulta_token_hash);
        $this->assertSame($hashHistorico, $historico->fresh()->consulta_token_hash);

        $this->actingAs($admin)
            ->get(route('pqrsd.index', ['q' => $compartido]))
            ->assertOk()
            ->assertSee($compartido)
            ->assertSee($oficial)
            ->assertSee('Radicado oficial')
            ->assertSee('Alias histórico')
            ->assertSee('Se muestran los dos');

        $this->actingAs($admin)
            ->get(route('pqrsd.show', $nuevo->id))
            ->assertOk()
            ->assertSee('Regularización de radicado')
            ->assertSee('No consta que el ciudadano haya sido informado')
            ->assertSee('Administrador PQRSD')
            ->assertSee($compartido)
            ->assertDontSee('RESPUESTA SOLO HISTORICO');

        $this->actingAs($admin)
            ->post(route('pqrsd.contacto-regularizacion', $nuevo->id), [
                'fecha_contacto' => '2026-10-30 15:00:00',
                'resultado' => 'no_contactado',
                'observacion' => 'Intento registrado por el funcionario.',
            ])
            ->assertRedirect();

        $this->assertSame(1, ContactoRegularizacionRadicado::query()->count());
        $this->assertSame('no_contactado', ContactoRegularizacionRadicado::query()->first()->resultado);
        $this->assertSame($oficial, $nuevo->fresh()->radicado);
    }

    public function test_el_codigo_no_vence_por_el_paso_del_tiempo(): void
    {
        $plano = $this->radicar('Titular Vigente Prueba', '303');
        $caso = Pqrsd::query()->where('radicado', $plano['radicado'])->firstOrFail();
        $hash = $caso->consulta_token_hash;

        Carbon::setTestNow(now()->addYears(3));

        $this->post(route('publico.consulta.segura.store'), [
            'radicado' => $plano['radicado'],
            'token' => $plano['token'],
        ])->assertOk()->assertSee($plano['radicado']);

        $this->from(route('publico.consulta.segura'))
            ->post(route('publico.consulta.segura.store'), [
                'radicado' => $plano['radicado'],
                'token' => str_repeat('b', 40),
            ])
            ->assertSessionHasErrors('radicado');

        $this->assertSame($hash, $caso->fresh()->consulta_token_hash);
    }

    public function test_la_suspension_de_radicacion_no_consume_el_consecutivo(): void
    {
        config(['pqrsd_ops.suspender_radicacion' => true]);

        try {
            app(RadicadoGenerator::class)->siguiente(2026);
            $this->fail('La suspensión debía impedir el radicado.');
        } catch (RadicacionSuspendidaException) {
            $this->assertSame(0, SecuenciaRadicado::query()->where('anio', 2026)->count());
        }

        config(['pqrsd_ops.suspender_radicacion' => false]);
        $this->assertSame('PQRSD-2026-000001', app(RadicadoGenerator::class)->siguiente(2026));
    }

    private function radicar(string $nombre, string $documento): array
    {
        $this->post(route('publico.store'), [
            'idempotency_key' => (string) Str::uuid(),
            'tipo_pqrsd_id' => TipoPqrsd::query()->where('codigo', 'PETICION')->value('id'),
            'quien_radica' => 'paciente',
            'asunto' => 'Asunto de prueba ciudadana',
            'descripcion' => 'Descripción de los hechos con longitud suficiente para la validación del formulario público.',
            'acepto_tratamiento_datos' => '1',
            'solicitante_nombre' => $nombre,
            'solicitante_tipo_documento' => 'CC',
            'solicitante_documento' => $documento,
            'solicitante_telefono' => '3001234567',
            'medio_respuesta' => 'consulta_segura',
        ])->assertRedirect();

        return session('publico_confirmacion');
    }
}
