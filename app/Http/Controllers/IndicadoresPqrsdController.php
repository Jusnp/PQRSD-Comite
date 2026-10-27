<?php

namespace App\Http\Controllers;

use App\Exports\PqrsdReporteExport;
use App\Models\Aseguradora;
use App\Models\MotivoPqrsd;
use App\Models\Sede;
use App\Models\TipoPqrsd;
use App\Services\IndicadoresPqrsdService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class IndicadoresPqrsdController extends Controller
{
    public function __construct(private readonly IndicadoresPqrsdService $indicadores) {}

    public function index(Request $request): View
    {
        $tablero = $this->indicadores->construir($request);
        $anios = range((int) now('America/Bogota')->format('Y'), (int) now('America/Bogota')->format('Y') - 5);

        $motivosQuery = MotivoPqrsd::where('activo', true)->orderBy('nombre');
        if (($tablero['filtros']['tipo_pqrsd_id'] ?? 'todos') !== 'todos') {
            $motivosQuery->where('tipo_pqrsd_id', (int) $tablero['filtros']['tipo_pqrsd_id']);
        }

        return view('reportes.indicadores', [
            'tablero' => $tablero,
            'sedes' => Sede::where('activo', true)->orderBy('nombre')->get(),
            'aseguradoras' => Aseguradora::where('activo', true)->orderBy('nombre')->get(),
            'tipos' => TipoPqrsd::where('activo', true)->orderBy('orden')->get(),
            'motivos' => $motivosQuery->get(),
            'anios' => $anios,
            'filtros' => $tablero['filtros'],
        ]);
    }

    public function excel(Request $request): BinaryFileResponse|Response
    {
        $tablero = $this->indicadores->construir($request);
        $rows = $this->indicadores->filasExportacion($tablero);

        return (new PqrsdReporteExport($rows))
            ->download('indicadores-pqrsd-'.now('America/Bogota')->format('Ymd-His').'.xlsx');
    }

    public function pdf(Request $request): Response
    {
        $tablero = $this->indicadores->construir($request);
        $rows = $this->indicadores->filasExportacion($tablero);

        $pdf = Pdf::loadView('reportes.indicadores-pdf', [
            'tablero' => $tablero,
            'rows' => $rows,
            'generado' => now('America/Bogota'),
            'filtros' => $tablero['filtros'],
        ])->setPaper('a4', 'landscape');

        return $pdf->download('indicadores-pqrsd-'.now('America/Bogota')->format('Ymd-His').'.pdf');
    }
}
