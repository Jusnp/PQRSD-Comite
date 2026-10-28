<?php

namespace App\Http\Controllers;

use App\Exports\PqrsdReporteExport;
use App\Models\Pqrsd;
use App\Services\ExpedienteAuditoriaService;
use App\Services\IndicadoresPqrsdService;
use App\Services\ReporteService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class ReporteController extends Controller
{
    public function __construct(
        private readonly ReporteService $reportes,
        private readonly IndicadoresPqrsdService $indicadores,
    ) {}

    public function index(Request $request): RedirectResponse
    {
        $depurado = $this->indicadores->depurarSolicitud($request);
        $redireccion = redirect()->route('reportes.indicadores', $depurado['consulta']);

        return $depurado['errores'] === []
            ? $redireccion
            : $redireccion->withErrors($depurado['errores']);
    }

    public function excel(Request $request): BinaryFileResponse|Response
    {
        $depurado = $this->indicadores->depurarSolicitud($request);
        if ($depurado['errores'] !== []) {
            return redirect()->route('reportes.indicadores', $depurado['consulta'])->withErrors($depurado['errores']);
        }
        $request->query->replace($depurado['consulta']);
        $casos = $this->reportes->filtrar($request)->limit(5000)->get();
        $rows = $this->reportes->filasExportacion($casos);

        $this->reportesAuditoria($request, 'exportacion_excel', $casos->count());

        return (new PqrsdReporteExport($rows))
            ->download('reporte-pqrsd-'.now('America/Bogota')->format('Ymd-His').'.xlsx');
    }

    public function pdf(Request $request): Response
    {
        $depurado = $this->indicadores->depurarSolicitud($request);
        if ($depurado['errores'] !== []) {
            return redirect()->route('reportes.indicadores', $depurado['consulta'])->withErrors($depurado['errores']);
        }
        $request->query->replace($depurado['consulta']);
        $casos = $this->reportes->filtrar($request)->limit(500)->get();
        $indicadores = $this->reportes->indicadores($this->reportes->filtrar($request));
        $rows = $this->reportes->filasExportacion($casos);

        $pdf = Pdf::loadView('reportes.pdf', [
            'rows' => $rows,
            'indicadores' => $indicadores,
            'generado' => now('America/Bogota'),
            'filtros' => $request->all(),
        ])->setPaper('a4', 'landscape');

        $this->reportesAuditoria($request, 'exportacion_pdf', $casos->count());

        return $pdf->download('reporte-pqrsd-'.now('America/Bogota')->format('Ymd-His').'.pdf');
    }

    public function expedientePdf(Pqrsd $pqrsd, ExpedienteAuditoriaService $auditoria): Response
    {
        $this->authorize('view', $pqrsd);
        app(\App\Services\PqrsdService::class)->auditar($pqrsd, request()->user(), 'exportacion_expediente_pdf', null, [
            'radicado' => $pqrsd->radicado,
        ]);

        return response($auditoria->pdf($pqrsd), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="informe-'.$pqrsd->radicado.'.pdf"',
        ]);
    }

    public function expedienteZip(Pqrsd $pqrsd, ExpedienteAuditoriaService $auditoria): BinaryFileResponse
    {
        $this->authorize('view', $pqrsd);
        $ruta = $auditoria->zip($pqrsd, request()->user());

        return response()->download($ruta, 'expediente-'.$pqrsd->radicado.'.zip')->deleteFileAfterSend(true);
    }

    private function reportesAuditoria(Request $request, string $accion, int $total): void
    {
        $filtros = collect($request->query())->except(['documento', 'page'])->all();
        if ($request->filled('documento')) {
            $filtros['documento'] = 'filtro-aplicado';
        }
        app(\App\Services\PqrsdService::class)->auditar(null, $request->user(), $accion, null, [
            'total' => $total,
            'filtros' => $filtros,
        ]);
    }
}
