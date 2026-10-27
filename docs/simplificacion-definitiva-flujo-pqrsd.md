# Simplificación definitiva del flujo operativo PQRSD

**Institución:** Comité de Estudios Médicos  
**Fecha:** 2026-10-19  
**Línea base previa:** 210 pruebas habituales / 1.585 aserciones · 6 MySQL / 40 aserciones.

## Objetivo

Los dos auxiliares PQRSD atienden cualquier caso desde la Bandeja compartida, sin remisiones a otras áreas ni registros manuales de gestiones. El expediente se concentra en cuatro pasos: recibir, clasificar, responder/comunicar y finalizar.

## Funciones retiradas de la interfaz operativa

| Retirado | Comportamiento nuevo |
|----------|----------------------|
| Formulario «Registrar gestión» (asunto, descripción, Guardar gestión) | No disponible en UI. Ruta `pqrsd.actuaciones.store` responde **403**. |
| Sección «Gestiones y soportes» como bloque operativo | Sustituida: archivos del ciudadano en Información; soportes de respuesta en Respuesta. |
| «Solicitar información a otra área» y barra de acciones asociada | Retirada. Rutas de creación/mutación de remisiones responden **403**. |
| Botón «Finalizar PQRSD (no disponible)» deshabilitado | Mientras hay pendientes se muestra estado breve «En trámite — pendiente de respuesta» (u otro mensaje de requisitos). El botón activo solo aparece cuando `puedeFinalizarse()`. |

## Nuevo flujo de cuatro secciones

1. **Información de la PQRSD** — solicitante, paciente, descripción, recepción, archivos del ciudadano.  
2. **Clasificación** — tipo, riesgo, justificación, plazo.  
3. **Respuesta de PQRS** — elaborar, publicar en consulta segura o registrar comunicación verificable; adjuntar soportes de respuesta.  
4. **Estado y finalización** — cumplimiento SLA, requisitos, finalizar cuando corresponda, historial automático y auditoría.

## Remisiones históricas

- No se crean ni mutan remisiones nuevas por HTTP.  
- Las remisiones ya existentes siguen en BD (tablas y FK intactas).  
- Si hay remisiones, el expediente muestra «Consultar remisiones históricas (solo lectura)».  
- `pqrsd.remisiones.show` permanece para consulta autorizada, sin formularios de mutación.

## Historial automático

Se reutiliza el sistema existente (`historial_estados`, `actuaciones_pqrsd` generadas por el sistema, `auditoria_pqrsd`):

- Radicación  
- Clasificación / SLA  
- Elaboración de respuesta  
- Publicación / comunicación  
- Finalización  

Las gestiones manuales antiguas siguen consultables en «Consultar gestiones históricas».

## Requisitos de finalización

Sin cambios de política de negocio (config `cierre_exige_clasificacion` / `cierre_exige_comunicacion`):

1. Clasificación formal (tipo + riesgo + plazo).  
2. Contenido de respuesta elaborado (si aún no hay comunicación válida).  
3. Comunicación válida (consulta segura o canal verificable con evidencia).  

No se exige aprobación de otro funcionario ni asignación de responsable.

## Antes y después del vencimiento

| Situación | Comportamiento |
|-----------|----------------|
| Respuesta válida antes del vencimiento | Puede finalizarse; oportunidad en término. |
| Plazo vencido sin respuesta | Sigue en trámite; semáforo vencido; **no** cierre automático. |
| Respuesta válida después del vencimiento | Puede finalizarse; se conserva `resuelto_extemporaneo` (u homólogo). |
| Solo borrador elaborado | No habilita cierre. |

## Archivos modificados

- `resources/views/pqrsd/show.blade.php` — layout de 4 secciones.  
- `resources/views/pqrsd/remisiones/show.blade.php` — solo lectura.  
- `app/Http/Controllers/PqrsdController.php` — `actuacion()` → 403.  
- `app/Http/Controllers/RemisionInternaController.php` — create/store/remitir/respuesta/finalizar → 403.  
- `app/Models/Pqrsd.php` — etiquetas de progreso («En trámite — pendiente de respuesta»).  
- Pruebas Feature actualizadas + `tests/Feature/SimplificacionDefinitivaFlujoPqrsdTest.php`.  
- Este documento.

**No modificado:** tiempos SLA, meta 95 %, Gmail/OAuth, logo, favicon, migraciones destructivas, tablas históricas.

## Pruebas y resultados

**Resultados:**

- Suite habitual: **212** pruebas, todas OK.
- Suite MySQL concurrencia: **6** pruebas / **40** aserciones, todas OK.

Cobertura de verificación (casos de la solicitud §9):

1. Auxiliar 1 atiende flujo completo.  
2. Auxiliar 2 atiende otro caso / continúa sin asignación.  
3. Admin conserva permisos de administración.  
4. Sin asignación ni aprobación.  
5. Sin formularios de gestión manual.  
6. Remisiones nuevas → 403.  
7. Históricos consultables.  
8. Auditoría automática.  
9–10. Adjuntos ciudadano y soportes de respuesta.  
11–15. Cierre prematuro bloqueado; comunicación habilita; cierre anticipado; vencido no auto-cierra; tardío conserva incumplimiento.  
16–18. Cerrados protegidos; indicadores 95 %; concurrencia/versionado.

## Verificación en navegador

Recomendado en `http://127.0.0.1:8000`:

1. Login auxiliar → Bandeja → expediente activo.  
2. Confirmar 4 secciones y ausencia de «Registrar gestión» / «Solicitar información a otra área».  
3. Clasificar → elaborar → publicar → Finalizar antes del vencimiento.  
4. Abrir expediente cerrado: solo lectura + historial/auditoría.
