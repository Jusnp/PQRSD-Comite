@extends('layouts.pqrsd')
@section('title', 'Cuentas de correo')
@section('heading', 'Cuentas de correo / inventario Workspace')
@section('subheading', 'Clasificación de buzones — credenciales solo por OAuth/env, nunca en BD')
@section('content')
@if($pendientes > 0)
    <div class="alert alert-warning" role="status">
        {{ $pendientes }} dirección(es) con clasificación o autorización pendiente.
        No conectar Gmail API a direcciones con tipo_buzon=desconocido o grupos sin buzón canónico.
    </div>
@endif
<div style="margin-bottom:14px;display:flex;gap:10px;flex-wrap:wrap;align-items:center">
    <a class="btn btn-green" href="{{ route('cuentas-correo.create') }}">Nueva cuenta</a>
    <a class="btn btn-secondary" href="{{ route('cuentas-correo.inventario.export') }}">Exportar inventario CSV</a>
    <form method="POST" action="{{ route('cuentas-correo.inventario.import') }}" enctype="multipart/form-data" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        @csrf
        <input type="file" name="inventario_csv" accept=".csv,text/csv" required>
        <button class="btn btn-secondary" type="submit">Importar clasificación</button>
    </form>
</div>
@error('inventario_csv')
    <div class="alert alert-error">{{ $message }}</div>
@enderror
<div class="card card-pad table-wrap">
<table class="pqrsd-table">
<thead>
<tr>
    <th>Dirección</th>
    <th>Tipo buzón</th>
    <th>Canónico</th>
    <th>Autorización</th>
    <th>Integración</th>
    <th>Estado sync</th>
    <th>Última validación inventario</th>
    <th></th>
</tr>
</thead>
<tbody>
@foreach($items as $item)
<tr>
    <td>
        {{ $item->direccion }}
        @if($item->es_buzon_central)<span class="badge badge-blue">Central</span>@endif
        <div class="muted">{{ $item->responsable_nombre ?: $item->area_responsable }}</div>
    </td>
    <td><code>{{ $item->tipo_buzon ?: 'desconocido' }}</code></td>
    <td>{{ $item->buzon_canonico ?: '—' }}</td>
    <td>{{ str_replace('_',' ', $item->estado_autorizacion ?: 'pendiente') }}</td>
    <td>{{ $item->tipo_integracion }}</td>
    <td>
        <span class="badge {{ $item->estado_conexion === 'ok' ? 'badge-green' : ($item->estado_conexion === 'error' ? 'badge-red' : 'badge-muted') }}">
            {{ str_replace('_',' ', $item->estado_conexion) }}
        </span>
    </td>
    <td>{{ $item->fecha_ultima_validacion_inventario?->timezone('America/Bogota')->format('d/m/Y H:i') ?? '—' }}</td>
    <td style="white-space:nowrap">
        <a href="{{ route('cuentas-correo.edit', $item) }}">Editar</a>
        @if($item->tipo_integracion === 'google_api' || $item->tipo_buzon === 'individual')
            <a href="{{ route('cuentas-correo.oauth', $item) }}" style="margin-left:8px">OAuth</a>
            <form style="display:inline" method="POST" action="{{ route('cuentas-correo.oauth.revocar', $item) }}" data-pqrsd-confirmar data-variante="peligro" data-titulo="¿Revocar la autorización OAuth?" data-mensaje="La cuenta dejará de usar en PQRSD la autorización de Google. No se modifica Authentik ni otras aplicaciones." data-aceptar="Revocar OAuth">@csrf<button class="btn btn-secondary" type="submit" style="min-height:32px;padding:4px 10px;margin-left:6px">Revocar OAuth</button></form>
        @endif
        <form style="display:inline" method="POST" action="{{ route('cuentas-correo.probar', $item) }}">@csrf<button class="btn btn-secondary" type="submit" style="min-height:32px;padding:4px 10px;margin-left:6px">Probar</button></form>
        <form style="display:inline" method="POST" action="{{ route('cuentas-correo.sincronizar', $item) }}">@csrf<button class="btn btn-green" type="submit" style="min-height:32px;padding:4px 10px;margin-left:6px">Sync</button></form>
    </td>
</tr>
@endforeach
</tbody>
</table>
{{ $items->links() }}
</div>
<p class="muted" style="margin-top:12px">
    Guía de clasificación: <code>docs/guia-admin-google-workspace.md</code>.
    No inventar tipos de buzón: diligenciar CSV tras consulta en Google Admin Console.
</p>
@endsection
