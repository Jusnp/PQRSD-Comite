# Integración Google Workspace / Gmail API

Estado del código: driver `google_api` implementado con cliente HTTP, historyId, recuperación 404, watch renovable y mocks de prueba. **La conexión real queda pendiente de credenciales y autorización institucional.**

## Requisitos externos (administrador Google / Cloud)

1. Proyecto en Google Cloud con Gmail API habilitada.
2. Credenciales:
   - **Recomendado institucional:** Service Account + Domain-Wide Delegation (scopes `gmail.readonly`, `gmail.send`, `gmail.modify` según política).
   - Alternativa: OAuth 2.0 de usuario de servicio controlado.
3. Pub/Sub topic para `users.watch` (push a endpoint interno o cola).
4. Lista blanca de buzones en `GMAIL_ALLOWED_MAILBOXES`.
5. Cada `cuentas_correo.credential_ref` apunta a una variable de entorno (nunca secreto en BD).

## Variables

Ver `.env.production.example`:

- `PQRSD_MAIL_DRIVER=google_api`
- `GMAIL_PUBSUB_TOPIC=projects/.../topics/...`
- `GMAIL_SERVICE_ACCOUNT_ENABLED=true` solo tras validar DWD
- `MAIL_CRED_...=` token JSON o ruta a JSON de service account

## Operación

```bash
php artisan pqrsd:sync-correos --sync --cuenta=ID
php artisan pqrsd:gmail-renew-watch
```

La renovación de watch está programada diariamente a las 03:15 America/Bogota.

## Pruebas locales

Las pruebas Feature usan `FakeGmailApiClient` (sin red). No declarar recepción/envío real sin ambiente autorizado.
