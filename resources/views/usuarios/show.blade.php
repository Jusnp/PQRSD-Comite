@extends('layouts.pqrsd')
@section('title', 'Funcionario')
@section('heading', $item->name)
@section('subheading', $item->email)
@section('retorno')
    <a href="{{ route('usuarios.index') }}">← Volver a usuarios</a>
@endsection
@section('content')
<div class="card card-pad" style="margin-bottom:16px">
    <p><strong>Rol:</strong>
        @php $rol = $item->roles->first()?->name; @endphp
        {{ $rol === 'admin' ? 'Administrador PQRSD' : ($rol === 'auxiliar_pqrsd' ? 'Auxiliar PQRSD' : ($rol ?: '—')) }}
    </p>
    <p><strong>Estado Laravel:</strong>
        <span class="badge {{ $item->activo ? 'badge-green' : 'badge-muted' }}">{{ $item->activo ? 'Activo' : 'Inactivo' }}</span>
    </p>
    <p><strong>Aprovisionamiento:</strong> {{ $item->etiquetaEstadoAuthentik() }}</p>
    <p><strong>Activación:</strong>
        <span class="badge {{ $item->activo ? 'badge-green' : 'badge-muted' }}">{{ $item->activo ? 'Activo' : 'Pendiente de activación' }}</span>
    </p>
    <p><strong>Correo:</strong> {{ $item->email_verified_at ? 'Verificado' : 'No verificado' }}</p>
    <p><strong>Creado:</strong> {{ $item->created_at?->timezone('America/Bogota')->format('d/m/Y H:i') }}</p>
    <p><strong>Último acceso:</strong> {{ $item->last_login_at?->timezone('America/Bogota')->format('d/m/Y H:i') ?: '—' }}</p>
    @if(blank($item->authentik_sub) && ! $item->activo && $item->authentik_invitation_expires_at)
        <p><strong>Vence invitación:</strong> {{ $item->authentik_invitation_expires_at->timezone('America/Bogota')->format('d/m/Y H:i') }}
            @if($item->invitationExpired()) <span class="badge badge-muted">Vencida</span> @endif
        </p>
    @endif
    @if($item->authentik_status === \App\Models\User::AUTHENTIK_REVOCATION_PENDING && ! session('error'))
        <p>No se pudo confirmar el estado de la invitación. Se requiere revisión administrativa.</p>
    @endif
    @if($item->authentik_invitation_url && ! $item->invitationExpired() && blank($item->authentik_sub) && $item->authentik_status !== \App\Models\User::AUTHENTIK_REVOCATION_PENDING)
        <p class="muted">Enlace de un solo uso, visible solo en esta ficha. Entrégueselo al funcionario por un canal privado.</p>
        <button class="btn btn-green" type="button" id="copiar-invitacion">Copiar enlace de invitación</button>
        <input type="hidden" id="enlace-invitacion" value="{{ $item->authentik_invitation_url }}">
        <p id="copiar-invitacion-aviso" class="muted" hidden>Enlace copiado. No lo publique ni lo deje en un mensaje compartido.</p>
    @endif
    @if($identidadExistente && blank($item->authentik_user_pk))
        <p>Ya existe una identidad Authentik para este identificador y este correo. No se emitirá otra invitación. La vinculación exige una autorización expresa.</p>
    @endif
    @if($item->authentik_status === \App\Models\User::AUTHENTIK_REVIEW && blank($item->authentik_sub))
        <p>No se pudo comprobar de forma segura si esta persona ya existe en Authentik. Se requiere revisión administrativa antes de generar una invitación.</p>
    @endif
    @if($item->authentik_invitation_delivery === 'ausente' && blank($item->authentik_sub) && ! ($identidadExistente ?? null))
        <p>La invitación guardada ya no existe en Authentik. Esta consulta no comprobó una identidad activa, con ese identificador y ese correo, dentro de pqrsd-usuarios. Un listado vacío o un HTTP 404 no demuestra que la persona no exista. No se autorizó la vinculación ni se emitió otra invitación.</p>
    @endif
    @if($item->authentik_last_error && ! session('error') && $item->authentik_status !== \App\Models\User::AUTHENTIK_LINK_PENDING)
        <div class="alert alert-danger" role="alert"><strong>Error de incorporación:</strong> {{ $item->authentik_last_error }}</div>
    @endif

    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:16px">
        <a class="btn btn-secondary" href="{{ route('usuarios.edit', $item) }}">Editar datos</a>
        @if(blank($item->authentik_sub) && filled($item->authentik_invitation_pk) && ! $item->invitationExpired())
            <form method="POST" action="{{ route('usuarios.revocar', $item) }}" data-pqrsd-confirmar data-variante="peligro" data-titulo="¿Revocar invitación?" data-mensaje="Estás a punto de revocar la invitación privada de {{ $item->name }}. Si confirmas y Authentik completa la operación, el enlace anterior dejará de funcionar." data-aceptar="Revocar invitación">
                @csrf
                <button class="btn btn-secondary" type="submit">Revocar invitación</button>
            </form>
        @endif
        @if(blank($item->authentik_sub) && blank($item->authentik_user_pk) && ($identidadExistente ?? null))
            <form method="POST" action="{{ route('usuarios.vincular', $item) }}">@csrf
                <label class="muted" style="display:flex;gap:8px;align-items:center">
                    <input type="checkbox" name="identidad_verificada" value="1" required>
                    Verifiqué la identidad de esta persona por un medio independiente
                </label>
                <button class="btn btn-green" type="submit">Autorizar vinculación</button>
            </form>
        @endif
        @if(blank($item->authentik_sub) && blank($item->authentik_invitation_url) && ! $item->invitationExpired() && ! ($identidadExistente ?? null) && $item->authentik_status !== \App\Models\User::AUTHENTIK_LINK_PENDING && $item->authentik_status !== \App\Models\User::AUTHENTIK_REVOCATION_PENDING && $item->authentik_invitation_delivery !== 'ausente')
            <form method="POST" action="{{ route('usuarios.emitir', $item) }}">@csrf
                <button class="btn btn-green" type="submit">Generar invitación</button>
            </form>
        @endif
        @if(blank($item->authentik_sub) && $item->invitationExpired() && ! ($identidadExistente ?? null) && $item->authentik_status !== \App\Models\User::AUTHENTIK_REVOCATION_PENDING)
            <form method="POST" action="{{ route('usuarios.reinvitar', $item) }}">@csrf
                <button class="btn btn-secondary" type="submit">Generar nuevo enlace</button>
            </form>
        @endif
        @if(blank($item->authentik_sub) && filled($item->authentik_user_pk))
            <form method="POST" action="{{ route('usuarios.recuperar', $item) }}">@csrf
                <label class="muted" style="display:flex;gap:8px;align-items:center">
                    <input type="checkbox" name="identidad_verificada" value="1" required>
                    Verifiqué la identidad de esta persona por un medio independiente
                </label>
                <button class="btn btn-secondary" type="submit">Generar enlace de recuperación</button>
            </form>
        @endif
        @if($item->activo || $item->authentik_status !== 'deactivated')
            <form method="POST" action="{{ route('usuarios.desactivar', $item) }}" data-pqrsd-confirmar data-variante="peligro" data-titulo="¿Desactivar funcionario?" data-mensaje="Estás a punto de desactivar el acceso de {{ $item->name }} a PQRSD. La persona perderá sus permisos de acceso al sistema." data-aceptar="Desactivar funcionario">
                @csrf
                <button class="btn btn-secondary" type="submit">Desactivar</button>
            </form>
        @endif
        @if(! $item->activo || $item->authentik_status === 'deactivated')
            <form method="POST" action="{{ route('usuarios.reactivar', $item) }}" data-pqrsd-confirmar data-variante="confirmacion" data-titulo="¿Reactivar funcionario?" data-mensaje="Estás a punto de reactivar el acceso de {{ $item->name }} a PQRSD." data-aceptar="Reactivar funcionario">
                @csrf
                <button class="btn btn-green" type="submit">Reactivar</button>
            </form>
        @endif
        <a class="btn btn-secondary" href="{{ route('usuarios.index') }}">Volver a usuarios</a>
    </div>
