# Despliegue de PQRSD en el VPS

Este procedimiento prepara un entorno nuevo. No copia expedientes, no usa MariaDB y no modifica Authentik. El Compose de desarrollo (`compose.dev.yaml`) sigue siendo el de la máquina local.

Imágenes fijadas: PHP 8.3.26-FPM, Nginx 1.27.5 y PostgreSQL 16.10. El código de aplicación entra en la imagen. Los adjuntos privados viven en el volumen `pqrsd_prod_storage`, montado en `storage`, y sobreviven a una imagen nueva.

## 1. Requisitos del VPS

Datos ya confirmados de este servidor, sin volver a entrar:

- Ubuntu 24.04.4 LTS.
- Docker Engine 29.1.3.
- Compose v2 ya está instalado. Los comandos de este documento usan `docker compose`. No use `docker-compose` 1.29.2 para este proyecto y no desinstale esa serie: otros sistemas pueden depender de ella.
- Apache2 publica los puertos 80 y 443.
- SISPAM usa el puerto 8080. PQRSD no debe usarlo.
- El puerto 8081 estaba libre. Nginx de PQRSD se publica solo en `127.0.0.1:8081`.
- Authentik ya funciona en el mismo VPS: `https://auth.comitedeestudiosmedicos.com.co`.
- Dominio de PQRSD: `https://pqrsd.comitedeestudiosmedicos.com.co`.

Hace falta, además, espacio para tres contenedores y un directorio de respaldos fuera de Docker. La zona de los contenedores es `America/Bogota`. PostgreSQL de PQRSD es una base nueva, en su propia red, sin puerto hacia el anfitrión y sin relación con las bases de SISPAM o Authentik.

## 2. Directorios

En el servidor, fuera de otros proyectos:

```bash
sudo mkdir -p /opt/pqrsd/app /var/backups/pqrsd
sudo chown --recursive "$USER":"$USER" /opt/pqrsd /var/backups/pqrsd
```

Copie el código o clone el repositorio privado en `/opt/pqrsd/app`. No copie `.env`, `.env.docker`, volcados ni adjuntos de la máquina de desarrollo.

## 3. Imágenes

Desde `/opt/pqrsd/app`, etiquete con el commit que se va a ejecutar:

```bash
export PQRSD_IMAGE_TAG="$(git rev-parse --short HEAD)"
docker build -f Dockerfile.prod --target app -t "pqrsd-prod-app:${PQRSD_IMAGE_TAG}" .
docker build -f Dockerfile.prod --target web -t "pqrsd-prod-web:${PQRSD_IMAGE_TAG}" .
```

La imagen de aplicación instala Composer con `--no-dev`, compila los assets y no lleva PHPUnit. Conserve la etiqueta anterior hasta comprobar la nueva.

## 4. Secretos

```bash
cp .env.production.example .env.production
php -r "echo 'base64:'.base64_encode(random_bytes(32));"
```

Escriba el resultado en `APP_KEY` de `.env.production`. Genérela una sola vez. Si más adelante hay sesiones cifradas u otros datos cifrados, no la regenere.

Complete en ese mismo archivo, sin pegarlos en Git:

- `POSTGRES_PASSWORD`: clave del propietario de la base. Sirve para administrar y para `pg_dump`. No es la clave de la aplicación.
- `PQRSD_APP_DB_PASSWORD`: otra clave, solo del rol `pqrsd_app`, distinta de la anterior y de las claves locales.
- `AUTHENTIK_CLIENT_ID` y `AUTHENTIK_CLIENT_SECRET` del cliente que ya existe. No cree otro.

La plantilla ya deja:

- `APP_URL` y `PQRSD_PUBLIC_URL` en `https://pqrsd.comitedeestudiosmedicos.com.co`.
- `AUTHENTIK_BASE_URL` en `https://auth.comitedeestudiosmedicos.com.co`.
- `AUTHENTIK_REDIRECT_URI` en `https://pqrsd.comitedeestudiosmedicos.com.co/auth/authentik/callback`.
- `PQRSD_HTTP_PORT=8081`.

Deje vacíos `TRUSTED_PROXIES` y el token de Authentik. El correo queda en `log`, Gmail apagado y el aprovisionamiento apagado. Compose vuelve a fijar esos tres aunque el archivo diga otra cosa.

Restrinja el archivo:

```bash
chmod 600 .env.production
```

## 5. PostgreSQL nuevo

El proyecto Compose se llama `pqrsd-prod`. La red es `pqrsd_prod_net`. Los volúmenes son `pqrsd_prod_data` y `pqrsd_prod_storage`. El script `docker/postgres/init-prod/01-app-role.sh` corre solo cuando el volumen de datos está vacío. Crea el rol `pqrsd_app` con `NOSUPERUSER`, `NOCREATEDB` y `NOCREATEROLE`, le entrega el esquema `public` y no crea usuarios de prueba.

No monte `docker/postgres/init`. Ese directorio pertenece al entorno de desarrollo.

