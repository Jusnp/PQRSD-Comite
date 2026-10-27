# Usuarios PQRSD — aprovisionamiento Authentik (implementación + preparación real)

**Institución:** Comité de Estudios Médicos  
**IdP:** `https://auth.comitedeestudiosmedicos.com.co`  
**Aplicación:** Sistema PQRSD (`pqrsd-comite`)  
**Proveedor OIDC:** CEM PQRSD  
**Grupo autorizado:** `pqrsd-usuarios`

Modalidad definitiva: invitación privada de un solo uso, entregada por el administrador. No se usa SMTP ni un código de verificación por correo. El correo permanece sin verificar.

En Authentik, retirar `pqrsd-funcionario-email-stage` solamente del flujo `pqrsd-invitation-enrollment`. Conservar la etapa de invitación obligatoria, la contraseña con confirmación, la política de contraseñas, User Write (crear si hace falta, usuario activo, grupo `pqrsd-usuarios`) y User Login. No cambiar otros flujos ni el SMTP global.

El aprovisionamiento automático quedó habilitado después de los ingresos reales de los funcionarios piloto. Para detener solo las incorporaciones nuevas, sin modificar a quienes ya están vinculados, poner `AUTHENTIK_PROVISIONING_ENABLED=false`, ejecutar `php artisan config:clear` y reiniciar el servicio en el puerto 8010. No usar `config:cache`. El detalle vigente está en la sección 11; los apartados anteriores conservan el historial de la preparación.

No se documentan ni imprimen secretos (client secret, API token).

---

## 1. Estado comprobado en Laravel (local)

| Elemento | Valor comprobado |
|----------|------------------|
| OIDC habilitado | `AUTHENTIK_ENABLED=true` |
| Login local | `AUTHENTIK_LOCAL_LOGIN_ENABLED=false` (vigente: el ingreso es solo Authentik) |
| Aprovisionamiento API | `AUTHENTIK_PROVISIONING_ENABLED=true` (vigente; ver sección 11 para revertir) |
| `APP_URL` | `http://127.0.0.1:8010` |
| Callback OIDC | `http://127.0.0.1:8010/auth/authentik/callback` |
| Rutas | `GET /auth/authentik/redirect`, `GET /auth/authentik/callback` |
| Slug app | `pqrsd-comite` |
| Grupo | `pqrsd-usuarios` |
| Flow slug esperado | `pqrsd-invitation-enrollment` |
| `AUTHENTIK_ENROLLMENT_FLOW_PK` | **vacío (faltante)** |
| `AUTHENTIK_GROUP_PK` | **vacío (opcional pero recomendado)** |
| Client ID / API token en `.env` | presentes (no listados aquí) |
| Sesión | `SESSION_DRIVER=database` (permite revocar sesiones al desactivar) |

### Descubrimiento OIDC (público, verificado)

Endpoint consultado:

`https://auth.comitedeestudiosmedicos.com.co/application/o/pqrsd-comite/.well-known/openid-configuration`

Respuesta válida con:

- `issuer`: `https://auth.comitedeestudiosmedicos.com.co/application/o/pqrsd-comite/`
- `authorization_endpoint`: `…/application/o/authorize/`
- `token_endpoint`: `…/application/o/token/`
- `userinfo_endpoint`: `…/application/o/userinfo/`
- Scopes: `openid`, `profile`, `email`
- Claims relevantes: `sub`, `email`, `name`, `preferred_username`, `groups`

Cabeceras del IdP: `X-Powered-By: authentik` (versión exacta no expuesta públicamente; confirmarla en Admin → System info).

Laravel (SocialiteProviders Authentik) usa los mismos endpoints globales `/application/o/{authorize|token|userinfo}/` con `AUTHENTIK_BASE_URL` + Client ID/Secret de la app.

### Cómo vincula Laravel (importante)

1. Prefiere `authentik_sub` (claim OIDC `sub`) si ya está guardado.  
2. Si no, busca funcionario **ya creado en PQRSD** por correo exacto en estados de inscripción / local / activo.  
3. Compatibilidad histórica: documento = `preferred_username`.  
4. **Identidad desconocida → acceso denegado.**  
5. **Roles Spatie no se toman de grupos Authentik.** El rol lo define el administrador en PQRSD.  
6. Estar en `pqrsd-usuarios` **sin** fila Laravel autorizada **no** abre la aplicación.

