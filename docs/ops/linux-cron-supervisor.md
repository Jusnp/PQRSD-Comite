# Scheduler y worker en Linux (producción)

## Cron (obligatorio)

Como usuario de la aplicación (no root salvo que se documente):

```cron
* * * * * cd /var/www/pqrsd && php artisan schedule:run >> /dev/null 2>&1
```

Zona horaria de la app: `America/Bogota` (las tareas ya declaran `->timezone('America/Bogota')`).

## Supervisor (worker)

Archivo sugerido `/etc/supervisor/conf.d/pqrsd-worker.conf`:

```ini
[program:pqrsd-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/pqrsd/artisan queue:work database --sleep=3 --tries=3 --timeout=120 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/www/pqrsd/storage/logs/queue-worker.log
stopwaitsecs=3600
```

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start pqrsd-worker:*
```

Alternativa systemd: unidad `Type=simple` con `Restart=always` ejecutando el mismo `queue:work`.

## Salud

```bash
php artisan pqrsd:ops-health --json
php artisan queue:failed
```

Alertar si `worker_active=false`, `jobs_failed>0` o `oldest_job_age_seconds` supera el umbral.
