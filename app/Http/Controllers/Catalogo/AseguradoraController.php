<?php

namespace App\Http\Controllers\Catalogo;

use App\Http\Controllers\Controller;
use App\Models\Aseguradora;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AseguradoraController extends Controller
{
    public function index(): View
    {
        $items = Aseguradora::orderBy('nombre')->paginate(15);

        return view('catalogos.aseguradoras.index', compact('items'));
    }

    public function create(): View
    {
        return view('catalogos.aseguradoras.form', ['item' => new Aseguradora(['activo' => true, 'tipo' => 'eps'])]);
    }

    public function store(Request $request): RedirectResponse
    {
        Aseguradora::create($this->validated($request));

        return redirect()->route('aseguradoras.index')->with('success', 'Aseguradora creada correctamente.');
    }

    public function edit(Aseguradora $aseguradora): View
    {
        return view('catalogos.aseguradoras.form', ['item' => $aseguradora]);
    }

    public function update(Request $request, Aseguradora $aseguradora): RedirectResponse
    {
        $aseguradora->update($this->validated($request, $aseguradora->id));

        return redirect()->route('aseguradoras.index')->with('success', 'Aseguradora actualizada correctamente.');
    }

    private function validated(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'codigo' => ['required', 'string', 'max:30', 'unique:aseguradoras,codigo,'.($id ?? 'NULL')],
            'nombre' => ['required', 'string', 'max:255'],
            'nit' => ['nullable', 'string', 'max:20'],
            'tipo' => ['required', 'in:eps,arl,poliza,otra'],
            'activo' => ['sometimes', 'boolean'],
        ]) + ['activo' => $request->boolean('activo')];
    }
}
