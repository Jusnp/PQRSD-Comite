# Simplificación del expediente — bandeja compartida y solicitudes a áreas

**Fecha:** 2026-10-18  
**Institución:** Comité de Estudios Médicos  
**Línea base previa:** 180 pruebas / 1.194 aserciones  
**Alcance:** Quitar asignación operativa, simplificar solicitudes de información, conservar auditoría e historial.

---

## 1. Cambios en el expediente

- Sección **Asignar / reasignar responsable** eliminada de la UI.
- Resumen muestra **Última gestión** (usuario + fecha de la última actuación), no un responsable exclusivo.
- Acciones: Clasificar PQRSD · Registrar actuación · Adjuntar · Solicitar información · Registrar respuesta · Cerrar PQRSD.
- Casos cerrados: solo lectura (sin formularios de edición).

## 2. Eliminación visual y bloqueo de asignación

| Capa | Comportamiento |
|------|----------------|
| Vista `show` | Sin formulario ni botón «Asignar» |
| `PqrsdPolicy::assign` | Retorna `false` (no debilita otras abilities) |
| `POST pqrsd.asignar` | HTTP **403** para todos los roles |
| Columnas `responsable_id` / tabla asignaciones | Conservadas (historial) |
| `VencimientoService::reasignar` | Conservado para pruebas/SLA internos; no expuesto en UI |

## 3. Bandeja compartida

- Auxiliares con `pqrsd.view_all` ven todos los casos (con o sin `responsable_id`).
- Columna **Responsable** sustituida por **Última gestión**.
- Filtro por responsable retirado de «Más filtros».
- Filtros activos: tipo, motivo, EPS, sede, riesgo, estado, cumplimiento/vencimiento, fechas, canal, anonimato, medio de respuesta.

## 4. Simplificación de remisiones («Solicitar información a otra área»)

- Nombre visible nuevo; mensaje vacío: *No se han solicitado datos a otras áreas*.
- Botón secundario **Solicitar información** muestra el formulario bajo demanda.
- Formulario: área, motivo/información, fecha límite interna opcional, observaciones y documentos opcionales.
- `responsable_seguimiento_id` se fija al usuario autenticado (sin selector).
- Sin correos automáticos; no reinicia SLA; respuesta del área ≠ respuesta oficial PQRS.
- Tablas `remisiones_internas` y registros históricos intactos.

## 5. Auditoría

Siguen registrándose usuario y marca de tiempo en:

- Clasificación, actuaciones, adjuntos, solicitudes a áreas, respuestas de área, respuesta PQRS, cierre.

## 6. Archivos modificados

- `app/Policies/PqrsdPolicy.php`
- `app/Http/Controllers/PqrsdController.php`
- `app/Http/Controllers/RemisionInternaController.php`
- `app/Models/Pqrsd.php` (`ultimaActuacion`)
- `resources/views/pqrsd/show.blade.php`
- `resources/views/pqrsd/index.blade.php`
- `resources/views/pqrsd/remisiones/create.blade.php`
- `public/css/pqrsd-app.css`
- Pruebas ajustadas + `tests/Feature/SimplificacionExpedienteAuxiliaresTest.php`
- `docs/simplificacion-expediente-auxiliares-pqrsd.md`

## 7. Pruebas y resultado

```bash
php artisan test
```

| Métrica | Valor |
|---------|-------|
| Pruebas | **186** (aprobadas) |
| Aserciones | **1.243** |
| Fallidas | 0 |
| Duración | ~53 s |

### Verificación final en navegador (2026-10-18)

Ejecutada en `http://127.0.0.1:8000` con sesiones reales (no solo PHPUnit):

| # | Comprobación | Resultado |
|---|--------------|-----------|
| 1 | Sin «Asignar / reasignar responsable» en bandeja/expediente | OK |
| 2 | Auxiliar 1 y 2 abren/gestionan PQRSD-2026-000008 sin `responsable_id` | OK |
| 3 | Última gestión: Auxiliar 1 → luego Auxiliar 2 con fecha | OK |
| 4 | Expediente cerrado 000009 conserva 14 actuaciones + respuesta + historial | OK |
| 5 | «Solicitar información a otra área»: mensaje vacío + formulario `hidden` | OK |
| 6 | Filtro `tipo_pqrsd_id=2` reduce a Quejas | OK |
| 7 | Concurrencia: actuaciones append-only; HTTP sobre cerrado → **403** (corregido en Policy) | OK |
| 8 | Admin ve Administración/catálogos; auxiliar no | OK |
| 9 | SLA intacto (recepción/límite 000008) + meta **95%** en Indicadores | OK |
| 10 | Tema claro/oscuro y viewport móvil 390×844 | OK |

Corrección demostrada en esta verificación: `PqrsdPolicy` rechaza `update`/`classify`/`close` si el caso está `cerrado` (antes el HTTP aceptaba actuaciones sobre cerrados). Pruebas nuevas: `ConcurrenciaExpedienteVerificacionTest`.

Cobertura añadida/ajustada en `SimplificacionExpedienteAuxiliaresTest` y pruebas de remisiones/permisos:

- Bandeja compartida sin columna Responsable.
- Auxiliares gestionan sin `responsable_id`; ruta `asignar` → 403.
- Solicitud de información simplificada; SLA intacto tras solicitud/actuación.
- Respuesta oficial distinta de nota interna; meta SLA 95 %.
- Expedientes históricos con responsable accesibles; cerrados en lectura.
- Mensaje vacío de solicitudes alineado a la UI.

## 8. Validaciones visuales

- Expediente abierto: sin bloque de asignación; sección «Solicitar información a otra área».
- Expediente sin solicitudes: mensaje breve + botón «Solicitar información».
- Bandeja: columna «Última gestión» (desde historial de actuaciones).
- `POST /pqrsd/{id}/asignar` → 403.
- Logo, temas y permisos de catálogos sin cambio.
