@extends('layouts.pqrsd')
@section('title', 'Usuarios')
@section('heading', 'Usuarios')
@section('subheading', 'Funcionarios PQRSD — creación desde Laravel con activación en Authentik')
@section('retorno')
    <a href="{{ route('pqrsd.index') }}">← Volver a la bandeja</a>
@endsection
@section('content')
<div style="margin-bottom:14px"><a class="btn btn-green" href="{{ route('usuarios.create') }}">Nuevo funcionario</a></div>
<div class="card card-pad table-wrap">
<table class="pqrsd-table">
<thead>
<tr>
    <th>Nombre</th>
    <th>Correo</th>
    <th>Rol</th>
    <th>Estado Laravel</th>
    <th>Inscripción Authentik</th>
    <th>Creado</th>
    <th>Último acceso</th>
    <th></th>
</tr>
</thead>
<tbody>
@forelse($items as $item)
<tr>
    <td>{{ $item->name }}</td>
    <td>{{ $item->email }}</td>
    <td>
        @php $rol = $item->roles->first()?->name; @endphp
        {{ $rol === 'admin' ? 'Administrador PQRSD' : ($rol === 'auxiliar_pqrsd' ? 'Auxiliar PQRSD' : ($rol ?: '—')) }}
    </td>
    <td><span class="badge {{ $item->activo ? 'badge-green' : 'badge-muted' }}">{{ $item->activo ? 'Activo' : 'Inactivo' }}</span></td>
    <td>{{ $item->etiquetaEstadoAuthentik() }}</td>
    <td>{{ $item->created_at?->timezone('America/Bogota')->format('d/m/Y H:i') }}</td>
    <td>{{ $item->last_login_at?->timezone('America/Bogota')->format('d/m/Y H:i') ?: '—' }}</td>
    <td style="white-space:nowrap">
        <a href="{{ route('usuarios.show', $item) }}">Consultar</a>
        · <a href="{{ route('usuarios.edit', $item) }}">Editar</a>
    </td>
</tr>
@empty
<tr><td colspan="8" class="muted">No hay funcionarios registrados.</td></tr>
@endforelse
</tbody>
</table>
{{ $items->links() }}
</div>
@endsection
