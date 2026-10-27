@extends('layouts.pqrsd')
@section('title', 'Áreas internas')
@section('heading', 'Áreas internas')
@section('subheading', 'Catálogo para remisiones internas')
@section('content')
@can('areas.manage')
<div style="margin-bottom:14px"><a class="btn btn-green" href="{{ route('areas.create') }}">Nueva área</a></div>
@endcan
<div class="card card-pad table-wrap">
<table class="pqrsd-table">
<thead><tr><th>Código</th><th>Nombre</th><th>Orden</th><th>Estado</th><th></th></tr></thead>
<tbody>
@foreach($items as $item)
<tr>
<td>{{ $item->codigo }}</td>
<td>{{ $item->nombre }}</td>
<td>{{ $item->orden }}</td>
<td><span class="badge {{ $item->activo ? 'badge-green' : 'badge-muted' }}">{{ $item->activo ? 'Activa' : 'Inactiva' }}</span></td>
<td><a href="{{ route('areas.edit', $item) }}">Editar</a></td>
</tr>
@endforeach
</tbody>
</table>
{{ $items->links() }}
</div>
@endsection
