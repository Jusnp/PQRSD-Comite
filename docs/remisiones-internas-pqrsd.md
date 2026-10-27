# Remisiones internas PQRSD — seguimiento por áreas

**Fecha:** 2026-10-16  
**Alcance:** remisiones a áreas institucionales sin cuentas Laravel para esas áreas.  
**Fuera de alcance:** Gmail/OAuth/Pub/Sub, envíos automáticos, Dashboard, meta 95 %, cambios SLA, roles nuevos.

---

## 1. Inspección previa

| Elemento | ¿Existía? | Decisión |
|----------|-----------|----------|
| Entidad `RemisionInterna` | No | Crear tablas + servicio |
| Catálogo de áreas | No (solo texto `servicio_area`) | Crear `areas_institucionales` |
| Actuaciones `remision_area` / `respuesta_area` | Solo simuladas en pruebas | Reutilizar en timeline al crear/actualizar remisiones |
| Adjuntos / evidencias | Sí (`AdjuntoPqrsdService`) | Extender con `remision_interna_id` |
| Vínculo al expediente | N/A | FK `pqrsd_id` (varias remisiones por radicado) |

No se crean radicados nuevos por remisión. No se crean usuarios de área.

---

## 2. Funcionalidades implementadas

### Gestión y remisiones internas (expediente)

- Sección en el detalle del radicado con tabla: área, motivo, fecha remisión, límite interno, estado, responsable, fecha respuesta, acciones.
- Botón **Nueva remisión**.
- Formulario: área, motivo, información solicitada, responsable de seguimiento, fecha límite interna, observaciones, adjuntos.
- Detalle de remisión con seguimiento, documentos y acciones por estado.

### Estados

| Código | Etiqueta |
|--------|----------|
| `preparada` | Preparada |
| `remitida_canal_externo` | Registrada como remitida por canal externo *(transitorio en historial)* |
| `pendiente_respuesta` | Pendiente de respuesta |
| `respuesta_recibida` | Respuesta recibida |
| `finalizada` | Finalizada |

Al registrar remisión por canal externo se exige medio + evidencia; el estado operativo queda en **pendiente de respuesta**. No se afirma envío electrónico desde Laravel (`envio_institucional_laravel = false`).

La respuesta del área se etiqueta como **información interna** y no se confunde con la respuesta de PQRS al ciudadano.

### Control de tiempos

Las remisiones **no**:

- reinician el SLA;
- modifican la fecha de recepción;
- cambian el riesgo;
- pausan el vencimiento;
- cierran el expediente.

Si la fecha límite interna es posterior al vencimiento oficial, se muestra advertencia (sesión + UI).

Riesgos vitales: aviso de conservar procedimientos de atención inmediata.

### Permisos

Admin y ambos auxiliares (vía `pqrsd.update` / `pqrsd.view` y `PqrsdPolicy`): crear, consultar, registrar remisión externa, registrar respuesta del área, adjuntar evidencias, finalizar.  
Administración de usuarios y configuración crítica: solo administrador.

---

## 3. Archivos creados / modificados

| Archivo | Rol |
|---------|-----|
| `database/migrations/2026_10_16_120000_create_remisiones_internas_tables.php` | Tablas áreas, remisiones, FK adjuntos |
| `app/Models/AreaInstitucional.php` | Catálogo |
| `app/Models/RemisionInterna.php` | Entidad + estados/medios |
| `app/Services/RemisionInternaService.php` | Crear / remitir / respuesta / finalizar + assert SLA |
| `app/Http/Controllers/RemisionInternaController.php` | HTTP |
| `resources/views/pqrsd/remisiones/create.blade.php` | Formulario |
| `resources/views/pqrsd/remisiones/show.blade.php` | Detalle y acciones |
| `resources/views/pqrsd/show.blade.php` | Sección tabla remisiones |
| `app/Services/AdjuntoPqrsdService.php` | Soporte `remision_interna_id` |
| `app/Models/AdjuntoPqrsd.php` / `Pqrsd.php` | Relaciones |
| `database/seeders/CatalogosSeeder.php` | Áreas (Psicología, Facturación, etc.) |
| `routes/web.php` | Rutas remisiones |
| `tests/Feature/RemisionesInternasTest.php` | Pruebas nuevas |
| `docs/remisiones-internas-pqrsd.md` | Este documento |

---

## 4. Pruebas ejecutadas

```text
php artisan test --filter=RemisionesInternasTest
php artisan test
```

Cobertura nueva:

- Flujo integral queja → clasificación → remisión Psicología → canal externo + evidencia → respuesta área → consulta auxiliar 2 → respuesta PQRS → cierre → SLA intacto.
- Autorización (ajeno forbidden).
- Advertencia plazo interno posterior + flag sin envío Laravel.
- Catálogo de áreas.

---

## 5. Resultado PHPUnit

| Métrica | Valor |
|---------|-------|
| Resultado | **passed** |
| Pruebas | **149** |
| Aserciones | **888** |
| Fallidas | **0** |
| Baseline previo | 145 pruebas / 827 aserciones |
| Pruebas nuevas | 4 (`RemisionesInternasTest`) |

---

## 6. Límites del flujo manual

- Las áreas **no** tienen acceso a la aplicación; el auxiliar registra todo.
- No hay envío automático de correo ni integración Workspace.
- El medio “correo institucional” implica gestión **manual fuera de Laravel**.
- El catálogo de áreas es inicial; puede ampliarse institucionalmente sin cambiar el flujo.

---

## 7. Revisión visual pendiente

- Contrastar sección de remisiones en tema claro/oscuro.
- Validar tabla en pantallas estrechas.
- Confirmar descarga de evidencias de remisión con ambos auxiliares.
- Recorrer en navegador el caso Psicología de punta a punta (además de PHPUnit).
