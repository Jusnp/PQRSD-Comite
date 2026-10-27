# Plan de integración Google Workspace — PQRSD Comité de Estudios Médicos

**Fecha:** 2026-10-18  
**Institución:** Comité de Estudios Médicos  
**Línea base:** 194 pruebas habituales + 6 de concurrencia MySQL aprobadas  
**Naturaleza de este documento:** planificación técnica verificable  
**Fuera de alcance de esta etapa:** activar Gmail API, OAuth, Pub/Sub; modificar Bandeja compartida, roles, reglas SLA, indicadores u operación diaria de auxiliares.

---

## 1. Arquitectura propuesta

### 1.1 Principio rector

Workspace es un **canal de entrada/salida de mensajes**, no el sistema de radicación ni el motor SLA. El expediente PQRSD, la Bandeja operativa compartida, las reglas de riesgo y los indicadores siguen siendo la fuente de verdad.

```
                    Google Workspace (Gmail)
                              │
              ┌───────────────┼───────────────┐
              │ sync / watch  │               │ send
              ▼               │               ▼
     GoogleGmailMailProvider  │     GoogleGmailMailProvider
              │               │               │
              ▼               │               ▼
     EmailSyncService ────────┤      EmailReplyService /
     EmailIngestionService    │      (futuro) envío respuesta oficial
              │               │
              ▼               │
     mensajes_correo + adjuntos_correo
              │
              ▼
     Revisión humana (correos.review)
         │              │
         ▼              ▼
  Asociar a PQRSD   Confirmar nueva radicación
  (existente)       (EmailReviewService → PqrsdService)
         │
         ▼
  Expediente PQRSD (Bandeja compartida, SLA, indicadores)
         │
         ▼
  Respuesta oficial (estados elaborada → … → enviada / puesta_disposicion)
         │
         ▼
  Canal ciudadano: consulta segura / email / correspondencia
```

### 1.2 Capas

| Capa | Responsabilidad | Estado actual |
|------|-----------------|---------------|
| **Proveedor** | Hablar con Gmail REST (`fetchNew`, `send`, `testConnection`) | `GoogleGmailMailProvider` + Fake en tests |
| **Orquestación** | Sync, cursors `historyId`, jobs, Pub/Sub | `EmailSyncService`, jobs, webhook (sin credenciales reales) |
| **Ingesta** | Deduplicar, clasificar, persistir, adjuntos | `EmailIngestionService` + `EmailClassifier` |
| **Revisión humana** | Confirmar / asociar / descartar | `EmailReviewService` + bandeja correo |
| **Expediente** | Radicación, SLA, respuesta oficial, cierre | Inalterado en esta fase |
| **Alertas** | Vital / vencimiento en BD + UI; correo opcional | `SlaNotificationService` + `/vencimientos` |

### 1.3 Modelo de buzones

No asumir N direcciones = N bandejas Gmail. Usar inventario institucional (`tipo_buzon`, `buzon_canonico`) ya modelado en `cuentas_correo`:

- **individual** → OAuth/API sobre esa identidad.  
- **alias / reenvío / grupo** → conectar solo el **buzón canónico** que almacena mensajes.  
- Lista blanca `GMAIL_ALLOWED_MAILBOXES` + piloto `GMAIL_PILOT_MAILBOX`.

### 1.4 Dualidad de canales (no mezclar)

| Canal | Qué es | Qué no es |
|-------|--------|-----------|
| **Radicación pública + QR** | Entrada ciudadana directa al expediente | No depende de Gmail |
| **Bandeja de correo** (`/bandeja-correo`) | Cola de mensajes institucionales a revisar | No es la Bandeja PQRSD |
| **Bandeja PQRSD** (`/pqrsd`) | Expedientes operativos compartidos | No lista correos crudos |
| **Respuesta oficial PQRS** | Acto de fondo en el expediente (`respuesta_estado`) | Distinto del reply de hilo Gmail |
| **Respuesta por correo** (`EmailReplyService`) | Mensaje saliente en hilo Gmail | No sustituye por sí sola la respuesta oficial salvo procedimiento explícito |

