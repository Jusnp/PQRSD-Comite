# Auditoría preproducción — Sistema PQRSD Mente Plena

**Fecha:** 14 de octubre de 2026  
**Stack verificado:** Laravel 13.36.0 · PHP 8.3.33 · MySQL (local Laragon)  
**Repositorio Git:** no inicializado en el workspace auditado  

---

## A. Resumen ejecutivo

### Estado anterior
- 49/49 pruebas OK; caso trazador, SLA, cierre con evidencia y `PqrsdPolicy` operativos.
- Veredicto: **NO LISTO PARA PRODUCCIÓN** por correo mock, scheduler sin evidencia, worker no persistente, `APP_DEBUG=true`, sin respaldos verificados y sin descarga autorizada de adjuntos.

### Cambios implementados
1. Descarga autorizada de adjuntos con auditoría y pruebas (403/401/404).
2. Scheduler con zona `America/Bogota`, `withoutOverlapping`, logs `correo-sync.log` / `sla-cumplimiento.log`, tabla `ejecuciones_programadas`; evidencia real tras `schedule:run`.
3. Salud de colas (`pqrsd:ops-health`), jobs únicos, heartbeat de worker, drenado de cola pendiente (0 jobs / 0 failed).
4. Driver Gmail API (`google_api`) con historyId, recuperación 404, watch renovable, Fake client y tests — **sin fingir conexión real**.
5. Checklist `pqrsd:check-production`, `.env.production.example`, docs ops Windows/Linux.
6. Respaldos `pqrsd:backup` + `pqrsd:backup-verify` con checksum.

### Estado posterior
- **70/70** pruebas automatizadas aprobadas (49 originales + 21 nuevas).
- Bloqueantes de código/ops local: mayormente corregidos o preparados.
- Bloqueantes externos (Gmail real, cron/supervisor en servidor prod, `.env` productivo, backups offsite): **pendientes de validación institucional**.
- **Veredicto global: AÚN NO LISTO PARA PRODUCCIÓN** (faltan validaciones externas críticas).

---

## B. Archivos modificados / creados

| Ruta | Motivo | Riesgo |
|------|--------|--------|
| `app/Http/Controllers/AdjuntoPqrsdController.php` | Descarga autorizada | Medio (acceso archivos) |
| `app/Policies/PqrsdPolicy.php` | Sin cambio de reglas (reutilizado `view`) | Bajo |
| `routes/web.php` | Ruta download | Bajo |
| `resources/views/pqrsd/show.blade.php` | Enlaces adjuntos | Bajo |
| `database/migrations/2026_10_14_200000_*` | Tablas descargas/ejecuciones/respaldos | Bajo |
| `app/Mail/Providers/GoogleGmailMailProvider.php` | Driver Gmail | Alto (correo) |
| `app/Mail/Gmail/*` | Cliente HTTP/Fake/credenciales | Alto |
| `app/Mail/MailProviderManager.php` | `google_api` → Gmail real | Medio |
| `app/Services/Correo/EmailSyncService.php` | No avanzar cursor si incompleto | Medio |
| `app/Console/Commands/*` | Sync, SLA, ops, backup, check-prod, watch | Medio |
| `routes/console.php` | Schedule ampliado | Medio |
| `config/logging.php`, `pqrsd_correo.php`, `pqrsd_ops.php` | Canales y Gmail | Bajo |
| `app/Jobs/SyncCuentaCorreoJob.php` | Unique + heartbeat | Bajo |
| `tests/Feature/*` nuevos | Cobertura bloqueantes | Bajo |
| `.env.production.example` | Plantilla segura | Bajo |
| `docs/ops/*`, `docs/auditoria-preproduccion-pqrsd.md` | Operación e informe | Nulo |
| `.env` local | **No modificado** (sigue local/debug) | — |

Línea base de negocio (SLA, cierre, policy de casos, trazador) **conservada**.

---

## C. Configuración

### Variables requeridas (producción)
Ver `.env.production.example`. Críticas: `APP_ENV=production`, `APP_DEBUG=false`, `PQRSD_MAIL_DRIVER=google_api`, credenciales Gmail vía `credential_ref`, `QUEUE_CONNECTION=database`, `SESSION_ENCRYPT=true`, `APP_URL` HTTPS.

### Instalación / operación
```bash
composer install --no-dev
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan pqrsd:check-production --strict
php artisan schedule:list
php artisan pqrsd:ops-health --json
php artisan queue:work database --sleep=3 --tries=3 --timeout=120
```

