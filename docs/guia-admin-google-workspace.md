# Guía — Administrador Google Workspace (clasificación y piloto OAuth)

Dominio: `menteplena.com.co`  
Aplicación: PQRSD Mente Plena (Laravel)

## 1. Clasificar cada dirección

En [Google Admin Console](https://admin.google.com):

### Usuario / buzón individual
1. Directory → Users → buscar la dirección.  
2. Si existe como usuario con licencia: `tipo_buzon=individual`.  
3. `buzon_canonico` = la misma dirección.

### Alias
1. En el usuario → Account → Alternate email addresses / Aliases.  
2. Si la dirección del inventario aparece como alias: `tipo_buzon=alias`.  
3. `buzon_canonico` = usuario primario (el que tiene la bandeja real).  
4. La integración OAuth/API se hace sobre el **canónico**, registrando la dirección original como canal.

### Grupo de Google
1. Directory → Groups → buscar.  
2. Si es grupo: `tipo_buzon=grupo`.  
3. Revisar miembros y **delivery** (quién recibe el correo).  
4. `buzon_canonico` = buzón miembro o casilla que efectivamente almacena mensajes.  
5. Gmail API **no** abre un “buzón de grupo” como usuario; no conectar el grupo directo.

### Reenvío / routing
1. Apps → Google Workspace → Gmail → Routing / Compliance / Default routing.  
2. Si hay forward a otra casilla: `tipo_buzon=reenvio`.  
3. Documentar destino en `buzon_canonico` y observaciones.

### Plantilla
- Archivo: `docs/templates/inventario-buzones-pqrsd.csv`  
- O exportar desde la app: **Cuentas de correo → Exportar inventario CSV**  
- Valores `tipo_buzon`: `individual|alias|grupo|reenvio|desconocido|otro`  
- Valores `estado_autorizacion`: `pendiente|autorizado|rechazado|no_aplica`  
- **No inventar** clasificaciones: dejar `desconocido` hasta verificar.

Importar el CSV completado en la misma pantalla (no crea direcciones nuevas).

## 2. Preparar piloto OAuth (preferido vs Domain-Wide Delegation)

### Google Cloud
1. Crear o seleccionar proyecto GCP institucional.  
2. APIs & Services → Enable **Gmail API**.  
3. Google Auth Platform / OAuth consent screen:  
   - Tipo según política (Internal si Workspace lo permite).  
   - App name: PQRSD Mente Plena.  
4. Create OAuth Client ID → Web application.  
5. Authorized redirect URI exacta:
   - `https://<DOMINIO-PQRSD>/oauth/gmail/callback`  
   - En local autorizado solo si TI lo aprueba: `http://pqrsd.test/oauth/gmail/callback`  
6. Alcance único en la pantalla de consentimiento y en Laravel: `https://www.googleapis.com/auth/gmail.modify`.
   Ese alcance cubre lectura, inserción en la bandeja y envío. No agregar `gmail.send` ni el alcance total de la cuenta: el cliente OAuth de este proyecto solo tiene `gmail.modify`.
7. Revisar si Google clasifica estos scopes como **sensitive/restricted** para el tipo de app y completar verificación si aplica.
8. Entregar a TI (vault): Client ID y Client Secret. **No por chat ni Git.**

### Primer buzón: callcenter@menteplena.com.co

Google Admin confirmó que esa dirección es un usuario real, activo y con Gmail. No es un grupo. La fila local permanece inactiva, con tipo `desconocido` y verificación `pendiente`, hasta que el perfil de OAuth coincida. El middleware `cuenta.activa` exige un funcionario activo, no un buzón activo.

1. Un funcionario con `correos.manage` puede iniciar OAuth aunque el buzón esté inactivo.
2. OAuth no marca el buzón como activo ni descarga correo. Si el perfil coincide, fija `sincronizar_desde` en ese momento.
3. Activar el buzón es un paso posterior, en la ficha, después de revisar la autorización.
4. Mientras `activo` esté en falso, Sync, la prueba de conexión, el worker y el envío no consultan ese buzón.
5. `GMAIL_ALLOWED_MAILBOXES` debe listar únicamente las direcciones autorizadas. En producción, si queda vacío, la API se rechaza.
6. El token queda cifrado en `gmail_oauth_tokens`. El secreto del cliente queda en el entorno, no en la base ni en Git.
7. Varios buzones se registran como cuentas distintas. La radicación web usa la única cuenta activa con `recibe_radicacion_web`. Si el expediente tiene aseguradora y esa organización tiene su propia cuenta marcada, usa esa. Si hay dos candidatos del mismo alcance, no elige.
8. Un buzón con aseguradora no asocia solo un expediente de otra aseguradora.

### Worker y programador

Hacen falta dos procesos aparte del sitio web. No los active en el VPS hasta autorizar el despliegue.

- Worker: `php artisan queue:work --sleep=3 --tries=3`
- Programador: `php artisan schedule:work` o cron `* * * * * php artisan schedule:run`

Sin worker, el aviso de radicación queda en cola. Sin programador, no hay sincronización ni renovación del watch.

### Workspace
1. Elegir **un** usuario piloto de pruebas (idealmente sin correo clínico real).  
2. Autorizar el consentimiento OAuth para ese usuario.  
3. Confirmar que no hay políticas que bloqueen apps de terceros no verificadas (o excepciones controladas).

### Laravel (TI)
```env
GMAIL_PILOT_MAILBOX=callcenter@menteplena.com.co
GMAIL_ALLOWED_MAILBOXES=callcenter@menteplena.com.co
GMAIL_OAUTH_CLIENT_ID=...
GMAIL_OAUTH_CLIENT_SECRET=...
GMAIL_OAUTH_REDIRECT_URI="${APP_URL}/oauth/gmail/callback"
PQRSD_MAIL_DRIVER=google_api
```
Luego: Cuentas → OAuth en la fila del piloto → `php artisan pqrsd:gmail-pilot-status`

### Domain-Wide Delegation
Solo si OAuth por buzón es insuficiente **y** hay aprobación expresa SSI + Workspace. Mantener `GMAIL_SERVICE_ACCOUNT_ENABLED=false` por defecto.

## 3. Pub/Sub (después del piloto OAuth)

1. Crear topic Pub/Sub.  
2. Dar permiso de publicación a `gmail-api-push@system.gserviceaccount.com` (servicio Gmail).  
3. Push subscription → URL `https://<DOMINIO>/webhooks/gmail-pubsub` con OIDC.  
4. Configurar `GMAIL_PUBSUB_TOPIC`, `GMAIL_PUBSUB_PUSH_AUDIENCE`, `GMAIL_PUBSUB_SERVICE_ACCOUNT`.  
5. `php artisan pqrsd:gmail-renew-watch`  
6. Validar evento real en tabla `gmail_pubsub_events` (no solo tests Fake).

## 4. Prohibiciones

- No compartir contraseñas ni códigos 2FA.  
- No conectar los 11 buzones en el piloto.  
- No cambiar routing productivo sin ticket.  
- No importar histórico masivo.