</div>

<div class="card card-pad">
    <h2 class="exp-section-title" style="margin-top:0">Auditoría de aprovisionamiento</h2>
    <script>
        document.getElementById('copiar-invitacion')?.addEventListener('click', async () => {
            const enlace = document.getElementById('enlace-invitacion')?.value ?? '';
            if (!enlace) return;
            try {
                await navigator.clipboard.writeText(enlace);
                const aviso = document.getElementById('copiar-invitacion-aviso');
                if (aviso) aviso.hidden = false;
            } catch (e) {
                window.pqrsdAviso?.({
                    titulo: 'No se pudo copiar automáticamente',
                    mensaje: 'Seleccione el enlace y cópielo manualmente. No lo publique.',
                    valor: enlace,
                    variante: 'info',
                });
            }
        });
    </script>
    @forelse($item->auditoriasUsuario as $a)
        <p style="margin:0 0 8px">
            <strong>{{ $a->created_at->timezone('America/Bogota')->format('d/m/Y H:i') }}</strong>
            — {{ [
                'invitation_skipped_config' => 'Histórico: no se emitió invitación porque el aprovisionamiento estaba desactivado o incompleto',
                'invitation_deferred' => 'Histórico: el registro quedó pendiente porque el aprovisionamiento general está desactivado',
                'invitation_failed' => 'Histórico: falló un intento de invitación',
                'invitacion_ausente' => 'La invitación ya no está en Authentik; no es un error de sincronización vigente',
                'invitation_created' => 'Invitación privada creada',
                'estado_reclasificado' => 'El error anterior dejó de ser el estado actual: la identidad ya existe y falta autorización',
                'vinculacion_autorizada_por_administrador' => 'Un administrador autorizó la vinculación de la identidad existente',
                'incorporacion_revision' => 'La consulta no bastó para crear una invitación',
                'invitation_rejected_existing_identity' => 'No se creó otra invitación porque ya hay una identidad',
                'invitation_revoked' => 'Invitación revocada en Authentik',
                'invitation_revoke_failed' => 'Authentik rechazó la revocación; la invitación no se marcó como revocada',
                'invitation_revoke_unconfirmed' => 'No se pudo confirmar si Authentik revocó la invitación',
            ][$a->accion] ?? $a->accion }}
            @if($a->actor)<span class="muted">por {{ $a->actor->name }}</span>@endif
        </p>
    @empty
        <p class="muted">Sin eventos registrados.</p>
    @endforelse
</div>
@endsection
