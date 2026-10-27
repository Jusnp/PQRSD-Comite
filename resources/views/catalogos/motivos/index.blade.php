@extends('layouts.pqrsd')
@section('title', 'Motivos')
@section('heading', 'Motivos PQRSD')
@section('content')
@can('motivos.manage')
<div style="margin-bottom:14px"><a class="btn btn-green" href="{{ route('motivos.create') }}">Nuevo motivo</a></div>
@endcan
<div class="card card-pad table-wrap">
<table class="pqrsd-table">
<thead><tr><th>Tipo</th><th>Código</th><th>Nombre</th><th>Estado</th><th></th></tr></thead>
<tbody>
@foreach($items as $item)
<tr>
<td>{{ $item->tipo?->nombre }}</td><td>{{ $item->codigo }}</td><td>{{ $item->nombre }}</td>
<td><span class="badge {{ $item->activo ? 'badge-green' : 'badge-muted' }}">{{ $item->activo ? 'Activo' : 'Inactivo' }}</span></td>
<td><a href="{{ route('motivos.edit', $item) }}">Editar</a></td>
</tr>
@endforeach
</tbody>
</table>
{{ $items->links() }}
</div>
@endsection
