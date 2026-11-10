@extends('layouts.pqrsd')
@section('title', 'Bandeja de correo')
@section('heading')
@if($filtro === 'radicados')
Correos radicados
@elseif($filtro === 'todos')
Todos los correos
@else
Correos pendientes de radicación
@endif
@endsection
@section('subheading', 'Consulta y seguimiento sin crear expedientes duplicados')
@section('content')
<div class="pqrsd-nav" style="margin-top:0">
    @foreach(['pendientes'=>'Pendientes','radicados'=>'Radicados','todos'=>'Todos'] as $key=>$label)
        <a href="{{ route('bandeja-correo.index', ['filtro'=>$key]) }}" class="{{ $filtro===$key ? 'is-active' : '' }}">{{ $label }}</a>
    @endforeach
</div>
<div class="card card-pad table-wrap">
<table class="pqrsd-table">
<thead>
<tr><th>Fecha</th><th>Buzón</th><th>Remitente</th><th>Asunto</th><th>Estado</th><th>Radicado</th><th>Acción</th></tr>
</thead>
<tbody>
@forelse($items as $item)
<tr>
<td>{{ $item->fecha_mensaje?->timezone('America/Bogota')->format('d/m/Y H:i') }}</td>
<td>{{ $item->cuenta?->direccion }}@if($item->destinatario_original)<div class="muted">Orig: {{ $item->destinatario_original }}</div>@endif</td>
<td>{{ $item->remitente }}</td>
<td><a href="{{ route('bandeja-correo.show', $item) }}">{{ $item->asunto ?: '(sin asunto)' }}</a></td>
<td><span class="badge badge-muted">{{ $item->etiquetaRevision() }}</span></td>
<td>
    @if($item->pqrsd)
        @can('view', $item->pqrsd)
            <a href="{{ route('pqrsd.show', $item->pqrsd) }}">{{ $item->pqrsd->radicado }}</a>
        @else
            {{ $item->pqrsd->radicado }}
        @endcan
    @else
        {{ $item->radicado_detectado ?: '—' }}
    @endif
</td>
<td><a href="{{ route('bandeja-correo.show', $item) }}">Ver conversación y responder</a></td>
</tr>
@empty
<tr><td colspan="7">
    @if($filtro === 'radicados')
        No hay correos radicados.
    @elseif($filtro === 'todos')
        No hay correos.
    @else
        No hay correos pendientes de radicación.
    @endif
</td></tr>
@endforelse
</tbody>
</table>
{{ $items->links() }}
</div>
@endsection
