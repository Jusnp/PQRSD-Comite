@extends('layouts.pqrsd')
@section('title', 'Aseguradoras')
@section('heading', 'EPS y aseguradoras')
@section('content')
@can('aseguradoras.manage')
<div style="margin-bottom:14px"><a class="btn btn-green" href="{{ route('aseguradoras.create') }}">Nueva aseguradora</a></div>
@endcan
<div class="card card-pad table-wrap">
<table class="pqrsd-table">
<thead><tr><th>Código</th><th>Nombre</th><th>Tipo</th><th>Estado</th><th></th></tr></thead>
<tbody>
@foreach($items as $item)
<tr>
<td>{{ $item->codigo }}</td><td>{{ $item->nombre }}</td><td>{{ strtoupper($item->tipo) }}</td>
<td><span class="badge {{ $item->activo ? 'badge-green' : 'badge-muted' }}">{{ $item->activo ? 'Activa' : 'Inactiva' }}</span></td>
<td><a href="{{ route('aseguradoras.edit', $item) }}">Editar</a></td>
</tr>
@endforeach
</tbody>
</table>
{{ $items->links() }}
</div>
@endsection
