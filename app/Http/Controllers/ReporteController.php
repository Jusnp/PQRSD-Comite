<?php

namespace App\Http\Controllers;

use App\Exports\PqrsdReporteExport;
use App\Models\Aseguradora;
use App\Models\Sede;
use App\Models\TipoPqrsd;
use App\Models\User;
use App\Services\ReporteService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class ReporteController extends Controller
{
    public function __construct(private readonly ReporteService $reportes) {}

    public function index(Request $request): View
    {
        $query = $this->reportes->filtrar($request);
        $indicadores = $this->reportes->indicadores(clone $query);
        $items = (clone $query)->paginate(25)->withQueryString();

        return view('reportes.index', [
            'items' => $items,
            'indicadores' => $indicadores,
            'sedes' => Sede::orderBy('nombre')->get(),
            'aseguradoras' => Aseguradora::orderBy('nombre')->get(),
            'tipos' => TipoPqrsd::orderBy('orden')->get(),
            'responsables' => User::where('activo', true)->orderBy('name')->get(),
            'filtros' => $request->all(),
        ]);
    }

    public function excel(Request $request): BinaryFileResponse|Response
    {
        $casos = $this->reportes->filtrar($request)->limit(5000)->get();
        $rows = $this->reportes->filasExportacion($casos);

        return (new PqrsdReporteExport($rows))
            ->download('reporte-pqrsd-'.now('America/Bogota')->format('Ymd-His').'.xlsx');
    }

    public function pdf(Request $request): Response
    {
        $casos = $this->reportes->filtrar($request)->limit(500)->get();
        $indicadores = $this->reportes->indicadores($this->reportes->filtrar($request));
        $rows = $this->reportes->filasExportacion($casos);

        $pdf = Pdf::loadView('reportes.pdf', [
            'rows' => $rows,
            'indicadores' => $indicadores,
            'generado' => now('America/Bogota'),
            'filtros' => $request->all(),
        ])->setPaper('a4', 'landscape');

        return $pdf->download('reporte-pqrsd-'.now('America/Bogota')->format('Ymd-His').'.pdf');
    }
}
