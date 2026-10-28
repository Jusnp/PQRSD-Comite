<?php

namespace Tests\Feature;

use App\Models\CanalIngreso;
use App\Models\TipoPqrsd;
use App\Models\User;
use Database\Seeders\CatalogosSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\UsersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class InterfazPublicaReportesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(CatalogosSeeder::class);
        $this->seed(UsersSeeder::class);
    }

    public function test_formulario_publico_radica_y_permite_consulta_con_radicado(): void
    {
        $tipo = TipoPqrsd::where('codigo', 'QUEJA')->firstOrFail();
        $this->assertDatabaseHas('canales_ingreso', ['codigo' => 'WEB']);

        $response = $this->post(route('publico.store'), [
            'idempotency_key' => (string) Str::uuid(),
            'tipo_pqrsd_id' => $tipo->id,
            'quien_radica' => 'paciente',
            'solicitante_nombre' => 'Ciudadano Demo',
            'solicitante_tipo_documento' => 'CC',
            'solicitante_documento' => '1098765432',
            'solicitante_email' => 'ciudadano.demo@example.test',
            'medio_respuesta' => 'email',
            'consentimiento_comunicacion_email' => '1',
            'asunto' => 'Queja de prueba pública',
            'descripcion' => 'Descripción ficticia de prueba pública con longitud suficiente.',
            'acepto_tratamiento_datos' => '1',
            'solicitante_telefono' => '3001234567',
        ]);

        $response->assertRedirect(route('publico.confirmacion'));
        $payload = session('publico_confirmacion');
        $this->assertIsArray($payload);
        $this->assertNotEmpty($payload['radicado'] ?? null);
        $this->assertNotEmpty($payload['token'] ?? null);

        $this->get(route('publico.confirmacion'))
            ->assertOk()
            ->assertSee($payload['radicado'])
            ->assertSee('Su PQRSD fue radicada correctamente')
            ->assertDontSee($payload['token'])
            ->assertSee('confirmacion-exito', false);

        $this->post(route('publico.consulta.store'), [
            'radicado' => $payload['radicado'],
        ])->assertOk()->assertSee($payload['radicado'])->assertDontSee('Ciudadano Demo');

        $this->from(route('publico.consulta'))->post(route('publico.consulta.store'), [
            'radicado' => 'PQRSD-2099-000001',
        ])->assertRedirect();
    }

    public function test_qr_y_paginas_publicas_responden(): void
    {
        $this->get(route('publico.formulario'))->assertOk()->assertSee('Formulario público');
        $this->get(route('publico.qr'))->assertOk()->assertSee('Código QR');
        $this->assertFileExists(public_path('qrs/formulario-pqrsd.svg'));
    }

    public function test_bandeja_y_reportes_requieren_permiso(): void
    {
        $admin = User::where('email', 'admin@menteplena.test')->firstOrFail();

        $this->actingAs($admin)->get(route('pqrsd.index'))->assertOk()->assertSee('Bandeja de PQRSD');
        $this->actingAs($admin)->get(route('dashboard'))->assertRedirect(route('pqrsd.index'));
        $this->actingAs($admin)->get(route('reportes.index'))->assertRedirect(route('reportes.indicadores'));
        $this->actingAs($admin)->get(route('reportes.indicadores'))->assertOk()->assertSee('Indicadores y reportes');
        $this->actingAs($admin)->get(route('reportes.excel'))->assertOk();
        $this->actingAs($admin)->get(route('reportes.pdf'))->assertOk();

        $sinPermiso = User::factory()->create(['activo' => true]);
        $this->actingAs($sinPermiso)->get(route('reportes.index'))->assertForbidden();
        $this->actingAs($sinPermiso)->get(route('pqrsd.index'))->assertForbidden();
    }

    public function test_formulario_publico_no_expone_checkbox_urgencia_vital(): void
    {
        $this->get(route('publico.formulario'))
            ->assertOk()
            ->assertDontSee('posible_urgencia')
            ->assertDontSee('Creo que esta situación implica urgencia vital')
            ->assertSee('Urgencia vital:');
    }
}
