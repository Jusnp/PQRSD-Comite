<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Proveedor por defecto
    |--------------------------------------------------------------------------
    |
    | El proveedor institucional de @menteplena.com.co aún no está confirmado
    | (Microsoft 365, Google Workspace u otro IMAP). Mientras tanto el sistema
    | opera con el driver "mock" y admite imap / microsoft_graph / google_api.
    |
    */

    'driver_default' => env('PQRSD_MAIL_DRIVER', 'mock'),

    'buzon_central' => env('PQRSD_MAIL_CENTRAL', 'pqrsd-central@menteplena.test'),

    /*
    | Credenciales: cada cuenta referencia una clave de entorno (credential_ref).
    | Ejemplo: credential_ref = MAIL_CRED_GESTIONSAVIA → env('MAIL_CRED_GESTIONSAVIA')
    | Nunca se persiste el secreto en la base de datos.
    */

    'sync_interval_minutes' => (int) env('PQRSD_MAIL_SYNC_MINUTES', 5),

    'max_reintentos' => (int) env('PQRSD_MAIL_MAX_RETRIES', 5),

    'reintento_minutos' => (int) env('PQRSD_MAIL_RETRY_MINUTES', 10),

    'adjuntos_disk' => env('PQRSD_MAIL_ATTACHMENTS_DISK', 'local'),

    'adjuntos_path' => 'correo/adjuntos',

    'raw_path' => 'correo/raw',

    /*
    | Patrones que sugieren PQRSD (asistencia humana si hay duda).
    */
    'palabras_pqrsd' => [
        'pqrs', 'pqrsd', 'peticion', 'petición', 'queja', 'reclamo',
        'sugerencia', 'denuncia', 'tutela', 'supersalud', 'inconformidad',
    ],

    /*
    | Patrones de ruido comercial / notificaciones automáticas.
    */
    'palabras_ruido' => [
        'unsubscribe', 'newsletter', 'noreply', 'no-reply', 'mailer-daemon',
        'out of office', 'fuera de la oficina', 'automatic reply',
    ],

    'radicado_regex' => '/PQRSD-(\d{4})-(\d{6})/i',

    /*
    | Fixtures del MockMailProvider (solo desarrollo/pruebas).
    */
    'mock_inbox' => storage_path('app/correo/mock_inbox.json'),

    /*
    | Gmail API / Google Workspace
    | Credenciales solo vía env (credential_ref). No almacenar secretos en BD.
    */
    'gmail' => [
        'service_account_enabled' => (bool) env('GMAIL_SERVICE_ACCOUNT_ENABLED', false),
        'pubsub_topic' => env('GMAIL_PUBSUB_TOPIC'),
        'pubsub_push_audience' => env('GMAIL_PUBSUB_PUSH_AUDIENCE', env('APP_URL').'/webhooks/gmail-pubsub'),
        'pubsub_service_account' => env('GMAIL_PUBSUB_SERVICE_ACCOUNT'),
        'pubsub_skip_oidc' => (bool) env('GMAIL_PUBSUB_SKIP_OIDC', false),
        'pubsub_dev_token' => env('GMAIL_PUBSUB_DEV_TOKEN'),
        'pilot_mailbox' => env('GMAIL_PILOT_MAILBOX'),
        'allowed_mailboxes' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('GMAIL_ALLOWED_MAILBOXES', ''))
        ))),
        'reconcile_query' => env('GMAIL_RECONCILE_QUERY', 'newer_than:7d'),
        'oauth_client_id' => env('GMAIL_OAUTH_CLIENT_ID'),
        'oauth_client_secret' => env('GMAIL_OAUTH_CLIENT_SECRET'),
        'oauth_redirect_uri' => env('GMAIL_OAUTH_REDIRECT_URI'),
        'oauth_scopes' => array_values(array_filter(array_map('trim', explode(' ', (string) env(
            'GMAIL_OAUTH_SCOPES',
            'https://www.googleapis.com/auth/gmail.readonly https://www.googleapis.com/auth/gmail.send'
        ))))),
    ],
];
