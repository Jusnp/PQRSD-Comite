<?php

return [
    'backup_retention_days' => (int) env('PQRSD_BACKUP_RETENTION_DAYS', 30),
    'backup_path' => env('PQRSD_BACKUP_PATH', storage_path('app/private/backups')),
    'worker_heartbeat_max_seconds' => (int) env('PQRSD_WORKER_HEARTBEAT_MAX', 120),
    'oldest_job_alert_seconds' => (int) env('PQRSD_OLDEST_JOB_ALERT', 900),
];
