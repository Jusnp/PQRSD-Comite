<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Authentik\AuthentikProvisionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

class UsuarioController extends Controller
{
    /** Roles operativos asignables desde la administración. */
    private const ROLES_OPERATIVOS = ['admin', 'auxiliar_pqrsd'];

    public function __construct(
        private readonly AuthentikProvisionService $provision,
    ) {}

    public function index(): View
    {
        $items = User::query()
            ->with('roles')
            ->orderBy('name')
            ->paginate(20);

        return view('usuarios.index', compact('items'));
    }

    public function create(): View
    {
        return view('usuarios.form', [
            'item' => new User(['activo' => false]),
            'roles' => $this->rolesOperativos(),
            'modo' => 'create',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', Rule::in(self::ROLES_OPERATIVOS)],
        ], [
            'email.unique' => 'Ya existe un funcionario con ese correo.',
            'role.required' => 'Seleccione el rol del funcionario.',
        ]);

        $role = $data['role'];

        $user = User::create([
            'name' => $data['name'],
            'email' => strtolower(trim($data['email'])),
            'password' => Hash::make(Str::password(48)),
            'activo' => false,
            'documento' => null,
            'authentik_status' => User::AUTHENTIK_PENDING,
            'theme_preference' => 'light',
        ]);
        $user->syncRoles([$role]);

        $resultado = $this->provision->invite($user->fresh('roles'), Auth::user());
        $mensaje = $resultado['status'] === User::AUTHENTIK_PENDING
            ? $resultado['message']
            : 'Funcionario registrado. '.$resultado['message'];

        $alerta = in_array($resultado['status'], [User::AUTHENTIK_FAILED, User::AUTHENTIK_REVIEW], true) ? 'error' : 'success';

        return redirect()
            ->route('usuarios.show', $user)
            ->with($alerta, $mensaje);
    }

    public function show(User $usuario): View
    {
        if ($this->provision->invitacionRemotaAusente($usuario)) {
            $usuario->update([
                'authentik_invitation_url' => null,
                'authentik_invitation_pk' => null,
                'authentik_last_error' => null,
                'authentik_invitation_delivery' => 'ausente',
            ]);
            $yaRegistrado = $usuario->auditoriasUsuario()->where('accion', 'invitacion_ausente')->exists();
            if (! $yaRegistrado) {
                $this->provision->registrar($usuario, Auth::user(), 'invitacion_ausente', [
                    'motivo' => 'authentik_respondio_404',
                ]);
            }
        }

        if ($usuario->invitationExpired() && (filled($usuario->authentik_invitation_url) || filled($usuario->authentik_invitation_pk))) {
            $usuario->update([
                'authentik_invitation_url' => null,
                'authentik_invitation_pk' => null,
            ]);
        }

        $identidad = null;
        if (blank($usuario->authentik_sub) && blank($usuario->authentik_user_pk)) {
            $identidad = $this->provision->consultarIdentidadCoincidente($usuario);
            if ($identidad !== null && $usuario->authentik_status === User::AUTHENTIK_FAILED) {
                $usuario->update([
                    'authentik_status' => User::AUTHENTIK_LINK_PENDING,
                    'authentik_last_error' => null,
                ]);
                $this->provision->registrar($usuario, Auth::user(), 'estado_reclasificado', [
                    'motivo' => 'identidad_existente_sin_autorizacion',
                ]);
            }
        }

        $usuario->load([
            'roles',
            'auditoriasUsuario' => fn ($q) => $q->with('actor')->latest()->limit(30),
        ]);

        return view('usuarios.show', [
            'item' => $usuario,
            'identidadExistente' => $identidad,
        ]);
    }

    public function edit(User $usuario): View
    {
        return view('usuarios.form', [
            'item' => $usuario->load('roles'),
            'roles' => $this->rolesOperativos(),
            'modo' => 'edit',
        ]);
    }