```bash
docker compose --env-file .env.production -f compose.prod.yaml up -d --build
docker compose --env-file .env.production -f compose.prod.yaml ps
```

Nginx queda en `127.0.0.1:8081`. PHP-FPM y PostgreSQL no tienen puerto publicado. El puerto 8080 permanece para SISPAM.

Compruebe el privilegio, sin mostrar claves:

```bash
docker compose --env-file .env.production -f compose.prod.yaml exec -T postgres \
  psql -U pqrsd_owner -d pqrsd_prod -tAc "SELECT rolname, rolsuper, rolcreatedb FROM pg_roles WHERE rolname = 'pqrsd_app'"
```

La columna de superusuario debe ser falsa.

## 6. Migraciones de la base nueva

Solo cuando `pqrsd_prod` esté vacía y el volumen sea el de este proyecto:

```bash
docker compose --env-file .env.production -f compose.prod.yaml exec -T app \
  php artisan migrate --force --no-interaction
docker compose --env-file .env.production -f compose.prod.yaml exec -T app \
  php artisan db:seed --class=RolesAndPermissionsSeeder --force --no-interaction
docker compose --env-file .env.production -f compose.prod.yaml exec -T app \
  php artisan db:seed --class=CatalogosSeeder --force --no-interaction
```

No ejecute `db:seed` sin `--class` si `APP_ENV` no fuera `production`: en cualquier otro entorno también carga cuentas de laboratorio. Compose fija `APP_ENV=production`, y en producción el seeder general se limita a roles y catálogos. Aun así, los dos comandos anteriores son los que deben usarse.

No ejecute `migrate:fresh`, `db:wipe` ni estos comandos contra `pqrsd_postgres_dev` o MariaDB.

Verifique que no hay expedientes:

```bash
docker compose --env-file .env.production -f compose.prod.yaml exec -T postgres \
  psql -U pqrsd_owner -d pqrsd_prod -tAc "SELECT count(*) FROM pqrsd"
```

El resultado debe ser `0`.

## 7. Validación

```bash
curl --silent --output /dev/null --write-out "%{http_code}\n" http://127.0.0.1:8081/up
curl --silent --output /dev/null --write-out "%{http_code}\n" http://127.0.0.1:8081/publico/consulta
```

`/up` debe responder 200. La consulta pública debe responder 200. Esa comprobación es directa al contenedor, sin pasar por Apache. El formulario aún no debe recibir expedientes reales.

`pqrsd:check-production --strict` exige correo real y OAuth de Gmail. Con el correo apagado, esas líneas quedan en rojo a propósito. No encienda Gmail para volver verde el chequeo.

No hay worker ni `schedule:run`. El programador del código también lanzaría sincronización de correo, Gmail y un respaldo con sintaxis MySQL. No lo active en este despliegue.

## 8. Authentik

Esta fase no modifica Authentik. Cuando se autorice, un administrador añade en el cliente OIDC existente, sin crear otro, la URI:

`https://pqrsd.comitedeestudiosmedicos.com.co/auth/authentik/callback`

Las rutas de la aplicación son `/auth/authentik/redirect` y `/auth/authentik/callback`. El aprovisionamiento sigue apagado. Una base nueva no tiene funcionarios: un ingreso correcto sin usuario local no abre sesión. El alta del primer administrador es un paso aparte. No se importan los usuarios ni los expedientes de desarrollo.

Hasta registrar la URI, el personal no puede entrar desde el dominio público. El formulario y la consulta ciudadana no usan Authentik.

## 9. Respaldos

Use `pg_dump` con el propietario de la base. No use `php artisan pqrsd:backup` para PostgreSQL.

```bash
docker compose --env-file .env.production -f compose.prod.yaml exec -T postgres \
  pg_dump -U pqrsd_owner -d pqrsd_prod --format=custom --file=/tmp/pqrsd_prod.dump
docker compose --env-file .env.production -f compose.prod.yaml cp \
  postgres:/tmp/pqrsd_prod.dump /var/backups/pqrsd/pqrsd_prod.dump
docker compose --env-file .env.production -f compose.prod.yaml exec -T postgres \
  rm /tmp/pqrsd_prod.dump
```

Restauración de prueba, en una base distinta creada para ese ensayo y después eliminada:

```bash
docker compose --env-file .env.production -f compose.prod.yaml exec -T postgres \
  psql -U pqrsd_owner -d postgres -c "CREATE DATABASE pqrsd_prod_restore_check"
docker compose --env-file .env.production -f compose.prod.yaml cp \
  /var/backups/pqrsd/pqrsd_prod.dump postgres:/tmp/pqrsd_prod.dump
docker compose --env-file .env.production -f compose.prod.yaml exec -T postgres \
  pg_restore -U pqrsd_owner -d pqrsd_prod_restore_check --no-owner /tmp/pqrsd_prod.dump
docker compose --env-file .env.production -f compose.prod.yaml exec -T postgres \
  psql -U pqrsd_owner -d pqrsd_prod_restore_check -tAc "SELECT count(*) FROM pqrsd"
docker compose --env-file .env.production -f compose.prod.yaml exec -T postgres \
  psql -U pqrsd_owner -d postgres -c "DROP DATABASE pqrsd_prod_restore_check"
```

