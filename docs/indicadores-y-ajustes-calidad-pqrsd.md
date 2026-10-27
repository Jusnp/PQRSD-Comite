# Indicadores y ajustes de Calidad — PQRSD Mente Plena

**Fecha actualización:** 2026-10-15  
**Línea base previa (informe anterior):** 108 pruebas / 442 aserciones  
**Suite tras esta corrección:** ver sección 12–13 (ejecutada en esta sesión).

**Alcance de esta iteración:** activar Denuncias 10 días hábiles + meta de oportunidad 95%.  
**Fuera de alcance:** Gmail, OAuth, Pub/Sub, Dashboard, cambios a `PqrsdPolicy` / cierres.

---

## 1. Estado anterior de ambos parámetros

| Parámetro | Estado anterior | Problema |
|-----------|-----------------|----------|
| Denuncias | Regla `DENUNCIA_10D_PENDIENTE` **inactiva**; flag `denuncia_unidad_conteo_pendiente_confirmacion = true` | No aplicaba plazo; unidad indefinida |
| Meta oportunidad | `meta_oportunidad_pct` nullable; sin default 95 en `.env` | Línea de meta ausente o inconsistente; colores circulares no usaban meta |

El informe de 108 pruebas **no incluía** estas configuraciones activas.

---

## 2. Cambios reales realizados

### Denuncias — 10 días hábiles
- Regla activa `DENUNCIA_10DH`: 10 × `dias_habiles`, versión 1, descripción de parámetro institucional de desarrollo.
- `SlaCalculator::reglaParaCaso($nivel, $tipoCodigo)`:
  - Riesgos `vital_*` / `priorizado` / `simple` conservan prioridad.
  - Si tipo = `DENUNCIA` (y no hay riesgo especial) → `DENUNCIA_10DH`.
  - En caso contrario → regla por nivel (ordinario, etc.).
- `PqrsdService` y `VencimientoService::aplicarClasificacion` usan `reglaParaCaso`.
- Regla legacy `DENUNCIA_10D_PENDIENTE` se desactiva en seeder (no recalcula historial).
- Flags: `denuncia_unidad_conteo_pendiente_confirmacion = false`; `denuncia_aprobacion_juridica_produccion_pendiente = true`.

### Meta 95%
- Única fuente: `config('pqrsd_sla.meta_oportunidad_pct')` ← `PQRSD_META_OPORTUNIDAD` (default **95**).
- Definido en `.env`, `.env.example`, `.env.production.example`.
- Cumplimiento con **porcentaje preciso** (sin redondear antes):
  - `≥ 95` → cumple  
  - `< 95` (p. ej. 94,99) → no cumple  
  - sin evaluables → sin datos  
- Reflejado en tarjetas, circulares, línea de meta en gráfica, tabla y export Excel/PDF.

---

## 3. Archivos modificados

- `config/pqrsd_sla.php`
- `.env`, `.env.example`, `.env.production.example`
- `database/seeders/CatalogosSeeder.php`
- `app/Services/SlaCalculator.php`
- `app/Services/PqrsdService.php`
- `app/Services/VencimientoService.php`
- `app/Services/IndicadoresPqrsdService.php`
- `resources/views/reportes/indicadores.blade.php`
- `resources/views/reportes/partials/circular.blade.php`
- `resources/views/reportes/indicadores-pdf.blade.php`
- `tests/Feature/DenunciaYMetaOportunidadTest.php` (nuevo)
- `tests/Feature/IndicadoresPqrsdTest.php` (ajuste denuncias)
- `docs/indicadores-y-ajustes-calidad-pqrsd.md` (este archivo)

---

## 4. Regla de Denuncias activa en desarrollo

| Campo | Valor |
|-------|--------|
| Código | `DENUNCIA_10DH` |
| Cantidad / unidad | 10 / `dias_habiles` |
| Activo | sí |
| Motor | `SlaCalculator::sumarDiasHabiles` (salta sábados, domingos y festivos CO) |
| Inicio de conteo | `fecha_recepcion` (hora conservada) |
| Historial | No se recalculan casos ya radicados |

**No** se presenta como disposición legal verificada; la aprobación jurídica para producción sigue pendiente.

---

## 5. Meta del 95% en indicadores

| Superficie | Uso de la meta |
|------------|----------------|
| Tarjeta % oportunidad | Texto meta + cumple / no cumple / sin datos |
| Circulares | Color verde/rojo/gris según `cumplimiento_meta` |
| Gráfica de líneas | Serie horizontal “Meta institucional” = 95 |
| Tabla | Columna de cumplimiento vs meta |
| Excel / PDF | Columnas `Meta %` y `Cumple meta` |
| JS Chart.js | Solo consume `tablero.evolucion_oportunidad.meta` (sin hardcode) |

---

## 6. Evidencia de cálculos (ejemplos de prueba)

- Denuncia viernes 2026-10-09 10:00 → límite **2026-10-26 10:00** (10 hábiles, salta finde + festivo 12).
- Denuncia jueves 2026-10-08 09:00 → límite **2026-10-23 09:00**.
- Respuesta exactamente en límite → oportuno; +1 minuto → extemporáneo.
- Denuncia + riesgo vital → regla `VITAL_GENERAL_24H` (prioridad de riesgo).
- 19/20 oportunos → 95,0% preciso → **cumple**; 94,99 → **no cumple**.
- Felicitación sin SLA → `sin_datos`, no entra al denominador de oportunidad.

---

## 7. Fórmulas (vigentes)

- Tiempo respuesta = `fecha_respuesta − fecha_recepcion`.
- Oportuno = `fecha_respuesta ≤ fecha_limite`.
- % oportunidad preciso = `(oportunas / evaluables) × 100` (sin redondear para meta).
- Visualización puede mostrar 1 decimal **después** de evaluar cumplimiento.

---

## 8. Clasificación Felicitación (sin cambio de esta iteración)

Sigue sin SLA inventado; no distorsiona % de oportunidad.

---

## 9–11. Terminología / FN / bandeja

Sin cambios en esta tarea. Bandeja sigue como home. “Respuesta de PQRS” y fecha de nacimiento permanecen.

---

## 12–13. Pruebas ejecutadas

```text
php artisan test
→ passed: 116 tests, 480 assertions
```

Línea base anterior: 108 / 442. Nuevas: `DenunciaYMetaOportunidadTest` (regla activa, viernes, festivo, límite exacto/fuera, prioridad vital, meta 95/94.99/100/sin datos, tablero+felicitaciones, ordinario ≠ denuncia).

---

## 14. Pendientes de aprobación institucional

1. Validación jurídica de Denuncias 10 días hábiles para **producción**.
2. Confirmación formal de la meta 95% como KPI institucional definitivo.
3. Revisión visual manual claro/oscuro/móvil del tablero.
4. Incorporar imágenes de referencia oficiales al repo si se exige pixel-perfect.

---

## 15. Verificación visual

**No realizada en navegador en esta sesión.** Meta 95 y textos de denuncias validados por código + pruebas HTTP/HTML.
