# Auditoría de integridad y concurrencia — Expedientes PQRSD

**Fecha:** 2026-10-18  
**Institución:** Comité de Estudios Médicos  
**Línea base previa:** 186 pruebas / 1.243 aserciones  
**Alcance:** Bandeja compartida (2 auxiliares + administrador), sin asignación obligatoria.

---

## 1. Operaciones revisadas

| Operación | Entrada HTTP | Servicio | ¿Modifica PQRSD? |
|-----------|--------------|----------|------------------|
| Clasificación | `POST pqrsd.clasificar` | `VencimientoService::aplicarClasificacion` | Sí (riesgo, SLA, historial) |
| Actuación | `POST pqrsd.actuaciones.store` | create directo | Append-only |
| Adjuntos | `POST` storeAdjunto | `AdjuntoPqrsdService` | Append-only + actuación |
| Solicitud a otra área | `POST pqrsd.remisiones.store` | `RemisionInternaService::crear` | Remisión + actuación |
| Remisión externa / respuesta área / finalizar | rutas remisiones | `RemisionInternaService` | Remisión (no respuesta oficial) |
| Respuesta oficial PQRS | `POST pqrsd.responder` | `registrarRespuesta` + campos respuesta | Sí |
| Cambio de estado | vía clasificación / actuaciones | `PqrsdService::registrarCambioEstado` | Sí |
| Cierre | `POST pqrsd.cerrar` | `VencimientoService::cerrar` | Sí |
| Asignación | `POST pqrsd.asignar` | deshabilitada | Bloqueada (403) |

---

## 2. Restricciones para expedientes cerrados

### Backend (no solo Blade)

- `PqrsdPolicy::update|classify|close` → `false` si `estado_operativo === 'cerrado'`.
- `Pqrsd::bloquearParaEscritura` / `assertAbiertoParaEscritura` en servicios de mutación.
- `RemisionInternaService` bloquea el radicado abierto antes de crear/remitir/responder área/finalizar.
- `PqrsdService::registrarCambioEstado` rechaza escritura sobre cerrado.
- Excepción institucional: **ninguna** para mutar un cerrado vía HTTP operativo. Consulta e historial permanecen.

### Conservación

- No se borran actuaciones, adjuntos, remisiones, historial de estados ni auditoría.
- Casos cerrados: UI en solo lectura; historial visible.

---

## 3. Concurrencia entre auxiliares

### Controles existentes (antes)

- Actuaciones y adjuntos: append-only (no se sobrescriben entre sí).
- Clasificación / remisiones: `DB::transaction`.
- Cierre: exigía evidencia de respuesta, sin bloqueo pesimista ni versión.

### Controles añadidos (mínimos)

| Mecanismo | Dónde | Para qué |
|-----------|-------|----------|
| `lockForUpdate` | clasificar, responder, cerrar, remisiones | Serializar mutaciones del mismo radicado |
| Token `expediente_version` **obligatorio** en HTTP | clasificar / responder / cerrar | Detectar pantalla obsoleta; no omitible |
| Mensaje de conflicto | `@error('expediente')` en expediente | UX clara: recargar e intentar de nuevo |
| Cierre idempotente | `cerrar` con lock | Rechaza segundo cierre («ya está cerrado») |
| SLA assert | respuesta y cierre | No alteran recepción / límites |

### Verificación del token (2026-10-18)

**Vulnerabilidad real encontrada:** `expediente_version` era `nullable` y `assertVersionCompatible(null)` omitía la comparación → un cliente HTTP podía omitir el campo y eludir el control optimista.

**Corrección mínima:**

- Validación HTTP: `required|string|size:64|regex:/^[a-f0-9]{64}$/i` en clasificar, responder y cerrar.
- `bloquearParaEscritura(..., versionObligatoria: true)` en esas rutas.
- Servicios internos / pruebas de dominio pueden seguir llamando sin token (`versionObligatoria: false`).
- Formularios Blade ya envían el hidden `expediente_version` (sin cambio visual).

### Orden dentro de la transacción

1. `DB::transaction`
2. `lockForUpdate` sobre el radicado
3. Comprobar abierto / no cerrado
4. Comparar `expediente_version` con `versionToken()` (hash de campos críticos)
5. Aplicar mutación (actualiza campos que forman el token)

### Escenarios

1. Ambos abren el mismo caso → permitido (solo lectura concurrente).
2. Ambos registran actuaciones distintas → ambas se conservan (append-only).
3. Aux1 clasifica; Aux2 clasifica con versión antigua → error de conflicto; prevalece clasificación de Aux1.
4. Aux1 responde; Aux2 intenta cerrar sin evidencia previa → falla por evidencia; con respuesta válida y versión actual → cierra una sola vez.
5. Aux1 cierra; Aux2 con formulario abierto → Policy 403 o mensaje de cerrado/conflicto.
6. Aux2 guarda tras cierre → 403 (actuación, respuesta, remisión, clasificar, cerrar).

---

## 4. Problemas reales encontrados

1. **HTTP sobre cerrado (ya corregido en sesión previa):** Policy no bloqueaba; actuaciones podían persistirse.
2. **Clasificación concurrente:** última escritura ganaba sin aviso (pantalla obsoleta).
3. **Respuesta concurrente:** texto oficial podía sobrescribirse silenciosamente.
4. **Doble cierre:** riesgo de actuaciones de cierre duplicadas en carrera sin `lockForUpdate`.
5. **Servicios sin defensa:** remisiones/respuesta/cierre no revalidaban «abierto» tras el authorize.
6. **Omitir token (corregido en verificación final):** `nullable` permitía saltarse la versión vía HTTP manipulado.

---

