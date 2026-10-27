# Simplificación operativa — menú, permisos e indicadores PQRSD

**Institución:** Comité de Estudios Médicos  
**Fecha:** 2026-10-16  
**Alcance:** Organizar lo existente (Bandeja como centro). Sin nuevos módulos, sin Dashboard, sin cambios a Gmail/OAuth/Pub/Sub, SLA ni meta 95%.

---

## 1. Menú anterior y nuevo

### Anterior (problema)
- Auxiliar veía “Nueva” / radicación manual junto a catálogos y configuración.
- Enlaces administrativos mezclados con operación diaria.
- Accesos rápidos podían duplicar funciones administrativas.

### Nuevo — Auxiliar PQRSD (Operación)
- Bandeja de PQRSD  
- Vencimientos  
- Indicadores PQRSD  
- Reportes  
- Formulario público / Código QR  
- Mi perfil  

**No muestra:** Nueva PQRSD, usuarios, sedes, aseguradoras, canales, tipos, motivos, reglas SLA, cuentas de correo, salud operativa.

### Nuevo — Administrador
**Operación (igual que auxiliar)** más apartado desplegable **Administración**:
- **Radicación manual:** Nueva PQRSD  
- **Catálogos:** Sedes, Aseguradoras, Canales, Tipos, Motivos, Áreas internas  
- **Seguridad:** Usuarios y permisos  
- **Configuración:** Reglas SLA, Integraciones / Cuentas correo, Salud operativa  

Accesos rápidos del header: solo Bandeja, Vencimientos, Indicadores, Reportes, Formulario público / QR (sin duplicar administración).

---

## 2. Permisos administrador y auxiliar

| Permiso / capacidad | Admin | Auxiliar |
|---------------------|-------|----------|
| `pqrsd.view` / `pqrsd.view_all` | Sí | Sí |
| `pqrsd.create_manual` | Sí | **No** |
| `pqrsd.create` | Sí | **No** |
| `pqrsd.update` / classify / assign / manage / close | Sí | Sí |
| `reportes.view` | Sí | Sí |
| Catálogos (`*.manage`) | Sí | **No** |
| `usuarios.manage` | Sí | **No** |
| `reglas_sla.manage` / `correos.manage` | Sí | **No** |
| `areas.manage` | Sí | **No** |

Seeder: `database/seeders/RolesAndPermissionsSeeder.php`.  
Policy: `PqrsdPolicy::create` exige `pqrsd.create_manual` o `pqrsd.create` (sin debilitar otras reglas).

---

## 3. Restricción de radicación manual

- Rutas `GET pqrsd.create` y `POST pqrsd.store` bajo middleware `permission:pqrsd.create_manual`.
- Menú y botón “Nueva radicación” con `@can('pqrsd.create_manual')`.
- Formulario público / QR **sin autenticación** sigue generando radicados.
- Auxiliar puede actuaciones, adjuntos, clasificación, remisión, respuesta y cierre sobre expedientes existentes.

---

## 4. Filtros de Bandeja

**Principales:** buscar, estado, riesgo, tipo, sede, aseguradora.  
**Más filtros (colapsables):** motivo, fechas, responsable, canal, cumplimiento, anonimato, medio de respuesta.  
Acciones: Filtrar, Limpiar, Exportar (cuando aplique).  
Parámetros conservados en paginación y ordenamiento (`sort` / `dir`).

### Motivo
Catálogo `motivos_pqrsd` alimenta el filtro; opción “Sin motivo registrado”; selector dependiente del tipo si hay `tipo_pqrsd_id`. No se alteran motivos históricos.

### Columnas del listado
Radicado, recepción, solicitante, tipo, motivo, sede, EPS, riesgo, estado, fecha límite, cumplimiento, responsable, Ver expediente.  
Orden por prioridad (default), fecha o vencimiento. Destacados: pendiente evaluación, riesgo vital, próximo a vencer, vencido, pendiente respuesta.

---

## 5. Mejoras de indicadores

Filtros globales compartidos: año, mes, sede, EPS, tipo, **motivo**.  
Cohorte por **fecha de recepción**. Meta institucional **95%** sin cambio.

### Evolución de oportunidad
- **Año completo (mes = todos):** serie enero–diciembre (agrupación mensual).  
- **Mes específico:** serie diaria; si hay pocos evaluables (&lt; 8), agrupación semanal.  
- Título y eje X reflejan la agrupación.  
- Sin evaluables → “Sin datos” (no 0% ficticio); tooltips con período, evaluables, oportunos, fuera de plazo y % oportunidad.  
- Tarjetas, circulares, barras, líneas, tabla y exportaciones usan la misma cohorte filtrada.

---

## 6. Archivos modificados (principales)

| Área | Archivos |
|------|----------|
| Permisos | `RolesAndPermissionsSeeder.php`, `PqrsdPolicy.php`, `routes/web.php` |
| Menú | `resources/views/layouts/pqrsd.blade.php`, CSS nav-admin |
| Bandeja | `PqrsdController.php`, `resources/views/pqrsd/index.blade.php` |
| Áreas | `AreaInstitucionalController`, vistas catálogo, ruta resource |
| Indicadores | `IndicadoresPqrsdService.php`, `IndicadoresPqrsdController.php`, `reportes/indicadores.blade.php` |
| Pruebas | `SimplificacionOperacionAuxiliaresTest.php`, ajustes `CasoCompletoOperadoresTest`, `PqrsdRadicacionTest` |
| Docs | `docs/simplificacion-operacion-auxiliares-pqrsd.md` |

---

## 7. Pruebas ejecutadas

Comando: `php artisan test`

Cobertura añadida / ajustada:
1. Admin accede a Nueva PQRSD y Administración  
2. Auxiliar no visualiza Nueva ni Administración  
3. Auxiliar 403 en GET/POST radicación manual  
4. Auxiliar consulta todas las PQRSD  
5. Auxiliar clasifica, remite (UI) y responde  
6. Auxiliar 403 en usuarios, motivos, tipos, sedes, aseguradoras, canales, SLA  
7. Auxiliar conserva perfil, indicadores, reportes y QR  
8. Admin conserva acceso administrativo  
9. Radicación pública continúa  
10. Filtro por motivo y filtros combinados  
11. Evolución anual (12 meses) y mensual (día/semana)  
12. Coherencia indicadores + “Sin datos”

**Resultado (suite completa):** **163 pruebas aprobadas**, **1003 aserciones** (línea base previa: 153 / 931).

---

## 8. Verificaciones en navegador

Pendiente / recomendado tras desplegar localmente:
- Login auxiliar1 / auxiliar2 → menú solo operación; sin “Nueva”.  
- Login admin → Administración desplegable + Nueva PQRSD.  
- Bandeja: filtros principales / Más filtros; motivo y combinación sede+EPS+tipo+fecha.  
- Indicadores: año completo vs mes; tooltips; “Sin datos” con año vacío.  
- Logo CEM en header y menú; tema claro/oscuro; móvil (menú lateral).

---

## 9. Restricciones respetadas

- No Gmail API / OAuth / Pub/Sub / correos reales.  
- No restaurar Dashboard.  
- No alterar reglas SLA ni meta 95%.  
- No debilitar `PqrsdPolicy` más allá de exigir `create_manual` para creación.  
- No nuevos roles; no eliminación de datos históricos ni catálogos.
