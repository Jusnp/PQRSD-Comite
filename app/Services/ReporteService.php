<?php

namespace App\Services;

use App\Models\Pqrsd;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReporteService
{
    public function filtrar(Request $request): Builder
    {
        $query = Pqrsd::query()
            ->with(['canal', 'tipo', 'motivo', 'categoria', 'subcategoria', 'sede', 'aseguradora', 'responsable', 'reglaSla', 'encuesta', 'actuaciones.usuario']);

        app(IndicadoresPqrsdService::class)->aplicarFiltrosCohorte($query, $request);

        return $query->orderByDesc('fecha_recepcion');
    }

    /**
     * @return array<string, mixed>
     */
    public function indicadores(Builder $query): array
    {
        $total = (clone $query)->count();

        $porCumplimiento = (clone $query)
            ->select('estado_cumplimiento', DB::raw('count(*) as total'))
            ->groupBy('estado_cumplimiento')
            ->pluck('total', 'estado_cumplimiento');

        $porSede = (clone $query)
            ->leftJoin('sedes', 'sedes.id', '=', 'pqrsd.sede_id')
            ->select(DB::raw("COALESCE(sedes.nombre, 'Sin sede') as etiqueta"), DB::raw('count(*) as total'))
            ->groupBy('etiqueta')
            ->orderByDesc('total')
            ->get();

        $porEps = (clone $query)
            ->leftJoin('aseguradoras', 'aseguradoras.id', '=', 'pqrsd.aseguradora_id')
            ->select(DB::raw("COALESCE(aseguradoras.nombre, 'Sin EPS') as etiqueta"), DB::raw('count(*) as total'))
            ->groupBy('etiqueta')
            ->orderByDesc('total')
            ->get();

        $porTipo = (clone $query)
            ->leftJoin('tipos_pqrsd', 'tipos_pqrsd.id', '=', 'pqrsd.tipo_pqrsd_id')
            ->select(DB::raw("COALESCE(tipos_pqrsd.nombre, 'Sin tipo') as etiqueta"), DB::raw('count(*) as total'))
            ->groupBy('etiqueta')
            ->orderByDesc('total')
            ->get();

        $porRiesgo = (clone $query)
            ->select('nivel_riesgo as etiqueta', DB::raw('count(*) as total'))
            ->groupBy('nivel_riesgo')
            ->orderByDesc('total')
            ->get();

        $porResponsable = (clone $query)
            ->leftJoin('users', 'users.id', '=', 'pqrsd.responsable_id')
            ->select(DB::raw("COALESCE(users.name, 'Sin responsable') as etiqueta"), DB::raw('count(*) as total'))
            ->groupBy('etiqueta')
            ->orderByDesc('total')
            ->get();

        $respondidosConFecha = (clone $query)
            ->whereNotNull('fecha_respuesta')
            ->whereNotNull('fecha_recepcion')
            ->get(['fecha_recepcion', 'fecha_respuesta']);

        $tiempoPromedioHoras = $respondidosConFecha->isEmpty()
            ? null
            : $respondidosConFecha->avg(fn (Pqrsd $c) => $c->fecha_recepcion->diffInHours($c->fecha_respuesta));

        $casos = (clone $query)->get();
        $vigente = $casos->countBy(fn (Pqrsd $c) => $c->cumplimientoVigente());
        $oportunos = (int) ($vigente['resuelto_oportuno'] ?? 0);
        $extemporaneos = (int) ($vigente['resuelto_extemporaneo'] ?? 0);
        $respondidos = $oportunos + $extemporaneos;

        return [
            'total' => $total,
            'vencidos' => (int) ($vigente['vencido'] ?? 0),
            'proximos' => (int) ($vigente['proximo_vencer'] ?? 0),
            'dentro_plazo' => (int) ($vigente['dentro_plazo'] ?? 0),
            'oportunos' => $oportunos,
            'extemporaneos' => $extemporaneos,
            'pct_oportunidad' => $respondidos > 0 ? round(($oportunos / $respondidos) * 100, 1) : null,
            'por_cumplimiento_almacenado' => $porCumplimiento,
            'tiempo_promedio_horas' => $tiempoPromedioHoras !== null ? round((float) $tiempoPromedioHoras, 1) : null,
            'por_sede' => $porSede,
            'por_eps' => $porEps,
            'por_tipo' => $porTipo,
            'por_riesgo' => $porRiesgo,
            'por_responsable' => $porResponsable,
            'por_cumplimiento' => $porCumplimiento,
        ];
    }

    public function filasExportacion(Collection $casos): Collection
    {
        return $casos->map(function (Pqrsd $c) {
            $horas = null;
            if ($c->fecha_respuesta && $c->fecha_recepcion) {
                $horas = $c->fecha_recepcion->diffInHours($c->fecha_respuesta);
            }

            return [
                'Radicado' => $c->radicado,
                'Recepción' => $c->fecha_recepcion?->timezone('America/Bogota')->format('Y-m-d H:i'),
                'Radicación' => $c->fecha_radicacion?->timezone('America/Bogota')->format('Y-m-d H:i'),
                'Tipo' => $c->tipo?->nombre ?? 'Pendiente de clasificación',
                'Categoría' => $c->categoria?->nombre,
                'Subcategoría' => $c->subcategoria?->nombre,
                'Motivo' => $c->motivo?->nombre,
                'Riesgo' => $c->nivel_riesgo,
                'Identificación' => $c->es_anonimo ? 'Anónimo' : trim(($c->solicitante_tipo_documento ?? '').' '.($c->solicitante_documento ?? '')),
                'Teléfono' => $c->es_anonimo ? null : $c->solicitante_telefono,
                'EPS' => $c->aseguradora?->nombre,
                'Sede' => $c->sede?->nombre,
                'Canal' => $c->canal?->nombre,
                'Responsable' => $c->responsable?->name,
                'Estado operativo' => $c->estado_operativo,
                'Cumplimiento' => $c->cumplimientoVigente(),
                'Cumplimiento almacenado' => $c->estado_cumplimiento,
                'Plazo regla' => $c->reglaSla?->codigo,
                'Fecha límite' => $c->fecha_limite?->timezone('America/Bogota')->format('Y-m-d H:i'),
                'Fecha respuesta' => $c->fecha_respuesta?->timezone('America/Bogota')->format('Y-m-d H:i'),
                'Fecha cierre' => $c->fecha_cierre?->timezone('America/Bogota')->format('Y-m-d H:i'),
                'Actuaciones' => $c->actuaciones
                    ->sortBy('created_at')
                    ->map(fn ($a) => ($a->usuario?->name ?? 'Sistema').': '.$a->titulo)
                    ->implode(' | '),
                'Satisfacción' => $c->encuesta?->puntuacion,
                'Horas respuesta' => $horas,
            ];
        });
    }
}
