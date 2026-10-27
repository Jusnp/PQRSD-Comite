<?php

namespace App\Services;

use App\Models\Festivo;
use App\Models\Pqrsd;
use App\Models\ReglaSla;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class SlaCalculator
{
    public function calcularFechaLimite(ReglaSla $regla, CarbonInterface $inicio): Carbon
    {
        $inicio = Carbon::parse($inicio)->timezone('America/Bogota');

        return match ($regla->unidad) {
            'horas_calendario' => $inicio->copy()->addHours((int) $regla->cantidad),
            'dias_calendario' => $inicio->copy()->addDays((int) $regla->cantidad),
            'dias_habiles' => $this->sumarDiasHabiles($inicio, (int) $regla->cantidad),
            default => $inicio->copy()->addDays((int) $regla->cantidad),
        };
    }

    /**
     * Suma N días hábiles colombianos (salta sábados, domingos y festivos).
     * El conteo inicia al día siguiente hábil relativo: cada día hábil completo suma 1.
     * Conserva la hora del inicio en la fecha límite resultante.
     */
    public function sumarDiasHabiles(CarbonInterface $inicio, int $dias): Carbon
    {
        $fecha = Carbon::parse($inicio)->timezone('America/Bogota');
        $hora = $fecha->format('H:i:s');
        $restantes = $dias;

        $festivos = $this->festivosDesde($fecha);

        while ($restantes > 0) {
            $fecha->addDay();

            if ($this->esDiaNoHabil($fecha, $festivos)) {
                continue;
            }

            $restantes--;
        }

        return Carbon::parse($fecha->toDateString().' '.$hora, 'America/Bogota');
    }

    public function esDiaHabil(CarbonInterface $fecha): bool
    {
        $fecha = Carbon::parse($fecha)->timezone('America/Bogota');

        return ! $this->esDiaNoHabil($fecha, $this->festivosDesde($fecha->copy()->subDay()));
    }

    /**
     * Edad en años cumplidos a la fecha de recepción (no la edad actual).
     */
    public function edadEnFecha(?CarbonInterface $nacimiento, CarbonInterface $referencia, ?int $edadDeclarada = null): ?int
    {
        if ($nacimiento) {
            return Carbon::parse($nacimiento)->timezone('America/Bogota')
                ->diffInYears(Carbon::parse($referencia)->timezone('America/Bogota'));
        }

        return $edadDeclarada;
    }

    /**
     * Resuelve el nivel de riesgo efectivo.
     * Un vital con menor de 18 años a la recepción siempre aplica vital_menor.
     * Cumplir 18 después de radicar no cambia la regla ya aplicada.
     */
    public function resolverNivelRiesgo(
        string $nivelSolicitado,
        ?CarbonInterface $nacimiento,
        CarbonInterface $fechaRecepcion,
        ?int $edadDeclarada = null
    ): array {
        $edad = $this->edadEnFecha($nacimiento, $fechaRecepcion, $edadDeclarada);

        if (in_array($nivelSolicitado, ['vital_menor', 'vital_general'], true)) {
            if ($edad !== null && $edad < 18) {
                return [
                    'nivel_riesgo' => 'vital_menor',
                    'edad_en_recepcion' => $edad,
                    'motivo' => 'Riesgo vital con edad < 18 en la fecha de recepción',
                ];
            }

            if ($nivelSolicitado === 'vital_menor' && ($edad === null)) {
                return [
                    'nivel_riesgo' => 'vital_menor',
                    'edad_en_recepcion' => null,
                    'motivo' => 'Vital menor declarado; edad no verificable — alerta de clasificación',
                    'alerta_edad' => true,
                ];
            }

            return [
                'nivel_riesgo' => $edad !== null && $edad >= 18 ? 'vital_general' : $nivelSolicitado,
                'edad_en_recepcion' => $edad,
                'motivo' => 'Riesgo vital general (18+ a la recepción o sin confirmación de menor)',
            ];
        }

        return [
            'nivel_riesgo' => $nivelSolicitado,
            'edad_en_recepcion' => $edad,
            'motivo' => 'Nivel solicitado sin ajuste por edad',
        ];
    }

    public function reglaParaNivel(string $nivelRiesgo): ?ReglaSla
    {
        return ReglaSla::query()
            ->where('nivel_riesgo', $nivelRiesgo)
            ->where('activo', true)
            ->orderBy('prioridad')
            ->first();
    }

    /**
     * Selecciona la regla SLA aplicable.
     * Prioridad: riesgos especiales (vital/priorizado/simple) > Denuncia 10 días hábiles > nivel ordinario u otros.
     * No inventa plazos para felicitaciones u otros tipos sin regla.
     */
    public function reglaParaCaso(string $nivelRiesgo, ?string $tipoCodigo = null): ?ReglaSla
    {
        if (in_array($nivelRiesgo, ['vital_menor', 'vital_general', 'priorizado', 'simple'], true)) {
            return $this->reglaParaNivel($nivelRiesgo);
        }

        if ($tipoCodigo === 'DENUNCIA') {
            $denuncia = ReglaSla::query()
                ->where('codigo', 'DENUNCIA_10DH')
                ->where('activo', true)
                ->first();

            if ($denuncia) {
                return $denuncia;
            }
        }

        if ($nivelRiesgo === 'pendiente') {
            return null;
        }

        return $this->reglaParaNivel($nivelRiesgo);
    }

    public function aplicarACaso(ReglaSla $regla, CarbonInterface $inicioConteo, ?CarbonInterface $ahora = null): array
    {
        $inicio = Carbon::parse($inicioConteo)->timezone('America/Bogota');
        $limite = $this->calcularFechaLimite($regla, $inicio);
        $ahora = Carbon::parse($ahora ?? now('America/Bogota'))->timezone('America/Bogota');

        return [
            'regla_sla_id' => $regla->id,
            'regla_sla_version' => $regla->version,
            'fecha_inicio_conteo' => $inicio,
            'fecha_limite' => $limite,
            'estado_cumplimiento' => $this->calcularEstadoCumplimiento(
                $inicio,
                $limite,
                $regla->nivel_riesgo,
                $ahora,
                respondido: false,
                fechaRespuesta: null
            ),
        ];
    }

    public function calcularEstadoCumplimiento(
        ?CarbonInterface $inicio,
        ?CarbonInterface $limite,
        ?string $nivelRiesgo,
        ?CarbonInterface $ahora = null,
        bool $respondido = false,
        ?CarbonInterface $fechaRespuesta = null
    ): string {
        if (! $inicio || ! $limite) {
            return 'sin_clasificar';
        }

        $ahora = Carbon::parse($ahora ?? now('America/Bogota'))->timezone('America/Bogota');
        $limite = Carbon::parse($limite)->timezone('America/Bogota');
        $inicio = Carbon::parse($inicio)->timezone('America/Bogota');

        if ($respondido || $fechaRespuesta) {
            $respuesta = Carbon::parse($fechaRespuesta ?? $ahora)->timezone('America/Bogota');

            return $respuesta->lessThanOrEqualTo($limite)
                ? 'resuelto_oportuno'
                : 'resuelto_extemporaneo';
        }

        if ($ahora->greaterThan($limite)) {
            return 'vencido';
        }

        if ($this->estaProximoAVencer($inicio, $limite, $nivelRiesgo ?? 'ordinario', $ahora)) {
            return 'proximo_vencer';
        }

        return 'dentro_plazo';
    }

    public function estaProximoAVencer(
        CarbonInterface $inicio,
        CarbonInterface $limite,
        string $nivelRiesgo,
        ?CarbonInterface $ahora = null
    ): bool {
        $ahora = Carbon::parse($ahora ?? now('America/Bogota'))->timezone('America/Bogota');
        $inicio = Carbon::parse($inicio)->timezone('America/Bogota');
        $limite = Carbon::parse($limite)->timezone('America/Bogota');

        if ($ahora->greaterThanOrEqualTo($limite)) {
            return false;
        }

        $totalSegundos = max(1, $inicio->diffInSeconds($limite));
        $restantes = $ahora->diffInSeconds($limite);
        $fraccion = (float) config('pqrsd_sla.proximo_vencer_fraccion', 0.25);
        $minimos = (int) (config('pqrsd_sla.proximo_vencer_minutos_minimos.'.$nivelRiesgo)
            ?? config('pqrsd_sla.proximo_vencer_minutos_minimos.ordinario', 1440));

        $umbralPorFraccion = (int) floor($totalSegundos * $fraccion);
        $umbralPorMinutos = $minimos * 60;
        $umbral = max($umbralPorFraccion, $umbralPorMinutos);

        // Para plazos cortos (vital 8h), no superar el 50% del plazo
        $umbral = min($umbral, (int) floor($totalSegundos * 0.5));

        return $restantes <= $umbral;
    }

    /**
     * @return array{
     *   fecha_inicio:?string,
     *   fecha_limite:?string,
     *   transcurrido_segundos:int,
     *   restante_segundos:int,
     *   total_segundos:int,
     *   estado:string,
     *   semaforo:string
     * }
     */
    public function snapshotPlazo(
        ?CarbonInterface $inicio,
        ?CarbonInterface $limite,
        ?string $nivelRiesgo,
        ?CarbonInterface $ahora = null,
        bool $respondido = false,
        ?CarbonInterface $fechaRespuesta = null
    ): array {
        $ahora = Carbon::parse($ahora ?? now('America/Bogota'))->timezone('America/Bogota');
        $estado = $this->calcularEstadoCumplimiento(
            $inicio,
            $limite,
            $nivelRiesgo,
            $ahora,
            $respondido,
            $fechaRespuesta
        );

        if (! $inicio || ! $limite) {
            return [
                'fecha_inicio' => null,
                'fecha_limite' => null,
                'transcurrido_segundos' => 0,
                'restante_segundos' => 0,
                'total_segundos' => 0,
                'estado' => $estado,
                'semaforo' => 'gris',
            ];
        }

        $inicio = Carbon::parse($inicio)->timezone('America/Bogota');
        $limite = Carbon::parse($limite)->timezone('America/Bogota');
        $total = max(0, $inicio->diffInSeconds($limite, false));
        if ($total < 0) {
            $total = abs($inicio->diffInSeconds($limite));
        }
        $transcurrido = $ahora->lessThan($inicio) ? 0 : $inicio->diffInSeconds($ahora);
        $restante = $ahora->lessThanOrEqualTo($limite)
            ? $ahora->diffInSeconds($limite)
            : 0;

        return [
            'fecha_inicio' => $inicio->toDateTimeString(),
            'fecha_limite' => $limite->toDateTimeString(),
            'transcurrido_segundos' => $transcurrido,
            'restante_segundos' => $restante,
            'total_segundos' => $total,
            'estado' => $estado,
            'semaforo' => $this->semaforoDeEstado($estado),
        ];
    }

    public function semaforoDeEstado(string $estado): string
    {
        return match ($estado) {
            'dentro_plazo' => 'verde',
            'proximo_vencer' => 'amarillo',
            'vencido', 'resuelto_extemporaneo' => 'rojo',
            'resuelto_oportuno' => 'verde',
            default => 'gris',
        };
    }

    public function formatearDuracion(int $segundos): string
    {
        $segundos = abs($segundos);
        $dias = intdiv($segundos, 86400);
        $horas = intdiv($segundos % 86400, 3600);
        $minutos = intdiv($segundos % 3600, 60);

        $partes = [];
        if ($dias > 0) {
            $partes[] = $dias.'d';
        }
        if ($horas > 0 || $dias > 0) {
            $partes[] = $horas.'h';
        }
        $partes[] = $minutos.'m';

        return implode(' ', $partes);
    }

    /**
     * Filtra por el cumplimiento calculado con fechas, no por el estado guardado.
     */
    public function restringirConsulta(Builder $query, string $estado): void
    {
        $ahora = now('America/Bogota');

        if ($estado === 'sin_clasificar') {
            $query->where(function (Builder $q) {
                $q->whereNull('fecha_limite')->orWhereNull('fecha_inicio_conteo');
            });

            return;
        }

        if ($estado === 'resuelto_oportuno') {
            $query->whereNotNull('fecha_respuesta')
                ->whereNotNull('fecha_limite')
                ->whereColumn('fecha_respuesta', '<=', 'fecha_limite');

            return;
        }

        if ($estado === 'resuelto_extemporaneo') {
            $query->whereNotNull('fecha_respuesta')
                ->whereNotNull('fecha_limite')
                ->whereColumn('fecha_respuesta', '>', 'fecha_limite');

            return;
        }

        if ($estado === 'vencido') {
            $query->whereNull('fecha_respuesta')
                ->whereNotNull('fecha_limite')
                ->where('fecha_limite', '<', $ahora);

            return;
        }

        if (in_array($estado, ['proximo_vencer', 'dentro_plazo'], true)) {
            $ids = (clone $query)
                ->whereNull('fecha_respuesta')
                ->whereNotNull('fecha_limite')
                ->where('fecha_limite', '>=', $ahora)
                ->pluck('id');

            $coincidentes = Pqrsd::query()
                ->whereIn('id', $ids->all() ?: [0])
                ->get()
                ->filter(fn (Pqrsd $caso) => $caso->cumplimientoVigente($ahora) === $estado)
                ->pluck('id');

            $query->whereIn('id', $coincidentes->all() ?: [0]);

            return;
        }

        $query->whereRaw('1 = 0');
    }

    /**
     * @return Collection<int, string>
     */
    private function festivosDesde(CarbonInterface $desde): Collection
    {
        return Festivo::query()
            ->where('fecha', '>=', Carbon::parse($desde)->toDateString())
            ->pluck('fecha')
            ->map(fn ($f) => Carbon::parse($f)->toDateString());
    }

    private function esDiaNoHabil(CarbonInterface $fecha, Collection $festivos): bool
    {
        if ($fecha->isWeekend()) {
            return true;
        }

        return $festivos->contains($fecha->toDateString());
    }
}