---

## 2. Componentes existentes reutilizables

### 2.1 Cuentas y permisos

| Componente | Reutilización |
|------------|---------------|
| `cuentas_correo` + inventario (`tipo_buzon`, `buzon_canonico`, CSV) | Inventario previo a OAuth |
| `correos.manage` (solo admin) | Configurar cuentas, OAuth, ops salud |
| `correos.review` (admin + auxiliar hoy) | Revisar mensajes, confirmar/asociar/descartar |
| `correos.reply` (admin + auxiliar hoy) | Enviar reply de hilo desde plataforma |
| `CuentaCorreoController` / UI cuentas | Gestión sin secretos en BD (`credential_ref`) |

**Decisión institucional pendiente (no cambiar ahora):** si el auxiliar debe conservar `correos.review` / `correos.reply` en el piloto Workspace, o si la revisión de correo queda solo en admin hasta estabilizar.

### 2.2 Mensajes, adjuntos y sync

| Componente | Reutilización |
|------------|---------------|
| `mensajes_correo` | ID interno + `message_id` RFC + vínculo `pqrsd_id` |
| Unique `(cuenta_correo_id, message_id)` + dedupe global | Anti-duplicados |
| `adjuntos_correo` (ruta privada, checksum SHA-256) | Conservación de soportes |
| `sincronizaciones_correo` | Auditoría de corridas de sync |
| `EmailClassifier` | Sugiere; **nunca radica solo** |
| `EmailIngestionService` | Persistencia + raw EML opcional |
| `EmailSyncService` + `SyncCuentaCorreoJob` | Orquestación periódica |
| `ReintentarMensajesCorreoJob` | Reintentos de ingesta |
| `gmail_oauth_tokens` (cifrados) / `gmail_pubsub_events` | Auth e idempotencia push |
| `MailProvider` + `MailProviderManager` | Intercambio mock → `google_api` sin reescribir negocio |

### 2.3 Radicación y expediente

| Componente | Reutilización |
|------------|---------------|
| `EmailReviewService::confirmarNuevaPqrsd` | Radicación controlada canal `CORREO` |
| `EmailReviewService::asociarACaso` / `descartar` | Procedimiento humano |
| `PqrsdService::radicarManual` | Mismo motor que radicación interna |
| Actuaciones `correo_entrada` / `correo_asociado` / `correo_saliente` | Trazabilidad en expediente |
| Formulario público + consulta segura | Canal paralelo; no se reemplaza |
| Respuesta oficial (`elaborada` → `aprobada` → `puesta_disposicion` / `enviada` → `consultada`) | Evidencia de fondo ya modelada |
| `NotificacionPqrsd` + destinos `admin` / `auxiliar_pqrsd` | Alertas vitales/vencimiento |

### 2.4 Qué NO reutilizar como “radicación automática”

- Clasificación `nueva_pqrsd` del clasificador = **sugerencia**; el código ya fuerza `pendiente_revision` hasta confirmación humana.  
- Ruido / ambiguos / no-PQRSD permanecen descartables.  
- Pub/Sub y watch: solo aceleran sync; la verdad sigue siendo ingesta + Message-ID.

---

## 3. Flujo de recepción

### 3.1 Premisa

**No todo mensaje recibido es PQRSD.** El sistema importa, clasifica y **espera decisión humana** salvo asociación inequívoca por hilo/radicado (aún así visible en bandeja correo / expediente).

### 3.2 Pasos (diseño operativo)

1. **Ingreso seguro**  
   - Gmail API (`users.history.list` / recuperación `newer_than`) vía cuenta autorizada y en allow-list.  
   - Transporte TLS; tokens OAuth cifrados (`APP_KEY`); secretos solo en vault/env (`credential_ref`).  
   - Webhook Pub/Sub (fase posterior): validación OIDC + idempotencia en `gmail_pubsub_events`; el job solo dispara sync, no radica.