### API de aprovisionamiento (cuando se habilite)

Cliente: `App\Services\Authentik\HttpAuthentikApiClient`

| Operación | Endpoint |
|-----------|----------|
| Resolver flow | `GET /api/v3/flows/instances/{slug}/` |
| Resolver grupo | `GET /api/v3/core/groups/?name=` |
| Crear invitación | `POST /api/v3/stages/invitation/invitations/` |
| Enviar correo | `POST /api/v3/stages/invitation/invitations/{pk}/send_email/` |
| Detectar correo IdP | `GET /api/v3/admin/settings/` (heurística) |
| Buscar usuario | `GET /api/v3/core/users/` |
| Quitar del grupo | `POST /api/v3/core/groups/{pk}/remove_user/` |
| Reincorporar | `POST /api/v3/core/groups/{pk}/add_user/` |

URL de inscripción generada:

`{AUTHENTIK_BASE_URL}/if/flow/{AUTHENTIK_ENROLLMENT_FLOW_SLUG}/?itoken={invitation_pk}`

Si el correo del IdP no está disponible → estado **«Invitación preparada — pendiente de entrega»** (no se afirma envío falso).

Fallos de API → usuario permanece **pendiente / invitation_failed**, nunca activo.

---

## 2. Variables de entorno

### Obligatorias para OIDC (login)

| Variable | Estado local | Notas |
|----------|--------------|-------|
| `AUTHENTIK_ENABLED` | true | Botón en `/login` |
| `AUTHENTIK_BASE_URL` | HTTPS IdP | Sin barra final |
| `AUTHENTIK_CLIENT_ID` | definido | Debe ser el de **Sistema PQRSD / CEM PQRSD** |
| `AUTHENTIK_CLIENT_SECRET` | definido | No rotar en docs |
| `AUTHENTIK_REDIRECT_URI` | `${APP_URL}/auth/authentik/callback` | Debe coincidir **exactamente** con Redirect URIs del proveedor |

### Aprovisionamiento — estado vigente

| Variable | Estado vigente | Notas |
|----------|----------------|-------|
| `AUTHENTIK_PROVISIONING_ENABLED` | **true** | Para detener solo altas nuevas, ver la sección 11. No cambia a los funcionarios ya vinculados. |
| `AUTHENTIK_API_TOKEN` | definido | Preferible token de **servicio de alcance mínimo** (no superadmin) |
| `AUTHENTIK_ENROLLMENT_FLOW_SLUG` | `pqrsd-invitation-enrollment` | Debe existir en Authentik |
| `AUTHENTIK_ENROLLMENT_FLOW_PK` | opcional | Si está vacío, PQRSD resuelve el flujo por el slug |
| `AUTHENTIK_GROUP_NAME` | `pqrsd-usuarios` | OK |
| `AUTHENTIK_GROUP_PK` | **falta (recomendado)** | Evita ambigüedad de nombre |

### Operación / arranque

| Variable | Valor recomendado ahora |
|----------|-------------------------|
| `AUTHENTIK_LOCAL_LOGIN_ENABLED` | `false` (vigente). El valor `true` fue solo del arranque histórico |
| `AUTHENTIK_APP_SLUG` | `pqrsd-comite` |
| `AUTHENTIK_INVITATION_TTL_HOURS` | `48` |
| `AUTHENTIK_EMAIL_CONFIGURED` | vacío o `true`/`false` forzado tras verificar correo IdP |

### Nota sobre scopes

`AUTHENTIK_SCOPES` en `.env` **no controla** SocialiteProviders Authentik (el proveedor pide `openid`, `profile`, `email` y `goauthentik.io/api` por defecto). No depende de esa variable para el login actual.

---

## 3. Configuración de Authentik (historial de preparación)

Estos pasos ya se aplicaron al proveedor CEM PQRSD. No repetirlos sobre SISPAM, SIRUTA, Documenta, MedTech ni otros flujos.

