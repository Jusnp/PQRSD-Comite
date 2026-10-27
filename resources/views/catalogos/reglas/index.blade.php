@extends('layouts.pqrsd')
@section('title', 'Reglas SLA')
@section('heading', 'Reglas de tiempos')
@section('subheading', 'Motor de plazos versionado')
@section('content')
@can('reglas_sla.manage')
<div style="margin-bottom:14px"><a class="btn btn-green" href="{{ route('reglas.create') }}">Nueva regla</a></div>
@endcan
<div class="card card-pad table-wrap">
<table class="pqrsd-table">
<thead><tr><th>Código</th><th>Nombre</th><th>Riesgo</th><th>Plazo</th><th>Versión</th><th>Estado</th><th></th></tr></thead>
<tbody>
@foreach($items as $item)
<tr>
<td>{{ $item->codigo }}</td><td>{{ $item->nombre }}</td>
<td>{{ str_replace('_',' ', $item->nivel_riesgo) }}</td>
<td>{{ $item->cantidad }} {{ str_replace('_',' ', $item->unidad) }}</td>
<td>v{{ $item->version }}</td>
<td><span class="badge {{ $item->activo ? 'badge-green' : 'badge-muted' }}">{{ $item->activo ? 'Activa' : 'Inactiva' }}</span></td>
<td><a href="{{ route('reglas.edit', $item) }}">Editar</a></td>
</tr>
@endforeach
</tbody>
</table>
{{ $items->links() }}
</div>
@endsection
