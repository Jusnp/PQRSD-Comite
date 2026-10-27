# Corrección — Vencimientos con bandeja compartida

**Fecha:** 2026-10-19  
**Institución:** Comité de Estudios Médicos  

---

## 1. Causa exacta (antes de cambiar código)

### Hallazgo sobre «Próximos a vencer»

En el entorno local (`NOW = 2026-10-19 09:42`, zona `America/Bogota`) la consulta **no fallaba por `responsable_id`**.

Inventario real de expedientes:

| Radicado | Operativo | Cumplimiento | Responsable | Límite |
|----------|-----------|--------------|-------------|--------|
| …000001 | cerrado | resuelto_extemporaneo | sí | 2026-10-10 |
| …000002 | pendiente | **dentro_plazo** | sí | 2026-10-26 |
| …000003 | pendiente | **vencido** | sí | 2026-10-14 |
| …000004 | pendiente | **vencido** | sí | 2026-10-14 |
| …000005 | pendiente | **vencido** | sí | 2026-03-14 |
| …000006/7 | pendiente | sin_clasificar | null | null |
| …000008 | radicado | **dentro_plazo** | null | 2026-10-29 |
| …000009 | cerrado | resuelto_oportuno | sí | 2026-10-14 |

Contadores tras `refrescarAbiertos()`:

- `proximo_vencer` = **0**
- `vencido` = **3**
- `dentro_plazo` = **2**

**Conclusión:** el mensaje «No hay casos en este estado» en `/vencimientos/proximos` era **correcto respecto a los datos**: ningún expediente abierto estaba dentro del umbral de proximidad SLA. Los tres vencidos debían verse en `/vencimientos/vencidos`.

El umbral de «próximo a vencer» (sin cambiar reglas) es:

- restante ≤ max(25 % del plazo total, minutos mínimos por nivel),  
- con tope del 50 % del plazo (`SlaCalculator::estaProximoAVencer` + `config/pqrsd_sla.php`).

El caso demo «vital próximo» (recepción hace ~22 h al sembrarse) **ya había cruzado el límite** al momento de la revisión, por eso figuraba como `vencido` y no como `proximo_vencer`.

### Filtro por responsable

`VencimientoController::scopedQuery` solo filtraba por `responsable_id` / creador / radicador **si el usuario no tenía `pqrsd.view_all`**. Admin y ambos auxiliares **sí** tienen `view_all`, así que no eran excluidos por asignación.

Aun así se reforzó el listado para operación de bandeja compartida (ver §2).

---

## 2. Cambios realizados (mínimos)

1. **Consulta de pendientes**  
   - Excluye `cerrado` y cumplimientos `resuelto_oportuno` / `resuelto_extemporaneo`.  
   - Exige `fecha_limite` no nula.  
   - Con `pqrsd.view_all`: todos los pendientes (no depende de `responsable_id`).  
   - Orden: vital menor → vital general → priorizado → simple → ordinario, luego fecha límite.

2. **Pestaña Riesgo vital** (`/vencimientos/vitales`)  
   - Lista vitales abiertos pendientes de respuesta (prioridad visual).

3. **UI semáforo**  
   - Columnas: prioridad/semáforo, radicado (enlace), tipo, riesgo, recepción, límite, tiempo restante/vencido, estado de respuesta.  
   - Se reemplazó la columna «Responsable» (histórico de asignaciones intacto en BD).  
   - Mensajes vacíos específicos por pestaña.

4. **Demo seeder**  
   - Casos relativos a «ahora», **sin** `responsable_id`, para laboratorio.

5. **Pruebas**  
   - `VencimientosBandejaCompartidaTest` con fechas controladas (`Carbon::setTestNow`).

**No modificado:** reglas SLA, meta 95 %, Gmail/OAuth/Pub/Sub, roles, logo, plazos históricos de expedientes ya existentes.

---

## 3. Qué debe mostrarse en cada pestaña

| Pestaña | Incluye | Excluye |
|---------|---------|---------|
| **Próximos a vencer** | Abiertos con `estado_cumplimiento = proximo_vencer` | Cerrados, resueltos, dentro de plazo, vencidos, sin límite |
| **Vencidos** | Abiertos con `estado_cumplimiento = vencido` | Cerrados, resueltos, próximos, dentro de plazo |
| **Riesgo vital** | Abiertos `vital_menor` / `vital_general` con límite, no resueltos | No vitales, cerrados, resueltos |

---

## 4. Pruebas ejecutadas

- `VencimientosBandejaCompartidaTest`  
  1. Próximo sin responsable  
  2. Vencido sin responsable  
  3. Vital próximo  
  4. Fuera de umbral (`dentro_plazo`)  
  5. Respondido oportuno  
  6. Cerrado  
  7. Visibilidad admin + auxiliar1 + auxiliar2  
  8. Coherencia con Bandeja e Indicadores  
- Mensaje vacío claro cuando no hay próximos  
- Suite completa: **196** pruebas / **1.389** aserciones  
- Concurrencia MySQL: **6** / **40**

### Regenerar demos relativos en laboratorio

```bash
php artisan db:seed --class=DemoVencimientosSeeder
```

Crea radicados **nuevos** (no altera plazos de expedientes previos). Tras el seed, «Próximos» y «Vencidos» deben poblarse con casos sin responsable.
