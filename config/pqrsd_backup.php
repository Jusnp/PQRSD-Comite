<?php

return [
    'retention_days' => (int) env('PQRSD_BACKUP_RETENTION_DAYS', 30),
    'path' => env('PQRSD_BACKUP_PATH', storage_path('app/private/backups')),
    'encryption_enabled' => (bool) env('PQRSD_BACKUP_ENCRYPT', false),
    /*
     | Clave de cifrado independiente del APP_KEY de la app (recomendado).
     | Generar fuera del servidor de datos y custodiar en vault institucional.
     | No incluir en el paquete de respaldo.
     */
    'encryption_key' => env('PQRSD_BACKUP_ENCRYPTION_KEY'),
    'external_driver' => env('PQRSD_BACKUP_EXTERNAL_DRIVER', 'null'), // null|local_staging|s3
    'external_disk' => env('PQRSD_BACKUP_EXTERNAL_DISK', 's3'),
    'external_prefix' => env('PQRSD_BACKUP_EXTERNAL_PREFIX', 'pqrsd-backups/'),
    'notify_on_failure' => (bool) env('PQRSD_BACKUP_NOTIFY_FAIL', true),
    /*
     | RPO/RTO: valores que la institución debe aprobar; no son objetivos inventados.
     | Dejar null hasta definición formal de SSI/continuidad.
     */
    'rpo_hours_pending_approval' => env('PQRSD_RPO_HOURS'),
    'rto_hours_pending_approval' => env('PQRSD_RTO_HOURS'),
];
