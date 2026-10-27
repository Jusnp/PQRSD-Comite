@extends('layouts.pqrsd')
@section('title', 'Tipos PQRSD')
@section('heading', 'Tipos de solicitud')
@section('content')
@can('tipos.manage')
<div style="margin-bottom:14px"><a class="btn btn-green" href="{{ route('tipos.create') }}">Nuevo tipo</a></div>
@endcan
<div class="card card-pad table-wrap">
<table class="pqrsd-table">
<thead><tr><th>Orden</th><th>Código</th><th>Nombre</th><th>Motivos</th><th>Estado</th><th></th></tr></thead>
<tbody>
@foreach($items as $item)
<tr>
<td>{{ $item->orden }}</td><td>{{ $item->codigo }}</td><td>{{ $item->nombre }}</td><td>{{ $item->motivos_count }}</td>
<td><span class="badge {{ $item->activo ? 'badge-green' : 'badge-muted' }}">{{ $item->activo ? 'Activo' : 'Inactivo' }}</span></td>
<td><a href="{{ route('tipos.edit', $item) }}">Editar</a></td>
</tr>
@endforeach
</tbody>
</table>
{{ $items->links() }}
</div>
@endsection
