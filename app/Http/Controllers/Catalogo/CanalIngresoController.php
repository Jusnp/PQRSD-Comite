<?php

namespace App\Http\Controllers\Catalogo;

use App\Http\Controllers\Controller;
use App\Models\CanalIngreso;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CanalIngresoController extends Controller
{
    public function index(): View
    {
        $items = CanalIngreso::orderBy('nombre')->paginate(15);

        return view('catalogos.canales.index', compact('items'));
    }

    public function create(): View
    {
        return view('catalogos.canales.form', ['item' => new CanalIngreso(['activo' => true])]);
    }

    public function store(Request $request): RedirectResponse
    {
        CanalIngreso::create($this->validated($request));

        return redirect()->route('canales.index')->with('success', 'Canal creado correctamente.');
    }

    public function edit(CanalIngreso $canal): View
    {
        return view('catalogos.canales.form', ['item' => $canal]);
    }

    public function update(Request $request, CanalIngreso $canal): RedirectResponse
    {
        $canal->update($this->validated($request, $canal->id));

        return redirect()->route('canales.index')->with('success', 'Canal actualizado correctamente.');
    }

    private function validated(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'codigo' => ['required', 'string', 'max:40', 'unique:canales_ingreso,codigo,'.($id ?? 'NULL')],
            'nombre' => ['required', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string'],
            'requiere_digitalizacion' => ['sometimes', 'boolean'],
            'activo' => ['sometimes', 'boolean'],
        ]) + [
            'requiere_digitalizacion' => $request->boolean('requiere_digitalizacion'),
            'activo' => $request->boolean('activo'),
        ];
    }
}
