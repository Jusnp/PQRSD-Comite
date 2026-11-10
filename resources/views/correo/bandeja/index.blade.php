@extends('layouts.pqrsd')
@section('title', 'Bandeja de correo')
@section('heading', 'Correos pendientes de radicación')
@section('subheading', 'Revisión humana antes de crear un expediente')
@section('content')
<div class="pqrsd-nav" style="margin-top:0">
    @foreach(['pendientes'=>'Pendientes','respuestas'=>'Respuestas','ruido'=>'Ruido/descartes','todos'=>'Todos'] as $key=>$label)
        <a href="{{ route('bandeja-correo.index', ['filtro'=>$key]) }}" class="{{ $filtro===$key ? 'is-active' : '' }}">{{ $label }}</a>
    @endforeach
</div>
<div class="card card-pad table-wrap">
<table class="pqrsd-table">
<thead>
<tr><th>Fecha</th><th>Cuenta</th><th>Remitente</th><th>Asunto</th><th>Clasificación</th><th>Radicado</th></tr>
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
        <a href="{{ route('pqrsd.show', $item->pqrsd) }}">{{ $item->pqrsd->radicado }}</a>
    @else
        {{ $item->radicado_detectado ?: '—' }}
    @endif
</td>
</tr>
@empty
<tr><td colspan="6">No hay correos pendientes de radicación.</td></tr>
@endforelse
</tbody>
</table>
{{ $items->links() }}
</div>
@endsection
