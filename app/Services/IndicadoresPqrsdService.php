<?php

namespace App\Services;

use App\Models\Pqrsd;
use App\Models\ReglaSla;
use App\Models\TipoPqrsd;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Indicadores de oportunidad PQRSD (cohorte principal por fecha de recepción).
 *
 * Oportunidad = comparación de fecha_respuesta vs fecha_limite (motor SLA).
 * fecha_respuesta se fija al registrar comunicación válida (consulta segura o canal verificable),
 * no al guardar una respuesta solo elaborada (config: oportunidad_exige_comunicacion).
 * Tiempo de respuesta = fecha_respuesta − fecha_recepcion (solo casos con comunicación/fecha oficial).
 */
class IndicadoresPqrsdService
{
    public function filtrarCohorteRecepcion(Request $request): Builder
    {
        $query = Pqrsd::query()->with(['tipo', 'sede', 'aseguradora', 'reglaSla']);

        $user = Auth::user();
        if ($user && ! $user->can('pqrsd.view_all')) {
            $uid = $user->id;
            $query->where(function ($q) use ($uid) {
                $q->where('responsable_id', $uid)
                    ->orWhere('creado_por', $uid)
                    ->orWhere('radicado_por', $uid);
            });
        }

        if ($request->filled('anio') && $request->input('anio') !== 'todos') {
            $query->whereYear('fecha_recepcion', (int) $request->input('anio'));
        }
        if ($request->filled('mes') && $request->input('mes') !== 'todos') {
            $query->whereMonth('fecha_recepcion', (int) $request->input('mes'));
        }
        if ($request->filled('sede_id') && $request->input('sede_id') !== 'todos') {
            $query->where('sede_id', (int) $request->input('sede_id'));
        }
        if ($request->filled('aseguradora_id') && $request->input('aseguradora_id') !== 'todos') {
            $query->where('aseguradora_id', (int) $request->input('aseguradora_id'));
        }
        if ($request->filled('tipo_pqrsd_id') && $request->input('tipo_pqrsd_id') !== 'todos') {
            $query->where('tipo_pqrsd_id', (int) $request->input('tipo_pqrsd_id'));
        }
        if ($request->filled('motivo_pqrsd_id') && $request->input('motivo_pqrsd_id') !== 'todos') {
            if ($request->input('motivo_pqrsd_id') === 'sin_motivo') {
                $query->whereNull('motivo_pqrsd_id');
            } else {
                $query->where('motivo_pqrsd_id', (int) $request->input('motivo_pqrsd_id'));
            }
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    public function construir(Request $request): array
    {
        $casos = $this->filtrarCohorteRecepcion($request)->get();
        $generales = $this->resumenGeneral($casos);
        $porRiesgo = $this->resumenPorDimension($casos, 'nivel_riesgo');
        $porTipo = $this->resumenPorTipo($casos);
        $barras = $this->datosBarras(array_merge($porRiesgo, $porTipo));
        $evolucion = $this->evolucionMensualRecepcion($request);
        $volumenRespuesta = $this->volumenMensualPorFechaRespuesta($request);

        $meta = $this->metaOportunidad();

        return [
            'cohorte' => 'fecha_recepcion',
            'denominador_explicacion' => 'Porcentaje de oportunidad = (respuestas oportunas / respuestas evaluables) × 100. '
                .'Evaluables: casos con respuesta oficial registrada y fecha límite SLA. '
                .'Pendientes y no evaluables se cuentan aparte. '
                .'Cumplimiento de meta: se compara el porcentaje preciso (sin redondear) contra la meta institucional.',
            'meta_oportunidad_pct' => $meta,
            'denuncia_pendiente' => (bool) config('pqrsd_sla.denuncia_unidad_conteo_pendiente_confirmacion', false),
            'denuncia_aprobacion_produccion_pendiente' => (bool) config('pqrsd_sla.denuncia_aprobacion_juridica_produccion_pendiente', true),
            'generales' => $generales,
            'circulares_riesgo' => array_values($porRiesgo),
            'circulares_tipo' => array_values($porTipo),
            'barras' => $barras,
            'evolucion_oportunidad' => $evolucion,
            'volumen_por_respuesta' => $volumenRespuesta,
            'tabla' => array_values(array_merge($porRiesgo, $porTipo)),
            'filtros' => $this->normalizarFiltros($request),
        ];
    }

    public function metaOportunidad(): float
    {
        return (float) config('pqrsd_sla.meta_oportunidad_pct', 95);
    }

    /**
     * Porcentaje preciso (sin redondear) para evaluar cumplimiento de meta.
     */
    public function pctOportunidadPreciso(int $oportunos, int $evaluables): ?float
    {
        if ($evaluables <= 0) {
            return null;
        }

        return ($oportunos / $evaluables) * 100;
    }

    /**
     * @return 'cumple'|'no_cumple'|'sin_datos'
     */
    public function evaluacionMeta(?float $pctPreciso, ?float $meta = null): string
    {
        if ($pctPreciso === null) {
            return 'sin_datos';
        }

        $meta ??= $this->metaOportunidad();

        return $pctPreciso >= $meta ? 'cumple' : 'no_cumple';
    }

    /**
     * @param  Collection<int, Pqrsd>  $casos
     * @return array<string, mixed>
     */
    public function resumenGeneral(Collection $casos): array
    {
        $recibidas = $casos->count();
        $conRespuesta = $casos->filter(fn (Pqrsd $c) => $c->fecha_respuesta !== null);
        $evaluables = $conRespuesta->filter(fn (Pqrsd $c) => $c->fecha_limite !== null);
        $oportunos = $evaluables->filter(fn (Pqrsd $c) => $this->esOportuno($c))->count();
        $extemporaneos = $evaluables->filter(fn (Pqrsd $c) => ! $this->esOportuno($c))->count();
        $pendientes = $casos->filter(fn (Pqrsd $c) => $c->fecha_respuesta === null
            && ! in_array($c->estado_operativo, ['cerrado'], true))->count();
        $vencidosSinRespuesta = $casos->filter(function (Pqrsd $c) {
            if ($c->fecha_respuesta !== null || ! $c->fecha_limite) {
                return false;
            }

            return $c->cumplimientoVigente() === 'vencido';
        })->count();

        $promedioSegundos = $conRespuesta
            ->filter(fn (Pqrsd $c) => $c->fecha_recepcion !== null)
            ->avg(fn (Pqrsd $c) => $c->fecha_recepcion->diffInSeconds($c->fecha_respuesta));

        $evaluablesCount = $evaluables->count();
        $pctPreciso = $this->pctOportunidadPreciso($oportunos, $evaluablesCount);
        $meta = $this->metaOportunidad();
        $cumplimientoMeta = $this->evaluacionMeta($pctPreciso, $meta);

        return [
            'recibidas' => $recibidas,
            'respondidas' => $conRespuesta->count(),
            'pendientes' => $pendientes,
            'vencidas_sin_respuesta' => $vencidosSinRespuesta,
            'oportunas' => $oportunos,
            'fuera_plazo' => $extemporaneos,
            'evaluables' => $evaluablesCount,
            'no_evaluables_respondidas' => $conRespuesta->count() - $evaluablesCount,
            'pct_oportunidad_preciso' => $pctPreciso,
            'pct_oportunidad' => $pctPreciso !== null ? round($pctPreciso, 1) : null,
            'meta_oportunidad_pct' => $meta,
            'cumplimiento_meta' => $cumplimientoMeta,
            'tiempo_promedio_segundos' => $promedioSegundos !== null ? (int) round($promedioSegundos) : null,
            'tiempo_promedio_legible' => $promedioSegundos !== null
                ? $this->formatearDuracion((int) round($promedioSegundos))
                : null,
        ];
    }

    public function esOportuno(Pqrsd $caso): bool
    {
        if (! $caso->fecha_respuesta || ! $caso->fecha_limite) {
            return false;
        }

        return $caso->cumplimientoVigente() === 'resuelto_oportuno';
    }

    /**
     * @param  Collection<int, Pqrsd>  $casos
     * @return array<string, array<string, mixed>>
     */
    public function resumenPorDimension(Collection $casos, string $campo): array
    {
        $orden = ['vital_menor', 'vital_general', 'priorizado', 'simple', 'ordinario', 'pendiente'];
        $etiquetas = [
            'vital_menor' => 'Riesgo vital en menores',
            'vital_general' => 'Riesgo vital general',
            'priorizado' => 'Riesgo priorizado',
            'simple' => 'Riesgo simple',
            'ordinario' => 'Ordinario / peticiones y quejas',
            'pendiente' => 'Sin clasificar (riesgo)',
        ];

        $out = [];
        foreach ($orden as $clave) {
            $grupo = $casos->where($campo, $clave);
            $out[$clave] = $this->bloqueClasificacion(
                clave: $clave,
                etiqueta: $etiquetas[$clave] ?? $clave,
                grupo: $grupo,
                plazo: $this->plazoParaNivel($clave),
            );
        }

        return $out;
    }

    /**
     * @param  Collection<int, Pqrsd>  $casos
     * @return array<string, array<string, mixed>>
     */
    public function resumenPorTipo(Collection $casos): array
    {
        $tipos = TipoPqrsd::query()->orderBy('orden')->get();
        $out = [];

        foreach ($tipos as $tipo) {
            $grupo = $casos->where('tipo_pqrsd_id', $tipo->id);
            $tieneSla = $grupo->contains(fn (Pqrsd $c) => $c->fecha_limite !== null);
            $out[$tipo->codigo] = $this->bloqueClasificacion(
                clave: $tipo->codigo,
                etiqueta: $tipo->nombre,
                grupo: $grupo,
                plazo: match ($tipo->codigo) {
                    'FELICITACION' => $tieneSla
                        ? 'Según regla SLA del caso (si aplica)'
                        : 'Sin plazo legal inventado',
                    'DENUNCIA' => (int) config('pqrsd_sla.denuncia_dias_habiles', 10).' días hábiles (parámetro institucional desarrollo)',
                    default => 'Según nivel de riesgo del caso',
                },
                soloOportunidadSiHayPlazo: $tipo->codigo === 'FELICITACION',
            );
        }

        return $out;
    }

    /**
     * @param  Collection<int, Pqrsd>  $grupo
     * @return array<string, mixed>
     */
    private function bloqueClasificacion(
        string $clave,
        string $etiqueta,
        Collection $grupo,
        string $plazo,
        bool $soloOportunidadSiHayPlazo = false,
    ): array {
        $recibidas = $grupo->count();
        $respondidas = $grupo->filter(fn (Pqrsd $c) => $c->fecha_respuesta !== null)->count();
        $evaluables = $grupo->filter(fn (Pqrsd $c) => $c->fecha_respuesta && $c->fecha_limite);
        $oportunos = $evaluables->filter(fn (Pqrsd $c) => $this->esOportuno($c))->count();
        $fuera = $evaluables->count() - $oportunos;
        $pendientes = $grupo->filter(fn (Pqrsd $c) => ! $c->fecha_respuesta)->count();
        $vencidos = $grupo->filter(function (Pqrsd $c) {
            if ($c->fecha_respuesta || ! $c->fecha_limite) {
                return false;
            }

            return $c->cumplimientoVigente() === 'vencido';
        })->count();

        $promedio = $grupo
            ->filter(fn (Pqrsd $c) => $c->fecha_respuesta && $c->fecha_recepcion)
            ->avg(fn (Pqrsd $c) => $c->fecha_recepcion->diffInSeconds($c->fecha_respuesta));

        $evalCount = $evaluables->count();
        $pctPreciso = null;
        $pct = null;
        $cumplimientoMeta = 'sin_datos';
        $estadoVisual = 'sin_datos';
        $meta = $this->metaOportunidad();

        if ($soloOportunidadSiHayPlazo && $evalCount === 0) {
            $cumplimientoMeta = 'sin_datos';
        } elseif ($evalCount > 0) {
            $pctPreciso = $this->pctOportunidadPreciso($oportunos, $evalCount);
            $pct = $pctPreciso !== null ? round($pctPreciso, 1) : null;
            $cumplimientoMeta = $this->evaluacionMeta($pctPreciso, $meta);
            $estadoVisual = match ($cumplimientoMeta) {
                'cumple' => 'ok',
                'no_cumple' => 'alerta',
                default => 'sin_datos',
            };
        }

        return [
            'clave' => $clave,
            'etiqueta' => $etiqueta,
            'recibidos' => $recibidas,
            'respondidos' => $respondidas,
            'pendientes' => $pendientes,
            'vencidos' => $vencidos,
            'oportunos' => $oportunos,
            'fuera_plazo' => $fuera,
            'evaluables' => $evalCount,
            'pct_oportunidad_preciso' => $pctPreciso,
            'pct_oportunidad' => $pct,
            'meta_oportunidad_pct' => $meta,
            'cumplimiento_meta' => $cumplimientoMeta,
            'tiempo_promedio_legible' => $promedio !== null ? $this->formatearDuracion((int) round($promedio)) : null,
            'plazo' => $plazo,
            'estado_visual' => $estadoVisual,
        ];
    }

    private function plazoParaNivel(string $nivel): string
    {
        $regla = ReglaSla::query()
            ->where('nivel_riesgo', $nivel)
            ->where('activo', true)
            ->orderBy('prioridad')
            ->first();

        if (! $regla) {
            return 'Sin regla SLA activa';
        }

        $unidad = match ($regla->unidad) {
            'horas_calendario' => 'horas calendario',
            'dias_habiles' => 'días hábiles',
            'dias_calendario' => 'días calendario',
            default => $regla->unidad,
        };

        return $regla->cantidad.' '.$unidad;
    }

    /**
     * @param  array<int, array<string, mixed>>  $bloques
     * @return array{labels: list<string>, oportunos: list<int>, fuera: list<int>}
     */
    public function datosBarras(array $bloques): array
    {
        $conDatos = array_values(array_filter(
            $bloques,
            fn (array $b) => ($b['evaluables'] ?? 0) > 0 || ($b['respondidos'] ?? 0) > 0
        ));

        return [
            'labels' => array_map(fn (array $b) => $b['etiqueta'], $conDatos),
            'oportunos' => array_map(fn (array $b) => (int) $b['oportunos'], $conDatos),
            'fuera' => array_map(fn (array $b) => (int) $b['fuera_plazo'], $conDatos),
        ];
    }

    /**
     * Evolución de oportunidad (cohorte por recepción).
     * Año completo → serie mensual (ene–dic).
     * Mes específico → serie diaria (o semanal si el mes tiene pocos evaluables).
     *
     * @return array<string, mixed>
     */
    public function evolucionMensualRecepcion(Request $request): array
    {
        $anio = ($request->filled('anio') && $request->input('anio') !== 'todos')
            ? (int) $request->input('anio')
            : (int) now('America/Bogota')->format('Y');

        $mesSeleccionado = ($request->filled('mes') && $request->input('mes') !== 'todos')
            ? (int) $request->input('mes')
            : null;

        if ($mesSeleccionado !== null) {
            return $this->evolucionDentroDelMes($request, $anio, $mesSeleccionado);
        }

        $base = $this->filtrarCohorteRecepcion(new Request(array_merge(
            $request->except(['anio', 'mes']),
            ['anio' => $anio, 'mes' => 'todos']
        )))->get();

        $labels = [];
        $pctOportuno = [];
        $pctFuera = [];
        $detalle = [];

        for ($mes = 1; $mes <= 12; $mes++) {
            $labels[] = Carbon::create($anio, $mes, 1, 0, 0, 0, 'America/Bogota')->translatedFormat('M');
            $grupo = $base->filter(fn (Pqrsd $c) => (int) $c->fecha_recepcion?->timezone('America/Bogota')->format('n') === $mes);
            $eval = $grupo->filter(fn (Pqrsd $c) => $c->fecha_respuesta && $c->fecha_limite);
            $op = $eval->filter(fn (Pqrsd $c) => $this->esOportuno($c))->count();
            $fuera = $eval->count() - $op;
            $totalEval = $eval->count();

            $pctOportuno[] = $totalEval > 0 ? round(($op / $totalEval) * 100, 1) : null;
            $pctFuera[] = $totalEval > 0 ? round(($fuera / $totalEval) * 100, 1) : null;
            $detalle[] = [
                'periodo' => $mes,
                'etiqueta' => Carbon::create($anio, $mes, 1)->translatedFormat('F Y'),
                'recibidos' => $grupo->count(),
                'respondidos' => $grupo->filter(fn (Pqrsd $c) => $c->fecha_respuesta)->count(),
                'evaluables' => $totalEval,
                'oportunos' => $op,
                'fuera_plazo' => $fuera,
                'pct_oportunidad' => $totalEval > 0 ? round(($op / $totalEval) * 100, 1) : null,
            ];
        }

        return [
            'titulo' => 'Evolución mensual de la oportunidad de respuesta PQRSD',
            'agrupacion' => 'mes',
            'eje_x' => 'Mes',
            'cohorte' => 'fecha_recepcion',
            'anio' => $anio,
            'mes' => null,
            'labels' => $labels,
            'pct_oportuno' => $pctOportuno,
            'pct_fuera' => $pctFuera,
            'meta' => $this->metaOportunidad(),
            'detalle' => $detalle,
            'sin_datos_serie' => collect($pctOportuno)->filter(fn ($v) => $v !== null)->isEmpty(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function evolucionDentroDelMes(Request $request, int $anio, int $mes): array
    {
        $base = $this->filtrarCohorteRecepcion(new Request(array_merge(
            $request->except(['anio', 'mes']),
            ['anio' => $anio, 'mes' => $mes]
        )))->get();

        $diasEnMes = (int) Carbon::create($anio, $mes, 1, 0, 0, 0, 'America/Bogota')->daysInMonth;
        $evalTotalMes = $base->filter(fn (Pqrsd $c) => $c->fecha_respuesta && $c->fecha_limite)->count();
        $usarSemanas = $evalTotalMes > 0 && $evalTotalMes < 8 && $diasEnMes >= 28;

        $labels = [];
        $pctOportuno = [];
        $pctFuera = [];
        $detalle = [];

        if ($usarSemanas) {
            $inicio = Carbon::create($anio, $mes, 1, 0, 0, 0, 'America/Bogota')->startOfDay();
            $finMes = $inicio->copy()->endOfMonth();
            $semana = 1;
            $cursor = $inicio->copy();
            while ($cursor->lte($finMes)) {
                $finSemana = $cursor->copy()->addDays(6)->endOfDay();
                if ($finSemana->gt($finMes)) {
                    $finSemana = $finMes->copy();
                }
                $grupo = $base->filter(function (Pqrsd $c) use ($cursor, $finSemana) {
                    $f = $c->fecha_recepcion?->timezone('America/Bogota');

                    return $f && $f->betweenIncluded($cursor, $finSemana);
                });
                $this->acumularPuntoEvolucion(
                    $grupo,
                    'Sem '.$semana.' ('.$cursor->format('d').'–'.$finSemana->format('d').')',
                    $cursor->translatedFormat('d M').' – '.$finSemana->translatedFormat('d M Y'),
                    $semana,
                    $labels,
                    $pctOportuno,
                    $pctFuera,
                    $detalle
                );
                $cursor = $finSemana->copy()->addDay()->startOfDay();
                $semana++;
            }
            $titulo = 'Evolución semanal de la oportunidad — '.Carbon::create($anio, $mes, 1)->translatedFormat('F Y');
            $agrupacion = 'semana';
            $eje = 'Semana';
        } else {
            for ($dia = 1; $dia <= $diasEnMes; $dia++) {
                $grupo = $base->filter(fn (Pqrsd $c) => (int) $c->fecha_recepcion?->timezone('America/Bogota')->format('j') === $dia);
                $fecha = Carbon::create($anio, $mes, $dia, 0, 0, 0, 'America/Bogota');
                $this->acumularPuntoEvolucion(
                    $grupo,
                    (string) $dia,
                    $fecha->translatedFormat('d F Y'),
                    $dia,
                    $labels,
                    $pctOportuno,
                    $pctFuera,
                    $detalle
                );
            }
            $titulo = 'Evolución diaria de la oportunidad — '.Carbon::create($anio, $mes, 1)->translatedFormat('F Y');
            $agrupacion = 'dia';
            $eje = 'Día del mes';
        }

        return [
            'titulo' => $titulo,
            'agrupacion' => $agrupacion,
            'eje_x' => $eje,
            'cohorte' => 'fecha_recepcion',
            'anio' => $anio,
            'mes' => $mes,
            'labels' => $labels,
            'pct_oportuno' => $pctOportuno,
            'pct_fuera' => $pctFuera,
            'meta' => $this->metaOportunidad(),
            'detalle' => $detalle,
            'sin_datos_serie' => collect($pctOportuno)->filter(fn ($v) => $v !== null)->isEmpty(),
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Pqrsd>  $grupo
     * @param  list<string>  $labels
     * @param  list<?float>  $pctOportuno
     * @param  list<?float>  $pctFuera
     * @param  list<array<string, mixed>>  $detalle
     */
    private function acumularPuntoEvolucion(
        Collection $grupo,
        string $label,
        string $etiqueta,
        int $periodo,
        array &$labels,
        array &$pctOportuno,
        array &$pctFuera,
        array &$detalle
    ): void {
        $eval = $grupo->filter(fn (Pqrsd $c) => $c->fecha_respuesta && $c->fecha_limite);
        $op = $eval->filter(fn (Pqrsd $c) => $this->esOportuno($c))->count();
        $fuera = $eval->count() - $op;
        $totalEval = $eval->count();

        $labels[] = $label;
        $pctOportuno[] = $totalEval > 0 ? round(($op / $totalEval) * 100, 1) : null;
        $pctFuera[] = $totalEval > 0 ? round(($fuera / $totalEval) * 100, 1) : null;
        $detalle[] = [
            'periodo' => $periodo,
            'etiqueta' => $etiqueta,
            'recibidos' => $grupo->count(),
            'respondidos' => $grupo->filter(fn (Pqrsd $c) => $c->fecha_respuesta)->count(),
            'evaluables' => $totalEval,
            'oportunos' => $op,
            'fuera_plazo' => $fuera,
            'pct_oportunidad' => $totalEval > 0 ? round(($op / $totalEval) * 100, 1) : null,
        ];
    }

    /**
     * Volumen mensual agrupado por fecha de respuesta (etiqueta explícita).
     *
     * @return array<string, mixed>
     */
    public function volumenMensualPorFechaRespuesta(Request $request): array
    {
        $anio = ($request->filled('anio') && $request->input('anio') !== 'todos')
            ? (int) $request->input('anio')
            : (int) now('America/Bogota')->format('Y');

        $filtrosBase = $request->except(['anio', 'mes']);
        $recibidasQuery = $this->filtrarCohorteRecepcion(new Request(array_merge($filtrosBase, [
            'anio' => $anio,
            'mes' => 'todos',
        ])))->get();

        // Respondidas en el año según fecha_respuesta (misma sede/EPS/tipo).
        $respondidasQuery = Pqrsd::query()->whereNotNull('fecha_respuesta');
        $user = Auth::user();
        if ($user && ! $user->can('pqrsd.view_all')) {
            $uid = $user->id;
            $respondidasQuery->where(function ($q) use ($uid) {
                $q->where('responsable_id', $uid)
                    ->orWhere('creado_por', $uid)
                    ->orWhere('radicado_por', $uid);
            });
        }
        $respondidasQuery->whereYear('fecha_respuesta', $anio);
        if (($filtrosBase['sede_id'] ?? 'todos') !== 'todos' && ! empty($filtrosBase['sede_id'])) {
            $respondidasQuery->where('sede_id', (int) $filtrosBase['sede_id']);
        }
        if (($filtrosBase['aseguradora_id'] ?? 'todos') !== 'todos' && ! empty($filtrosBase['aseguradora_id'])) {
            $respondidasQuery->where('aseguradora_id', (int) $filtrosBase['aseguradora_id']);
        }
        if (($filtrosBase['tipo_pqrsd_id'] ?? 'todos') !== 'todos' && ! empty($filtrosBase['tipo_pqrsd_id'])) {
            $respondidasQuery->where('tipo_pqrsd_id', (int) $filtrosBase['tipo_pqrsd_id']);
        }
        if (($filtrosBase['motivo_pqrsd_id'] ?? 'todos') !== 'todos' && ! empty($filtrosBase['motivo_pqrsd_id'])) {
            if ($filtrosBase['motivo_pqrsd_id'] === 'sin_motivo') {
                $respondidasQuery->whereNull('motivo_pqrsd_id');
            } else {
                $respondidasQuery->where('motivo_pqrsd_id', (int) $filtrosBase['motivo_pqrsd_id']);
            }
        }
        $respondidas = $respondidasQuery->get();

        $labels = [];
        $recibidasSerie = [];
        $respondidasSerie = [];
        $detalle = [];

        for ($mes = 1; $mes <= 12; $mes++) {
            $labels[] = Carbon::create($anio, $mes, 1)->translatedFormat('M');
            $rec = $recibidasQuery->filter(fn (Pqrsd $c) => (int) $c->fecha_recepcion?->format('n') === $mes)->count();
            $resp = $respondidas->filter(fn (Pqrsd $c) => (int) $c->fecha_respuesta?->format('n') === $mes)->count();
            $recibidasSerie[] = $rec;
            $respondidasSerie[] = $resp;
            $detalle[] = [
                'mes' => $mes,
                'recibidas_por_recepcion' => $rec,
                'respondidas_por_fecha_respuesta' => $resp,
            ];
        }

        return [
            'titulo' => 'Volumen mensual (recepción vs respuesta)',
            'nota' => 'Barras de recibidas: cohorte por fecha_recepcion. Barras de respondidas: agrupadas por fecha_respuesta.',
            'anio' => $anio,
            'labels' => $labels,
            'recibidas' => $recibidasSerie,
            'respondidas' => $respondidasSerie,
            'detalle' => $detalle,
        ];
    }

    public function formatearDuracion(int $segundos): string
    {
        if ($segundos < 3600) {
            return round($segundos / 60).' min';
        }
        if ($segundos < 86400) {
            $h = intdiv($segundos, 3600);
            $m = intdiv($segundos % 3600, 60);

            return $m > 0 ? "{$h} h {$m} min" : "{$h} h";
        }
        $d = intdiv($segundos, 86400);
        $h = intdiv($segundos % 86400, 3600);

        return $h > 0 ? "{$d} d {$h} h" : "{$d} d";
    }

    /**
     * @return array<string, mixed>
     */
    public function normalizarFiltros(Request $request): array
    {
        return [
            'anio' => $request->input('anio', 'todos'),
            'mes' => $request->input('mes', 'todos'),
            'sede_id' => $request->input('sede_id', 'todos'),
            'aseguradora_id' => $request->input('aseguradora_id', 'todos'),
            'tipo_pqrsd_id' => $request->input('tipo_pqrsd_id', 'todos'),
            'motivo_pqrsd_id' => $request->input('motivo_pqrsd_id', 'todos'),
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function filasExportacion(array $tablero): Collection
    {
        $meta = $tablero['meta_oportunidad_pct'] ?? $this->metaOportunidad();

        return collect($tablero['tabla'] ?? [])->map(fn (array $f) => [
            'Clasificación' => $f['etiqueta'],
            'Recibidos' => $f['recibidos'],
            'Respondidos' => $f['respondidos'],
            'Pendientes' => $f['pendientes'],
            'Vencidos' => $f['vencidos'],
            'Oportunos' => $f['oportunos'],
            'Fuera de plazo' => $f['fuera_plazo'],
            'Evaluables' => $f['evaluables'],
            '% Oportunidad' => $f['pct_oportunidad'] ?? 'Sin datos',
            'Meta %' => $meta,
            'Cumple meta' => match ($f['cumplimiento_meta'] ?? 'sin_datos') {
                'cumple' => 'Sí',
                'no_cumple' => 'No',
                default => 'Sin datos',
            },
            'Tiempo promedio' => $f['tiempo_promedio_legible'] ?? '—',
            'Plazo' => $f['plazo'],
        ]);
    }
}
