<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

#[Fillable([
    'name',
    'email',
    'password',
    'documento',
    'telefono',
    'activo',
    'theme_preference',
    'authentik_sub',
    'authentik_user_pk',
    'authentik_issuer',
    'authentik_status',
    'authentik_invitation_pk',
    'authentik_authorization_hash',
    'authentik_invitation_url',
    'authentik_invitation_expires_at',
    'authentik_invitation_delivery',
    'authentik_last_error',
    'last_login_at',
    'email_verified_at',
])]
#[Hidden(['password', 'remember_token', 'authentik_invitation_pk', 'authentik_invitation_url', 'authentik_authorization_hash'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    public const AUTHENTIK_LOCAL = 'local';

    public const AUTHENTIK_PENDING = 'pending_invitation';

    public const AUTHENTIK_PREPARED = 'invitation_prepared';

    public const AUTHENTIK_SENT = 'invitation_sent';

    public const AUTHENTIK_ACTIVE = 'active';

    public const AUTHENTIK_DEACTIVATED = 'deactivated';

    public const AUTHENTIK_FAILED = 'invitation_failed';

    public const AUTHENTIK_REVOKED = 'invitation_revoked';

    public const AUTHENTIK_REVOCATION_PENDING = 'revocation_unverified';

    public const AUTHENTIK_LINK_PENDING = 'pending_link';

    public const AUTHENTIK_REVIEW = 'revision_requerida';

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'activo' => 'boolean',
            'authentik_invitation_expires_at' => 'datetime',
            'authentik_invitation_url' => 'encrypted',
            'last_login_at' => 'datetime',
        ];
    }

    public function vinculadoConAuthentik(): bool
    {
        return filled($this->authentik_sub) || filled($this->authentik_user_pk);
    }

    public function resolvedTheme(): string
    {
        $theme = $this->theme_preference ?: 'light';

        return in_array($theme, ['light', 'dark'], true) ? $theme : 'light';
    }

    public function pqrsdAsignados(): HasMany
    {
        return $this->hasMany(Pqrsd::class, 'responsable_id');
    }

    public function auditoriasUsuario(): HasMany
    {
        return $this->hasMany(UsuarioAuditoria::class, 'user_id');
    }

    public function etiquetaEstadoAuthentik(): string
    {
        if ($this->authentik_status === self::AUTHENTIK_DEACTIVATED) {
            return 'Desactivado';
        }
        if ($this->authentik_status === self::AUTHENTIK_ACTIVE || ($this->activo && filled($this->authentik_sub))) {
            return 'Activo';
        }
        if ($this->authentik_invitation_delivery === 'recovery_admin' && filled($this->authentik_user_pk) && blank($this->authentik_sub)) {
            return 'Pendiente de primer ingreso';
        }
        if ($this->authentik_status === self::AUTHENTIK_LINK_PENDING && blank($this->authentik_user_pk)) {
            return 'Pendiente de autorización';
        }
        if ($this->authentik_status === self::AUTHENTIK_LINK_PENDING && filled($this->authentik_user_pk)) {
            return 'Pendiente de primer ingreso';
        }
        if ($this->authentik_status === self::AUTHENTIK_REVIEW) {
            return 'Revisión administrativa';
        }
        if ($this->authentik_status === self::AUTHENTIK_FAILED) {
            return 'Error de aprovisionamiento';
        }
        if ($this->authentik_invitation_delivery === 'ausente' && blank($this->authentik_sub)) {
            return 'Invitación ausente en Authentik';
        }
        if ($this->authentik_status === self::AUTHENTIK_REVOCATION_PENDING) {
            return 'Revocación pendiente de verificación';
        }
        if ($this->authentik_status === self::AUTHENTIK_REVOKED) {
            return 'Invitación revocada';
        }
        if (in_array($this->authentik_status, [self::AUTHENTIK_PREPARED, self::AUTHENTIK_SENT], true)) {
            if ($this->invitationExpired() && blank($this->authentik_invitation_url)) {
                return 'Invitación vencida';
            }
            if (filled($this->authentik_invitation_url) && ! $this->invitationExpired()) {
                return 'Invitación disponible';
            }
            if (filled($this->authentik_sub) || filled($this->authentik_user_pk)) {
                return 'Pendiente de primer ingreso';
            }
        }

        return 'Pendiente de invitación';
    }

    public function invitationExpired(): bool
    {
        return $this->authentik_invitation_expires_at !== null
            && $this->authentik_invitation_expires_at->isPast();
    }
}
