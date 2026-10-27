# Cambio de identidad institucional — Comité de Estudios Médicos

**Fecha:** 2026-10-16  
**Alcance:** identidad visible del Sistema PQRSD (layouts, login, formulario público, QR, reportes PDF, favicon).  
**Fuera de alcance:** lógica de negocio, SLA, permisos, Gmail/OAuth, datos históricos, correos técnicos `@menteplena.*`.

---

## 1. Nombres

| Concepto | Valor |
|----------|--------|
| **Nombre anterior (marca visible)** | PQRSD Mente Plena / Mente Plena |
| **Nombre institucional** | Comité de Estudios Médicos |
| **Nombre completo del sistema** | Sistema PQRSD — Comité de Estudios Médicos |
| **Nombre corto (nav / encabezados)** | Comité de Estudios Médicos |

---

## 2. Configuración central

Archivo: `config/identidad.php`

| Clave | Uso |
|-------|-----|
| `nombre_institucional` | Nombre oficial de la institución |
| `nombre_sistema` | Título completo de la plataforma |
| `nombre_corto` | Encabezados y navegación |
| `logo_principal` / `logo_compacto` | Rutas opcionales bajo `public/` |
| `favicon` | Recurso de favicon |
| `descripcion` | Meta description |
| `login_titulo` / `login_subtitulo` | Pantalla de acceso |
| `publico_encabezado` / `qr_titulo` | Canal ciudadano y QR |
| `textos.*` | Textos reutilizables (revisión, tratamiento, QR) |

`APP_NAME` (`.env`, `.env.example`, `.env.production.example`) apunta a  
`Sistema PQRSD — Comité de Estudios Médicos`.

Variables opcionales: `IDENTIDAD_NOMBRE`, `IDENTIDAD_SISTEMA`, `IDENTIDAD_CORTO`, `IDENTIDAD_LOGO`, `IDENTIDAD_LOGO_COMPACTO`.

Partial reutilizable: `resources/views/partials/brand-mark.blade.php`  
(si no hay archivo de logo, muestra wordmark tipográfico).

---

## 3. Estado del logo institucional

| Recurso | Estado |
|---------|--------|
| Logo autorizado Comité de Estudios Médicos | **No encontrado** en el repositorio |
| `public/images/mente-plena*.png` | Referencias retiradas de pantallas vigentes; archivos antiguos **no eliminados** (si existían en despliegues) |
| Solución temporal | Wordmark tipográfico **Comité de Estudios Médicos** (CSS `.brand-wordmark`) |
| Incorporación futura | Colocar PNG en `public/images/` y definir `IDENTIDAD_LOGO` / `IDENTIDAD_LOGO_COMPACTO` |

No se inventó un logo gráfico nuevo.

---

## 4. Estado del favicon

| Aspecto | Detalle |
|---------|---------|
| Archivo | `public/favicon.svg` |
| Formas / colores | Conservados (marca en «C») |
| Metadatos | `title` y `aria-label`: **Comité de Estudios Médicos** |
| Layouts | `partials/favicon.blade.php` (`?v=3`) sin duplicar lógica |

---

## 5. Vistas y archivos actualizados

- `config/identidad.php` *(nuevo)*
- `config/app.php` (default `APP_NAME`)
- `resources/views/partials/brand-mark.blade.php` *(nuevo)*
- `resources/views/partials/favicon.blade.php`
- `resources/views/layouts/guest.blade.php`
- `resources/views/layouts/pqrsd.blade.php`
- `resources/views/layouts/publico.blade.php`
- `resources/views/publico/formulario.blade.php`
- `resources/views/publico/confirmacion.blade.php`
- `resources/views/publico/consulta.blade.php`
- `resources/views/publico/consulta-resultado.blade.php`
- `resources/views/publico/qr.blade.php`
- `resources/views/reportes/pdf.blade.php`
- `resources/views/reportes/indicadores-pdf.blade.php`
- `public/favicon.svg`
- `public/css/pqrsd-app.css` (wordmark)
- `.env` / `.env.example` / `.env.production.example` (`APP_NAME` únicamente en producción example)
- `README.md` (título)
- Pruebas de marca: `FaviconTest`, `NavegacionBandejaPrincipalTest`, `OperadoresYTemaTest`, `IdentidadInstitucionalTest` *(nuevo)*

**No modificados (a propósito):** seeders de correos `@menteplena.*`, cuentas, políticas Gmail, rutas, SLA, `PqrsdPolicy`, remisiones (lógica), expedientes en BD.

---

## 6. Pruebas ejecutadas

```text
php artisan config:clear
php artisan test --filter="IdentidadInstitucionalTest|FaviconTest|NavegacionBandejaPrincipalTest|OperadoresYTemaTest"
php artisan test
```

### Resultados

| Métrica | Valor |
|---------|-------|
| Resultado | **passed** |
| Pruebas | **153** |
| Aserciones | **924** |
| Fallidas | **0** |
| Baseline previa | 149 pruebas / 888 aserciones |

Comprobaciones de marca: login, layouts admin/público, QR, favicon, permisos intactos, sin logo Mente Plena en pantallas vigentes.

---

## 7. Validaciones visuales (pendientes en navegador)

- [ ] Login en escritorio y móvil (tema claro/oscuro)
- [ ] Menú lateral + encabezado con wordmark legible
- [ ] Formulario público / confirmación / consulta
- [ ] Módulo QR
- [ ] PDF de reportes e indicadores generados tras el cambio
- [ ] Remisiones internas: solo identidad del layout (sin cambio funcional)

---

## 8. Pendientes de aprobación institucional

1. **Logo gráfico definitivo** del Comité de Estudios Médicos (archivo oficial + autorización de uso).
2. **Responsable del tratamiento de datos / avisos legales:** el texto de consentimiento ya no nombra a Mente Plena; usa redacción genérica de política de tratamiento. Confirmar si debe citar formalmente al Comité (o a otra persona jurídica) y actualizar contratos/avisos oficiales.
3. Documentación operativa histórica (`docs/*` con “Mente Plena”) y guías de Google Workspace: **no reescritas** en bloque; requieren decisión de SSI/Calidad.
4. Dominios y buzones `@menteplena.com.co`: permanecen como identificadores técnicos hasta decisión de infraestructura.
5. Credenciales de prueba `*@menteplena.test`: se mantienen (no son marca de UI).

---

## 9. Principio aplicado

Cambio de **identidad visible del programa**, no de datos históricos ni de persona jurídica por defecto.  
Expedientes, adjuntos y menciones antiguas en contenido de casos **no se alteraron**.