No restaure ese archivo encima de `pqrsd_prod` si la base de servicio ya tiene datos que quiera conservar.

## 10. Actualización y reversión

1. Respalde con `pg_dump` antes de cambiar la imagen.
2. Construya y etiquete la imagen nueva sin borrar la etiqueta anterior.
3. Cambie `PQRSD_IMAGE_TAG` en `.env.production`.
4. `docker compose --env-file .env.production -f compose.prod.yaml up -d --no-deps app web`
5. Si hay migraciones nuevas y autorizadas, ejecute `php artisan migrate --force` solo sobre `pqrsd_prod`.
6. Repita la validación de `/up` y de la consulta pública.

El volumen de adjuntos no se recrea con ese comando. Para volver atrás, fije otra vez la etiqueta anterior y levante `app` y `web`. No use `down -v`: borra la base y los adjuntos. `stop` detiene los contenedores y conserva los volúmenes.

Si una migración ya se aplicó, volver la imagen atrás no deshace el esquema. Revise ese caso antes de migrar.

## Apache, DNS y certificado

El archivo `deploy/apache/pqrsd.conf.example` es solo un ejemplo. No está instalado y esta fase no recarga Apache.

Cuando se autorice el alta del sitio:

1. DNS. Cree el nombre `pqrsd.comitedeestudiosmedicos.com.co` hacia la misma dirección pública que ya usan los sitios de este VPS. No hace falta publicar el puerto 8081: el navegador entra por 443. No cambie los nombres de SISPAM ni de Authentik.
2. Espere a que el nombre resuelva a ese servidor.
3. Emita el certificado solo para `pqrsd.comitedeestudiosmedicos.com.co`. La ruta prevista en el ejemplo es `/etc/letsencrypt/live/pqrsd.comitedeestudiosmedicos.com.co/`. Use un método que no reescriba los virtual hosts existentes. `certbot --apache` puede editar otros sitios; prefiera emitir el certificado y dejar el virtual host de PQRSD como único archivo nuevo.
4. Copie el ejemplo a `/etc/apache2/sites-available/pqrsd.conf`.
5. Compruebe que Apache tiene activos `ssl`, `rewrite`, `proxy`, `proxy_http` y `headers`. Habilitar un módulo que ya esté activo no cambia SISPAM. No deshabilite módulos ajenos.
6. Revise el archivo con `apachectl configtest`. Solo si la prueba es correcta, habilite ese sitio y recargue Apache. No reinicie el servidor completo.

Hasta ese momento el contenedor puede responder en `127.0.0.1:8081` y el dominio público todavía no.

## Detrás de Apache

Apache termina TLS y reenvía a `http://127.0.0.1:8081`. El ejemplo fija `X-Forwarded-Proto` en `https` y el puerto en `443`, y borra `X-Forwarded-Host` recibido del navegador. Conserva el host `pqrsd.comitedeestudiosmedicos.com.co`.

Nginx, dentro de Docker, no obedece el esquema que traiga el cliente: escribe `HTTPS`, el puerto `443` y el protocolo `https` hacia PHP. Laravel, en producción, genera las URLs desde `APP_URL` y fuerza `https`. Los hosts de confianza son ese dominio, `PQRSD_PUBLIC_URL` y el loopback del chequeo interno. No se aceptan subdominios ni el comodín `*`.

`TRUSTED_PROXIES` queda vacío. Laravel no trata como proxy una dirección enviada desde Internet. `PQRSD_TRUSTED_PROXY_CIDR` permanece en `127.0.0.1`, que no coincide con la pasarela de Docker, así que un `X-Forwarded-For` falso no cambia la IP vista por la aplicación. La IP de auditoría será la pasarela hasta que, con la red ya creada, se anote solo esa IP en `PQRSD_TRUSTED_PROXY_CIDR`. No use `0.0.0.0/0`.

Las cookies van cifradas, seguras y `SameSite=lax`. Los adjuntos siguen en `storage/app/private`. El límite de carga es 12 MB. HSTS lo envía el virtual host de Apache, que es quien habla con el navegador.

## Docker Compose v2

Compose v2 ya está instalado en el VPS. Confirme la versión, sin reinstalar ni actualizar Docker:

```bash
docker compose version
```

`compose.prod.yaml` usa ese comando: lleva `name`, no declara `version` y espera `depends_on` con `condition: service_healthy`. No lo lance con `docker-compose` 1.29.2. En Ubuntu 24.04 el paquete de los repositorios es `docker-compose-v2`, no `docker-compose-plugin`. Si `docker compose version` fallara, el paquete a instalar sería `docker-compose-v2`, sin quitar la serie 1 ni tocar los archivos de SISPAM o Authentik.
