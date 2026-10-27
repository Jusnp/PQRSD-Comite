# Google Workspace / Gmail API — configuración real

## Hallazgo técnico (inspección)

El driver `google_api` usa **Gmail API REST** (`https://gmail.googleapis.com/gmail/v1`) mediante `HttpGmailApiClient`.  
**No** usa IMAP cuando `tipo_integracion=google_api`. IMAP solo aplica al driver `imap`.

Pruebas Fake (`FakeGmailApiClient`) no equivalen a integración real.

## Tipos de dirección (menteplena.com.co)

Las filas en `cuentas_correo` **no deben asumirse como 11 buzones independientes**.

| Campo | Uso |
|-------|-----|
| `tipo_buzon=individual` | Usuario Gmail real; OAuth o DWD sobre esa identidad |
| `tipo_buzon=alias` | Misma bandeja que `buzon_canonico`; no abrir sesión del alias |
| `tipo_buzon=grupo` | Google Group: **no** tiene API Gmail de buzón; requiere miembro/reenvío a buzón canónico |
| `tipo_buzon=reenvio` | Correo llega a central; usar `central_forward` / `buzon_canonico` |
| `tipo_buzon=desconocido` | Estado actual del seeder hasta confirmación Workspace Admin |

**Acción humana:** el administrador de Google Workspace debe clasificar cada dirección del inventario y completar `tipo_buzon` / `buzon_canonico`.

## Autorización (prioridad OAuth)

Preferido: **OAuth 2.0 por buzón piloto** (menor privilegio).

1. Crear OAuth Client (Web) en Google Cloud.
2. Redirect: `{APP_URL}/oauth/gmail/callback`
3. Scopes mínimos: `gmail.readonly` + `gmail.send` (ajustar con SSI).
4. Variables: `GMAIL_OAUTH_CLIENT_ID`, `GMAIL_OAUTH_CLIENT_SECRET`, `GMAIL_OAUTH_REDIRECT_URI`
5. `GMAIL_PILOT_MAILBOX` = una sola cuenta autorizada.
6. En la UI: `cuentas-correo/{id}/oauth` (permiso `correos.manage`).
7. Tokens quedan **cifrados** en `gmail_oauth_tokens` (Crypt/APP_KEY). No aparecen en logs.

Domain-Wide Delegation (`GMAIL_SERVICE_ACCOUNT_ENABLED`) solo con aprobación explícita del admin Workspace. Deshabilitada por defecto.

## Pub/Sub

Flujo: Gmail `users.watch` → Pub/Sub → `POST /webhooks/gmail-pubsub` → job → `EmailSyncService`.

- Audience OIDC: `GMAIL_PUBSUB_PUSH_AUDIENCE`
- Idempotencia por `message_id` en `gmail_pubsub_events`
- Renovación watch: `pqrsd:gmail-renew-watch` (diario 03:15 America/Bogota)
- Cursor/`historyId` **no avanza** si la sincronización tiene errores

## Piloto (no masivo)

```bash
php artisan pqrsd:gmail-pilot-status
```

Hasta tener Client ID/Secret + buzón piloto + token OAuth, el estado es **BLOQUEADO**.

Checklist piloto (cuando existan permisos):

1. Autenticación OAuth real  
2. Enviar un correo de prueba al piloto  
3. `pqrsd:sync-correos --sync --cuenta=ID`  
4. Verificar mensaje, adjuntos, metadatos  
5. Asociar/crear radicado  
6. Respuesta y evidencia de **aceptación Gmail** (no confundir con entrega SMTP final)  
7. No importar histórico masivo  

## Estado

| Ítem | Clasificación |
|------|---------------|
| Driver REST + Fake tests | PREPARADO / tests VERIFICADOS (fake) |
| OAuth + Pub/Sub push + idempotencia | PREPARADO |
| Prueba real Workspace | BLOQUEADO (credenciales/autorización) |
