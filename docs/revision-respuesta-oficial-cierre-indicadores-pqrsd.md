# Revisión — Respuesta oficial, cierre e indicadores

**Fecha:** 2026-10-19  
**Institución:** Comité de Estudios Médicos  
**Línea base previa:** 201 / 1.504 habituales · 6 / 40 MySQL  

---

## 1. Comportamiento anterior

- Guardar una respuesta (incluso «elaborada») llamaba a `registrarRespuesta`, fijaba `fecha_respuesta`, pasaba a `respondido` y calculaba oportunidad (`resuelto_oportuno` / `resuelto_extemporaneo`).
- El cierre exigía solo `fecha_respuesta` o estado `respondido`.
- La UI decía «Fecha de respuesta oficial» aunque el auxiliar solo elaborara un borrador.
- La sección «Finalización» mostraba meta «En curso», lo que se confundía con un cierre ya realizado.
- Podía coexistir alerta de «pendiente de clasificación» con riesgo/plazo ya calculados (provisionales) sin etiquetarlo.

---

## 2. Causa de las inconsistencias

| Inconsistencia | Causa |
|----------------|--------|
| Elaboración = oportunidad | `PqrsdController::responder` siempre invocaba `VencimientoService::registrarRespuesta` |
| Cierre con borrador | `cerrar` no distinguía comunicación válida vs elaboración |
| Fecha editable | Input `fecha_respuesta` permitía alterar el indicador |
| Envío con casilla vacía | `marcar_envio_verificable` no exigía canal ni evidencia |
| «Finalización / En curso» | Meta poco clara; no listaba requisitos reales |
| Clasificación vs plazo | Radicación con nivel/tipo aplica SLA, pero `pendiente_clasificacion` o tipo vacío mantiene alerta sin marcar «provisional» |

---

## 3. Estados reales de respuesta

| Estado | Significado | `fecha_respuesta` / oportunidad | Cierre |
|--------|-------------|----------------------------------|--------|
| *(vacío)* / sin texto | Sin elaboración | No | No |
| `elaborada` / `aprobada` | Borrador guardado (`respuesta_elaborada_en`) | **No** | **No** |
| `puesta_disposicion` | Disponible en consulta segura | **Sí** (reloj servidor) | **Sí** |
| `enviada` | Comunicación por canal verificable + evidencia | **Sí** | **Sí** |
| `consultada` | Ciudadano leyó en portal | Ya existía `fecha_respuesta` | **Sí** |
| Legacy: `fecha_respuesta` sin `respuesta_estado` | Expedientes anteriores | Conservado | Compatible |

---

## 4. Requisitos efectivos de cierre (backend)

Método `Pqrsd::requisitosPendientesCierre()` / `VencimientoService::cerrar`:

1. Clasificación formal (`cierre_exige_clasificacion`): no `pendiente_clasificacion`, riesgo ≠ `pendiente`, cumplimiento ≠ `sin_clasificar`, tipo y fecha límite presentes.  
2. Comunicación válida (`cierre_exige_comunicacion`): `puesta_disposicion` \| `enviada` \| `consultada`, o legacy con solo `fecha_respuesta`.  
3. Un borrador elaborado **no basta**.

La UI oculta/deshabilita el botón; la validación también ocurre en servidor.

---

## 5. Expediente abierto vs finalizado

| Situación | Meta de sección | Formulario cierre |
|-----------|-----------------|-------------------|
| Abierto con pendientes | Pendiente de finalizar | No (lista de pendientes) |
| Abierto listo | Lista para finalizar | Sí + confirmación |
| Cerrado | Finalizada | No; muestra fecha, usuario, motivo, oportunidad |

---

## 6. Eventos para indicadores

- **Oportunidad / tiempo de respuesta:** `fecha_respuesta` vs `fecha_limite` / `fecha_recepcion`.  
- **Cuándo se fija `fecha_respuesta`:** primera comunicación válida (portal o canal evidenciado), con reloj del servidor.  
- **Meta 95 %:** sin cambio (`config/pqrsd_sla.php` → `meta_oportunidad_pct`).  
- Flags nuevos: `oportunidad_exige_comunicacion`, `cierre_exige_comunicacion`, `cierre_exige_clasificacion`.

---

## 7. Cambios implementados

| Archivo | Cambio |
|---------|--------|
| Migración `..._add_respuesta_elaboracion_y_evidencia...` | `respuesta_elaborada_en`, canal y evidencia de comunicación |
| `app/Models/Pqrsd.php` | Helpers de clasificación, comunicación y requisitos de cierre |
| `app/Http/Controllers/PqrsdController.php` | Elaboración ≠ comunicación; evidencia obligatoria en envío |
| `app/Services/VencimientoService.php` | Cierre con requisitos reales |
| `config/pqrsd_sla.php` | Flags documentados |
| `resources/views/pqrsd/show.blade.php` | Etiquetas, finalización, provisional, formulario |
| `public/css/pqrsd-app.css` | Meta de secciones sin corte multilínea |
| `app/Support/EtiquetasExpediente.php` | Hitos elaboración / disposición / comunicación |
| Tests nuevos y actualización de flujos HTTP | Cobertura A–C, cierre, evidencia, legacy |

---

## 8. Expedientes históricos

- **No** se recalculan `fecha_respuesta` ni oportunidad de casos ya persistidos.  
- Casos legacy con `fecha_respuesta` y sin `respuesta_estado` siguen pudiendo cerrarse.  
- Casos ya cerrados permanecen en solo lectura.

---

## 9. Decisiones pendientes de Calidad / Jurídica

1. Confirmar que la **puesta a disposición en consulta segura** y/o el **envío evidenciado** son los eventos que satisfacen la obligación de respuesta (criterio operativo actual).  
2. Definir excepciones legales (si las hay) donde un borrador o un canal distinto basten.  
3. Política ordinaria / denuncias: flags de validación jurídica ya existentes en `pqrsd_sla.php` se mantienen.  
4. No inventar reglas jurídicas adicionales en código hasta esa aprobación.

---

## 10. Pruebas y validación

```text
php artisan test
# → 210 passed, 1.582 assertions

php artisan test --configuration=phpunit.mysql-concurrency.xml
# → 6 passed, 40 assertions
```

Suite nueva principal: `RevisionRespuestaOficialCierreIndicadoresTest` (elaboración, portal, evidencia, cierre, legacy, UI).

Checklist navegador: expediente abierto con «Pendiente de finalizar»; elaboración sin oportunidad; portal/envío; cierre con confirmación; cerrado en solo lectura; plazos provisionales etiquetados; temas claro/oscuro; metas de sección en una línea.
