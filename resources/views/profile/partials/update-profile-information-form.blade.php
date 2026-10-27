<h2 class="exp-section-title" style="margin-top:0">Datos del funcionario</h2>
<p class="muted">El nombre y el correo se usan dentro de PQRSD. Si la cuenta está vinculada, la identidad de acceso sigue siendo la de Authentik.</p>

<form method="post" action="{{ route('profile.update') }}">
    @csrf
    @method('patch')

    <div class="form-grid">
        <div>
            <label class="form-label" for="name">Nombre completo</label>
            <input class="form-input" id="name" name="name" type="text" value="{{ old('name', $user->name) }}" required autocomplete="name">
        </div>
        <div>
            <label class="form-label" for="email">Correo electrónico</label>
            <input class="form-input" id="email" name="email" type="email" value="{{ old('email', $user->email) }}" required autocomplete="email">
            <p class="muted">Cambiar el correo en PQRSD no lo marca como verificado ni modifica la cuenta de Authentik.</p>
        </div>
    </div>

    <div style="margin-top:18px">
        <button class="btn btn-green" type="submit">Guardar datos</button>
    </div>
</form>
