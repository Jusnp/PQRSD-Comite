<x-guest-layout>
    <x-auth-session-status class="mb-4" :status="session('status')" />

    @if ($errors->any())
        <div class="alert alert-danger" role="alert" style="margin-bottom:16px">
            {{ $errors->first() }}
        </div>
    @endif

    <div style="text-align:center;margin-bottom:18px">
        <p style="margin:0;font-size:15px">Acceso exclusivo para funcionarios autorizados</p>
    </div>

    @if ((bool) config('authentik.enabled') || (bool) config('services.authentik.enabled'))
        <a class="btn btn-green" style="display:block;width:100%;text-align:center;box-sizing:border-box"
           href="{{ route('auth.authentik.redirect') }}">
            Iniciar sesión con Authentik
        </a>
    @else
        <p class="muted">El acceso institucional no está configurado. Contacte al administrador del sistema.</p>
    @endif
</x-guest-layout>
