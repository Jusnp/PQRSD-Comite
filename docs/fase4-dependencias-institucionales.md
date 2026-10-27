# Fase 4 — Matriz de dependencias institucionales

**Fecha:** 14 oct 2026  
**Veredicto global:** NO LISTO PARA PRODUCCIÓN  

## Diagnóstico verificado (código + pruebas)

| Ítem | Resultado |
|------|-----------|
| PHPUnit | **81/81** (248 aserciones) — 79 previas + 2 inventario CSV; ejecutado en esta sesión |
| Piloto Gmail | **BLOQUEADO** (`pqrsd:gmail-pilot-status`) |
| Git | No inicializado |
| Driver Gmail | REST real en código; sin credenciales → sin prueba real |
| Pub/Sub | Webhook + OIDC + idempotencia en código; **solo Fake/dev token en tests** |
| Panel `/ops/salud` | Implementado |
| Inventario UI/CSV | Implementado en Fase 4 (antes: columnas BD sin UI completa) |

### Diferencias código vs documentación previas

1. Docs decían inventario de tipos de buzón; la UI no exponía `tipo_buzon` hasta esta fase (ahora sí + CSV).  
2. Pub/Sub: la verificación OIDC usa `tokeninfo` de Google (incluye `exp`); no es una librería JWKS local. Suficiente para push si audience/issuer/email coinciden; validación **real** con Pub/Sub de GCP sigue pendiente.  
3. Backup offsite documentado como adaptador; destino institucional **no** provisionado (`external_driver=null` en local).

---

## Matriz

| ID | Dependencia | Área responsable | Información necesaria | Acción concreta | Evidencia requerida | Estado | Bloqueante asociado | Siguiente acción |
|----|-------------|------------------|----------------------|-----------------|---------------------|--------|---------------------|------------------|
| D01 | Clasificar 11+ direcciones | Workspace Admin | tipo_buzon, buzón canónico | Completar CSV `docs/templates/inventario-buzones-pqrsd.csv` o export desde app | CSV firmado / import en sistema | PENDIENTE | Gmail real / aliases | Seguir `guia-admin-google-workspace.md` |
| D02 | Autorizar OAuth piloto | Workspace Admin + Cloud | Client ID, redirect, scopes, usuario piloto | Crear OAuth client; consent; `GMAIL_PILOT_MAILBOX` | Captura Admin + `gmail-pilot-status` OK | PENDIENTE | Piloto Gmail | Entregar Client ID/Secret a TI (vault), no por chat |
| D03 | Proyecto GCP + Gmail API | Google Cloud Admin | Project ID, APIs | Habilitar Gmail API + Auth Platform | Consola GCP | PENDIENTE | OAuth/PubSub | Checklist en guía Workspace |
| D04 | Pub/Sub topic + push | Google Cloud Admin | Topic, push URL, SA | Configurar push a `/webhooks/gmail-pubsub` | Mensaje real recibido + fila `gmail_pubsub_events` | PENDIENTE | Notificaciones | Solo tras piloto OAuth |
| D05 | SO y servidor definitivo | Infraestructura | Windows Server o Linux | Provisionar VM/host | Acta de entrega servidor | PENDIENTE | Cron/worker prod | Completar `checklist-infraestructura-productiva.md` |
| D06 | HTTPS + dominio | Infraestructura | FQDN, certificado | TLS en reverse proxy | Cert válido + APP_URL https | PENDIENTE | Config prod | No usar Laragon como prod |
| D07 | Cron schedule:run | Infraestructura | Cuenta servicio | Instalar cron/Task Scheduler | Log + `ejecuciones_programadas` sin intervención | PENDIENTE | Scheduler automático | Distinto de `schedule:run` manual |
| D08 | Worker supervisado | Infraestructura | Supervisor/NSSM/systemd | Servicio persistente | Heartbeat + `ops/salud` worker_active | PENDIENTE | Colas | Reinicio post-deploy |
| D09 | Destino backup offsite | SSI | Ubicación, ACLs, retención | Provisionar destino + claves vault | Transfer OK + restore lab | PENDIENTE | Backups externos | `solicitud-seguridad-backups.md` |
| D10 | RPO/RTO | SSI + Continuidad | Objetivos aprobados | Definir horas y firmar | Acta SSI | PENDIENTE | Continuidad | No inventar cifras |
| D11 | Acceso correos / datos salud | SSI | Base legal, minimización | Autorizar scopes y buzones | Acta SSI | PENDIENTE | OAuth scopes | Scopes mínimos |
| D12 | Caso trazador UAT | Calidad | Datos ficticios | Ejecutar `plan-piloto-pqrsd.md` | Acta aceptación parcial | PENDIENTE | E2E | Tras piloto Gmail |
| D13 | Procedimiento transitorio | Calidad + TI | Canales actuales | Adoptar procedimiento temporal | Firmas responsables | PENDIENTE | Operación mientras tanto | Ver sección procedimiento |
| D14 | Aceptación producción | Calidad + SSI + TI | Evidencias checklist | Firmar `acta-aceptacion-produccion.md` | Acta completa | PENDIENTE | Go-live | Solo si criterios §11 cumplidos |

---

## Mensajes listos para enviar (sin secretos)

### A Workspace Admin
> Solicitamos clasificar las direcciones PQRSD `@menteplena.com.co` (individual / alias / grupo / reenvío) y el buzón canónico de cada una, usando la plantilla CSV del sistema. También necesitamos autorizar un único buzón piloto OAuth para pruebas con datos ficticios. No envíe contraseñas ni tokens por correo.

### A Google Cloud Admin
> Requerimos proyecto GCP con Gmail API, cliente OAuth (redirect del sistema PQRSD), y preparación de Pub/Sub push hacia el webhook institucional. Credenciales solo vía vault/TI. Confirmación de project ID y topic name por canal seguro.

### A Infraestructura
> El SO productivo aún no está definido. Pedimos completar el checklist de infraestructura (PHP 8.3, HTTPS, MySQL, cron `schedule:run`, worker persistente, monitoreo). Laragon no es el entorno de producción.

### A Seguridad de la Información
> Solicitamos aprobar scopes OAuth mínimos, destino offsite de respaldos cifrados, custodia de claves fuera del paquete de backup, retención y definición formal de RPO/RTO. Adjunto: `solicitud-seguridad-backups.md`.

### A Calidad
> Mientras se habilita Gmail real, compartimos procedimiento operativo transitorio de radicación manual y conciliación diaria (no sustituye la automatización). El piloto E2E con datos ficticios se ejecutará cuando exista buzón piloto autorizado (`plan-piloto-pqrsd.md`).
