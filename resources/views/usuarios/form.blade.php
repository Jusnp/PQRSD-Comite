@extends('layouts.pqrsd')
@section('title', ($modo ?? 'create') === 'edit' ? 'Editar funcionario' : 'Nuevo funcionario')
@section('heading', ($modo ?? 'create') === 'edit' ? 'Editar funcionario' : 'Nuevo funcionario')
@section('subheading', 'Sin contraseña local: la activación se completa en Authentik')
@section('retorno')
    <a href="{{ route('usuarios.index') }}">← Volver a usuarios</a>
    @if($item->exists)
        · <a href="{{ route('usuarios.show', $item) }}">Volver a la ficha</a>
    @endif
@endsection
@section('content')
<form method="POST" action="{{ $item->exists ? route('usuarios.update', $item) : route('usuarios.store') }}" class="card card-pad">
    @csrf
    @if($item->exists) @method('PUT') @endif
    <div class="form-grid">
        <div>
            <label class="form-label" for="name">Nombre completo <span class="required">*</span></label>
            <input class="form-input" id="name" name="name" value="{{ old('name', $item->name) }}" required autocomplete="name">
        </div>
        <div>
            <label class="form-label" for="email">Correo electrónico <span class="required">*</span></label>
            <input class="form-input" id="email" type="email" name="email" value="{{ old('email', $item->email) }}" required autocomplete="email">
            <p class="muted">Cualquier correo válido. La invitación se entrega en privado y el correo queda sin verificar.</p>
        </div>
        <div>
            <label class="form-label" for="role">Rol PQRSD <span class="required">*</span></label>
            <select class="form-select" id="role" name="role" required>
                <option value="">Seleccione</option>
                @foreach($roles as $role)
                    @php
                        $etiqueta = $role->name === 'admin' ? 'Administrador PQRSD' : ($role->name === 'auxiliar_pqrsd' ? 'Auxiliar PQRSD' : $role->name);
                    @endphp
                    <option value="{{ $role->name }}" @selected(old('role', $item->roles->first()?->name) === $role->name)>{{ $etiqueta }}</option>
                @endforeach
            </select>
        </div>
        @if(($modo ?? 'create') === 'edit')
            <div>
                <label class="form-label" for="telefono">Teléfono (opcional)</label>
                <input class="form-input" id="telefono" name="telefono" value="{{ old('telefono', $item->telefono) }}">
            </div>
            <div class="full">
                <p class="muted" style="margin:0">
                    Estado Laravel: <strong>{{ $item->activo ? 'Activo' : 'Inactivo' }}</strong>.
                    Authentik: <strong>{{ $item->etiquetaEstadoAuthentik() }}</strong>.
                    Use las acciones de la ficha para desactivar, reactivar o reenviar invitación.
                </p>
            </div>
        @else
            <div class="full">
                <p class="muted" style="margin:0">
                    La cuenta quedará <strong>pendiente</strong> hasta que el funcionario complete la inscripción en Authentik.
                    No se solicita ni envía contraseña desde PQRSD.
                </p>
            </div>
        @endif
    </div>
    <div style="margin-top:18px;display:flex;gap:10px;flex-wrap:wrap">
        <button class="btn btn-green" type="submit">{{ ($modo ?? 'create') === 'edit' ? 'Guardar cambios' : 'Registrar funcionario' }}</button>
        <a class="btn btn-secondary" href="{{ $item->exists ? route('usuarios.show', $item) : route('usuarios.index') }}">Cancelar</a>
    </div>
</form>
@endsection
