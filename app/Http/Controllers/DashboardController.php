<?php

namespace App\Http\Controllers;

use App\Models\Aseguradora;
use App\Models\MensajeCorreo;
use App\Models\Pqrsd;
use App\Models\Sede;
use App\Services\ReporteService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(private readonly ReporteService $reportes) {}

    public function __invoke(Request $request): View
    {
        $query = $this->reportes->filtrar($request);
        $indicadores = $this->reportes->indicadores(clone $query);
        $base = $this->scopedPqrsd();

        $stats = [
            'total' => (clone $base)->count(),
            'hoy' => (clone $base)->whereDate('fecha_radicacion', now('America/Bogota')->toDateString())->count(),
            'abiertos' => (clone $base)->whereNotIn('estado_operativo', ['cerrado'])->count(),
            'en_gestion' => (clone $base)->where('estado_operativo', 'en_gestion')->count(),
            'vencidos' => (clone $base)->where('estado_cumplimiento', 'vencido')->count(),
            'proximos' => (clone $base)->where('estado_cumplimiento', 'proximo_vencer')->count(),
            'oportunos' => (clone $base)->where('estado_cumplimiento', 'resuelto_oportuno')->count(),
            'extemporaneos' => (clone $base)->where('estado_cumplimiento', 'resuelto_extemporaneo')->count(),
            'vitales' => (clone $base)->whereIn('nivel_riesgo', ['vital_menor', 'vital_general'])->count(),
            'vitales_menores' => (clone $base)->where('nivel_riesgo', 'vital_menor')->count(),
            'pendientes_clasificacion' => (clone $base)->where('estado_operativo', 'pendiente_clasificacion')->count(),
            'correos_pendientes' => Auth::user()->can('correos.review')
                ? MensajeCorreo::pendientesRevision()->count()
                : 0,
            'tiempo_promedio_horas' => $indicadores['tiempo_promedio_horas'],
        ];

        $alertas = [
            'vencidos' => (clone $base)->with('responsable')->where('estado_cumplimiento', 'vencido')->latest('fecha_limite')->limit(5)->get(),
            'proximos' => (clone $base)->with('responsable')->where('estado_cumplimiento', 'proximo_vencer')->orderBy('fecha_limite')->limit(5)->get(),
            'vitales' => (clone $base)->whereIn('nivel_riesgo', ['vital_menor', 'vital_general'])
                ->whereNotIn('estado_operativo', ['cerrado', 'respondido'])
                ->latest('fecha_recepcion')->limit(5)->get(),
        ];

        $recientes = (clone $base)->with(['canal', 'tipo', 'sede'])
            ->latest('fecha_radicacion')
            ->limit(10)
            ->get();

        $porCanal = (clone $base)
            ->leftJoin('canales_ingreso', 'canales_ingreso.id', '=', 'pqrsd.canal_ingreso_id')
            ->select(DB::raw("COALESCE(canales_ingreso.nombre,'Sin canal') as etiqueta"), DB::raw('count(*) as total'))
            ->groupBy('etiqueta')
            ->orderByDesc('total')
            ->get();

        return view('dashboard', [
            'stats' => $stats,
            'alertas' => $alertas,
            'recientes' => $recientes,
            'porCanal' => $porCanal,
            'indicadores' => $indicadores,
            'sedes' => Sede::orderBy('nombre')->get(),
            'aseguradoras' => Aseguradora::orderBy('nombre')->get(),
            'filtros' => $request->all(),
        ]);
    }

    private function scopedPqrsd(): Builder
    {
        $query = Pqrsd::query();

        if (! Auth::user()->can('pqrsd.view_all')) {
            $uid = Auth::id();
            $query->where(function ($q) use ($uid) {
                $q->where('responsable_id', $uid)
                    ->orWhere('creado_por', $uid)
                    ->orWhere('radicado_por', $uid);
            });
        }

        return $query;
    }
}
