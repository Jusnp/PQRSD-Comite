<?php

namespace App\Services;

use App\Models\Pqrsd;
use App\Models\ReglaSla;
use App\Models\TipoPqrsd;
use App\Models\User;
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
    /** @var list<string> */
    public const NIVELES_RIESGO = [
        'vital_menor', 'vital_general', 'priorizado', 'simple', 'ordinario', 'pendiente',
    ];

    /** @var list<string> */
    public const ESTADOS_CUMPLIMIENTO = [
        'dentro_plazo', 'proximo_vencer', 'vencido', 'resuelto_oportuno', 'resuelto_extemporaneo', 'sin_clasificar',
    ];

    public function filtrarCohorteRecepcion(Request $request): Builder
    {
        $query = Pqrsd::query()->with(['tipo', 'sede', 'aseguradora', 'reglaSla', 'categoria', 'subcategoria', 'encuesta']);
        $this->aplicarFiltrosCohorte($query, $request);

        return $query;
    }

    /**
     * Misma cohorte para indicadores, listado y exportaciones.
     * Año, mes y el rango desde/hasta usan la fecha de recepción.
     */
    public function aplicarFiltrosCohorte(Builder $query, Request $request): void
    {
        $this->aplicarVisibilidad($query);
        $this->aplicarPeriodoRecepcion($query, $request);
        $this->aplicarAtributos($query, $request);
    }

    /**
     * @return array{consulta: array<string, mixed>, errores: array<string, string>, solicitud: Request}
     */
    public function depurarSolicitud(Request $request): array
    {
        $datos = $request->query();
        $errores = [];

        $desde = $this->fechaRecepcion($datos['desde'] ?? null);
        $hasta = $this->fechaRecepcion($datos['hasta'] ?? null);
        if ($this->vino($datos['desde'] ?? null) && $desde === null) {
            $errores['desde'] = 'La fecha desde no es válida. Use AAAA-MM-DD. Ese valor no se aplicó.';
            unset($datos['desde']);
        }
        if ($this->vino($datos['hasta'] ?? null) && $hasta === null) {
            $errores['hasta'] = 'La fecha hasta no es válida. Use AAAA-MM-DD. Ese valor no se aplicó.';
            unset($datos['hasta']);
        }
        if ($desde !== null && $hasta !== null && $desde > $hasta && ! isset($errores['desde']) && ! isset($errores['hasta'])) {
            $errores['hasta'] = 'La fecha hasta es anterior a la fecha desde. El rango no se aplicó.';
            unset($datos['desde'], $datos['hasta']);
        }

        $riesgo = $datos['nivel_riesgo'] ?? null;
        if ($this->vino($riesgo) && ! in_array((string) $riesgo, self::NIVELES_RIESGO, true)) {
            $errores['nivel_riesgo'] = 'El nivel de riesgo no corresponde al catálogo. No se aplicó.';
            unset($datos['nivel_riesgo']);
        }

        $cumplimiento = $datos['estado_cumplimiento'] ?? null;
        if ($this->vino($cumplimiento) && ! in_array((string) $cumplimiento, self::ESTADOS_CUMPLIMIENTO, true)) {
            $errores['estado_cumplimiento'] = 'El estado de cumplimiento no es válido. No se aplicó.';
            unset($datos['estado_cumplimiento']);
        }

        $responsable = $datos['responsable_id'] ?? null;
        if ($this->vino($responsable) && (! ctype_digit((string) $responsable) || ! User::query()->whereKey((int) $responsable)->exists())) {
            $errores['responsable_id'] = 'Ese responsable no está registrado. El filtro no se aplicó.';
            unset($datos['responsable_id']);
        }

        unset($datos['page']);

        return [
            'consulta' => $datos,
            'errores' => $errores,
            'solicitud' => new Request($datos),
        ];
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
            'por_categoria' => $this->conteoEtiqueta($casos, fn (Pqrsd $c) => $c->categoria?->nombre ?? 'Sin categoría'),
            'por_subcategoria' => $this->conteoEtiqueta(
                $casos,
                fn (Pqrsd $c) => ($c->categoria?->nombre ?? 'Sin categoría').' / '.($c->subcategoria?->nombre ?? 'Sin subcategoría')
            ),
            'vencidos_por_sede' => $this->conteoEtiqueta(
                $casos->filter(fn (Pqrsd $c) => $c->cumplimientoVigente() === 'vencido'),
                fn (Pqrsd $c) => $c->sede?->nombre ?? 'Sin sede'
            ),
            'satisfaccion_por_sede' => $this->promedioEncuesta($casos, fn (Pqrsd $c) => $c->sede?->nombre ?? 'Sin sede'),
            'satisfaccion_por_tipo' => $this->promedioEncuesta($casos, fn (Pqrsd $c) => $c->tipo?->nombre ?? 'Sin tipo'),
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
        ] + $this->bloqueAdministrativo($casos);
    }

    /**
     * Conteos vivos. Próximos y vencidos salen de la fecha límite y el momento actual.
     *
     * @param  Collection<int, Pqrsd>  $casos
     * @return array<string, mixed>
     */
    public function resumenOperativo(Collection $casos): array
    {
        return $this->bloqueAdministrativo($casos);
    }

    /**
     * @param  Collection<int, Pqrsd>  $casos
     * @return array<string, mixed>
     */
    private function bloqueAdministrativo(Collection $casos): array
    {
        $cerradas = $casos->where('estado_operativo', 'cerrado');
        $encuestas = $casos->map(fn (Pqrsd $c) => $c->relationLoaded('encuesta') ? $c->encuesta : $c->encuesta()->first())->filter();
        $promedio = $encuestas->isEmpty() ? null : round((float) $encuestas->avg('puntuacion'), 2);
        $tasa = $cerradas->count() > 0
            ? round(($encuestas->count() / $cerradas->count()) * 100, 1)
            : null;

        return [
            'recibidas' => $casos->count(),
            'pendientes_clasificacion' => $casos->filter(fn (Pqrsd $c) => $c->pendienteDeClasificacion())->count(),
            'en_proceso' => $casos->filter(fn (Pqrsd $c) => ! in_array($c->estado_operativo, ['cerrado', 'respondido'], true)
                && $c->fecha_respuesta === null
                && $c->nivel_riesgo !== 'pendiente')->count(),
            'proximas_vencer' => $casos->filter(fn (Pqrsd $c) => $c->cumplimientoVigente() === 'proximo_vencer')->count(),
            'vencidas' => $casos->filter(fn (Pqrsd $c) => $c->cumplimientoVigente() === 'vencido')->count(),
            'cerradas' => $cerradas->count(),
            'satisfaccion_promedio' => $promedio,
            'encuestas' => $encuestas->count(),
            'tasa_participacion' => $tasa,
            'alertas_vitales_preventivas' => $casos->where('alerta_vital_preventiva', true)->where('nivel_riesgo', 'pendiente')->count(),
            'sin_clasificar_espera_critica' => $casos->filter(fn (Pqrsd $c) => $c->esperaSuperaPlazoMasCorto())->count(),
        ];
    }

    /**
     * @param  Collection<int, Pqrsd>  $casos
     * @param  callable(Pqrsd): string  $etiqueta
     * @return list<array{etiqueta: string, total: int}>
     */
    private function conteoEtiqueta(Collection $casos, callable $etiqueta): array
    {
        return $casos
            ->groupBy($etiqueta)
            ->map(fn (Collection $grupo, string $nombre) => ['etiqueta' => $nombre, 'total' => $grupo->count()])
            ->sortByDesc('total')
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Pqrsd>  $casos
     * @param  callable(Pqrsd): string  $etiqueta
     * @return list<array{etiqueta: string, promedio: float|null, encuestas: int}>
     */
    private function promedioEncuesta(Collection $casos, callable $etiqueta): array
    {
        return $casos
            ->groupBy($etiqueta)
            ->map(function (Collection $grupo, string $nombre) {
                $encuestas = $grupo->map(fn (Pqrsd $c) => $c->encuesta)->filter();

                return [
                    'etiqueta' => $nombre,
                    'promedio' => $encuestas->isEmpty() ? null : round((float) $encuestas->avg('puntuacion'), 2),
                    'encuestas' => $encuestas->count(),
                ];
            })
            ->values()
            ->all();
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

        $respondidasQuery = Pqrsd::query()->whereNotNull('fecha_respuesta');
        $this->aplicarVisibilidad($respondidasQuery);
        $this->aplicarAtributos($respondidasQuery, new Request($filtrosBase));
        $respondidasQuery->whereYear('fecha_respuesta', $anio);
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
            'desde' => $request->input('desde', ''),
            'hasta' => $request->input('hasta', ''),
            'nivel_riesgo' => $this->valor($request, 'nivel_riesgo') ?? '',
            'responsable_id' => $this->valor($request, 'responsable_id') ?? '',
            'estado_cumplimiento' => $this->valor($request, 'estado_cumplimiento') ?? '',
        ];
    }

    private function aplicarVisibilidad(Builder $query): void
    {
        $user = Auth::user();
        if ($user && ! $user->can('pqrsd.view_all')) {
            $uid = $user->id;
            $query->where(function ($q) use ($uid) {
                $q->where('responsable_id', $uid)
                    ->orWhere('creado_por', $uid)
                    ->orWhere('radicado_por', $uid);
            });
        }
    }

    private function aplicarPeriodoRecepcion(Builder $query, Request $request): void
    {
        if ($this->valor($request, 'anio') !== null) {
            $query->whereYear('fecha_recepcion', (int) $request->input('anio'));
        }
        if ($this->valor($request, 'mes') !== null) {
            $query->whereMonth('fecha_recepcion', (int) $request->input('mes'));
        }

        $desde = $this->fechaRecepcion($request->input('desde'));
        $hasta = $this->fechaRecepcion($request->input('hasta'));
        if ($desde !== null && $hasta !== null && $desde > $hasta) {
            return;
        }
        if ($desde !== null) {
            $query->whereDate('fecha_recepcion', '>=', $desde);
        }
        if ($hasta !== null) {
            $query->whereDate('fecha_recepcion', '<=', $hasta);
        }
    }

    private function aplicarAtributos(Builder $query, Request $request): void
    {
        if ($this->valor($request, 'sede_id') !== null) {
            $query->where('sede_id', (int) $request->input('sede_id'));
        }
        if ($this->valor($request, 'aseguradora_id') !== null) {
            $query->where('aseguradora_id', (int) $request->input('aseguradora_id'));
        }
        if ($this->valor($request, 'tipo_pqrsd_id') !== null) {
            $query->where('tipo_pqrsd_id', (int) $request->input('tipo_pqrsd_id'));
        }
        $motivo = $this->valor($request, 'motivo_pqrsd_id');
        if ($motivo === 'sin_motivo') {
            $query->whereNull('motivo_pqrsd_id');
        } elseif ($motivo !== null) {
            $query->where('motivo_pqrsd_id', (int) $motivo);
        }
        $riesgo = $this->valor($request, 'nivel_riesgo');
        if ($riesgo !== null && in_array($riesgo, self::NIVELES_RIESGO, true)) {
            $query->where('nivel_riesgo', $riesgo);
        }
        $responsable = $this->valor($request, 'responsable_id');
        if ($responsable !== null && ctype_digit($responsable)) {
            $query->where('responsable_id', (int) $responsable);
        }
        $cumplimiento = $this->valor($request, 'estado_cumplimiento');
        if ($cumplimiento !== null && in_array($cumplimiento, self::ESTADOS_CUMPLIMIENTO, true)) {
            app(SlaCalculator::class)->restringirConsulta($query, $cumplimiento);
        }
        if ($this->valor($request, 'estado_operativo') !== null) {
            $query->where('estado_operativo', (string) $request->input('estado_operativo'));
        }
    }

    private function valor(Request $request, string $clave): ?string
    {
        if (! $request->filled($clave)) {
            return null;
        }
        $valor = trim((string) $request->input($clave));
        if ($valor === '' || $valor === 'todos') {
            return null;
        }

        return $valor;
    }

    private function fechaRecepcion(mixed $valor): ?string
    {
        if (! $this->vino($valor) || ! is_string($valor) && ! is_numeric($valor)) {
            return null;
        }
        $texto = trim((string) $valor);
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $texto, $partes)) {
            return null;
        }
        if (! checkdate((int) $partes[2], (int) $partes[3], (int) $partes[1])) {
            return null;
        }

        return $texto;
    }

    private function vino(mixed $valor): bool
    {
        if ($valor === null) {
            return false;
        }
        $texto = trim((string) $valor);

        return $texto !== '' && $texto !== 'todos';
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
