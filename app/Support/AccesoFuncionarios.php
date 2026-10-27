<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;

/**
 * El acceso web de funcionarios es solo OIDC. La vinculación del primer
 * administrador se hace con pqrsd:vincular-administrador, no con un formulario.
 */
class AccesoFuncionarios
{
    public static function administradorVinculado(): bool
    {
        if (! Schema::hasTable('users') || ! Schema::hasTable('roles') || ! Schema::hasColumn('users', 'authentik_sub')) {
            return false;
        }

        if (! Role::query()->where('name', 'admin')->where('guard_name', 'web')->exists()) {
            return false;
        }

        return User::role('admin')
            ->where('activo', true)
            ->whereNotNull('authentik_sub')
            ->where('authentik_sub', '!=', '')
            ->where('authentik_status', '!=', User::AUTHENTIK_DEACTIVATED)
            ->exists();
    }

    public static function loginLocalDisponible(): bool
    {
        return false;
    }
}
