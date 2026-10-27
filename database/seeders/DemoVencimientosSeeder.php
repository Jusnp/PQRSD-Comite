<?php

namespace Database\Seeders;

use App\Models\CanalIngreso;
use App\Models\User;
use App\Services\PqrsdService;
use App\Services\VencimientoService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * Datos ficticios para demostrar cálculos de vencimiento (bandeja compartida).
 * No contiene pacientes reales. No asigna responsable (operación compartida).
 */
class DemoVencimientosSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            return;
        }

        $user = User::where('email', 'admin@menteplena.test')->first()
            ?? User::role('admin')->first()
            ?? User::role('lider_pqrsd')->first();

        if (! $user) {
            $this->command?->warn('Sin usuario operativo; omitiendo demo de vencimientos.');

            return;
        }

        $canal = CanalIngreso::where('codigo', 'MANUAL')->firstOrFail();
        $pqrsd = app(PqrsdService::class);
        $venc = app(VencimientoService::class);
        $ahora = now('America/Bogota');

        // 1) Vital menor — recepción nocturna viernes (fechas fijas educativas; puede estar vencido hoy)
        $c1 = $pqrsd->radicarManual([
            'fecha_recepcion' => Carbon::parse('2026-10-09 23:00:00', 'America/Bogota'),
            'canal_ingreso_id' => $canal->id,
            'nivel_riesgo' => 'vital_menor',
            'paciente_nombre' => 'Paciente Demo Menor',
            'paciente_edad' => 10,
            'descripcion' => 'DEMO: vital menor viernes noche (8h calendario)',
        ], $user);

        // 2) Ordinario dentro de plazo (fuera del umbral de proximidad si aún faltan días)
        $c2 = $pqrsd->radicarManual([
            'fecha_recepcion' => $ahora->copy()->subDays(2),
            'canal_ingreso_id' => $canal->id,
            'nivel_riesgo' => 'ordinario',
            'descripcion' => 'DEMO: ordinario dentro de plazo (bandeja compartida, sin responsable)',
        ], $user);
        $venc->refrescarCumplimiento($c2);

        // 3) Vital general próximo a vencer (~3 h restantes de 24 h) — sin responsable
        $c3 = $pqrsd->radicarManual([
            'fecha_recepcion' => $ahora->copy()->subHours(21),
            'canal_ingreso_id' => $canal->id,
            'nivel_riesgo' => 'vital_general',
            'paciente_edad' => 35,
            'descripcion' => 'DEMO: vital general próximo a vencer (sin responsable)',
        ], $user);
        $venc->refrescarCumplimiento($c3);

        // 4) Simple vencido — sin responsable
        $c4 = $pqrsd->radicarManual([
            'fecha_recepcion' => $ahora->copy()->subHours(80),
            'canal_ingreso_id' => $canal->id,
            'nivel_riesgo' => 'simple',
            'descripcion' => 'DEMO: riesgo simple vencido (>72h, sin responsable)',
        ], $user);
        $venc->refrescarCumplimiento($c4);

        // 5) Edad a la recepción (fijo educativo)
        $c5 = $pqrsd->radicarManual([
            'fecha_recepcion' => Carbon::parse('2026-03-14 08:00:00', 'America/Bogota'),
            'canal_ingreso_id' => $canal->id,
            'nivel_riesgo' => 'vital_general',
            'paciente_fecha_nacimiento' => '2008-03-15',
            'paciente_nombre' => 'Demo Edad Recepción',
            'descripcion' => 'DEMO: edad evaluada a la recepción (17), no a hoy',
        ], $user);

        // 6) Simple con límite futuro fuera de umbral de proximidad
        $c6 = $pqrsd->radicarManual([
            'fecha_recepcion' => $ahora->copy()->subHours(12),
            'canal_ingreso_id' => $canal->id,
            'nivel_riesgo' => 'simple',
            'descripcion' => 'DEMO: simple dentro de plazo (72h), fuera de umbral próximo',
        ], $user);
        $venc->refrescarCumplimiento($c6);

        $this->command?->info('Demo vencimientos (bandeja compartida) creada:');
        foreach ([$c1, $c2, $c3, $c4, $c5, $c6] as $c) {
            $c->refresh();
            $this->command?->line(sprintf(
                '  %s | %s | resp=%s | recepción %s | límite %s | %s',
                $c->radicado,
                $c->nivel_riesgo,
                $c->responsable_id ?? 'null',
                $c->fecha_recepcion->timezone('America/Bogota')->format('Y-m-d H:i'),
                $c->fecha_limite?->timezone('America/Bogota')->format('Y-m-d H:i') ?? 'N/A',
                $c->estado_cumplimiento
            ));
        }
    }
}
