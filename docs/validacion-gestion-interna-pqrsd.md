# Validación — Gestión interna PQRSD (administrador y auxiliares)

**Fecha:** 2026-10-15  
**Alcance:** bandeja, expediente, clasificación, asignación, actuaciones, soportes, respuesta, consulta ciudadana, cierre.  
**Fuera de alcance:** Gmail API, OAuth, Pub/Sub, Dashboard, meta institucional del 95 %, reglas SLA arbitrarias, roles nuevos.

---

## 1. Funcionalidades existentes verificadas

| Capacidad | Estado previo | Observación |
|-----------|---------------|-------------|
| Bandeja filtrable (estado, riesgo, cumplimiento, sede, EPS) | Existía | Operativa |
| Semáforo SLA y plazos en listado/detalle | Existía | Operativa |
| Detalle de expediente, personas, descripción, medio de respuesta | Existía | Operativa |
| Descarga de adjuntos con autorización | Existía | `PqrsdPolicy` + `AdjuntoPqrsdController` |
| Registrar respuesta de PQRS (elaborada / puesta a disposición / enviada) | Existía | No marca “recibida” solo por guardar |
| Cierre con evidencia de respuesta | Existía | `VencimientoService::cerrar` |
| Clasificación SLA (`aplicarClasificacion`) | Solo servicio | Sin ruta/UI hasta esta entrega |
| Reasignación (`reasignar`) | Solo servicio | Sin ruta/UI hasta esta entrega |
| Radicación pública + confirmación con token en sesión | Existía | Token fuera de URL |
| Consulta ciudadana POST (radicado + token) | Existía | Enumeración protegida |
| Roles: 1 admin + 2 auxiliares con `view_all` / classify / assign / update / close | Existía | Admin reserva usuarios y config crítica |

---

## 2. Problemas detectados

1. **Clasificación y asignación** existían en `VencimientoService` pero no en HTTP ni en la UI del expediente.
2. **Actuaciones operativas** y **soportes internos** no tenían formulario ni endpoint para operadores.
3. La **bandeja** no destacaba con claridad los casos pendientes de evaluación clínica ni mostraba columna de medio de respuesta; faltaban filtros de tipo, anonimato y medio.
4. El título del bloque de respuesta debía quedar explícito como **«Respuesta de PQRS»** (terminología institucional).
5. Riesgo **simple no se asigna por omisión** al radicar desde el público (`nivel_riesgo = pendiente`); la UI debía reforzar que la clasificación es manual.

---

## 3. Correcciones aplicadas

- Rutas delgadas que delegan en servicios existentes:
  - `POST /pqrsd/{pqrsd}/clasificar` → `VencimientoService::aplicarClasificacion` (+ transición `pendiente_clasificacion` → `radicado`).
  - `POST /pqrsd/{pqrsd}/asignar` → `VencimientoService::reasignar` (ahora también registra `AsignacionPqrsd` y admite transición desde `pendiente_clasificacion`).
  - `POST /pqrsd/{pqrsd}/actuaciones` → `ActuacionPqrsd` (+ `asignado` → `en_gestion`).
  - `POST /pqrsd/{pqrsd}/adjuntos` → `AdjuntoPqrsdService::guardarSoportesInternos`.
- Policy: métodos `classify` y `assign`.
- Bandeja: badge «Pendiente evaluación», columna medio de respuesta, filtros tipo/anonimato/medio.
- Expediente: formularios de clasificar, asignar, actuación, soportes; estados de respuesta visibles (borrador / registrada / puesta a disposición / enviada / consultada).
- Confirmación pública: se mantiene token solo en sesión flash, con botones de copia (sin token en URL).

---

## 4. Archivos modificados / creados

| Archivo | Cambio |
|---------|--------|
| `app/Policies/PqrsdPolicy.php` | `classify`, `assign` |
| `app/Services/VencimientoService.php` | Historial de asignaciones; transición operativa |
| `app/Services/AdjuntoPqrsdService.php` | `guardarSoportesInternos` (reutiliza lógica privada) |
| `app/Http/Controllers/PqrsdController.php` | `clasificar`, `asignar`, `actuacion`, `storeAdjunto`; filtros bandeja |
| `routes/web.php` | Rutas POST operativas |
| `resources/views/pqrsd/index.blade.php` | Bandeja mejorada |
| `resources/views/pqrsd/show.blade.php` | Controles de gestión |
| `tests/Feature/GestionInternaFlujoCompletoTest.php` | **Nuevo** — recorrido integral |
| `tests/Feature/IndicadoresPqrsdTest.php` | Ajuste aserción terminología |
| `docs/validacion-gestion-interna-pqrsd.md` | **Este documento** |

---

## 5. Permisos comprobados

| Actor | Acceso operativo PQRSD | Usuarios / config crítica |
|-------|------------------------|---------------------------|
| `admin@menteplena.test` | Completo (`view_all`, classify, assign, update, close, manage) | Sí |
| `auxiliar1@menteplena.test` | Completo operativo a todas las PQRSD | No (`usuarios.manage`, reglas SLA, cuentas correo) |
| `auxiliar2@menteplena.test` | Igual que auxiliar 1 | No |

Se conserva `PqrsdPolicy` (view/update/close/classify/assign) y la protección de descarga de documentos.

---

## 6. Resultado del recorrido completo (ficticio)

Cubierto por `GestionInternaFlujoCompletoTest`:

1. Radicación pública identificada → confirmación con radicado + token.  
2. Aparición en bandeja con «Pendiente evaluación».  
3. Clasificación a `ordinario` por auxiliar (no `simple` automático).  
4. SLA calculado desde recepción original.  
5. Actuación operativa registrada.  
6. Soporte interno en disco privado.  
7. Respuesta elaborada ≠ enviada.  
8. Puesta a disposición en consulta segura.  
9. Ciudadano consulta con radicado + token.  
10. Evidencia de envío manual (sin Gmail).  
11. Cierre autorizado; plazos intactos.  
12. Historial reconstruible en el expediente.  
13. Token incorrecto no abre el caso.  

---

## 7. Pruebas ejecutadas

```text
php artisan test --filter=GestionInternaFlujoCompletoTest
php artisan test
```

---

## 8. Resultado de PHPUnit

| Métrica | Valor |
|---------|-------|
| Resultado | **passed** |
| Pruebas | **145** |
| Aserciones | **827** |
| Fallidas | **0** |

*(Antes de esta entrega: 143 pruebas / 743 aserciones. Se añadieron 2 pruebas del flujo integral y se ajustó 1 aserción de terminología.)*

---

## 9. Pendientes de revisión manual

- Recorrer en navegador la bandeja con filtros reales (sede/EPS) sobre datos de desarrollo.
- Verificar visualmente el contraste del badge «Pendiente evaluación» en tema claro/oscuro.
- Probar carga de varios adjuntos internos y descarga cruzada entre auxiliar1 y auxiliar2.
- Confirmar en UI que el token de confirmación no aparece en la barra de direcciones tras F5 (solo flash de sesión).

---

## 10. Pendientes de aprobación institucional

- Validación jurídica de denuncias anónimas y de días hábiles CO cuando aplique la regla correspondiente.
- Política formal de conservación del token por el ciudadano (comprobante descargable: no implementado; la sesión de confirmación + copia es el mecanismo actual).
- Criterios clínicos institucionales para cada nivel de riesgo (vital / priorizado / simple / ordinario).
- Reactivación futura de canales de envío automático (Gmail/Workspace), explícitamente fuera de este ciclo.
