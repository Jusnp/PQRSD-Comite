# Validación operativa y visual — PQRSD Mente Plena

**Fecha:** 2026-10-14  
**Alcance:** interfaz (logo/tema), operación con 1 admin + 2 auxiliares, remisiones, seguridad y caso completo.  
**Fuera de alcance:** Gmail API, OAuth, Pub/Sub, reglas SLA, debilitar `PqrsdPolicy`, datos reales.

> **Nota metodológica:** las comprobaciones de legibilidad/contraste del logo y del tema en pantallas reales son **inspección de assets + código + HTML generado en pruebas**. No se afirma prueba visual manual en navegador salvo donde se indique lo contrario.

---

## 1. Problemas reales encontrados

| # | Problema | Severidad | Acción |
|---|----------|-----------|--------|
| 1 | Logo original `mente-plena.png` (3000×3000) con márgenes internos grandes: a 44–56 px el texto queda ilegible | Media | Generadas variantes compacta y horizontal por recorte fiel (mismas tipografía/colores) |
| 2 | Auxiliar tenía permisos de configuración crítica (`correos.manage`, `reglas_sla.manage`, catálogos, sedes, etc.) | Alta | Restringidos en `RolesAndPermissionsSeeder` |
| 3 | **No existe módulo de remisión a áreas** (solo campo `servicio_area`, reasignación entre usuarios de plataforma y actuaciones genéricas) | Alta (funcional) | Documentado + propuesta mínima; **no implementado** en esta entrega |
| 4 | No hay UI para **subir** adjuntos desde el expediente (solo descarga autorizada de adjuntos ya existentes, p. ej. correo) | Media | Documentado como pendiente |
| 5 | Clasificación/reasignación posteriores a la radicación existen en servicio (`aplicarClasificacion`, `reasignar`) pero **sin formularios HTTP** en el detalle del caso | Media | Documentado; la clasificación sí se hace en radicación manual |
| 6 | FOUC / tema — ya corregido en revisión previa (`:root` claro, cookie en login, a11y) | — | Verificado en código/pruebas |

---

## 2. Cambios aplicados

1. Script `scripts/optimize-logo-mente-plena.php` → genera:
   - `public/images/mente-plena-compact.png` (recorte de márgenes)
   - `public/images/mente-plena-horizontal.png` (lienzo horizontal, mismos píxeles)
2. Layouts (login, header, menú lateral, público) usan **compact**.
3. CSS: `max-width` ampliado y borde sutil para enmarcar el fondo institucional del PNG.
4. Permisos de `auxiliar_pqrsd` sin administración de usuarios ni configuración técnica crítica.
5. Prueba `CasoCompletoOperadoresTest` (flujo ficticio con remisión **simulada** por actuación).
6. Ajustes en `OperadoresYTemaTest` (logo compact + denegaciones de config).

**No se modificó:** Gmail/OAuth/Pub/Sub, calculadora SLA, `PqrsdPolicy`, lógica de cierre.

---

## 3. Archivos modificados / añadidos

| Archivo | Cambio |
|---------|--------|
| `public/images/mente-plena-compact.png` | Nuevo (recorte fiel) |
| `public/images/mente-plena-horizontal.png` | Nuevo (variante horizontal) |
| `public/images/mente-plena.png` | Conservado (original) |
| `scripts/optimize-logo-mente-plena.php` | Generador reproducible |
| `resources/views/layouts/pqrsd.blade.php` | Logo compact |
| `resources/views/layouts/guest.blade.php` | Logo compact |
| `resources/views/layouts/publico.blade.php` | Logo compact |
| `public/css/pqrsd-app.css` / `pqrsd-theme.css` | Tamaño/borde logo |
| `database/seeders/RolesAndPermissionsSeeder.php` | Deny list auxiliar |
| `tests/Feature/OperadoresYTemaTest.php` | Aserciones permisos/logo |
| `tests/Feature/CasoCompletoOperadoresTest.php` | Nuevo |
| `docs/validacion-operativa-visual-pqrsd.md` | Este documento |

---

## 4. Verificación del logo

| Pantalla | Asset | Método |
|----------|-------|--------|
| Login (`layouts.guest`) | compact | Código + assert HTML en suite |
| Encabezado app (`layouts.pqrsd`) | compact | Código + assert |
| Menú lateral | compact | Código |
| Formulario público | compact | Código + assert |
| Móvil | CSS 56→48→44 px, `object-fit: contain` | Solo CSS (sin prueba visual manual) |
| Tema claro/oscuro | PNG con fondo `#BFCDD3` + texto blanco + borde CSS | Inspección de asset/CSS; **revisión manual recomendada** |

**Hallazgo de diseño:** el recorte mejora legibilidad respecto al cuadrado 3000×3000. Una versión “icono a la izquierda + texto a la derecha” redesignada por marca sería ideal a futuro; no se inventó tipografía ni colores con IA para no alterar la identidad.

---

## 5. Verificación del tema global

| Criterio | Estado | Evidencia |
|----------|--------|-----------|
| Botón 🌙/☀️ en app / público / login | Presente | Layouts + tests HTML |
| Persistencia cookie invitado | OK | `OperadoresYTemaTest` |
| Preferencia por usuario | OK | POST `/preferencias/tema` + BD |
| Sync al login | OK | cookie en `AuthenticatedSessionController` |
| Sin flash oscuro al cargar (default claro) | OK en CSS | `:root` = claro; `data-theme="dark"` = oscuro |
| Contraste formularios/tablas/alertas | Parcial | Variables CSS; **requiere revisión visual manual** |

No se creó un segundo sistema de temas.

---

## 6. Permisos finales

### `admin`
Todos los permisos Spatie (incluye usuarios, cuentas correo, reglas SLA, catálogos).

