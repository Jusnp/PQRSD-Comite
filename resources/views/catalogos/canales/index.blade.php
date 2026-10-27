@extends('layouts.pqrsd')
@section('title', 'Canales')
@section('heading', 'Canales de ingreso')
@section('content')
@can('canales.manage')
<div style="margin-bottom:14px"><a class="btn btn-green" href="{{ route('canales.create') }}">Nuevo canal</a></div>
@endcan
<div class="card card-pad table-wrap">
<table class="pqrsd-table">
<thead><tr><th>Código</th><th>Nombre</th><th>Digitalización</th><th>Estado</th><th></th></tr></thead>
<tbody>
@foreach($items as $item)
<tr>
<td>{{ $item->codigo }}</td><td>{{ $item->nombre }}</td>
<td>{{ $item->requiere_digitalizacion ? 'Sí' : 'No' }}</td>
<td><span class="badge {{ $item->activo ? 'badge-green' : 'badge-muted' }}">{{ $item->activo ? 'Activo' : 'Inactivo' }}</span></td>
<td><a href="{{ route('canales.edit', $item) }}">Editar</a></td>
</tr>
@endforeach
</tbody>
</table>
{{ $items->links() }}
</div>
@endsection