2. **Identificación única**  
   - Clave primaria de deduplicación: **Message-ID** RFC del mensaje.  
   - Complemento: `gmail` message id en `metadatos` si se requiere reconciliación API.  
   - Hilo: `In-Reply-To` / `References` / `thread_key`.

3. **Anti-duplicados**  
   - Único por cuenta + Message-ID.  
   - Si el mismo Message-ID ya existe en otra cuenta → copia marcada `ignorado_duplicado` (sin reprocesar adjuntos).  
   - Sync incompleto **no avanza** `historyId` (comportamiento actual a conservar).

4. **Revisión por auxiliar (o admin)**  
   - Mensajes en `pendiente_revision`, sugeridos como PQRSD, o ruido dudoso → `/bandeja-correo`.  
   - Acciones: **confirmar nueva PQRSD**, **asociar a radicado**, **descartar** (con motivo).  
   - La Bandeja PQRSD no se altera: solo aparece el expediente tras confirmación/asociación.

5. **Vínculo a expediente o nueva radicación controlada**  
   - Asociación automática sugerida si hay radicado en asunto/cuerpo o hilo previo → `respuesta_caso` + `pqrsd_id`.  
   - Nueva radicación **solo** vía `confirmarNuevaPqrsd` (usuario autenticado, canal `CORREO`, actuación de origen).  
   - Descarte explícito para spam, internos, comerciales, fuera de competencia.

6. **Adjuntos y trazabilidad**  
   - Cada adjunto: disco privado, mime, tamaño, checksum.  
   - Raw EML opcional en disco privado.  
   - Actuación en expediente + auditoría al confirmar/asociar/responder.  
   - Conservar vínculo `mensaje_correo_id` en `datos_adicionales` de la actuación.

### 3.3 Diagrama de estados del mensaje entrante

```
recibido/procesado → clasificacion:
  pendiente_revision ──► (humano) ──► nueva_pqrsd | respuesta_caso | descartado
  ruido ──► (humano puede confirmar o descartar)
  respuesta_caso (auto por hilo/radicado) ──► visible en expediente
  ignorado_duplicado ──► sin efecto operativo
```

---

## 4. Flujo de envío

### 4.1 Distinción de estados (obligatoria)

| Estado | Significado | Evidencia |
|--------|-------------|-----------|
| **Respuesta guardada** | Texto de fondo registrado en expediente (`respuesta_estado` ∈ `elaborada` / `aprobada`) | Actuación `respuesta_fondo` + campos PQRSD |
| **Puesta a disposición** | Disponible en consulta segura (`puesta_disposicion`) | `respuesta_puesta_disposicion_en` + token consulta |
| **Respuesta enviada** | Intento de envío por canal email **aceptado por Gmail API** (`users.messages.send` → Message-ID) | `respuesta_estado=enviada`, `respuesta_enviada_en`, registro `mensajes_correo` saliente |
| **Entrega confirmada** | Evidencia de que el destinatario recibió/abrió | **No garantizada por Gmail API estándar**; solo si existe DSN/MDN o acuse posterior (p. ej. consulta ciudadana `consultada`). No inventar “entregado” desde `send` OK |

### 4.2 Camino A — Reply de hilo (ya diseñado)

1. Auxiliar con `correos.reply` abre mensaje en bandeja correo (preferible ya asociado a PQRSD).  
2. Redacta cuerpo; sistema arma `In-Reply-To` / `References` y asunto con radicado.  
3. `EmailReplyService` → `MailProvider::send`.  
4. Si OK: crea `mensajes_correo` saliente + actuación `correo_saliente` + auditoría.  
5. Si fallo: no crear saliente “exitoso”; error visible; reintento controlado (ver §4.4).

**Importante:** un reply de hilo **no** debe marcar automáticamente la respuesta oficial como enviada salvo un procedimiento explícito futuro (checkbox / acción “enviar respuesta oficial por correo”) que enlace ambos.