### A. Proveedor OIDC «CEM PQRSD» / app «Sistema PQRSD»

1. Admin Authentik → **Applications** → Sistema PQRSD (`pqrsd-comite`).  
2. Abrir el proveedor **CEM PQRSD**.  
3. En **Redirect URIs**, registrar al menos:
   - Desarrollo: `http://127.0.0.1:8010/auth/authentik/callback`
   - Producción HTTPS institucional cuando exista.  
4. Confirmar Client type **Confidential**, grant **Authorization code**.  
5. Confirmar que el grupo `pqrsd-usuarios` está en la política de acceso de la aplicación (binding).  
6. No reutilizar Client ID/Secret de SISPAM u otras apps salvo que el proveedor sea realmente compartido **y** tenga todos los redirect URI necesarios.

### B. Flow de inscripción exclusivo PQRSD (invitación)

Objetivo: inscripción **solo con invitación válida**, sin registro libre; caducidad; grupo único `pqrsd-usuarios`; **sin** que el usuario elija rol admin (el rol vive en Laravel).

1. **Flows and Stages → Flows → Create** (o New Invitation → crear enrollment flow):
   - Name: `PQRSD Invitation Enrollment`
   - Slug: `pqrsd-invitation-enrollment` (debe coincidir con `.env`)
   - Designation: **Enrollment**
   - Authentication: Require unauthenticated  
2. Crear / enlazar **Invitation Stage**:
   - *Continue flow without invitation* = **false** (obligatorio).  
3. Orden sugerido de stages:
   1. Invitation Stage (temprano; evaluate on plan).  
   2. Prompt (usuario/contraseña).  
   3. Prompt (nombre / email — prefill desde `fixed_data` de la invitación).  
   4. **User Write Stage**:
      - *Create users group* = **`pqrsd-usuarios` únicamente**.  
      - No agregar grupos de Documenta, SIRUTA, SISPAM u otros.  
   5. User Login Stage (opcional) o redirección a la app.  
4. Copiar el **UUID del flow** → `AUTHENTIK_ENROLLMENT_FLOW_PK`.  
5. Crear una invitación de prueba manual en **Directory → Invitations** apuntando a ese flow y verificar que sin `itoken` el flow **no** continúa.  
6. Confirmar correo saliente del IdP (**System → Settings / Email**). Si no hay correo, PQRSD dejará la invitación en «preparada — pendiente de entrega».

