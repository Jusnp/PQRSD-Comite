<?php

namespace App\Policies;

use App\Models\Pqrsd;
use App\Models\User;

class PqrsdPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('pqrsd.view') || $user->can('pqrsd.view_all');
    }

    public function view(User $user, Pqrsd $pqrsd): bool
    {
        if ($user->can('pqrsd.view_all')) {
            return true;
        }

        if (! $user->can('pqrsd.view')) {
            return false;
        }

        return (int) $pqrsd->responsable_id === (int) $user->id
            || (int) $pqrsd->creado_por === (int) $user->id
            || (int) $pqrsd->radicado_por === (int) $user->id;
    }

    public function create(User $user): bool
    {
        return $user->can('pqrsd.create_manual') || $user->can('pqrsd.create');
    }

    public function update(User $user, Pqrsd $pqrsd): bool
    {
        if ($pqrsd->estado_operativo === 'cerrado') {
            return false;
        }

        if ($user->can('pqrsd.manage') || $user->can('pqrsd.view_all')) {
            return true;
        }

        return $user->can('pqrsd.update')
            && (int) $pqrsd->responsable_id === (int) $user->id;
    }

    public function close(User $user, Pqrsd $pqrsd): bool
    {
        if ($pqrsd->estado_operativo === 'cerrado') {
            return false;
        }

        return $user->can('pqrsd.close') || $user->can('pqrsd.manage') || $user->can('pqrsd.view_all');
    }

    public function classify(User $user, Pqrsd $pqrsd): bool
    {
        if ($pqrsd->estado_operativo === 'cerrado') {
            return false;
        }

        return $user->can('pqrsd.classify') || $user->can('pqrsd.manage') || $user->can('pqrsd.view_all');
    }

    /**
     * Asignación operativa deshabilitada: bandeja compartida.
     * El historial de asignaciones antiguas y el servicio interno se conservan;
     * las rutas HTTP de asignación deben responder 403.
     */
    public function assign(User $user, Pqrsd $pqrsd): bool
    {
        return false;
    }
}