### 4.3 Camino B — Envío de la respuesta oficial PQRS (diseño a implementar en fases posteriores)

Sin activar en esta etapa; contrato propuesto:

1. Auxiliar elabora respuesta en expediente (estados actuales).  
2. Validación: longitud mínima, expediente abierto, `expediente_version`, medio_respuesta compatible con email, consentimiento/email del solicitante cuando aplique.  
3. Autorización: usuario con permiso de gestión del caso; opcionalmente segundo paso “aprobar” (`aprobada`) antes de enviar (política Calidad).  
4. Envío: job idempotente que llama `MailProvider::send` desde el buzón canónico institucional (no desde cuenta personal del auxiliar).  
5. Resultado:  
   - Éxito → `respuesta_estado=enviada`, Message-ID saliente, actuación, auditoría.  
   - Fallo → estado permanece `elaborada`/`aprobada`/`puesta_disposicion`; alerta in-app; reintento.  
6. Si el ciudadano eligió **consulta segura**, el envío Gmail es opcional/complementario; la puesta a disposición en portal sigue siendo el canal principal.

### 4.4 Fallos y reintentos sin duplicar

| Mecanismo | Uso |
|-----------|-----|
| Idempotency key de envío | Guardar en metadatos antes del send; si Gmail aceptó pero falló el commit local, reconciliar por Message-ID |
| No reenviar el mismo cuerpo sin nueva acción humana o flag `force` auditado | Evitar doble notificación al ciudadano |
| Cola Laravel + `max_reintentos` | Reintentos con backoff |
| Registro de estado `pendiente` / `enviada` / `fallida` en notificación o mensaje saliente | Visibilidad operativa |
| Alertas SLA independientes de Mail | Ver §5 |

---

## 5. Alertas

### 5.1 Estado actual reutilizable

- Clasificación vital → `SlaNotificationService::notificarVitalInmediato`.  
- Recordatorios / vencido → `evaluarRecordatorios` (scheduler `pqrsd:actualizar-cumplimiento`).  
- Destinatarios configurados: roles **`admin`** y **`auxiliar_pqrsd`** (más legacy por compatibilidad).  
- Persistencia en `notificaciones_pqrsd` aunque el envío SMTP/Gmail falle (`estado` puede ser `omitida` / `fallida` / `enviada`).  
- UI operativa: listado de **Vencimientos**, semáforo en Bandeja PQRSD, bloque de notificaciones en expediente.

### 5.2 Diseño con Workspace

| Canal | Uso | Dependencia Gmail |
|-------|-----|-------------------|
| **In-app (obligatorio)** | Banner/listado de vitales y vencidos para admin + 2 auxiliares | **Ninguna** |
| **Correo de alerta** | Réplica informativa a los mismos destinatarios | Opcional; usa Mail Laravel / Gmail send |
| **Ops health** | Contador `sla_alerts_24h`, fallos de sync | Informativo |

### 5.3 Regla de resiliencia (requisito de diseño)

> Una integración de correo caída, OAuth expirado o Pub/Sub inactivo **no debe impedir** que admin y auxiliares vean alertas críticas en la aplicación.

Implicaciones:

1. Crear siempre el registro `notificaciones_pqrsd` **antes** o **independiente** del éxito de `Mail::raw` / Gmail send (ya parcialmente así).  
2. La pantalla Vencimientos / Bandeja debe leer estado de cumplimiento y notificaciones de BD, no del buzón.  
3. Fallo de envío de alerta → marcar notificación `fallida` + log; no silenciar el caso en UI.  
4. No acoplar el job de sync Gmail al cálculo SLA.

### 5.4 Destinatarios operativos

Informar siempre a:

1. Administrador PQRSD (`admin`).  
2. Auxiliar PQRSD 1 y 2 (`auxiliar_pqrsd`).