Referencia oficial: [Invitations | authentik](https://docs.goauthentik.io/users-sources/user/invitations/).

### C. Token de servicio (permisos mínimos)

Crear un **service account** dedicado (ej. `pqrsd-provisioner`), **no** usar superusuario permanente.

Permisos RBAC mínimos orientativos (ajustar a la UI de roles de su versión):

| Capacidad | Para qué |
|-----------|----------|
| Crear / leer / listar invitaciones | Preparar y reenviar |
| Acción enviar email de invitación | Solo si el correo IdP está activo |
| Leer flows | Resolver slug → UUID |
| Leer grupos | Resolver `pqrsd-usuarios` |
| `add_user` / `remove_user` **solo** sobre ese grupo | Reactivar / desactivar en PQRSD |
| Leer usuarios (búsqueda) | Resolver `pk` Authentik tras OIDC / desactivación |
| Leer settings admin (opcional) | Detectar si hay correo saliente |

**No** otorgar: administración global de usuarios/grupos ajenos, blueprints, outposts, ni roles de otras aplicaciones.

Emitir API token para esa cuenta → `AUTHENTIK_API_TOKEN`.

---

## 4. Primer administrador (historial; no repetir)

El ingreso local quedó cerrado. No crear otro administrador ni volver a `AUTHENTIK_LOCAL_LOGIN_ENABLED=true` para operar el sistema.

Registro de lo ya ejecutado, no es el procedimiento actual:

1. Durante el arranque, `AUTHENTIK_LOCAL_LOGIN_ENABLED` estuvo en `true`.  
2. Se usó la cuenta local de arranque, sin borrar cuentas históricas.  
3. El administrador institucional se vinculó por Authentik.  
4. Con el aprovisionamiento todavía en `false`, un alta quedaba pendiente hasta completar el flujo.  
5. El funcionario entró con **Iniciar sesión con Authentik**.  
6. Se comprobó el rol admin y el acceso a Usuarios.  
7. Después el login local pasó a `false`.  
8. La recuperación de aquella etapa era reactivar el login local. Hoy no se usa: el acceso vigente es OIDC.

---

## 5. Procedimiento piloto (historial; ya ejecutado)

Los pilotos de ingreso ya se completaron. No crear más funcionarios de prueba ni volver a dejar el aprovisionamiento en `false` salvo para detener altas nuevas, como indica la sección 11.

Usar una cuenta de prueba y credenciales autorizadas. Orden:

1. Confirmar redirect URI y OIDC discovery (ya verificado públicamente para `pqrsd-comite`).  
2. Completar flow de invitación + UUID + token de servicio.  
3. Entonces se puso `AUTHENTIK_PROVISIONING_ENABLED=true` en el entorno del piloto. Hoy sigue en `true`.  
4. Como admin local/OIDC: crear funcionario de prueba (Auxiliar).  
5. Verificar estado pendiente / preparada / enviada en la ficha.  
6. Completar inscripción con el enlace Authentik.  
7. En Authentik Admin: confirmar membresía **solo** en `pqrsd-usuarios`.  
8. Login OIDC en PQRSD → verificar rol Laravel `auxiliar_pqrsd` (no admin).  
9. Desactivar desde PQRSD → confirmar bloqueo de acceso PQRSD y salida del grupo; confirmar que otras apps no se ven afectadas.  
10. Si aquel piloto hubiera fallado, el plan era volver el flag a `false`. No ocurrió: el valor vigente permanece en `true`.

No crear más funcionarios ni repetir invitaciones de prueba.

---

## 6. Riesgos

| Riesgo | Mitigación |
|--------|------------|
| Redirect URI incorrecto / Client compartido con otra app | Registrar URI de PQRSD; validar Client ID de CEM PQRSD |
| Flow sin Invitation Stage estricto | `Continue without invitation = false` |
| Usuario Authentik en grupo pero sin fila Laravel | Acceso denegado (diseño actual) |
| Token superadmin | Sustituir por service account mínimo |
| Correo IdP ausente | Estado honesto «pendiente de entrega»; enlace para admin |
| Desactivación global de usuario Authentik | PQRSD solo quita `pqrsd-usuarios` |
| Roles inventados en inscripción | No pedir rol en prompts Authentik; rol solo en Laravel |
| Habilitar provisioning prematuro | Histórico: el flag ya está en `true` tras los pilotos. Para detener altas nuevas, sección 11 |

---

## 7. Condiciones que se exigieron antes de activar el aprovisionamiento (historial)

El flag ya está en `true`. Esta lista no es una instrucción para desactivarlo. Se conserva como registro de lo que se comprobó:

- [ ] Redirect URI de PQRSD registrado en CEM PQRSD.  
- [ ] OIDC login funciona con un usuario Laravel **ya existente** (p. ej. tras vincular el primer admin).  
- [ ] Flow `pqrsd-invitation-enrollment` existe, exige invitación, User Write → solo `pqrsd-usuarios`.  
- [ ] `AUTHENTIK_ENROLLMENT_FLOW_PK` cargado.  
- [ ] Token de servicio de alcance mínimo cargado (idealmente distinto de un superadmin).  
- [ ] `AUTHENTIK_GROUP_PK` opcionalmente cargado.  
- [ ] Decisión sobre correo IdP (`AUTHENTIK_EMAIL_CONFIGURED` o verificación real).  
- [ ] Piloto autorizado con cuenta de prueba (crear → invitar → OIDC → rol → desactivar).  
- [ ] Login local aún disponible hasta confirmar admin institucional.  
- [ ] Suite automatizada en verde (sin tocar Authentik real en PHPUnit).

---

## 8. Implementación Laravel (resumen)

- Formulario **Nuevo funcionario** sin contraseña; solo admin (`usuarios.manage`).  
- Estados: pendiente / preparada / enviada / activa / desactivada / fallo.  
- `AuthentikProvisionService` + cliente HTTP + fake de pruebas.  
- Desactivación: bloquea PQRSD, borra sesiones DB, `remove_user` del grupo.  
- Públicos (`/publico/*`) sin Authentik.  
- Documentación de arranque y recuperación arriba.

---

## 9. Pruebas de la preparación (historial)

Estas cifras corresponden a la preparación inicial, cuando el aprovisionamiento seguía en `false`. No describen el estado vigente. La sección 11 resume el comportamiento actual. Las pruebas siguen usando dobles y no escriben en Authentik.

Línea base solicitada: 246 / 1.786 y 6 MySQL / 40.

Tras esta preparación (relectura de config + discovery público + suite Authentik/usuarios):

```text
php artisan test --filter="UsuariosAuthentikProvisionTest|AuthentikLoginTest"
→ 18 tests, 78 assertions — passed
```

Las pruebas usan `FakeAuthentikApiClient` (cero invitaciones/cuentas reales en Authentik).

Discovery OIDC público del slug `pqrsd-comite`: **OK** en aquella revisión.  
El aprovisionamiento real posterior sí quedó habilitado. El valor vigente es `AUTHENTIK_PROVISIONING_ENABLED=true` (sección 11).

---

## 10. Checklist de preparación (historial; ya no es el procedimiento vigente)

1. Anotar versión Authentik (System info).  
2. Registrar redirect `http://127.0.0.1:8010/auth/authentik/callback` (y producción cuando exista).  
3. Crear flow de invitación PQRSD + copiar UUID → `.env`.  
4. Crear service account + token mínimo → `.env` (rotar si el token actual es demasiado privilegiado).  
5. Copiar UUID de `pqrsd-usuarios` → `AUTHENTIK_GROUP_PK`.  
6. Verificar correo saliente del IdP.  
7. Autorizar piloto y solo entonces `AUTHENTIK_PROVISIONING_ENABLED=true`.

---

## 11. Estado vigente y recuperación

Esta sección prevalece sobre el checklist de preparación. Los pilotos de ingreso ya se completaron y `AUTHENTIK_PROVISIONING_ENABLED` permanece en `true`.

### Incorporación

- Solo un administrador con `usuarios.manage` registra funcionarios.
- El formulario pide nombre, correo y rol. El identificador interno se genera en Laravel y se comprueba contra duplicados locales y de Authentik.
- Si la lectura del directorio no queda demostrada, no se crea la invitación y el estado queda en revisión administrativa.
- La invitación es privada, de un solo uso y vence a las 48 horas. No depende de SMTP. Un correo incorporado por esta vía no se considera verificado: `email_verified_at` permanece vacío mientras no haya una verificación real.
- El funcionario permanece inactivo hasta un ingreso OIDC válido. Después recibe el rol elegido en PQRSD.
- La validación del ID token sigue exigiendo RS256, emisor, audiencia, expiración, nonce, state y `sub`.

### Detener incorporaciones nuevas

1. En `.env`: `AUTHENTIK_PROVISIONING_ENABLED=false`.
2. `php artisan config:clear`.
3. Reiniciar `php artisan serve` en `127.0.0.1:8010`.

Los funcionarios ya vinculados no cambian de estado ni pierden el grupo por este ajuste.

### Desactivación

- PQRSD marca la cuenta inactiva, borra sus sesiones locales y registra la auditoría.
- Cada solicitud autenticada vuelve a comprobar que la cuenta sigue activa.
- El retiro remoto, cuando existe identidad, se limita al grupo `pqrsd-usuarios`. No se cambia `is_active` de la identidad Authentik, porque esa identidad puede servir a otros sistemas.
- Si Authentik rechaza el retiro del grupo, PQRSD queda bloqueado y el aviso es de error. No se anuncia éxito remoto.
- Un administrador no puede desactivar ni reactivar su propia ficha. Un auxiliar no tiene `usuarios.manage`.
- Un funcionario vinculado no puede borrar su cuenta desde Mi perfil. La ruta `DELETE /profile` responde 403 y no llama a Authentik. La desactivación sigue siendo la operación administrativa.

### Sesión ya abierta

Cobertura automatizada, con base de pruebas y doble de Authentik. No se ha cerrado una sesión real de los funcionarios 9, 10, 11 o 12.

- `test_desactivacion_bloquea_y_retira_grupo` inserta una fila en `sessions`, desactiva al funcionario de prueba, comprueba que esa fila desaparece y que un ingreso OIDC posterior vuelve al login.
- `test_modal_navegacion_errores_y_bloqueo_de_cuenta` inicia sesión con `actingAs`, marca la cuenta inactiva y comprueba que la siguiente solicitud a `/pqrsd` redirige al login y deja al usuario como invitado. El middleware `cuenta.activa` hace esa comprobación en cada solicitud autenticada, no solo al entrar.

Procedimiento manual, solo con una cuenta desechable autorizada expresamente. No usar los identificadores 9, 10, 11 ni 12.

1. El administrador registra al funcionario desechable y esa persona entra por Authentik en un navegador.
2. Deja abierta la bandeja `/pqrsd`.
3. Desde otra sesión administrativa, desactiva esa ficha.
4. En el primer navegador, recarga `/pqrsd`. El resultado esperado es volver a `/login`.
5. Confirmar en Authentik que la identidad sigue activa para las demás aplicaciones y que solo salió de `pqrsd-usuarios` si el retiro del grupo fue aceptado.

### Permisos de `pqrsd-servicio`

La cuenta técnica no es superusuario. Los cuatro permisos siguientes están asignados de forma global en Authentik 2024.8.3. En esta versión no aparecen en `system_permissions`, así que el alcance se conoce por la asignación administrativa y por las consultas que la aplicación ya pudo hacer. Esta tarea no modifica permisos, usuarios ni grupos.

| Permiso | Qué permite en el directorio | Uso real de PQRSD |
|---------|------------------------------|-------------------|
| Can view User | Leer usuarios de toda la instancia, también los de SISPAM, SIRUTA, Documenta y MedTech | Comprobar duplicados antes de invitar. Una lectura limitada al grupo `pqrsd-usuarios` ocultó identidades que ya existían fuera de ese grupo. |
| Can add Invitation | Crear invitaciones en cualquier flujo al que la API acepte el alta | Crea una invitación privada solo en `pqrsd-invitation-enrollment`, con el usuario, el correo y el nombre del funcionario. |
| Can view Invitation | Consultar invitaciones de cualquier flujo | Antes de revocar, lee la invitación guardada en la ficha y comprueba flujo, usuario, correo y nombre. |
| Can delete Invitation | Eliminar cualquier invitación cuyo identificador se envíe | Solo borra si esa lectura coincide con el funcionario. Un HTTP 403 no se marca como revocación. Un 404 u otra respuesta incierta deja «Revocación pendiente de verificación». |

El código no asigna roles privilegiados de Authentik, no cambia `is_active` de una identidad al desactivar y no escribe usuarios de otras aplicaciones. El retiro y el alta de membresía que usa la desactivación se limitan en código al grupo `pqrsd-usuarios`.

Opciones de menor privilegio, sin aplicarlas ahora:

1. Mantener Can view User global. Es la única opción que hoy demuestra la ausencia de duplicados fuera de `pqrsd-usuarios`. Quitarlo volvió a ocultar identidades existentes.
2. Sustituir los tres permisos de invitación por un permiso de objeto sobre el flujo `pqrsd-invitation-enrollment`, si una versión posterior de Authentik permite crear, ver y borrar invitaciones solo de ese flujo. Hasta comprobarlo, el permiso global sigue siendo el que usa el alta y la revocación.
3. No agregar Can change User, Can delete User ni superusuario. Esos permisos permitirían modificar o borrar identidades que también usan otros sistemas.

La opción 2 reduce el riesgo de borrar una invitación ajena si alguien reutilizara el token fuera de PQRSD. No reemplaza a Can view User: sin lectura global, la búsqueda de duplicados deja de ser segura.

### Mensajes

Las confirmaciones propias de PQRSD usan el diálogo institucional (`pqrsd-dialogo`), con cierre por Escape o Cancelar, foco restaurado y envío solo después de confirmar. Las páginas 403, 404, 419 y 500 son institucionales y no muestran trazas ni secretos.
