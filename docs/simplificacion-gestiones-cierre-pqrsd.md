# Simplificación — Gestiones, soportes, finalización e historial

**Fecha:** 2026-10-19  
**Institución:** Comité de Estudios Médicos  
**Línea base previa:** 196 pruebas / 1.389 aserciones (+ 6 MySQL / 40)  
**Tras esta entrega:** **198** / **1.461** habituales + **6** / **40** MySQL  

---

## 1. Archivos modificados

| Archivo | Cambio |
|---------|--------|
| `resources/views/pqrsd/show.blade.php` | Etiquetas y organización UI de gestiones / finalización |
| `app/Support/EtiquetasExpediente.php` | **Nuevo** — etiquetas presentacionales (sin tocar BD) |
| `app/Http/Controllers/PqrsdController.php` | Mensajes de éxito más claros (misma lógica) |
| `tests/Feature/ExpedienteInterfazOperativaTest.php` | Aserciones de nuevas etiquetas |
| `tests/Feature/SimplificacionGestionesCierreTest.php` | **Nuevo** — flujo auxiliar + cierre protegido |
| `docs/simplificacion-gestiones-cierre-pqrsd.md` | Este documento |

---

## 2. Antes → después (interfaz)

| Antes | Después |
|-------|---------|
| Actuaciones y documentos | **Gestiones y soportes** |
| Registrar actuación / Título / Detalle | **Registrar gestión** / Asunto / Descripción de lo realizado |
| Actuación interna… | **Uso interno: no visible para el ciudadano** |
| Agregar documentos / Incorporar soportes | **Adjuntar soporte (opcional)** / Adjuntar soporte |
| Línea de tiempo de actuaciones (+ tipo técnico) | **Historial de gestiones** con etiquetas comprensibles |
| Cierre e historial de auditoría | **Finalización de PQRSD** |
| Cerrar con evidencia | **Finalizar PQRSD** (+ requisitos pendientes si faltan) |
| Historial crudo (`estado_operativo` → …) | Hitos: registrada / clasificada / respuesta / finalizada |
| Auditoría técnica siempre visible | Bloque desplegable **Ver detalles de auditoría** (`auditoria.view`) |

---

## 3. Funciones que permanecen (sin cambio de reglas)

- Bandeja compartida, roles admin + 2 auxiliares  
- SLA, meta 95 %, clasificación, respuesta oficial  
- Requisitos de cierre (evidencia de respuesta)  
- `expediente_version`, transacciones, locks  
- Adjuntos privados + descarga autorizada  
- Historial y auditoría íntegros en BD  
- Registrar gestión ≠ respuesta oficial  
- Finalizar no altera `fecha_respuesta` ni oportunidad  

---

## 4. Pruebas ejecutadas

- `SimplificacionGestionesCierreTest`  
- `ExpedienteInterfazOperativaTest`  
- `GestionInternaFlujoCompletoTest` / `SimplificacionExpedienteAuxiliaresTest` (etiquetas actualizadas)  
- Suite completa habitual + concurrencia MySQL  

---

## 5. Validación en navegador

Verificado en `http://127.0.0.1:8000/pqrsd/15` (auxiliar):

1. Acciones: Registrar gestión · Adjuntar soporte · Finalizar PQRSD.  
2. Sección **Gestiones y soportes** con bloques 1–3 y etiquetas nuevas.  
3. **Finalización**: sin respuesta → «Requisitos pendientes…»; sin botón Finalizar.  
4. **Ver detalles de auditoría** desplegable.  
5. Tema claro y oscuro operativos.  
6. Logo e identidad del Comité conservados.
