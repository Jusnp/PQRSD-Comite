<?php

namespace App\Http\Controllers\Catalogo;

use App\Http\Controllers\Controller;
use App\Models\ReglaSla;
use App\Models\VersionReglaSla;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class ReglaSlaController extends Controller
{
    public function index(): View
    {
        $items = ReglaSla::orderBy('prioridad')->paginate(15);

        return view('catalogos.reglas.index', compact('items'));
    }

    public function create(): View
    {
        return view('catalogos.reglas.form', [
            'item' => new ReglaSla([
                'activo' => true,
                'unidad' => 'horas_calendario',
                'prioridad' => 100,
                'version' => 1,
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $data['creado_por'] = Auth::id();
        $data['version'] = 1;
        $data['vigente_desde'] = now('America/Bogota');

        $regla = ReglaSla::create($data);

        VersionReglaSla::create([
            'regla_sla_id' => $regla->id,
            'version' => 1,
            'snapshot' => $regla->toSnapshot(),
            'modificado_por' => Auth::id(),
            'motivo' => 'Creación inicial',
        ]);

        return redirect()->route('reglas.index')->with('success', 'Regla SLA creada correctamente.');
    }

    public function edit(ReglaSla $regla): View
    {
        return view('catalogos.reglas.form', ['item' => $regla]);
    }

    public function update(Request $request, ReglaSla $regla): RedirectResponse
    {
        $data = $this->validated($request, $regla->id);
        $regla->fill($data);
        $regla->version = $regla->version + 1;
        $regla->save();

        VersionReglaSla::create([
            'regla_sla_id' => $regla->id,
            'version' => $regla->version,
            'snapshot' => $regla->toSnapshot(),
            'modificado_por' => Auth::id(),
            'motivo' => $request->input('motivo_version', 'Actualización de regla'),
        ]);

        return redirect()->route('reglas.index')->with('success', 'Regla SLA actualizada (nueva versión).');
    }

    private function validated(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'codigo' => ['required', 'string', 'max:50', 'unique:reglas_sla,codigo,'.($id ?? 'NULL')],
            'nombre' => ['required', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string'],
            'nivel_riesgo' => ['required', 'in:vital_menor,vital_general,priorizado,simple,ordinario'],
            'cantidad' => ['required', 'integer', 'min:1'],
            'unidad' => ['required', 'in:horas_calendario,dias_calendario,dias_habiles'],
            'prioridad' => ['required', 'integer', 'min:1'],
            'activo' => ['sometimes', 'boolean'],
        ]) + ['activo' => $request->boolean('activo')];
    }
}
