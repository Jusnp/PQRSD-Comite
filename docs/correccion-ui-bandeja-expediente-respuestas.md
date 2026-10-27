# Corrección UI — Bandeja, expediente y respuestas

**Fecha:** 2026-10-19  
**Institución:** Comité de Estudios Médicos  
**Alcance:** Presentación y etiquetas. Sin cambio de consultas, permisos, SLA, meta 95 %, Gmail ni bandeja compartida.

---

## 1. Problemas detectados

| # | Problema | Causa |
|---|----------|--------|
| 1 | Bandeja con demasiadas columnas; «Ver expediente» fuera de viewport | Tabla ancha sin priorización; acción sin anclaje |
| 2 | Encabezados de secciones del expediente desalineados | Sin plantilla única flecha / título / meta |
| 3 | Resumen superior irregular | Chips sin grid uniforme ni altura mínima |
| 4 | Formulario de respuesta confundía guardar / publicar / enviar | Etiquetas genéricas («Guardar respuesta de PQRS», «Respuesta enviada») |
| 5 | «Fecha respuesta» sin aclarar si era elaboración, portal o envío | Misma fecha alimenta oportunidad; faltaba etiqueta «oficial» y textos de ayuda |
| 6 | Error 500 al abrir expediente sin `fecha_respuesta` | `optional(...)->timezone(...)->format(...)` sobre valor nulo |

---

## 2. Archivos modificados

| Archivo | Cambio |
|---------|--------|
| `public/css/pqrsd-app.css` | Bandeja (sticky acción, cards móvil), grid de secciones, chips 4 columnas, ayudas de respuesta |
| `resources/views/pqrsd/index.blade.php` | 8 columnas prioritarias; sede/EPS/última gestión en meta bajo radicado |
| `resources/views/pqrsd/show.blade.php` | Alineación de 6 secciones; chips; etiquetas A/B/C de respuesta; fecha oficial; fix nullsafe |
| `tests/Feature/CorreccionUiBandejaExpedienteRespuestasTest.php` | **Nuevo** — bandeja, secciones, estados elaborada/portal |
| `tests/Feature/IndicadoresPqrsdTest.php` | Aserciones de nuevas etiquetas |
| `tests/Feature/ExpedienteInterfazOperativaTest.php` | Botón cerrado no muestra «Guardar respuesta» |
| `tests/Feature/GestionInternaFlujoCompletoTest.php` | «Comunicada por canal verificable» + clase de sección |
| `docs/correccion-ui-bandeja-expediente-respuestas.md` | Este documento |

**No modificados:** controladores de lógica SLA, migraciones, Gmail/OAuth/Pub/Sub, permisos, meta 95 %.

---

## 3. Cambios visuales

### Bandeja

- Columnas principales: Radicado · Solicitante · Tipo/motivo · Riesgo · Estado · Fecha límite · Cumplimiento · Acción.
- Sede, EPS, recepción y última gestión bajo el radicado (`bandeja-meta`).
- Columna «Ver expediente» sticky a la derecha en escritorio; contenedor con scroll horizontal accesible (`bandeja-table-wrap` + hint).
- ≤760 px: filas como tarjetas; acción a ancho completo.

### Expediente — secciones

Estructura CSS Grid uniforme en `.exp-section-summary`:

1. Flecha (`::before`) a la izquierda  
2. Título (`.exp-section-title`) alineado a la izquierda  
3. Meta/estado/cantidad (`.exp-section-meta`) a la derecha  

Aplicado a: solicitante/paciente, clasificar, gestiones, solicitar info, respuesta, finalización.

### Resumen superior

- `.exp-chips` en 4 columnas (2 en tablet, 1 en móvil), altura mínima coherente.
- Alertas de riesgo vital / vencimiento / pendiente se mantienen fuera de las chips.

---

## 4. Interpretación de estados de respuesta (sin cambiar modelo)

| Estado UI | Valor interno | Significado |
|-----------|---------------|-------------|
| **A — Respuesta elaborada** | `elaborada` | Contenido guardado. Cuenta para oportunidad y habilita cierre. **No** es envío ni lectura. |
| Respuesta aprobada | `aprobada` | Aprobación interna opcional. |
| **B — Disponible en consulta segura** | `puesta_disposicion` | Texto en portal con radicado/código. **No** confirma lectura ciudadana. |
| **C — Comunicada por canal verificable** | `enviada` | Evidencia manual de comunicación externa (no Gmail automático). |
| Consultada por el ciudadano | `consultada` | Solo tras consulta segura exitosa en el portal. |

Botón de acción: **Guardar respuesta** (no «enviar»).

---

## 5. Fecha de respuesta e indicadores

| Concepto | Campo / regla | Observación |
|----------|---------------|-------------|
| Fecha oficial / oportunidad | `fecha_respuesta` | Comparada con `fecha_limite` → `resuelto_oportuno` / `resuelto_extemporaneo` |
| Elaboración vs envío | `respuesta_estado` + timestamps | Guardar elaborada **sí** fija `fecha_respuesta` (reglas existentes); **no** fija `respuesta_enviada_en` |
| Portal | `respuesta_puesta_disposicion_en` | Puesta a disposición |
| Comunicación | `respuesta_enviada_en` | Evidencia manual |
| Lectura ciudadana | `respuesta_consultada_en` | Solo consulta portal |

Etiqueta en formulario: **Fecha de respuesta oficial**, con texto de ayuda que aclara que no implica envío ni lectura.

**No se recalcularon expedientes históricos ni se alteró el SLA.**

---

## 6. Validación en navegador

Rutas: `/pqrsd` (bandeja) y `/pqrsd/15` (expediente). Servidor: `http://127.0.0.1:8000`.

| Verificación | Resultado |
|--------------|-----------|
| Escritorio 1366 px | Sin scroll horizontal (`scrollWidth === clientWidth`); «Ver expediente» visible |
| Celular 390 px | Filas en `display:grid` (tarjetas); thead oculto; acción accesible |
| 6 secciones | Misma izquierda de título (`titleLeft` idéntico) y meta a la derecha |
| Chips resumen | Grid 4×2 uniforme; «Estado de respuesta» integrado |
| Formulario respuesta | «Fecha de respuesta oficial», «Respuesta elaborada», «Disponible en consulta segura», «Guardar respuesta» |
| Tema claro / oscuro | Operativo (toggle del layout) |

Capturas locales (sesión agente): `page-2026-10-19T15-57-23-581Z.png` (bandeja 1366), `page-2026-10-19T15-57-51-438Z.png` (bandeja 390), `page-2026-10-19T15-58-27-652Z.png` (expediente).

---

## 7. Pruebas ejecutadas

```text
php artisan test --filter=CorreccionUiBandejaExpedienteRespuestasTest
# → 3 passed, 40 assertions

php artisan test
# → 201 passed, 1504 assertions

php artisan test --configuration=phpunit.mysql-concurrency.xml
# → 6 passed, 40 assertions
```

**Resultados reales:** suite habitual **201 / 1.504**; concurrencia MySQL **6 / 40**. (+3 pruebas UI respecto a la línea base 198 / 1.461.)

---

## 8. Restricciones respetadas

- Sin Gmail API / OAuth / Pub/Sub  
- Sin módulos nuevos de negocio  
- Sin cambio de reglas SLA ni meta 95 %  
- Sin migraciones destructivas  
- Sin cambio de permisos ni asignación obligatoria  
- Auditoría e historial conservados  
- Bandeja compartida intacta  
- Logo institucional sin cambios  
