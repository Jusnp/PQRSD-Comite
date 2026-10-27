# Validación final preproducción — PQRSD Mente Plena

**Fecha:** 14 octubre 2026  
**Stack:** Laravel 13.36.0 · PHP 8.3.33 · MySQL · Laragon (dev)  

## Veredicto global

**NO LISTO PARA PRODUCCIÓN.**

Motivo: faltan prueba Gmail real, cron/worker en servidor definitivo, destino offsite de respaldos y ensayo E2E autorizado. PHPUnit local **no** sustituye esas validaciones.

---

## Control de versiones (Git)

El workspace **no tenía** repositorio Git. Se endureció `.gitignore` (env, tokens Google, backups, correo privado, logs, vendor).

**Recomendación (sin ejecutar sin autorización):**

```bash
git init
git add .
git status   # verificar que no hay .env, *.enc, credentials
git commit -m "..."
```

No publicar a remoto ni incluir secretos. No se inicializó Git en esta fase (requiere autorización explícita).

---

## Pendientes — estado detallado

### 1. Gmail API real

| Campo | Valor |
|-------|--------|
| Estado anterior | Driver REST + Fake; sin OAuth/PubSub operativos |
| Cambio | OAuth 2.0, tokens cifrados, Pub/Sub push+OIDC, tipo_buzon, piloto, `pqrsd:gmail-pilot-status` |
| Archivos | `app/Services/Gmail/*`, `app/Http/Controllers/Gmail/*`, `app/Jobs/ProcessGmailPubSubNotificationJob.php`, migration fase3, config |
| Prueba | Feature OAuth/PubSub/idempotencia (Fake/Http::fake); `gmail-pilot-status` → BLOQUEADO |
| Resultado | Código PREPARADO; piloto real sin credenciales |
| Evidencia | `docs/google-workspace-configuracion-real.md`; tests Feature |
| Dependencias | Admin Workspace, Google Cloud OAuth/PubSub, buzón piloto |
| **Estado final** | **BLOQUEADO** (prueba real) / **PREPARADO** (código) |

### 2. Cron y worker servidor definitivo

| Campo | Valor |
|-------|--------|
| Estado anterior | Evidencia local Laragon; docs Supervisor/NSSM |
| Cambio | Panel ops, health ampliado, docs despliegue dual OS |
| Archivos | `OpsHealthController`, `OpsHealthService`, `docs/despliegue-servidor-pqrsd.md` |
| Prueba | Panel ops Feature; schedule local ya verificado en fase 2 |
| Dependencias | Servidor OS definitivo + cuenta servicio |
| **Estado final** | **PREPARADO** (docs/panel) / **BLOQUEADO** (servidor prod) |

### 3. Configuración producción independiente

| Campo | Valor |
|-------|--------|
| Estado anterior | `.env.production.example` + check básico |
| Cambio | Checks OAuth, piloto, OIDC, cookie secure, backup encrypt/destino |
| Archivos | `CheckProductionCommand`, `.env.production.example` |
| Prueba | `SeguridadProduccionTest` |
| Nota | `.env` local **no** modificado |
| **Estado final** | **PREPARADO** |

### 4. Respaldos cifrados externos + restore

| Campo | Valor |
|-------|--------|
| Estado anterior | Backup local + checksum |
| Cambio | AES bundle, adaptador externo, restore aislado |
| Archivos | `BackupEncryption`, `ExternalBackupStore`, comandos backup/restore |
| Prueba | `BackupEncryptionExternalTest` (cifrado, corrupción, null driver) |
| Dependencias | Vault de claves + bucket/staging institucional |
| **Estado final** | **PREPARADO** (local/cifrado/tests) / **BLOQUEADO** (offsite real + restore MySQL lab) |

### 5. Prueba integral E2E

| Campo | Valor |
|-------|--------|
| Estado anterior | Caso trazador PHPUnit |
| Cambio | Escenario documentado; no ejecutado contra Workspace real |
| Dependencias | Cuenta piloto + ensayo + autorización |
| **Estado final** | **BLOQUEADO** |

---

## Pruebas automatizadas

Ejecutar: `php artisan test`

Resultado de esta sesión: **79/79** aprobadas (240 aserciones).  
Se conservan las 70 pruebas previas y se añaden suites Fase 3 (Pub/Sub, OAuth, backup cifrado, ops panel, piloto).  
La lógica de `PqrsdPolicy`, SLA y cierres **no** se modificó.

---

## Acciones humanas requeridas

1. **Workspace Admin:** clasificar cada dirección (individual/alias/grupo/reenvío); autorizar un buzón piloto.  
2. **Google Cloud:** OAuth client, Gmail API, Pub/Sub topic, permisos publish de Gmail.  
3. **Admin servidor:** OS definitivo, cron `schedule:run`, worker Supervisor/NSSM, HTTPS, `.env` prod.  
4. **SSI:** vault clave de backup, destino offsite, aprobar RPO/RTO, restore drill en lab.  
5. **Calidad:** UAT E2E con datos ficticios + un envío autorizado (aceptación Gmail ≠ entrega final).  

---

## Clasificación rápida

| Pendiente | Tag |
|-----------|-----|
| Gmail real | BLOQUEADO |
| Pub/Sub código | PREPARADO |
| Cron/worker prod | BLOQUEADO |
| Check-production | PREPARADO / VERIFICADO (tests) |
| Backup cifrado local | VERIFICADO (tests) |
| Backup offsite | BLOQUEADO |
| E2E integral | BLOQUEADO |
| Git ignore seguro | PREPARADO (init no ejecutado) |
