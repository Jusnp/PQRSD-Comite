<?php

namespace Tests\Feature;

use App\Models\MotivoPqrsd;
use App\Models\Pqrsd;
use App\Models\TipoPqrsd;
use Database\Seeders\CatalogosSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\UsersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RegresionPublicaAuditoriaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(CatalogosSeeder::class);
        $this->seed(UsersSeeder::class);
    }

    private function datos(array $extra = []): array
    {
        return array_merge([
            'idempotency_key' => (string) Str::uuid(),
            'tipo_pqrsd_id' => TipoPqrsd::where('codigo', 'QUEJA')->value('id'),
            'quien_radica' => 'paciente',
            'solicitante_nombre' => 'Ciudadano Prueba',
            'solicitante_tipo_documento' => 'CC',
            'solicitante_documento' => '1234567890',
            'solicitante_email' => 'ciudadano@example.test',
            'solicitante_telefono' => '3001234567',
            'medio_respuesta' => 'email',
            'consentimiento_comunicacion_email' => '1',
            'asunto' => 'Solicitud de prueba',
            'descripcion' => 'Descripcion ficticia para probar el registro publico.',
            'acepto_tratamiento_datos' => '1',
        ], $extra);
    }

    public function test_web_no_atribuye_funcionario(): void
    {
        $this->post(route('publico.store'), $this->datos())
            ->assertRedirect(route('publico.confirmacion'));

        $caso = Pqrsd::query()->sole();

        $this->assertSame('web', $caso->origen_publico);
        $this->assertNull($caso->creado_por);
        $this->assertNull($caso->radicado_por);

        $this->assertDatabaseHas('actuaciones_pqrsd', [
            'pqrsd_id' => $caso->id,
            'usuario_id' => null,
            'titulo' => 'Radicación pública',
        ]);
    }

    public function test_qr_no_atribuye_funcionario(): void
    {
        $this->post(route('publico.store'), $this->datos([
            'via_qr' => '1',
        ]))->assertRedirect(route('publico.confirmacion'));

        $caso = Pqrsd::query()->sole();

        $this->assertSame('qr', $caso->origen_publico);
        $this->assertNull($caso->creado_por);
        $this->assertNull($caso->radicado_por);
    }

    public function test_rechaza_motivo_de_otro_tipo(): void
    {
        $peticionId = TipoPqrsd::where('codigo', 'PETICION')->value('id');

        $motivoIncorrecto = MotivoPqrsd::where(
            'tipo_pqrsd_id', $peticionId
        )->value('id');

        $this->assertNotNull($motivoIncorrecto);

        $this->post(route('publico.store'), $this->datos([
            'motivo_pqrsd_id' => $motivoIncorrecto,
        ]))->assertSessionHasErrors('motivo_pqrsd_id');

        $this->assertDatabaseCount('pqrsd', 0);
    }
}