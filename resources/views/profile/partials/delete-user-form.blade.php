@if($user->vinculadoConAuthentik())
    <h2 class="exp-section-title" style="margin-top:0">Acceso del funcionario</h2>
    <p>Esta cuenta entra con Authentik. PQRSD no pide ni cambia la contraseña de ese ingreso.</p>
    <p style="margin-bottom:0">La desactivación y la reactivación las hace un administrador desde Usuarios. Esta pantalla no elimina la identidad de Authentik ni cierra el acceso del funcionario en otras aplicaciones.</p>
@else
    <h2 class="exp-section-title" style="margin-top:0">Eliminar cuenta local</h2>
    <p>Esta acción solo borra una cuenta local de PQRSD que no está vinculada a Authentik. No llama al directorio institucional.</p>
    @if($errors->userDeletion->isNotEmpty())
        <div class="alert alert-error" role="alert">
            <ul>
                @foreach($errors->userDeletion->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif
    <form method="post" action="{{ route('profile.destroy') }}" data-pqrsd-confirmar data-variante="peligro" data-titulo="¿Eliminar la cuenta local?" data-mensaje="Se borrará esta cuenta local de PQRSD. No se modificará ninguna identidad de Authentik." data-aceptar="Eliminar cuenta local">
        @csrf
        @method('delete')
        <label class="form-label" for="password">Contraseña local actual</label>
        <input class="form-input" id="password" name="password" type="password" autocomplete="current-password" required>
        <div style="margin-top:18px">
            <button class="btn btn-red" type="submit">Eliminar cuenta local</button>
        </div>
    </form>
@endif
