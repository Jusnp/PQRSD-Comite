@extends('layouts.pqrsd')
@section('title', 'Sedes')
@section('heading', 'Sedes')
@section('subheading', 'Catálogo administrable de sedes')
@section('content')
@can('sedes.manage')
<div style="margin-bottom:14px"><a class="btn btn-green" href="{{ route('sedes.create') }}">Nueva sede</a></div>
@endcan
<div class="card card-pad table-wrap">
<table class="pqrsd-table">
<thead><tr><th>Código</th><th>Nombre</th><th>Ciudad</th><th>Estado</th><th></th></tr></thead>
<tbody>
@foreach($items as $item)
<tr>
<td>{{ $item->codigo }}</td><td>{{ $item->nombre }}</td><td>{{ $item->ciudad }}</td>
<td><span class="badge {{ $item->activo ? 'badge-green' : 'badge-muted' }}">{{ $item->activo ? 'Activa' : 'Inactiva' }}</span></td>
<td><a href="{{ route('sedes.edit', $item) }}">Editar</a></td>
</tr>
@endforeach
</tbody>
</table>
{{ $items->links() }}
</div>
@endsection
