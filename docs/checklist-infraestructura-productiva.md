# Checklist — Infraestructura productiva PQRSD

El SO de producción **aún no está confirmado**. Marque solo lo verificado. Laragon = desarrollo.

## Matriz mínima

| Requisito | Linux | Windows Server | Estado |
|-----------|-------|----------------|--------|
| SO definido y parcheado | ☐ | ☐ | PENDIENTE institución |
| PHP **8.3.x** CLI + FPM/IIS | ☐ | ☐ | Compatible con Laravel 13 |
| Extensiones: openssl, pdo_mysql, mbstring, tokenizer, xml, ctype, json, bcmath, fileinfo, zip | ☐ | ☐ | |
| Composer 2 | ☐ | ☐ | |
| Servidor web (Nginx/Apache o IIS) | ☐ | ☐ | |
| MySQL 8+ accesible | ☐ | ☐ | |
| Dominio + DNS | ☐ | ☐ | |
| HTTPS / TLS válido | ☐ | ☐ | `APP_URL` https |
| Disco privado adjuntos (no público) | ☐ | ☐ | |
| Usuario de servicio (no personal) | ☐ | ☐ | |
| Secrets en vault / `.env` protegido | ☐ | ☐ | No Git |
| Cron / Task Scheduler → `php artisan schedule:run` cada minuto | ☐ | ☐ | Automático |
| Worker persistente (Supervisor/systemd o NSSM) | ☐ | ☐ | Autorestart |
| Logs rotados | ☐ | ☐ | Sin PHI innecesaria |
| Monitoreo `/ops/salud` + alertas | ☐ | ☐ | |
| Backup offsite + restore lab | ☐ | ☐ | Ver SSI |
| Actualizaciones de seguridad planificadas | ☐ | ☐ | |

## Comandos de verificación (no sustituyen automatización)

```bash
php -v
php artisan --version
php artisan schedule:list
php artisan schedule:run
php artisan queue:work --once
php artisan pqrsd:check-production --strict
php artisan pqrsd:ops-health --json
php artisan pqrsd:gmail-pilot-status
```

Evidencia de automatización: filas recurrentes en `ejecuciones_programadas`, logs `correo-sync.log` / `sla-cumplimiento.log`, `worker_active=true` en ops sin sesión interactiva.

## Linux (referencia)

Ver `docs/ops/linux-cron-supervisor.md`.

## Windows Server (referencia)

Ver `docs/ops/windows-scheduler.md` (Programador de tareas + NSSM). **No** usar consola Laragon abierta como “producción”.

## Despliegue reversible (resumen)

1. Release en directorio versionado.  
2. `.env` prod separado (no copiar `.env` local).  
3. `migrate --force` solo con backup previo.  
4. `config:cache` / `route:cache` / `view:cache`.  
5. Reiniciar worker.  
6. Rollback: apuntar a release anterior + reiniciar servicios.

**No regenerar `APP_KEY`** si existen tokens OAuth cifrados.
