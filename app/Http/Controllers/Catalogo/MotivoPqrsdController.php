<?php

namespace App\Http\Controllers\Catalogo;

use App\Http\Controllers\Controller;
use App\Models\MotivoPqrsd;
use App\Models\TipoPqrsd;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MotivoPqrsdController extends Controller
{
    public function index(): View
    {
        $items = MotivoPqrsd::with('tipo')->orderBy('nombre')->paginate(15);

        return view('catalogos.motivos.index', compact('items'));
    }

    public function create(): View
    {
        return view('catalogos.motivos.form', [
            'item' => new MotivoPqrsd(['activo' => true]),
            'tipos' => TipoPqrsd::where('activo', true)->orderBy('orden')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        MotivoPqrsd::create($this->validated($request));

        return redirect()->route('motivos.index')->with('success', 'Motivo creado correctamente.');
    }

    public function edit(MotivoPqrsd $motivo): View
    {
        return view('catalogos.motivos.form', [
            'item' => $motivo,
            'tipos' => TipoPqrsd::where('activo', true)->orderBy('orden')->get(),
        ]);
    }

    public function update(Request $request, MotivoPqrsd $motivo): RedirectResponse
    {
        $motivo->update($this->validated($request, $motivo->id));

        return redirect()->route('motivos.index')->with('success', 'Motivo actualizado correctamente.');
    }

    private function validated(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'tipo_pqrsd_id' => ['required', 'exists:tipos_pqrsd,id'],
            'codigo' => ['required', 'string', 'max:40'],
            'nombre' => ['required', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string'],
            'activo' => ['sometimes', 'boolean'],
        ]) + ['activo' => $request->boolean('activo')];
    }
}
