# Mejora visual y operativa del expediente PQRSD

**Fecha:** 2026-10-18  
**Institución:** Comité de Estudios Médicos  
**Línea base previa:** 178 pruebas / 1.173 aserciones  
**Alcance:** Solo presentación y navegación de `pqrsd/show`. Sin cambios de lógica, SLA, meta 95%, permisos ni integraciones.

---

## 1. Problemas observados en la pantalla anterior

- Datos clave repetidos (recepción, riesgo, estado) en tarjetas y en «Datos del caso».
- Formularios de clasificación, asignación, actuación, adjuntos, respuesta y cierre en una sola columna larga.
- Acciones siempre visibles sin jerarquía clara.
- Alertas de pendiente/vital/vencido mezcladas con el resto del contenido.
- Remisiones y timeline al final, difíciles de localizar en móvil.

---

## 2. Organización nueva

| # | Sección | Implementación |
|---|---------|----------------|
| 1 | Resumen general | Tarjeta fija superior con chips |
| — | Alertas críticas | Fuera de `<details>` (siempre visibles) |
| — | Acciones rápidas | Barra de enlaces según permiso/estado |
| 2 | Solicitante y paciente | `<details id="sec-personas">` |
| 3 | Clasificación, riesgo y responsable | `<details id="sec-clasificacion">` (abierto si pendiente) |
| 4 | Actuaciones y documentos | `<details id="sec-actuaciones">` |
| 5 | Remisiones internas | `<details id="sec-remisiones">` (abierto si hay datos) |
| 6 | Respuesta de PQRS | `<details id="sec-respuesta">` |
| 7 | Cierre e historial | `<details id="sec-cierre">` |

---

## 3. Resumen superior

Muestra: radicado, tipo/motivo/canal, estado operativo, riesgo, recepción, fecha límite, transcurrido/restante, responsable, sede/EPS, estado de respuesta y semáforo SLA.  
Énfasis visual: pendiente, vital, vencido.

---

## 4. Acciones según estado

Si el caso **no** está cerrado y el usuario tiene permiso:

- Clasificar / Asignar → `#sec-clasificacion`
- Actuación / Adjuntos → `#sec-actuaciones`
- Crear remisión → ruta existente `pqrsd.remisiones.create`
- Registrar respuesta → `#sec-respuesta`
- Cerrar → `#sec-cierre`

Caso **cerrado:** sin barra de acciones; formularios de edición ocultos; mensaje de solo lectura.

Rutas, validaciones y policies **sin cambios**.

---

## 5. Archivos modificados

| Archivo | Cambio |
|---------|--------|
| `resources/views/pqrsd/show.blade.php` | Reorganización UI |
| `public/css/pqrsd-app.css` | Estilos expediente (claro/oscuro + responsive) |
| `tests/Feature/ExpedienteInterfazOperativaTest.php` | Pruebas de interfaz |
| `docs/mejora-visual-expediente-pqrsd.md` | Este documento |

---

## 6. Pruebas

```bash
php artisan test
```

Nuevas aserciones: resumen/secciones en pendiente; acciones ocultas en cerrado.  
Suite completa: **180 pruebas / 1.194 aserciones**.

---

## 7. Verificaciones visuales (navegador local)

| Caso | Observación |
|------|-------------|
| `PQRSD-2026-000001` cerrado | Alerta «Radicado cerrado»; sin barra de acciones; secciones en solo lectura |
| `PQRSD-2026-000005` abierto vital/vencido | Alertas pendiente + vital + vencido visibles; barra Clasificar/Asignar/Actuación/Remisión/Respuesta/Cerrar; clasificación abierta |
| Logo CEM | Presente en header y menú |
| Tema claro | Verificado; estilos dark usan variables del tema |

Escritorio verificado. Responsive definido en CSS (`≤800px` / `≤480px`).  
Sin cambios a controlador, policies, SLA ni rutas.