Sin reintroducir asignación obligatoria de responsable: la bandeja compartida implica alerta a **todos** los operadores activos de esos roles.

---

## 6. Seguridad

### 6.1 Alternativas de autenticación (compatibles Workspace)

| Alternativa | Privilegios | Recomendación |
|-------------|-------------|---------------|
| **OAuth 2.0 por buzón piloto** | Solo bandejas consentidas | **Preferida** para piloto y primera producción |
| **Service Account + Domain-Wide Delegation** | Amplio (impersonación) | Solo con acta SSI + Workspace Admin; `GMAIL_SERVICE_ACCOUNT_ENABLED=false` por defecto |
| IMAP con contraseña de app | Menor control API | No preferido si ya existe driver `google_api` |

### 6.2 Credenciales requeridas (nombres, no valores)

Documentar en vault institucional; **no** solicitar ni pegar secretos en esta fase:

- `GMAIL_OAUTH_CLIENT_ID`  
- `GMAIL_OAUTH_CLIENT_SECRET`  
- `GMAIL_OAUTH_REDIRECT_URI` (HTTPS prod)  
- `GMAIL_PILOT_MAILBOX` / `GMAIL_ALLOWED_MAILBOXES`  
- Opcional Pub/Sub: topic, audience OIDC, service account push  
- Opcional DWD: JSON de service account vía `credential_ref` → env  
- `APP_KEY` estable (cifra tokens OAuth; no regenerar en caliente)

### 6.3 Permisos mínimos de aplicación (Spatie)

| Permiso | Quién | Uso Workspace |
|---------|-------|---------------|
| `correos.manage` | admin | Cuentas, OAuth, inventario, ops |
| `correos.review` | admin (+ auxiliar según política) | Clasificar mensajes |
| `correos.reply` | admin (+ auxiliar según política) | Envío hilo |
| `pqrsd.*` operativos | auxiliar / admin | Expediente; sin cambio |

### 6.4 Alcances (scopes) API mínimos

Solicitar solo lo necesario, con aprobación SSI:

| Scope | Propósito |
|-------|-----------|
| `https://www.googleapis.com/auth/gmail.readonly` | Recepción / sync |
| `https://www.googleapis.com/auth/gmail.send` | Envío |

Evaluar con SSI si hace falta `gmail.modify` (p. ej. marcar leído / etiqueta). **No** solicitar `mail.google.com` (scope total).

### 6.5 Gestión de tokens

- Access/refresh tokens cifrados en `gmail_oauth_tokens`.  
- Refresh automático en cliente; si revocado → `estado_conexion=error` / `pendiente_credenciales`, sync detenido, alerta ops.  
- Allow-list de buzones; rechazo si dirección no autorizada.  
- Logs sin cuerpos clínicos ni tokens.

### 6.6 Protección de datos personales

- Adjuntos y EML en disco no público.  
- Minimización: no indexar cuerpos en herramientas externas.  
- Radicación pública y anónimos siguen reglas Ley 1581 ya implementadas.  
- Correos importados pueden contener PHI: acceso solo usuarios con permiso review/reply/view expediente.  
- Retención alineada a política SSI (definir acta; no inventar plazos aquí).

### 6.7 Errores, auditoría y recuperación

| Tema | Diseño |
|------|--------|
| Errores API | Clasificar 401/403 (auth), 404 history (reconciliación), 429 (backoff), 5xx (reintento) |
| Auditoría | `sincronizaciones_correo`, actuaciones, `auditoria` PQRSD, `gmail_pubsub_events` |
| Recuperación sync | No avanzar cursor si incompleto; job de reconcile `newer_than:7d` |
| Recuperación envío | Reintento idempotente; operador puede reenviar con traza |
| Fallo total Gmail | Operación PQRSD continúa (público, bandeja, SLA, consulta segura); procedimiento transitorio Calidad |

---

## 7. Dependencias institucionales

