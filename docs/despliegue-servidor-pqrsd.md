# Despliegue servidor PQRSD

El SO productivo **no está confirmado**. Instrucciones separadas para Linux y Windows. Laragon es solo desarrollo.

## Preflight

```bash
php artisan pqrsd:check-production --strict
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan pqrsd:ops-health --json
```

Conservar `.env` local intacto. Usar `.env.production.example` como plantilla en el servidor de ensayo/prod.

**No regenerar `APP_KEY`** si ya hay datos cifrados (OAuth tokens, sesiones).

## Scheduler

Debe ejecutarse cada minuto:

```text
php artisan schedule:run
```

Evidencia: `ejecuciones_programadas`, `storage/logs/sla-cumplimiento.log`, `correo-sync.log`, panel `/ops/salud`.

### Linux (cron)

```cron
* * * * * cd /var/www/pqrsd && php artisan schedule:run >> /dev/null 2>&1
```

### Windows (Programador de tareas / no Laragon)

- Disparador: cada 1 minuto  
- Programa: ruta al `php.exe` del servidor  
- Argumentos: `artisan schedule:run`  
- Inicio en: directorio de la app  
- Ejecutar aunque el usuario no haya iniciado sesión  

Desarrollo Laragon: `php artisan schedule:work` (consola dedicada).

## Worker persistente

```bash
php artisan queue:work database --queue=default --sleep=3 --tries=3 --timeout=120 --max-time=3600
```

### Linux — Supervisor

Ver `docs/ops/linux-cron-supervisor.md`.

### Windows — NSSM / servicio

Ver `docs/ops/windows-scheduler.md`.

Comprobar **heartbeat real**: `storage/logs/queue-worker.heartbeat` y `worker_active` en ops health.  
Configurar un programa ≠ proceso vivo.

Tras despliegue: reiniciar worker para cargar código nuevo.

## Panel de salud

- Ruta: `/ops/salud` (`correos.manage`) o `/ops/salud-auditor` (`auditoria.view`)
- Muestra: Gmail, última sync, edad job, cron, worker, pending/failed, alertas SLA, último backup

## Rollback (reversible)

1. Mantener release anterior en directorio paralelo o tag Git.  
2. Restaurar código anterior.  
3. `php artisan migrate:rollback` solo si la migración es reversible y autorizada.  
4. Reiniciar worker/scheduler.  
5. `pqrsd:ops-health --json`  

No restaurar backups clínicos sobre prod sin procedimiento SSI.

## Estado

| Ítem | Clasificación |
|------|---------------|
| Docs Linux/Windows + checks | PREPARADO |
| Cron/worker en servidor definitivo | BLOQUEADO (infra no provisionada aquí) |
| Scheduler/worker evidencia en Laragon local | VERIFICADO (fase previa) |
