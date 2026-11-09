# Integración Google Workspace / Gmail API

La conexión real sigue pendiente. Este despliegue no activa buzones ni cambia el correo de producción a Gmail.

## Alcance aprobado

- OAuth web interno, un cliente por el proyecto de Google Cloud.
- Alcance único: `https://www.googleapis.com/auth/gmail.modify`.
- No pedir `gmail.send` ni el alcance total de la cuenta.
- La cuenta de servicio permanece apagada: `GMAIL_SERVICE_ACCOUNT_ENABLED=false`.
- Compose de producción sigue forzando `PQRSD_MAIL_DRIVER=mock` y `MAIL_MAILER=log`.

## Variables

Los valores van en el entorno del servidor, fuera de Git. Nombres y procedimiento: `docs/guia-admin-google-workspace.md`.

Durante el primer piloto, `GMAIL_PILOT_MAILBOX` y `GMAIL_ALLOWED_MAILBOXES` contienen solo el buzón autorizado. Una lista vacía rechaza OAuth en cualquier entorno y rechaza la API de Gmail en producción.

## Operación

No arrancar `queue:work` ni `schedule:run` hasta la activación explícita del buzón. El programador ya define sincronización, watch y respaldo, pero Compose no lo ejecuta.

OAuth puede completarse con el buzón inactivo. Sync, la prueba de conexión y el envío siguen apagados hasta marcar la cuenta como activa.

## Pruebas locales

Las pruebas Feature usan clientes falsos y no abren red. No declarar recepción ni envío real sin una ventana autorizada.