| ID | Dependencia | Responsable | Bloquea |
|----|-------------|-------------|---------|
| D01 | Inventario clasificado de buzones (CSV firmado) | Workspace Admin | OAuth masivo |
| D02 | Proyecto GCP + Gmail API | Cloud Admin | Cualquier piloto |
| D03 | OAuth client + consent + buzón piloto ficticio | Workspace + TI | Sync/send real |
| D04 | Scopes y base legal acceso a correos | SSI | Activación prod |
| D05 | Pub/Sub topic + push HTTPS | Cloud + Infra | Notificaciones push (no bloquea sync polling) |
| D06 | Dominio HTTPS productivo `APP_URL` | Infra | Redirect OAuth real |
| D07 | Vault de secretos | SSI / TI | Credenciales |
| D08 | Política: ¿auxiliar revisa correo? | Calidad + SSI | Asignación final de `correos.review/reply` |
| D09 | Canal de alertas (solo in-app vs + email) | Calidad + TI | Expectativa operativa |
| D10 | UAT con datos ficticios | Calidad | Acta parcial |

Referencias: `docs/fase4-dependencias-institucionales.md`, `docs/guia-admin-google-workspace.md`, `docs/ops/gmail-workspace.md`.

---

## 8. Plan de pruebas

Ninguna de estas pruebas se ejecuta con credenciales reales en esta etapa de planificación.

### 8.1 Ya existentes (conservar / ampliar después)

- `GmailApiProviderTest` / `FakeGmailApiClient` — sin red.  
- `GmailPubSubAndOAuthTest` — webhook e idempotencia.  
- `CorreoRecepcionTest` — ingesta, dedupe, revisión.  
- `SeguridadProduccionTest` — checklist prod.  
- `pqrsd:gmail-pilot-status` — gate de piloto (debe seguir en NO hasta credenciales).

### 8.2 Matriz de pruebas para fases futuras

| ID | Escenario | Tipo | Criterio de éxito |
|----|-----------|------|-------------------|
| T01 | Sync piloto importa 1 mensaje ficticio | Manual + Feature | Fila en `mensajes_correo`, adjunto con checksum |
| T02 | Re-sync no duplica Message-ID | Automático | `duplicado=true`, sin segundo adjunto |
| T03 | Mensaje no-PQRSD → descartar | Manual UAT | `descartado`, sin PQRSD nuevo |
| T04 | Confirmar nueva PQRSD | Manual UAT | Radicado canal CORREO + actuación origen |
| T05 | Asociar a radicado existente | Manual UAT | `pqrsd_id` + actuación |
| T06 | Reply hilo | Manual | Saliente con Message-ID; actuación |
| T07 | Send falla (token revocado) | Manual/Fake | Sin marcar `enviada` oficial; error visible |
| T08 | Alerta vital con Mail caído | Automático | Registro `notificaciones_pqrsd` + visible en UI vencimientos |
| T09 | Pub/Sub duplicado | Automático | Un solo procesamiento efectivo |
| T10 | historyId expirado | Fake/Manual | Reconciliación sin pérdida/duplicado masivo |
| T11 | Auxiliar sin `correos.manage` | Automático | 403 en OAuth/cuentas |
| T12 | Respuesta oficial vs consulta segura | UAT | Estados `puesta_disposicion` / `enviada` / `consultada` coherentes |

### 8.3 Criterio de salida del piloto

`php artisan pqrsd:gmail-pilot-status` → listo **y** acta Calidad con T01–T08 en buzón ficticio, sin datos clínicos reales.

---

## 9. Fases de implementación