## 5. Correcciones mínimas aplicadas

- `app/Models/Pqrsd.php` — `versionToken`, `assertAbiertoParaEscritura`, `bloquearParaEscritura`.
- `app/Services/VencimientoService.php` — lock + versión en clasificar/responder/cerrar.
- `app/Services/RemisionInternaService.php` — lock + caso abierto en mutaciones.
- `app/Services/PqrsdService.php` — cambio de estado exige abierto.
- `app/Http/Controllers/PqrsdController.php` — `expediente_version` + errores comprensibles.
- `resources/views/pqrsd/show.blade.php` — token oculto + alerta de conflicto.
- Pruebas: `AuditoriaIntegridadConcurrenciaTest` (+ existentes `ConcurrenciaExpedienteVerificacionTest`).

Sin módulos nuevos, sin cambios SLA/meta 95%, sin Gmail/OAuth, sin restaurar asignaciones.

---

## 6. Integridad de respuestas (verificado)

- Registrar respuesta (`elaborada`) **no** implica `enviada` ni acuse de recibo.
- Respuesta de área (`respuesta_area`, `es_respuesta_oficial_ciudadano=false`) ≠ respuesta PQRS al ciudadano.
- Cierre exige `fecha_respuesta` o estado `respondido`.
- Consulta pública solo muestra texto si `respuestaDisponibleParaConsulta()` (puesta a disposición / enviada / consultada).
- Tras cierre, rutas de respuesta/clasificación/remisión → 403.

---

## 7. Auditoría

Cada mutación relevante deja rastro en:

- `actuaciones_pqrsd` (usuario, tipo, título, detalle, fecha).
- `historial_estados_pqrsd` (campo, anterior, nuevo, motivo, usuario).
- `auditorias_pqrsd` (acción, antes/después, usuario, IP).

No se sobrescriben filas históricas; los conflictos impiden el guardado obsoleto.

---

## 8. Pruebas ejecutadas

### A) Suite habitual (SQLite — simulaciones secuenciales)

```bash
php artisan test
```

| Tipo | Clase / método | Motor |
|------|----------------|-------|
| Secuencial | `AuditoriaIntegridadConcurrenciaTest` / `ConcurrenciaExpedienteVerificacionTest` | SQLite memoria |
| Conflicto de versión (secuencial) | clasificar/responder/cerrar con token obsoleto | SQLite |
| Token omitido / manipulado | HTTP validation | SQLite |

### B) Concurrencia REAL (MySQL/MariaDB aislado — dos procesos)

Base: **`pqrsd_concurrency_test`** (nunca `pqrsd_menteplena`).

```bash
php tools/setup_mysql_concurrency.php
php artisan test --configuration=phpunit.mysql-concurrency.xml
```

| Prueba MySQL real | Qué demuestra |
|-------------------|---------------|
| `test_mysql_dos_procesos_clasificacion_obsoleta_rechazada` | 2 procesos; 1 gana, 1 rechazado; riesgo final = ordinario |
| `test_mysql_dos_cierres_simultaneos_no_duplican` | Un solo `cierre`; SLA intacto |
| `test_mysql_respuesta_obsoleta_no_sobrescribe_en_paralelo` | Texto oficial del primero se conserva |
| `test_mysql_actuaciones_paralelas_se_conservan` | Ambas actuaciones COMMIT |
| `test_mysql_cerrado_rechaza_operaciones_y_rollback_no_deja_parciales` | Cerrado bloquea; rollback no deja parciales |
| `test_mysql_http_token_omitido_manipulado_y_vigente` | Tokens en HTTP sobre MySQL |

Infraestructura: conexión `mysql_concurrency`, workers `tools/concurrency_worker.php` (STDIN JSON), exclusión de esta clase en `phpunit.xml` por defecto.

Orden verificado en código/servicio: **lockForUpdate → estado abierto → token → mutación → auditoría → commit**. Excepción ⇒ rollback (probado con `rollback_probe`).

### Limitaciones restantes

| Aspecto | Situación |
|---------|-----------|
| Suite CI por defecto | Sigue en SQLite (rápida, sin MariaDB) |
| Deadlocks cruzados | Bajo riesgo: siempre se bloquea `pqrsd` por `id` primero; no se añadieron reintentos que dupliquen cierres/respuestas |
| UI tiempo real | Sin WebSockets; control al guardar |

---

## 9. Resultados

```text
# Suite habitual
php artisan test
Tests: 193 passed (1303 assertions)

# Concurrencia MySQL real (aislado)
php artisan test --configuration=phpunit.mysql-concurrency.xml
Tests: 6 passed (40 assertions)
```

| Suite | Pruebas | Aserciones | Fallidas |
|-------|---------|------------|----------|
| SQLite (`phpunit.xml`) | **193** | **1.303** | 0 |
| MySQL concurrencia (`phpunit.mysql-concurrency.xml`) | **6** | **40** | 0 |

No se requirieron correcciones de lógica adicional tras la validación MySQL real (solo infraestructura de prueba).

---

## 10. Riesgos pendientes

1. **Actuaciones / adjuntos / remisiones** no exigen `expediente_version` (append-only + lock de abierto).
2. La suite CI por defecto no ejecuta MySQL; debe lanzarse `phpunit.mysql-concurrency.xml` en entornos con MariaDB/MySQL local.
3. **Consulta pública** puede marcar `respuesta_consultada_en` en cerrado — no reabre ni altera SLA.
4. Sin bloqueo de UI en tiempo real.

**Objetivo cumplido:** dos auxiliares pueden trabajar en paralelo sobre la Bandeja compartida en MySQL sin perder actuaciones ni generar clasificaciones/respuestas/cierres inconsistentes.
