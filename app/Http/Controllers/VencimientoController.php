<?php

namespace App\Http\Controllers;

use App\Models\Pqrsd;
use App\Services\SlaCalculator;
use App\Services\VencimientoService;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class VencimientoController extends Controller
{
    public function __construct(
        private readonly VencimientoService $vencimientos,
        private readonly SlaCalculator $sla,
    ) {}

    public function proximos(): View
    {
        $this->authorize('viewAny', Pqrsd::class);
        $this->vencimientos->refrescarAbiertos();

        $proximos = $this->scopedPendientesQuery();
        $this->sla->restringirConsulta($proximos, 'proximo_vencer');
        $items = $this->ordenarAtencion($proximos)->paginate(20);

        $this->enriquecerTiempos($items);

        return view('vencimientos.index', [
            'items' => $items,
            'titulo' => 'Vencimientos — próximos a vencer',
            'filtro' => 'proximo_vencer',
            'mensajeVacio' => 'No hay radicados próximos a vencer según el umbral SLA actual '
                .'(último 25 % del plazo o el mínimo de minutos por nivel de riesgo, con tope del 50 % del plazo). '
                .'Los casos aún dentro de plazo normal aparecen en la Bandeja; los ya vencidos están en la pestaña Vencidos.',
        ]);
    }

    public function vencidos(): View
    {
        $this->authorize('viewAny', Pqrsd::class);
        $this->vencimientos->refrescarAbiertos();

        $vencidos = $this->scopedPendientesQuery()->whereNull('fecha_respuesta')
            ->where('fecha_limite', '<', now('America/Bogota'));
        $items = $this->ordenarAtencion($vencidos)->paginate(20);

        $this->enriquecerTiempos($items);

        return view('vencimientos.index', [
            'items' => $items,
            'titulo' => 'Vencimientos — vencidos',
            'filtro' => 'vencido',
            'mensajeVacio' => 'No hay radicados vencidos pendientes de respuesta. '
                .'Los expedientes ya respondidos o cerrados no se listan aquí.',
        ]);
    }

    public function vitales(): View
    {
        $this->authorize('viewAny', Pqrsd::class);
        $this->vencimientos->refrescarAbiertos();

        $items = $this->ordenarAtencion(
            $this->scopedPendientesQuery()
                ->whereIn('nivel_riesgo', ['vital_menor', 'vital_general'])
                ->whereNotNull('fecha_limite')
        )->paginate(20);

        $this->enriquecerTiempos($items);

        return view('vencimientos.index', [
            'items' => $items,
            'titulo' => 'Vencimientos — riesgo vital',
            'filtro' => 'vital',
            'mensajeVacio' => 'No hay radicados de riesgo vital abiertos pendientes de respuesta.',
        ]);
    }

    /**
     * Bandeja compartida: con pqrsd.view_all se ven todos los pendientes.
     * Sin view_all se limita a creador/radicador/responsable (roles legacy).
     * No se exige responsable_id para listar.
     */
    private function scopedPendientesQuery(): Builder
    {
        $query = Pqrsd::query()
            ->with(['canal', 'tipo', 'sede', 'reglaSla'])
            ->where('estado_operativo', '!=', 'cerrado')
            ->whereNotIn('estado_cumplimiento', ['resuelto_oportuno', 'resuelto_extemporaneo'])
            ->whereNotNull('fecha_limite');

        if (! Auth::user()?->can('pqrsd.view_all')) {
            $uid = Auth::id();
            $query->where(function ($q) use ($uid) {
                $q->where('responsable_id', $uid)
                    ->orWhere('creado_por', $uid)
                    ->orWhere('radicado_por', $uid);
            });
        }

        return $query;
    }

    private function ordenarAtencion(Builder $query): Builder
    {
        return $query
            ->orderByRaw("CASE nivel_riesgo
                WHEN 'vital_menor' THEN 1
                WHEN 'vital_general' THEN 2
                WHEN 'priorizado' THEN 3
                WHEN 'simple' THEN 4
                WHEN 'ordinario' THEN 5
                ELSE 6 END")
            ->orderBy('fecha_limite');
    }

    private function enriquecerTiempos(LengthAwarePaginator $items): void
    {
        $ahora = Carbon::now('America/Bogota');

        $items->getCollection()->transform(function (Pqrsd $caso) use ($ahora) {
            $limite = $caso->fecha_limite?->timezone('America/Bogota');
            if (! $limite) {
                $caso->setAttribute('tiempo_etiqueta', '—');

                return $caso;
            }

            if ($ahora->greaterThan($limite)) {
                $caso->setAttribute(
                    'tiempo_etiqueta',
                    'Vencido hace '.$this->sla->formatearDuracion($limite->diffInSeconds($ahora))
                );
            } else {
                $caso->setAttribute(
                    'tiempo_etiqueta',
                    'Restan '.$this->sla->formatearDuracion($ahora->diffInSeconds($limite))
                );
            }

            return $caso;
        });
    }
}