| Fase | Nombre | Actividades | Activa Gmail real | Cambia operación auxiliar Bandeja PQRSD |
|------|--------|-------------|-------------------|----------------------------------------|
| **0** | Planificación (esta) | Documento, dependencias, inventario | No | No |
| **1** | Preparación institucional | CSV buzones, GCP, OAuth client en vault, HTTPS | No | No |
| **2** | Piloto OAuth lectura | Un buzón ficticio, sync polling, revisión humana | Solo piloto | No (solo usa bandeja correo si tiene review) |
| **3** | Piloto envío | `gmail.send` replies de prueba; evidencias Message-ID | Solo piloto | No |
| **4** | Respuesta oficial por correo | Enlace expediente ↔ send idempotente; estados explícitos | Piloto | No cambia Bandeja ni SLA; añade acción de envío controlada |
| **5** | Pub/Sub + watch | Push + renew; polling como respaldo | Piloto/prod acotado | No |
| **6** | Ampliación buzones | Allow-list gradual según inventario | Sí, controlado | No |
| **7** | Operación asistida | Fin régimen transitorio Calidad | Sí | Procedimiento actualizado; misma Bandeja compartida |

**Reglas transversales a todas las fases:**

- No alterar reglas SLA ni meta 95 %.  
- No restaurar asignación obligatoria.  
- No sustituir radicación pública/QR.  
- Driver default permanece `mock` hasta decisión explícita de TI (`PQRSD_MAIL_DRIVER=google_api`).

---

## 10. Riesgos y requisitos previos

### 10.1 Riesgos

| Riesgo | Impacto | Mitigación |
|--------|---------|------------|
| Radicar automáticamente correo no-PQRSD | Alto | Confirmación humana obligatoria (ya en código) |
| Duplicar respuestas al ciudadano | Alto | Idempotencia Message-ID + estados de envío |
| Confundir `send` OK con entrega | Medio | Vocabulario de estados (§4.1); usar `consultada` como acuse portal |
| PHI en buzón piloto | Alto | Buzón ficticio; prohibir correos clínicos en piloto |
| Token/APP_KEY comprometidos | Crítico | Vault; rotación con procedimiento; no loguear secretos |
| Auxiliar sobrecargado con ruido de correo | Medio | Clasificador ruido + descarte; posible restringir review |
| Dependencia total de Gmail para alertas | Crítico | Alertas in-app independientes (§5.3) |
| Grupos Google sin bandeja API | Medio | Inventario `buzon_canonico` obligatorio |
| Regenerar `APP_KEY` | Alto | Invalida tokens cifrados; documentado en ops |

### 10.2 Requisitos previos (checklist)

- [ ] Inventario de buzones clasificado y aprobado  
- [ ] Acta SSI de scopes y acceso a correos  
- [ ] OAuth client en vault (sin secretos en Git/chat)  
- [ ] Buzón piloto sin datos reales  
- [ ] `APP_URL` HTTPS definitivo para redirect  
- [ ] Scheduler + worker productivos (sync y alertas)  
- [ ] `pqrsd:gmail-pilot-status` en verde  
- [ ] UAT T01–T08 firmado por Calidad  
- [ ] Procedimiento transitorio actualizado / cierre parcial  

### 10.3 Resultado esperado de esta etapa

Queda definido **cómo** se integrará Workspace reutilizando el módulo de correo, la revisión humana, la radicación controlada, la respuesta oficial con estados explícitos y las alertas resilientes.

**No** se activa ningún servicio externo ni se modifica el comportamiento actual del sistema: el driver sigue en mock/laboratorio hasta completar las fases 1–2 con autorización institucional.

---

## Referencias internas

- `docs/ops/gmail-workspace.md`  
- `docs/google-workspace-configuracion-real.md`  
- `docs/guia-admin-google-workspace.md`  
- `docs/fase4-dependencias-institucionales.md`  
- `docs/procedimiento-operativo-transitorio-calidad.md`  
- `docs/auditoria-integral-pqrsd-comite.md`  
- Código clave: `EmailIngestionService`, `EmailReviewService`, `EmailReplyService`, `EmailClassifier`, `GoogleGmailMailProvider`, `SlaNotificationService`, `config/pqrsd_correo.php`

---

*Plan elaborado sin activar Gmail API, OAuth ni Pub/Sub, y sin alterar Bandeja compartida, SLA, indicadores ni roles.*
