# PQRSD en Docker con PostgreSQL

Esta guía levanta el sistema en contenedores, contra una base PostgreSQL nueva. MariaDB (`pqrsd_menteplena`) no se modifica. Docker ocupa el puerto que ya usaba la aplicación local.

La aplicación Docker queda en `http://127.0.0.1:8010`.

## Qué incluye

| Servicio | Imagen fijada | Función |
| --- | --- | --- |
| `web` | `nginx:1.27.5-alpine` | Escucha el puerto 80 y se publica solo en `127.0.0.1:8010` |
| `app` | `php:8.3.26-fpm-bookworm` | Laravel, con `pdo_pgsql`, GD, intl, zip y bcmath. PHP-FPM queda en el puerto 9000 de la red Docker |
| `postgres` | `postgres:16.10-bookworm` | Base `pqrsd_postgres_dev`. PostgreSQL queda en el puerto 5432 de la red Docker |

PHP-FPM y PostgreSQL no tienen puerto publicado hacia el anfitrión ni hacia Internet. Solo se hablan por la red Docker `pqrsd_postgres_net`.

Volúmenes persistentes:

- `pqrsd_postgres_data`: datos de PostgreSQL.
- `pqrsd_storage`: `storage` de Laravel (sesiones de archivo si se cambiaran, logs, adjuntos nuevos). No copia los adjuntos privados del sistema MariaDB.

## Requisitos

- Docker Engine 24 o superior y Docker Compose v2.
- Los archivos de esta carpeta: `Dockerfile`, `compose.dev.yaml`, `docker/` y `.env.docker` (local, no versionado).
- No hace falta tener PostgreSQL instalado en Windows. MariaDB puede seguir corriendo; Docker no se conecta a `pqrsd_menteplena`.

## 1. Variables locales

En PowerShell, desde la raíz del proyecto:

```powershell
Copy-Item .env.docker.example .env.docker
php -r "echo 'base64:'.base64_encode(random_bytes(32));"
```

Edite `.env.docker`:

- `APP_KEY`: pegue el valor `base64:...` que imprimió PHP.
- `POSTGRES_PASSWORD` y `DB_PASSWORD`: la misma contraseña local del usuario `pqrsd`.
- `PQRSD_TEST_DB_PASSWORD`: otra contraseña, solo para el usuario `pqrsd_test`.
- `POSTGRES_USER` y `DB_USERNAME`: déjelos en `pqrsd`.

Copie al mismo archivo las variables `AUTHENTIK_*` del `.env` local. La URI de retorno debe seguir siendo `http://127.0.0.1:8010/auth/authentik/callback`. No copie `DB_HOST`, `DB_DATABASE`, `DB_USERNAME` ni `DB_PASSWORD` de MariaDB.

Compose vuelve a fijar, por encima del archivo, la conexión de la aplicación:

- `DB_CONNECTION=pgsql`
- `DB_HOST=postgres`
- `DB_PORT=5432`
- `DB_DATABASE=pqrsd_postgres_dev`
- `APP_URL=http://127.0.0.1:8010`
- `AUTHENTIK_REDIRECT_URI=http://127.0.0.1:8010/auth/authentik/callback`
- `APP_DEBUG=false`
- `MAIL_MAILER=log`

## 2. Construir e iniciar

Si `http://127.0.0.1:8010` ya lo está usando el servidor PHP local de este proyecto (`php artisan serve`), detenga solo ese proceso. No detenga MariaDB ni otros programas que no reconozca.

```powershell
docker compose --env-file .env.docker -f compose.dev.yaml up -d --build
docker compose --env-file .env.docker -f compose.dev.yaml ps
```

`postgres` debe quedar `healthy`. La primera construcción descarga PHP, Nginx, Node 22.14.0 y Composer 2.8.11, instala dependencias y ejecuta `npm run build`.

## 3. Migraciones y catálogos

Solo contra PostgreSQL, porque el contenedor no tiene ruta a MariaDB:

```powershell
docker compose --env-file .env.docker -f compose.dev.yaml exec app php artisan migrate --force --no-interaction
docker compose --env-file .env.docker -f compose.dev.yaml exec app php artisan db:seed --class=RolesAndPermissionsSeeder --force --no-interaction
docker compose --env-file .env.docker -f compose.dev.yaml exec app php artisan db:seed --class=CatalogosSeeder --force --no-interaction
```

Eso crea tablas vacías de usuarios, permisos, PQRSD, catálogos, tipificación, alertas, encuestas, auditoría y documentos. No copia los expedientes históricos ni los adjuntos clínicos.

No ejecute `migrate:fresh`, `db:wipe` ni `db:seed` sin `--class` apuntando al entorno de MariaDB.

Compruebe la conexión:

```powershell
docker compose --env-file .env.docker -f compose.dev.yaml exec app php artisan db:show
```

La salida debe decir `pgsql` y `pqrsd_postgres_dev`.

## 4. Logs, estado y parada

```powershell
docker compose --env-file .env.docker -f compose.dev.yaml ps
docker compose --env-file .env.docker -f compose.dev.yaml logs --tail 100 app
docker compose --env-file .env.docker -f compose.dev.yaml logs --tail 100 web
docker compose --env-file .env.docker -f compose.dev.yaml logs --tail 100 postgres
```

