@extends('layouts.pqrsd')
@section('title', $titulo)
@section('heading', $titulo)
@section('subheading', 'Semáforo de cumplimiento — plazos desde recepción original · bandeja compartida')
@section('content')
<style>
    .badge-yellow{background:rgba(202,138,4,.15);color:#ca8a04}
    .venc-priority-vital{box-shadow:inset 3px 0 0 #ef4444}
    .venc-empty{padding:20px;color:var(--pqrsd-muted, #64748b);line-height:1.5}
</style>
<nav class="pqrsd-nav" aria-label="Filtro de vencimientos" style="margin-bottom:16px">
    <a href="{{ route('vencimientos.proximos') }}" class="{{ request()->routeIs('vencimientos.proximos') ? 'is-active' : '' }}">Próximos a vencer</a>
    <a href="{{ route('vencimientos.vencidos') }}" class="{{ request()->routeIs('vencimientos.vencidos') ? 'is-active' : '' }}">Vencidos</a>
    <a href="{{ route('vencimientos.vitales') }}" class="{{ request()->routeIs('vencimientos.vitales') ? 'is-active' : '' }}">Riesgo vital</a>
</nav>
<div class="card card-pad table-wrap">
<table class="pqrsd-table">
<thead>
<tr>
    <th>Prioridad</th>
    <th>Radicado</th>
    <th>Tipo</th>
    <th>Riesgo</th>
    <th>Recepción</th>
    <th>Fecha límite</th>
    <th>Tiempo</th>
    <th>Estado respuesta</th>
</tr>
</thead>
<tbody>
@forelse($items as $caso)
@php
    $esVital = in_array($caso->nivel_riesgo, ['vital_menor', 'vital_general'], true);
    $respuesta = $caso->respuesta_estado
        ? str_replace('_', ' ', $caso->respuesta_estado)
        : 'Sin respuesta';
@endphp
<tr class="{{ $esVital ? 'venc-priority-vital' : '' }}">
    <td>
        <x-semaforo-sla :estado="$caso->estado_cumplimiento" />
        @if($esVital)
            <span class="badge badge-red" style="margin-top:4px;display:inline-block">Prioridad vital</span>
        @endif
    </td>
    <td>
        <a href="{{ route('pqrsd.show', $caso) }}">{{ $caso->radicado }}</a>
    </td>
    <td>{{ $caso->tipo?->nombre ?? '—' }}</td>
    <td>{{ str_replace('_', ' ', (string) $caso->nivel_riesgo) }}</td>
    <td>{{ $caso->fecha_recepcion?->timezone('America/Bogota')->format('d/m/Y H:i') ?? '—' }}</td>
    <td>{{ $caso->fecha_limite?->timezone('America/Bogota')->format('d/m/Y H:i') ?? '—' }}</td>
    <td>{{ $caso->tiempo_etiqueta ?? '—' }}</td>
    <td>{{ $respuesta }}</td>
</tr>
@empty
<tr>
    <td colspan="8" class="venc-empty">{{ $mensajeVacio ?? 'No hay casos en este estado.' }}</td>
</tr>
@endforelse
</tbody>
</table>
{{ $items->links() }}
</div>
@endsection
