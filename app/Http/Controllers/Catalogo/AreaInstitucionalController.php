<?php

namespace App\Http\Controllers\Catalogo;

use App\Http\Controllers\Controller;
use App\Models\AreaInstitucional;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AreaInstitucionalController extends Controller
{
    public function index(): View
    {
        $items = AreaInstitucional::orderBy('orden')->orderBy('nombre')->paginate(20);

        return view('catalogos.areas.index', compact('items'));
    }

    public function create(): View
    {
        return view('catalogos.areas.form', ['item' => new AreaInstitucional(['activo' => true, 'orden' => 100])]);
    }

    public function store(Request $request): RedirectResponse
    {
        AreaInstitucional::create($this->validated($request));

        return redirect()->route('areas.index')->with('success', 'Área institucional creada.');
    }

    public function edit(AreaInstitucional $area): View
    {
        return view('catalogos.areas.form', ['item' => $area]);
    }

    public function update(Request $request, AreaInstitucional $area): RedirectResponse
    {
        $area->update($this->validated($request, $area->id));

        return redirect()->route('areas.index')->with('success', 'Área institucional actualizada.');
    }

    private function validated(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'codigo' => ['required', 'string', 'max:40', 'unique:areas_institucionales,codigo,'.($id ?? 'NULL')],
            'nombre' => ['required', 'string', 'max:255'],
            'orden' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'activo' => ['sometimes', 'boolean'],
        ]) + [
            'orden' => (int) $request->input('orden', 100),
            'activo' => $request->boolean('activo'),
        ];
    }
}
