@extends('layouts.pqrsd')
@section('title', 'Categorías PQRSD')
@section('heading', 'Categorías y subcategorías')
@section('subheading', 'Catálogo editable. No es una clasificación jurídica definitiva.')
@section('content')
<div style="margin-bottom:14px"><a class="btn btn-green" href="{{ route('categorias.create') }}">Nueva categoría</a></div>
<div class="card card-pad">
<table class="table">
<thead><tr><th>Categoría</th><th>Subcategorías</th><th></th></tr></thead>
<tbody>
@forelse($items as $item)
<tr>
<td>{{ $item->nombre }} <span class="muted">{{ $item->codigo }}</span></td>
<td>{{ $item->subcategorias->pluck('nombre')->join(', ') ?: '—' }}</td>
<td><a href="{{ route('categorias.edit', $item) }}">Editar</a></td>
</tr>
@empty
<tr><td colspan="3">No hay categorías. Cree el catálogo inicial desde esta pantalla.</td></tr>
@endforelse
</tbody>
</table>
{{ $items->links() }}
</div>
@endsection
