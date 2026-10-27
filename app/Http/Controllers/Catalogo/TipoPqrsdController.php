<?php

namespace App\Http\Controllers\Catalogo;

use App\Http\Controllers\Controller;
use App\Models\TipoPqrsd;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TipoPqrsdController extends Controller
{
    public function index(): View
    {
        $items = TipoPqrsd::withCount('motivos')->orderBy('orden')->paginate(15);

        return view('catalogos.tipos.index', compact('items'));
    }

    public function create(): View
    {
        return view('catalogos.tipos.form', ['item' => new TipoPqrsd(['activo' => true, 'orden' => 0])]);
    }

    public function store(Request $request): RedirectResponse
    {
        TipoPqrsd::create($this->validated($request));

        return redirect()->route('tipos.index')->with('success', 'Tipo creado correctamente.');
    }

    public function edit(TipoPqrsd $tipo): View
    {
        return view('catalogos.tipos.form', ['item' => $tipo]);
    }

    public function update(Request $request, TipoPqrsd $tipo): RedirectResponse
    {
        $tipo->update($this->validated($request, $tipo->id));

        return redirect()->route('tipos.index')->with('success', 'Tipo actualizado correctamente.');
    }

    private function validated(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'codigo' => ['required', 'string', 'max:30', 'unique:tipos_pqrsd,codigo,'.($id ?? 'NULL')],
            'nombre' => ['required', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string'],
            'orden' => ['nullable', 'integer', 'min:0'],
            'activo' => ['sometimes', 'boolean'],
        ]) + ['activo' => $request->boolean('activo')];
    }
}
