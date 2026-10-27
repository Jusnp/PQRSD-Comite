<?php

namespace Tests\Feature;

use App\Models\CuentaCorreo;
use App\Models\User;
use App\Services\Correo\CuentaCorreoInventarioService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class InventarioBuzonesTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_import_actualiza_tipo_sin_inventar_cuentas_nuevas(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $cuenta = CuentaCorreo::create([
            'direccion' => 'piloto@menteplena.com.co',
            'activo' => true,
            'tipo_integracion' => 'mock',
            'tipo_buzon' => 'desconocido',
            'estado_autorizacion' => 'pendiente',
        ]);

        $lider = User::factory()->create(['activo' => true]);
        $lider->assignRole('lider_pqrsd');

        $this->actingAs($lider)
            ->get(route('cuentas-correo.inventario.export'))
            ->assertOk();

        $csv = "direccion,tipo_buzon,buzon_canonico,responsable_nombre,area_responsable,aseguradora_codigo,estado_autorizacion,observaciones_inventario,activo\n"
            ."piloto@menteplena.com.co,individual,,Ana Prueba,Calidad,,autorizado,Clasificado en ensayo,1\n"
            ."noexiste@menteplena.com.co,individual,,,,,autorizado,no debe crearse,1\n";

        $file = UploadedFile::fake()->createWithContent('inv.csv', $csv);

        $this->actingAs($lider)
            ->post(route('cuentas-correo.inventario.import'), ['inventario_csv' => $file])
            ->assertRedirect();

        $cuenta->refresh();
        $this->assertSame('individual', $cuenta->tipo_buzon);
        $this->assertSame('autorizado', $cuenta->estado_autorizacion);
        $this->assertSame('Ana Prueba', $cuenta->responsable_nombre);
        $this->assertNotNull($cuenta->fecha_ultima_validacion_inventario);
        $this->assertSame(1, CuentaCorreo::count());
    }

    public function test_plantilla_incluye_encabezados_canonicos(): void
    {
        $svc = app(CuentaCorreoInventarioService::class);
        $this->assertSame(
            'direccion,tipo_buzon,buzon_canonico,responsable_nombre,area_responsable,aseguradora_codigo,estado_autorizacion,observaciones_inventario,activo',
            explode("\n", $svc->plantillaParaAdmin())[0]
        );
    }
}