### Dependencias externas
- Google Cloud + Workspace (Gmail API, Pub/Sub, DWD u OAuth).
- Cron Linux o Programador de tareas Windows.
- Supervisor/systemd o NSSM para worker.
- Almacenamiento cifrado offsite para respaldos.
- MySQL productivo con least privilege.

Documentación: `docs/ops/windows-scheduler.md`, `linux-cron-supervisor.md`, `gmail-workspace.md`, `respaldos-restauracion.md`.

---

## D. Pruebas

| Métrica | Valor |
|---------|-------|
| Pruebas ejecutadas | 70 |
| Aprobadas | 70 |
| Fallidas | 0 |
| Assertions | 212 |

### Suites nuevas
- `AdjuntoDescargaAutorizadaTest` (6)
- `GmailApiProviderTest` (6)
- `SchedulerWorkerOpsTest` (6)
- `BackupRestoreTest` (1)
- `SeguridadProduccionTest` (2)

### Fallos encontrados durante la corrección
1. Tests de adjuntos asignaban roles antes del seeder → corregido.
2. Job retenido `ReintentarMensajesCorreoJob` → procesado con `queue:work` (pending=0).

### Evidencia de integración local
- `php artisan schedule:run` ejecutó SLA y escribió `sla-cumplimiento.log`.
- `pqrsd:sync-correos --sync` escribió `correo-sync.log` y filas en `ejecuciones_programadas`.
- Backup adjuntos con checksum verificado.
- Gmail: solo Fake client (sin red).

---

## E. Validaciones operativas

| Validación | Resultado |
|------------|-----------|
| Recepción real Gmail | **No ejecutada** (sin credenciales GCP/Workspace) |
| Envío real Gmail | **No ejecutada** |
| Scheduler con evidencia | **OK local** (logs + tabla) |
| Worker persistente | **Preparado** (docs); no hay servicio Windows/Linux instalado aquí |
| Jobs pending / failed | **0 / 0** tras drenado |
| Descarga autorizada | **OK** (tests 403/redirect/404/auditoría) |
| Configuración segura | Checklist OK en simulación prod; **local sigue inseguro a propósito** |
| Respaldo restaurado | Generación + verify OK; restore drill completo en aislado **pendiente SSI** |

---

## F. Pendientes externos

| Actor | Pendiente |
|-------|-----------|
| Admin Google Workspace | Autorizar buzones, DWD/OAuth, scopes Gmail |
| Google Cloud | Proyecto, API, Pub/Sub, service account |
| Admin servidor | Cron `schedule:run`, Supervisor worker, HTTPS, `.env` prod |
| Seguridad de la Información | Política retención backups, cifrado offsite, custodia de claves |
| Calidad / PQRSD | UAT con datos ficticios + un envío real autorizado |

---

## G. Veredicto por bloqueante

| # | Bloqueante | Clasificación |
|---|------------|---------------|
| 1 | Correo real Gmail API | **IMPLEMENTADO, PENDIENTE DE VALIDACIÓN** (código+tests fake; sin GCP) |
| 2 | `MAIL_MAILER=log` en audit local | **IMPLEMENTADO, PENDIENTE DE VALIDACIÓN** (plantilla prod; `.env` local intacto) |
| 3 | Scheduler / evidencias de log | **CORREGIDO Y VERIFICADO** (local) |
| 4 | Worker persistente | **IMPLEMENTADO, PENDIENTE DE VALIDACIÓN** (comandos+docs; servicio OS no instalado) |
| 5 | Jobs retenidos | **CORREGIDO Y VERIFICADO** (pending=0) |
| 6 | `APP_DEBUG` / `APP_ENV` prod | **IMPLEMENTADO, PENDIENTE DE VALIDACIÓN** (`check-production` + example) |
| 7 | Respaldos BD/adjuntos | **IMPLEMENTADO, PENDIENTE DE VALIDACIÓN** (backup+checksum; restore aislado / offsite pendiente) |
| 8 | Descarga autorizada adjuntos | **CORREGIDO Y VERIFICADO** |

### Veredicto final

**NO LISTO PARA PRODUCCIÓN.**

Motivo: los bloqueantes críticos de **correo real**, **worker/cron en servidor productivo** y **configuración/respaldos en entorno de producción** requieren validación externa. Las pruebas automatizadas locales (70/70) y la evidencia de scheduler/colas en Laragon **no sustituyen** esa validación.