    public function update(Request $request, User $usuario): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($usuario->id)],
            'role' => ['required', Rule::in(self::ROLES_OPERATIVOS)],
            'telefono' => ['nullable', 'string', 'max:30'],
        ]);

        $role = $data['role'];
        unset($data['role']);

        if ((int) $usuario->id === (int) Auth::id() && $role !== 'admin') {
            return back()->withInput()->withErrors([
                'role' => 'No puede quitarse el rol de administrador a su propia cuenta.',
            ]);
        }

        $usuario->update([
            'name' => $data['name'],
            'email' => strtolower(trim($data['email'])),
            'telefono' => $data['telefono'] ?? $usuario->telefono,
        ]);
        $usuario->syncRoles([$role]);
        $this->provision->registrar($usuario, Auth::user(), 'role_updated', [
            'role' => $role,
        ]);

        return redirect()->route('usuarios.show', $usuario)->with('success', 'Funcionario actualizado.');
    }

    public function revocar(User $usuario): RedirectResponse
    {
        if ((int) $usuario->id === (int) Auth::id()) {
            return back()->withErrors(['email' => 'No puede revocar la invitación de su propia cuenta.']);
        }

        $resultado = $this->provision->revocarInvitacion($usuario, Auth::user());
        $clave = $resultado['status'] === User::AUTHENTIK_REVOKED ? 'success' : 'error';

        return back()->with($clave, $resultado['message']);
    }

    public function recuperar(Request $request, User $usuario): RedirectResponse
    {
        if ((int) $usuario->id === (int) Auth::id()) {
            return back()->withErrors(['email' => 'No puede recuperar su propia cuenta desde esta ficha.']);
        }

        $request->validate([
            'identidad_verificada' => ['accepted'],
        ]);

        $resultado = $this->provision->recuperarIdentidadExistente(
            $usuario,
            Auth::user(),
            $request->boolean('identidad_verificada'),
        );

        if (($resultado['ok'] ?? false) !== true) {
            return back()->with('error', $resultado['message']);
        }

        return back()->with('success', $resultado['message']);
    }

    public function vincular(Request $request, User $usuario): RedirectResponse
    {
        if ((int) $usuario->id === (int) Auth::id()) {
            return back()->with('error', 'No puede autorizar la vinculación de su propia cuenta.');
        }

        $request->validate([
            'identidad_verificada' => ['accepted'],
        ]);

        $resultado = $this->provision->autorizarVinculacionExistente(
            $usuario,
            Auth::user(),
            $request->boolean('identidad_verificada'),
        );

        return back()->with(($resultado['ok'] ?? false) ? 'success' : 'error', $resultado['message']);
    }

    public function emitir(User $usuario): RedirectResponse
    {
        if ((int) $usuario->id === (int) Auth::id()) {
            return back()->with('error', 'No puede generar la invitación de su propia cuenta.');
        }
        if (filled($usuario->authentik_sub)) {
            return back()->with('error', 'Este funcionario ya está vinculado. No se emite otra invitación.');
        }

        $resultado = $this->provision->resendInvitation($usuario->fresh('roles'), Auth::user());
        $clave = in_array($resultado['status'], [User::AUTHENTIK_FAILED, User::AUTHENTIK_REVIEW, User::AUTHENTIK_REVOCATION_PENDING], true) ? 'error' : 'success';

        return back()->with($clave, $resultado['message']);
    }

    public function reinvitar(User $usuario): RedirectResponse
    {
        if ((int) $usuario->id === (int) Auth::id()) {
            return back()->withErrors(['email' => 'No puede reinvitar su propia sesión activa de esta forma.']);
        }

        $resultado = $this->provision->resendInvitation($usuario->fresh('roles'), Auth::user());
        $clave = in_array($resultado['status'], [User::AUTHENTIK_FAILED, User::AUTHENTIK_REVIEW, User::AUTHENTIK_REVOCATION_PENDING], true) ? 'error' : 'success';

        return back()->with($clave, $resultado['message']);
    }

    public function desactivar(User $usuario): RedirectResponse
    {
        if ((int) $usuario->id === (int) Auth::id()) {
            return back()->withErrors(['email' => 'No puede desactivar su propia cuenta.']);
        }

        $resultado = $this->provision->deactivate($usuario, Auth::user());

        return back()->with($resultado['ok'] ? 'success' : 'error', $resultado['message']);
    }

    public function reactivar(User $usuario): RedirectResponse
    {
        if ((int) $usuario->id === (int) Auth::id()) {
            return back()->withErrors(['email' => 'No puede cambiar su propio acceso desde esta ficha.']);
        }

        $resultado = $this->provision->reactivate($usuario->fresh('roles'), Auth::user());
        $fallo = ($resultado['ok'] ?? true) === false
            || in_array($resultado['status'] ?? '', [User::AUTHENTIK_FAILED, User::AUTHENTIK_REVIEW, User::AUTHENTIK_REVOCATION_PENDING], true);

        return back()->with($fallo ? 'error' : 'success', $resultado['message']);
    }

    /** @return list<Role> */
    private function rolesOperativos(): array
    {
        return Role::query()
            ->whereIn('name', self::ROLES_OPERATIVOS)
            ->orderBy('name')
            ->get()
            ->all();
    }
}
