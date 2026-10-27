# Corrección — error 500 remisiones y permisos de catálogos

**Institución:** Comité de Estudios Médicos  
**Fecha:** 2026-10-18  
**BD desarrollo:** `pqrsd_menteplena` (MySQL) — nombre histórico; no se renombró.  
**Línea base previa:** 163 pruebas / 1.003 aserciones  
**Resultado final:** **172 pruebas / 1.068 aserciones**

---

## 1. Causa exacta del error 500

Al abrir `/pqrsd/{id}`, `PqrsdController::show` carga la relación `remisionesInternas`.

El modelo `RemisionInterna` usa la tabla `remisiones_internas`.

**Causa:** la migración `2026_10_16_120000_create_remisiones_internas_tables` estaba **Pending** en el entorno local. La tabla no existía → `SQLSTATE[42S02] Base table or view not found: remisiones_internas`.

No era un fallo de código de relación ni de nombre de modelo; faltaba aplicar la migración en MySQL.

---

## 2. Migraciones aplicadas

Comando seguro (sin `migrate:fresh` ni `db:wipe`):

```bash
php artisan migrate:status
php artisan migrate --force
```

| Migración | Estado |
|-----------|--------|
| `2026_10_16_120000_create_remisiones_internas_tables` | Ran (batch 10) |

---

## 3. Tablas verificadas

| Tabla / columna | Resultado |
|-----------------|-----------|
| `areas_institucionales` | Creada |
| `remisiones_internas` | Creada (FK a `pqrsd`, `areas_institucionales`, `users`) |
| `adjuntos_pqrsd.remision_interna_id` | Columna + FK añadida |
| Modelo `RemisionInterna::$table` | `remisiones_internas` (coincide) |

Catálogos sembrados de nuevo con `CatalogosSeeder` (áreas internas incluidas; `updateOrCreate`, sin borrar datos).

---

## 4. Causa del acceso auxiliar a Administración

Las rutas de catálogos **ya** estaban protegidas con middleware Spatie (`permission:sedes.manage`, etc.).

El rol `auxiliar_pqrsd` en **código** (`RolesAndPermissionsSeeder`) ya denegaba esos permisos, pero la **base MySQL local** conservaba permisos antiguos (sync no se había vuelto a ejecutar tras el cambio de seeder).

**Procedimiento seguro aplicado:**

```bash
php artisan db:seed --class=RolesAndPermissionsSeeder --force
php artisan permission:cache-reset
php artisan cache:clear
```

Esto resincroniza permisos del rol sin eliminar usuarios ni expedientes.

---

## 5. Rutas administrativas protegidas

En `routes/web.php` (middleware Spatie):

| Recurso | Permiso |
|---------|---------|
| `usuarios` | `usuarios.manage` |
| `sedes` | `sedes.manage` |
| `aseguradoras` | `aseguradoras.manage` |
| `canales` | `canales.manage` |
| `tipos` | `tipos.manage` |
| `motivos` | `motivos.manage` |
| `areas` | `areas.manage` |
| `reglas` | `reglas_sla.manage` |
| `cuentas-correo` / `ops.*` | `correos.manage` |
| `pqrsd.create` / `pqrsd.store` | `pqrsd.create_manual` |

Métodos GET/POST/PUT/PATCH/DELETE del resource quedan cubiertos por el grupo middleware.

Vistas de catálogo: botones “Nueva …” envueltos en `@can(...)` (defensa en profundidad; el 403 ya lo impone el middleware).

---

## 6. Permisos del administrador

Conserva todos los permisos Spatie (sync completo), incluyendo:

- Catálogos, usuarios, SLA, integraciones, salud operativa  
- Radicación manual (`pqrsd.create_manual`)  
- Bandeja, vencimientos, indicadores, reportes, QR, remisiones, expedientes  

---

## 7. Permisos del auxiliar

**Puede:** `pqrsd.view`, `pqrsd.view_all`, `pqrsd.update`, `pqrsd.classify`, `pqrsd.assign`, `pqrsd.manage`, `pqrsd.close`, `reportes.view`, `auditoria.view`, `correos.review`, `correos.reply`.

**No puede:** `sedes|aseguradoras|canales|tipos|motivos|areas|reglas_sla|usuarios|catalogos|correos.manage`, `pqrsd.create`, `pqrsd.create_manual`.

Uso operativo de valores de catálogo (filtros, clasificación, selectores) **sí**; CRUD administrativo **no**.

---

## 8. Cambios en la navegación

- Auxiliar: solo Operación (Bandeja, Vencimientos, Indicadores, Reportes, QR, Mi perfil). Sin bloque Administración.  
- Admin: Operación + `<details>Administración</details>` con catálogos, seguridad, configuración y Nueva PQRSD.

---

## 9. Funcionamiento de los filtros

Bandeja e indicadores siguen cargando sedes, EPS, tipos, motivos y canales activos para filtros. No se desactivaron consultas ni tablas de catálogo.

---

## 10. Validación del expediente

- `/pqrsd/1` abre con HTTP 200.  
- Sección «Gestión y remisiones internas» visible.  
- Sin error SQL de tabla inexistente.  
- Expedientes sin remisiones muestran «Sin remisiones internas.»

---

## 11. Pruebas ejecutadas

Archivo nuevo: `tests/Feature/CorreccionPermisosCatalogosYRemisionesTest.php`

Cubre: tablas/migraciones, expediente con/sin remisiones, admin CRUD sedes, auxiliar 403 GET/POST/PUT catálogos, menú, filtros operativos, remisiones HTTP, radicación pública, meta 95% / SLA presentes.

```bash
php artisan test
```

**Resultado:** 172 passed, 1068 assertions.

---

## 12. Verificaciones reales en navegador

Servidor: `http://127.0.0.1:8000`

| Usuario | Comprobación | Resultado |
|---------|--------------|-----------|
| Auxiliar 1 | Menú sin Administración | OK |
| Auxiliar 1 | `/pqrsd/1` sin 500 | OK |
| Auxiliar 1 | `/sedes`, `/aseguradoras`, `/canales`, `/motivos` | HTTP 403 |
| Auxiliar 1 | Filtros sede/EPS en bandeja con opciones | OK |
| Admin (permisos DB + tests) | `sedes.manage` + CRUD | OK |

---

## Procedimiento si reaparece el desfase de permisos

En cualquier entorno no productivo donde el seeder de roles se haya actualizado:

```bash
php artisan db:seed --class=RolesAndPermissionsSeeder --force
php artisan permission:cache-reset
```

No usar `migrate:fresh` en bases con datos históricos.
