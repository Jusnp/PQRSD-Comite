<?php

namespace App\Http\Controllers\Catalogo;

use App\Http\Controllers\Controller;
use App\Models\CategoriaPqrsd;
use App\Models\SubcategoriaPqrsd;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class CategoriaPqrsdController extends Controller
{
    public function index(): View
    {
        $items = CategoriaPqrsd::with('subcategorias')->orderBy('orden')->orderBy('nombre')->paginate(15);

        return view('catalogos.categorias.index', compact('items'));
    }

    public function create(): View
    {
        return view('catalogos.categorias.form', [
            'item' => new CategoriaPqrsd(['activo' => true, 'orden' => 0]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        DB::transaction(function () use ($data) {
            $categoria = CategoriaPqrsd::create($data['categoria']);
            $this->guardarSubcategorias($categoria, $data['subcategorias']);
        });

        return redirect()->route('categorias.index')->with('success', 'Categoría creada. El catálogo sigue siendo editable.');
    }

    public function edit(CategoriaPqrsd $categoria): View
    {
        $categoria->load('subcategorias');

        return view('catalogos.categorias.form', ['item' => $categoria]);
    }

    public function update(Request $request, CategoriaPqrsd $categoria): RedirectResponse
    {
        $data = $this->validated($request, $categoria->id);
        DB::transaction(function () use ($categoria, $data) {
            $categoria->update($data['categoria']);
            $this->guardarSubcategorias($categoria, $data['subcategorias']);
        });

        return redirect()->route('categorias.index')->with('success', 'Categoría actualizada.');
    }

    /**
     * @return array{categoria: array<string, mixed>, subcategorias: list<array<string, mixed>>}
     */
    private function validated(Request $request, ?int $id = null): array
    {
        $data = $request->validate([
            'codigo' => ['required', 'string', 'max:40', 'unique:categorias_pqrsd,codigo'.($id ? ','.$id : '')],
            'nombre' => ['required', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string'],
            'orden' => ['nullable', 'integer', 'min:0'],
            'subcategorias' => ['nullable', 'array'],
            'subcategorias.*.id' => ['nullable', 'integer'],
            'subcategorias.*.codigo' => ['nullable', 'string', 'max:40'],
            'subcategorias.*.nombre' => ['nullable', 'string', 'max:255'],
            'subcategorias.*.activo' => ['sometimes', 'boolean'],
        ]);

        $subs = [];
        foreach ($data['subcategorias'] ?? [] as $fila) {
            $nombre = trim((string) ($fila['nombre'] ?? ''));
            $codigo = trim((string) ($fila['codigo'] ?? ''));
            if ($nombre === '' && $codigo === '') {
                continue;
            }
            $subs[] = [
                'id' => $fila['id'] ?? null,
                'codigo' => $codigo,
                'nombre' => $nombre,
                'activo' => (bool) ($fila['activo'] ?? false),
            ];
        }

        return [
            'categoria' => [
                'codigo' => $data['codigo'],
                'nombre' => $data['nombre'],
                'descripcion' => $data['descripcion'] ?? null,
                'orden' => (int) ($data['orden'] ?? 0),
                'activo' => $request->boolean('activo'),
            ],
            'subcategorias' => $subs,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $filas
     */
    private function guardarSubcategorias(CategoriaPqrsd $categoria, array $filas): void
    {
        foreach ($filas as $fila) {
            if ($fila['codigo'] === '' || $fila['nombre'] === '') {
                continue;
            }
            $payload = [
                'codigo' => $fila['codigo'],
                'nombre' => $fila['nombre'],
                'activo' => $fila['activo'],
            ];
            if (! empty($fila['id'])) {
                SubcategoriaPqrsd::query()
                    ->where('categoria_pqrsd_id', $categoria->id)
                    ->whereKey($fila['id'])
                    ->update($payload);

                continue;
            }
            $categoria->subcategorias()->create($payload);
        }
    }
}
