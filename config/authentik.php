<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Login OIDC (botón en /login)
    |--------------------------------------------------------------------------
    */
    'enabled' => filter_var(env('AUTHENTIK_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    /*
    |--------------------------------------------------------------------------
    | Login local (email/contraseña) — arranque y recuperación
    |--------------------------------------------------------------------------
    |
    | El formulario web local está cerrado. La vinculación del primer
    | administrador se hace solo con pqrsd:vincular-administrador.
    |
    */
    'local_login_enabled' => filter_var(env('AUTHENTIK_LOCAL_LOGIN_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

    /*
    |--------------------------------------------------------------------------
    | Aprovisionamiento de invitaciones (API)
    |--------------------------------------------------------------------------
    |
    | No activar en producción sin token de servicio de alcance mínimo,
    | flow de inscripción por invitación y grupo pqrsd-usuarios validados.
    |
    */
    'provisioning_enabled' => filter_var(env('AUTHENTIK_PROVISIONING_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    'base_url' => rtrim((string) env('AUTHENTIK_BASE_URL', ''), '/'),
    'api_token' => env('AUTHENTIK_API_TOKEN'),

    /*
    | Cuenta técnica. Un administrador asignó Can view User de forma global,
    | junto con los permisos de invitación. Authentik 2024.8.3 no refleja ese
    | permiso en system_permissions; la aplicación comprueba la capacidad con
    | el directorio real, su paginación y búsquedas exactas.
    */
    'service_username' => env('AUTHENTIK_SERVICE_USERNAME', 'pqrsd-servicio'),

    'app_slug' => env('AUTHENTIK_APP_SLUG', 'pqrsd-comite'),
    'group_name' => env('AUTHENTIK_GROUP_NAME', 'pqrsd-usuarios'),
    'group_pk' => env('AUTHENTIK_GROUP_PK'),

    /*
    | Flow de enrollment exclusivo por invitación (UUID preferido; slug como respaldo).
    */
    'enrollment_flow_pk' => env('AUTHENTIK_ENROLLMENT_FLOW_PK'),
    'enrollment_flow_slug' => env('AUTHENTIK_ENROLLMENT_FLOW_SLUG', 'pqrsd-invitation-enrollment'),

    'invitation_ttl_hours' => (int) env('AUTHENTIK_INVITATION_TTL_HOURS', 48),

    /*
    | null = detectar / intentar envío; true/false fuerza el estado de correo saliente.
    */
    'email_configured' => env('AUTHENTIK_EMAIL_CONFIGURED'),

];
