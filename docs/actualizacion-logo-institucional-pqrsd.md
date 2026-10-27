# Actualización del logo institucional — Comité de Estudios Médicos

**Fecha:** 2026-10-19  
**Alcance:** logo visible del Sistema PQRSD.  
**Fuera de alcance:** favicon, SLA, permisos, respuestas, cierre, Gmail, datos históricos.

---

## 1. Archivo original

| Concepto | Valor |
|----------|--------|
| Ruta solicitada | `logo-comite-estudios-medicos-blanco.png` *(no existía al inicio)* |
| Origen utilizado | `C:\Users\Juan\Downloads\Comité de Estudios Médicos.png` (707 870 bytes, 1254×1254) |
| Copia en raíz del proyecto | `logo-comite-estudios-medicos-blanco.png` |
| Características verificadas | Fondo blanco (~RGB 255), tipografía oscura (~RGB 69,72,83), tres líneas |

El PNG oscuro previo (`COMITE DE ESTUDIOS MEDICOS.png` / `public/images/comite-estudios-medicos*.png`) **no se eliminó** (puede figurar en documentación histórica).

---

## 2. Ruta pública definitiva

`public/images/logo-comite-estudios-medicos.png`

---

## 3. Configuración actualizada

`config/identidad.php`:

- `logo_principal`
- `logo_header`
- `logo_compacto`

→ todas apuntan a `images/logo-comite-estudios-medicos.png`.

Partial único: `resources/views/partials/brand-mark.blade.php` (login, layout admin, público, menú lateral).  
Favicon: sin cambios (`public/favicon.svg`).

CSS: placa blanca fija y `filter: none` en claro/oscuro (no se invierten colores del PNG).

---

## 4. Pantallas que consumen el logo (vía brand-mark)

Login · Bandeja · Expediente · Vencimientos · Indicadores · Reportes · Formulario público · Consulta pública · QR · menú lateral.

Alt accesible: **Comité de Estudios Médicos**.

---

## 5. Nota de nitidez

El PNG es 1254×1254 (cuadrado). En encabezado se muestra a ~72 px de alto con `object-fit: contain`. Resolución suficiente; no se aplica escalado agresivo.

---

## 6. Pruebas y verificación

```text
php artisan test
# → 210 passed, 1.585 assertions

php artisan test --configuration=phpunit.mysql-concurrency.xml
# → 6 passed, 40 assertions
```

Navegador (`http://127.0.0.1:8000`):

- Login / formulario público / consulta: logo nuevo HTTP 200.
- `alt="Comité de Estudios Médicos"`.
- Tema oscuro: fondo blanco del PNG conservado (`filter: none`).
- Favicon sin cambios.
- Pantallas autenticadas (bandeja, expediente, etc.) usan el mismo `partials/brand-mark` (cubierto por pruebas).