### `auxiliar_pqrsd` — permitido
`dashboard.view`, `pqrsd.view`, `pqrsd.view_all`, `pqrsd.create`, `pqrsd.update`, `pqrsd.classify`, `pqrsd.assign`, `pqrsd.manage`, `pqrsd.close`, `auditoria.view`, `reportes.view`, `correos.review`, `correos.reply`.

### `auxiliar_pqrsd` — denegado
`usuarios.manage`, `catalogos.manage`, `sedes.manage`, `aseguradoras.manage`, `canales.manage`, `tipos.manage`, `motivos.manage`, `reglas_sla.manage`, `correos.manage`.

### Legacy (solo pruebas / no asignar en producción)
| Rol | Alcance |
|-----|---------|
| `auditor` | Lectura / reportes / view_all; sin mutación |
| `responsable_area` | Solo casos asignados; sin view_all ni correo |
| `lider_pqrsd` / `superadministrador` | Amplios (riesgo si quedan asignados a personas reales) |

Seeders de usuarios/demo **no corren en `production`** y no desactivan correos fuera de la lista cerrada `@menteplena.test`.

---

## 7. Funcionamiento de remisiones

### Estado actual (inspección de código)

| Capacidad pedida | ¿Existe? | Cómo |
|------------------|----------|------|
| 1. Seleccionar área destinataria | Parcial | Campo libre `servicio_area` en radicación; no catálogo de áreas ni UI de remisión |
| 2. Motivo de remisión | No dedicado | Podría escribirse en actuaciones genéricas (sin formulario) |
| 3. Comunicación institucional autorizada | No (piloto Gmail bloqueado) | `EmailReplyService` es hacia el **remitente del correo**, no hacia un área |
| 4. Evidencia de envío | Solo si hubo correo saliente mock/API | No hay evidencia de remisión a área |
| 5. Registrar respuesta recibida | No dedicado | Solo “respuesta de fondo” al caso |
| 6. Seguimiento permanece en PQRSD | Sí (diseño) | Responsable = usuarios plataforma (admin/auxiliares) |
| 7. Vencimiento original intacto | Sí en reasignación | `VencimientoService::reasignar` no reinicia plazos |
| 8. Trazabilidad en radicado | Sí para actuaciones existentes | Timeline en `pqrsd/show` |

**Conclusión:** los tres operadores pueden gestionar el expediente sin cuentas de área en la plataforma, pero la **remisión formal a áreas externas a la app está incompleta**.

### Propuesta mínima (antes de implementar)

1. Endpoint + formulario en detalle del caso: `POST /pqrsd/{id}/remisiones` con `area`, `motivo`, `canal` ∈ {`registro_manual`, `correo_institucional_preparado`}, `observaciones`.
2. Crear `ActuacionPqrsd` `tipo=remision_area` (+ opcional `respuesta_area`) **sin** crear usuarios.
3. No tocar `fecha_inicio_conteo` / `fecha_limite`.
4. Mientras Gmail esté bloqueado: canal `registro_manual` obligatorio; marcar `envio_institucional=false` en `datos_adicionales`.
5. Adjunto opcional de evidencia (PDF/imagen) cuando exista upload de adjuntos.
6. Pruebas Feature: permisos auxiliar, plazos intactos, timeline visible.

---

## 8. Resultado del caso completo

Prueba automatizada: `Tests\Feature\CasoCompletoOperadoresTest`.

| Paso | Método | Resultado |
|------|--------|-----------|
| Recepción + radicación + clasificación | HTTP `POST pqrsd.store` (auxiliar) | OK |
| Asignación | Servicio `reasignar` (admin→auxiliar) | OK, SLA intacto |
| Remisión a área | **Simulada** `ActuacionPqrsd` (sin UI) | Registrada en timeline |
| Respuesta del área | **Simulada** actuación | Registrada |
| Respuesta al solicitante | HTTP `pqrsd.responder` | OK |
| Cierre | HTTP `pqrsd.cerrar` | OK |
| Auditoría / timeline | Assert actuaciones + show HTML | OK |
| Reportes / vencimientos | HTTP auxiliar | OK |
| Bloqueo config crítica | HTTP forbidden auxiliar | OK |

**No ejecutado en interfaz real de navegador:** clics manuales, contraste visual, impresión de pantalla, envío real de correo a un área.

---

## 9. Pruebas automatizadas ejecutadas

```text
php artisan test
→ passed: 92 tests, 350 assertions
```

Suite: **92** pruebas (91 previas + `CasoCompletoOperadoresTest`), **350** aserciones.

---

## 10. Pendiente de revisión manual

1. Abrir login, dashboard, público y menú móvil en **tema claro y oscuro**; confirmar legibilidad del logo compact.
2. Pulsar 🌙/☀️ en cada layout: recarga, navegación y re-login.
3. Con las 3 cuentas de laboratorio (solo entornos no productivos): recorrer un caso real en UI y anotar fricción de remisión (hoy hay que improvisar notas).
4. Decidir implementación de la propuesta mínima de remisiones **antes** de Workspace.
5. Validar con SSI/Calidad el listado de áreas destinatarias (texto libre vs catálogo).
6. Definir si auxiliares podrán subir evidencias desde el expediente (hoy no).

---

## Objetivo operativo

**Sí:** administrador y dos auxiliares pueden radicar, ver todos los casos, responder, cerrar, consultar vencimientos/reportes y dejar trazabilidad **sin depender de otros usuarios en la plataforma**, con SLA preservado en reasignaciones.

**No aún:** remisión formal a áreas con evidencia de comunicación institucional, reclasificación/reasignación por UI en el detalle, ni carga de adjuntos desde el expediente.