Detener sin borrar datos:

```powershell
docker compose --env-file .env.docker -f compose.dev.yaml stop
```

Volver a iniciar:

```powershell
docker compose --env-file .env.docker -f compose.dev.yaml start
```

`down` quita los contenedores y conserva los volúmenes nombrados. `down -v` borra la base PostgreSQL de Docker y el almacenamiento del contenedor. No lo use si quiere conservar ese entorno.

## 5. Respaldo y restauración de PostgreSQL

Guarde el archivo fuera del repositorio. No lo suba a Git.

```powershell
docker compose --env-file .env.docker -f compose.dev.yaml exec -T postgres pg_dump -U pqrsd -d pqrsd_postgres_dev -f /tmp/pqrsd_postgres_dev.sql
docker compose --env-file .env.docker -f compose.dev.yaml cp postgres:/tmp/pqrsd_postgres_dev.sql C:\respaldos\pqrsd_postgres_dev.sql
docker compose --env-file .env.docker -f compose.dev.yaml exec -T postgres rm /tmp/pqrsd_postgres_dev.sql
```

Restaurar en la misma base vacía o recreada:

```powershell
docker compose --env-file .env.docker -f compose.dev.yaml cp C:\respaldos\pqrsd_postgres_dev.sql postgres:/tmp/pqrsd_postgres_dev.sql
docker compose --env-file .env.docker -f compose.dev.yaml exec -T postgres psql -U pqrsd -d pqrsd_postgres_dev -f /tmp/pqrsd_postgres_dev.sql
```

El comando `php artisan pqrsd:backup` escribe un volcado con sintaxis pensada para el entorno MariaDB. Para esta base use `pg_dump`. El programador de tareas (correo, Gmail y ese respaldo) no arranca con Compose.

## 6. Pruebas

La suite habitual sigue en SQLite en memoria y no toca ninguna base real:

```powershell
php artisan test
```

Dentro del contenedor, la misma suite corre contra `pqrsd_postgres_test` con el usuario `pqrsd_test`. Ese usuario no puede conectarse a `pqrsd_postgres_dev`. `tests/bootstrap-pgsql.php` sustituye las variables de desarrollo antes de arrancar Laravel. `RefreshDatabase` reconstruye solo la base de pruebas. `php artisan test` volvería a cargar la suite SQLite.

```powershell
docker compose --env-file .env.docker -f compose.dev.yaml exec app vendor/bin/phpunit --configuration phpunit.pgsql.xml
```

Pasar la suite SQLite no sustituye esta segunda ejecución.

## 7. Authentik

El ingreso sigue siendo el OIDC ya existente. No hay un segundo formulario de contraseña. La URI de retorno es la que ya estaba en uso:

`http://127.0.0.1:8010/auth/authentik/callback`

No cree otro cliente ni registre otra URI. Copie la URL del servidor, el client ID, el client secret y el resto de variables `AUTHENTIK_*` al `.env.docker` local. Compose fija la URI de retorno en el puerto 8010 y fuerza `AUTHENTIK_PROVISIONING_ENABLED=false`.

El ingreso OIDC no depende de ese interruptor: el botón y el callback siguen activos. Con el aprovisionamiento apagado, un ingreso correcto no vuelve a llamar a Authentik para agregar al grupo `pqrsd-usuarios`, y un alta nueva queda pendiente sin crear invitación remota.

PostgreSQL de Docker no contiene los funcionarios de MariaDB hasta que esa transferencia se autorice. Si Authentik redirige y el callback no encuentra una identidad local, el ingreso no termina. Eso no se resuelve cambiando Authentik.

El correo real y Google Workspace permanecen apagados. `MAIL_MAILER=log`.

## 8. Desarrollo y despliegue institucional

Este Compose es un entorno de ejecución local reproducible:

- `APP_ENV=local` y `APP_DEBUG=false`.
- Código copiado en la imagen. Un cambio de PHP o de vistas exige `--build`.
- Incluye dependencias de desarrollo para poder correr PHPUnit.
- HTTP en el loopback, sin TLS.
- Correo en `log`. Alertas SLA por correo apagadas.
- Sin copia de expedientes ni de adjuntos del sistema en uso.

Para un servidor institucional haría falta, además, una decisión de la entidad:

- Un `compose` de producción con `composer install --no-dev`, sin PHPUnit en la imagen.
- HTTPS, dominio y `APP_URL` definitivos.
- Secretos en el gestor de la entidad, no en Git.
- La URI de Authentik de ese dominio.
- Política de copias con `pg_dump` y retención fuera del contenedor.
- Revisar si el programador de tareas debe correr y con qué comandos. No active sincronización de correo ni Gmail junto con este arranque.
- Límites de red: PostgreSQL y PHP-FPM siguen sin puerto público; solo Nginx publica el HTTP o, mejor, queda detrás del proxy institucional.

No declare este entorno como producción por el hecho de que los contenedores estén encendidos.

El despliegue en el VPS usa `Dockerfile.prod`, `compose.prod.yaml` y `docs/despliegue-vps.md`. No reutilice este Compose de desarrollo en el servidor.
