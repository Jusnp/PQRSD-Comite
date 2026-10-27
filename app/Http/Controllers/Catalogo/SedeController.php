<?php

namespace App\Http\Controllers\Catalogo;

use App\Http\Controllers\Controller;
use App\Models\Sede;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SedeController extends Controller
{
    public function index(): View
    {
        $items = Sede::orderBy('nombre')->paginate(15);

        return view('catalogos.sedes.index', compact('items'));
    }

    public function create(): View
    {
        return view('catalogos.sedes.form', ['item' => new Sede(['activo' => true])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        Sede::create($data);

        return redirect()->route('sedes.index')->with('success', 'Sede creada correctamente.');
    }

    public function edit(Sede $sede): View
    {
        return view('catalogos.sedes.form', ['item' => $sede]);
    }

    public function update(Request $request, Sede $sede): RedirectResponse
    {
        $sede->update($this->validated($request, $sede->id));

        return redirect()->route('sedes.index')->with('success', 'Sede actualizada correctamente.');
    }

    private function validated(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'codigo' => ['required', 'string', 'max:20', 'unique:sedes,codigo,'.($id ?? 'NULL')],
            'nombre' => ['required', 'string', 'max:255'],
            'ciudad' => ['nullable', 'string', 'max:255'],
            'direccion' => ['nullable', 'string', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'activo' => ['sometimes', 'boolean'],
        ]) + ['activo' => $request->boolean('activo')];
    }
}
