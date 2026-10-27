<?php

namespace App\Http\Controllers;

use App\Models\Pqrsd;
use App\Models\RemisionInterna;
use App\Services\RemisionInternaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RemisionInternaController extends Controller
{
    public function __construct(
        private readonly RemisionInternaService $remisiones,
    ) {}

    public function create(Pqrsd $pqrsd): View
    {
        $this->authorize('update', $pqrsd);

        abort(403, 'Las remisiones a otras áreas están deshabilitadas. Atienda y responda la PQRSD directamente desde la Bandeja compartida.');
    }

    public function store(Request $request, Pqrsd $pqrsd): RedirectResponse
    {
        $this->authorize('update', $pqrsd);

        abort(403, 'Las remisiones a otras áreas están deshabilitadas. No se pueden crear solicitudes internas nuevas.');
    }

    public function show(Pqrsd $pqrsd, RemisionInterna $remision): View
    {
        $this->authorize('view', $pqrsd);
        $this->ensureBelongs($pqrsd, $remision);

        $remision->load(['area', 'responsableSeguimiento', 'creador', 'adjuntos']);

        $alertaPlazo = $pqrsd->fecha_limite && $remision->fecha_limite_interna
            && $remision->fecha_limite_interna->gt($pqrsd->fecha_limite);

        return view('pqrsd.remisiones.show', [
            'pqrsd' => $pqrsd,
            'remision' => $remision,
            'alertaPlazo' => $alertaPlazo,
        ]);
    }

    public function remitir(Request $request, Pqrsd $pqrsd, RemisionInterna $remision): RedirectResponse
    {
        $this->authorize('update', $pqrsd);
        $this->ensureBelongs($pqrsd, $remision);

        abort(403, 'Las mutaciones de remisiones están deshabilitadas. Consulte el historial en solo lectura.');
    }

    public function respuesta(Request $request, Pqrsd $pqrsd, RemisionInterna $remision): RedirectResponse
    {
        $this->authorize('update', $pqrsd);
        $this->ensureBelongs($pqrsd, $remision);

        abort(403, 'Las mutaciones de remisiones están deshabilitadas. Consulte el historial en solo lectura.');
    }

    public function finalizar(Request $request, Pqrsd $pqrsd, RemisionInterna $remision): RedirectResponse
    {
        $this->authorize('update', $pqrsd);
        $this->ensureBelongs($pqrsd, $remision);

        abort(403, 'Las mutaciones de remisiones están deshabilitadas. Consulte el historial en solo lectura.');
    }

    private function ensureBelongs(Pqrsd $pqrsd, RemisionInterna $remision): void
    {
        if ((int) $remision->pqrsd_id !== (int) $pqrsd->id) {
            abort(404);
        }
    }
}
