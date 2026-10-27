# Auditoría funcional integral — PQRSD Comité de Estudios Médicos

**Fecha:** 2026-10-18  
**Institución:** Comité de Estudios Médicos  
**Alcance:** Estado del sistema **antes** de activar integración Google Workspace  
**Stack verificado:** Laravel 13 · PHP 8.3.33 · MySQL (local Laragon) · Spatie Permission  

**Restricciones respetadas en esta auditoría:** sin Gmail API real, sin OAuth, sin Pub/Sub, sin correos reales, sin módulos nuevos, sin cambio de roles, sin recalcular SLA históricos, sin alterar identidad/logo, sin datos reales de pacientes.

---

## 1. Resumen ejecutivo

El núcleo operativo PQRSD (radicación pública, bandeja compartida, clasificación, remisiones, respuesta, cierre con evidencia, indicadores al 95 %, permisos admin/auxiliar, concurrencia MySQL y consulta segura) está **funcionalmente terminado en laboratorio** y respaldado por pruebas automatizadas.

**Veredicto global: NO LISTO PARA PRODUCCIÓN únicamente por PHPUnit.**  
El sistema **sí está listo para iniciar la fase de integración Google Workspace** desde el punto de vista funcional interno, siempre que Calidad/Jurídica/SSI/Infraestructura cierren los bloqueantes institucionales listados más abajo.

| Dimensión | Veredicto |
|-----------|-----------|
| Flujo ciudadano | **VERIFICADO** (laboratorio) |
| Operación auxiliares + admin | **VERIFICADO** |
| Reglas SLA en código/semilla | **VERIFICADO** (parámetros ordinarios/denuncia pendientes de firma institucional) |
| Seguridad HTTP / roles / adjuntos / tokens | **VERIFICADO** (con un hallazgo crítico de notificaciones corregido en esta auditoría) |
| Integridad y concurrencia | **VERIFICADO** (MySQL real + suite habitual) |
| UX visual (escritorio/móvil, claro/oscuro, logo) | **VERIFICADO** (smoke navegador local) |
| Infraestructura productiva / Workspace | **BLOQUEADO POR DEPENDENCIA INSTITUCIONAL** |

### Corrección mínima aplicada en esta auditoría

| Hallazgo | Causa | Corrección | Prueba |
|----------|-------|------------|--------|
| Alertas vitales SLA sin destinatarios operativos | `pqrsd_sla.destinatarios_urgencia_roles` apuntaba solo a `lider_pqrsd` / `superadministrador` (roles no usados en operación diaria); con bandeja compartida sin `responsable_id`, las notificaciones quedaban `omitida` | Se alinearon destinatarios a `admin` + `auxiliar_pqrsd` (conservando roles legacy por compatibilidad) | `SlaVencimientosTest::test_alerta_vital_llega_a_admin_y_auxiliar_sin_responsable_asignado` |

No se modificaron plazos SLA (8/24/48/72 h ni 10 días hábiles), ni roles Spatie, ni OAuth/Gmail.

---

## 2. Matriz de componentes

Leyenda:

- **VERIFICADO** — comprobado en código + pruebas y/o smoke visual local.
- **FUNCIONAL, PENDIENTE DE VALIDACIÓN** — existe y opera en lab; falta UAT institucional o firma.
- **REQUIERE CORRECCIÓN** — defecto real (si aplica; ver sección 3).
- **BLOQUEADO POR DEPENDENCIA INSTITUCIONAL** — no se puede cerrar sin decisión/provisionamiento externo.

| # | Componente | Estado | Evidencia / nota |
|---|------------|--------|------------------|
| C01 | Formulario público identificado / anónimo | VERIFICADO | `FormularioPublicoController`, tests anónimo/medios |
| C02 | Medios de respuesta (email, correspondencia, consulta segura) | VERIFICADO | Validaciones condicionales + config medios |
| C03 | Token de consulta (hash SHA-256, no en URL GET) | VERIFICADO | `consulta_token_hash`; rechazo GET con token |
| C04 | Adjuntos PDF/Word/imagen (disco privado + MIME) | VERIFICADO | `AdjuntoPqrsdService` + tests adjuntos |
| C05 | Validaciones obligatorias + Ley 1581 | VERIFICADO | `acepto_tratamiento_datos`, honeypot, throttle |
| C06 | Generación de radicado + anti doble radicación | VERIFICADO | UUID idempotencia en caché + rate limit |
| C07 | Consulta segura ciudadana | VERIFICADO | `/publico/consulta` smoke + tests |
| C08 | Accesibilidad móvil formulario/bandeja | VERIFICADO | Viewport 390×844 smoke; menú colapsable |
| C09 | Bandeja compartida (admin + 2 auxiliares) | VERIFICADO | `pqrsd.view_all`; sin asignación obligatoria |
| C10 | Filtros sede/EPS/tipo/motivo/estado/riesgo | VERIFICADO | `PqrsdController@index` + UI bandeja |
| C11 | Clasificar / actuaciones / adjuntos internos | VERIFICADO | Policy + tests flujo auxiliar |
| C12 | Remisiones a otras áreas | VERIFICADO | `RemisionInternaService` + tests |
| C13 | Respuesta oficial + cierre con evidencia | VERIFICADO | `VencimientoService`; cerrado → mutaciones 403 |
| C14 | Auxiliar sin usuarios/catálogos/SLA/integraciones manage | VERIFICADO | Deny list + 403 HTTP en tests |
| C15 | Asignación de responsable deshabilitada | VERIFICADO | `PqrsdPolicy::assign` → `false` siempre |
| C16 | Admin: usuarios, catálogos, SLA, radicación manual, ops | VERIFICADO | Menú Administración + permisos sync all |
| C17 | SLA vital menor 8h / vital 24h / priorizado 48h / simple 72h | VERIFICADO | `CatalogosSeeder` + `SlaVencimientosTest` |
| C18 | Denuncias 10 días hábiles | FUNCIONAL, PENDIENTE DE VALIDACIÓN | Parametro activo; flag aprobación jurídica pendiente |
| C19 | Peticiones/quejas 10 días hábiles (ordinario) | FUNCIONAL, PENDIENTE DE VALIDACIÓN | `ordinario_validacion_juridica_pendiente=true` |
| C20 | Meta oportunidad 95 % | VERIFICADO | `PQRSD_META_OPORTUNIDAD` / UI indicadores |
| C21 | Indicadores: cohorte `fecha_recepcion` vs `fecha_respuesta` | VERIFICADO | `IndicadoresPqrsdService` + pantalla indicadores |
| C22 | No recálculo automático de históricos al cambiar reglas | VERIFICADO | Documentado en seeder; retiro regla provisional sin recalcular |
| C23 | Autenticación / CSRF / XSS (Blade escape) | VERIFICADO | Breeze + middleware auth; CSRF Laravel |
| C24 | Autorización backend ante HTTP manipulado | VERIFICADO | Middleware permission + Policy + tests 403 |
| C25 | Expedientes cerrados inmutables | VERIFICADO | Policy + locks + tests integridad/MySQL |
| C26 | Concurrencia (locks + `expediente_version` obligatorio) | VERIFICADO | Suite MySQL 6/40 + auditoría integridad |
| C27 | Auditoría técnica / actuaciones | VERIFICADO | Tablas auditoría + historial estados |
| C28 | Datos clínicos / adjuntos privados | VERIFICADO | Disco no público; descarga autorizada auditada |
| C29 | Migraciones e integridad relacional | VERIFICADO | `migrate:status` completo en lab |
| C30 | Identidad visual / logo auténtico Comité | VERIFICADO | `config/identidad.php` + PNG en `public/images` |
| C31 | Temas claro / oscuro | VERIFICADO | Toggle preferencia; smoke bandeja oscura |
| C32 | Dashboard independiente | VERIFICADO (ausente a propósito) | `/` → bandeja; no restaurado |
| C33 | QR institucional | VERIFICADO | `/publico/qr` + descarga PNG/SVG |
| C34 | Scheduler / worker / backups en lab | FUNCIONAL, PENDIENTE DE VALIDACIÓN | Comandos existen; `cron_parece_activo=false`, `worker_active=false` en ops local |
| C35 | HTTPS, dominio, SO prod, secrets vault | BLOQUEADO POR DEPENDENCIA INSTITUCIONAL | Checklist infraestructura |
| C36 | Gmail API / OAuth / Pub/Sub | BLOQUEADO POR DEPENDENCIA INSTITUCIONAL | Fuera de alcance; no activado |
| C37 | Backup offsite + restore lab institucional | BLOQUEADO POR DEPENDENCIA INSTITUCIONAL | Destino externo pendiente SSI |
| C38 | Destinatarios alertas vitales (roles operativos) | VERIFICADO | Corregido 2026-10-18 (antes omitía destinatarios) |
| C39 | Permiso Spatie residual `pqrsd.assign` en auxiliar | FUNCIONAL, PENDIENTE DE VALIDACIÓN | Middleware puede pasar; Policy niega siempre (403 efectivo). Limpieza cosmética opcional |
| C40 | Texto tratamiento de datos (responsable del tratamiento) | BLOQUEADO POR DEPENDENCIA INSTITUCIONAL | Requiere revisión Jurídica |
| C41 | UAT institucional con datos ficticios trazadores | FUNCIONAL, PENDIENTE DE VALIDACIÓN | Plan piloto documentado; acta pendiente |
| C42 | Auxiliar con `correos.review` / `correos.reply` | FUNCIONAL, PENDIENTE DE VALIDACIÓN | No administra cuentas (`correos.manage` denegado); validar si revisión de bandeja correo debe restringirse antes de Workspace |

---

## 3. Funcionalidades verificadas

### 3.1 Flujo ciudadano

1. Ciudadano abre `/publico/pqrsd` (identidad Comité; aviso de no urgencias).  
2. Puede radicar identificado o anónimo; elige medio de respuesta.  
3. Acepta tratamiento de datos; opcionalmente adjunta PDF/Word/imagen.  
4. Recibe radicado + token (token no se persiste en claro; solo hash).  
5. Consulta en `/publico/consulta` por POST (token no viaja en query GET).  
6. Controles: throttle, honeypot, idempotency UUID, validación MIME en servicio.

### 3.2 Operación auxiliares

Ambos auxiliares (`auxiliar_pqrsd`) con `pqrsd.view_all`:

- Ven la misma bandeja; filtran por sede, EPS, tipo, motivo, estado, riesgo y avanzados.  
- Abren expediente; clasifican; registran actuaciones; adjuntan; remiten a áreas; responden; cierran con evidencia.  
- Consultan indicadores y reportes.  
- **403** en usuarios, catálogos, reglas SLA, cuentas correo (manage), radicación manual.  
- Asignación HTTP siempre denegada (bandeja compartida).

### 3.3 Administración

Rol `admin` conserva gestión de usuarios, catálogos, SLA, radicación manual, supervisión, reportes, QR, salud operativa e integraciones (sin activar Workspace en esta fase).

### 3.4 Reglas e indicadores

| Regla | Valor en sistema | Estado institucional |
|-------|------------------|----------------------|
| Vital menor | 8 h calendario | Operativo |
| Vital general | 24 h calendario | Operativo |
| Priorizado | 48 h calendario | Operativo |
| Simple | 72 h calendario | Operativo |
| Ordinario (peticiones/quejas) | 10 días hábiles | **Pendiente firma Jurídica/Calidad** |
| Denuncia | 10 días hábiles | **Parámetro de trabajo; pendiente aprobación producción** |
| Meta oportunidad | 95 % | Operativo (`fecha_respuesta` vs `fecha_limite`; cohorte por `fecha_recepcion`) |

### 3.5 Seguridad e integridad

- Auth sesión + permisos Spatie en rutas.  
- Policies en mutaciones; cerrados bloqueados en backend.  
- `lockForUpdate` + `expediente_version` SHA-256 obligatorio en clasificar/responder/cerrar.  
- Adjuntos fuera de `public/`; descarga autorizada con auditoría.  
- Migraciones aplicadas; trazabilidad actuaciones/historial/auditoría.

---

## 4. Problemas encontrados

| Severidad | Problema | Estado |
|-----------|----------|--------|
| **Crítica (ops)** | Alertas vitales sin destinatarios en roles operativos reales | **Corregido** en `config/pqrsd_sla.php` + prueba de regresión |
| Baja | Auxiliar aún posee permiso Spatie `pqrsd.assign` aunque Policy lo anula | Documentado; no bloquea (403 efectivo) |
| Informativa | `ops-health` local: cron/worker no activos como servicio | Esperado en Laragon; no es bug de aplicación |
| Informativa | Suite PHPUnit ≠ certificación productiva | Reforzado en veredicto |

No se hallaron otros defectos críticos de integridad HTTP en esta pasada que requieran corrección inmediata.

---

## 5. Riesgos de seguridad

| Riesgo | Nivel residual | Mitigación actual | Pendiente |
|--------|----------------|-------------------|-----------|
| Enumeración / abuso radicación pública | Medio | Throttle, honeypot, idempotencia | WAF/rate en reverse proxy prod |
| Filtrado token en logs/Referer | Bajo | Consulta solo POST; hash en BD | Revisar retención logs access |
| Acceso a adjuntos clínicos | Medio | Policy + disco privado + auditoría descarga | Cifrado en reposo institucional |
| Escalada auxiliar → admin | Bajo | Deny list + tests 403 | Mantener reseed controlado |
| CSRF / XSS | Bajo | Tokens Laravel; escape Blade | CSP headers en prod |
| Secretos OAuth/APP_KEY | Alto si se filtran | Fuera de alcance Workspace aún | Vault SSI; no regenerar APP_KEY con tokens cifrados |
| Notificaciones vitales fallidas | Medio → Bajo | Destinatarios alineados a roles ops | Canal correo real aún mock/log |

---

## 6. Estado de integridad de datos

| Aspecto | Resultado |
|---------|-----------|
| Migraciones | Completas en entorno lab (hasta remisiones internas) |
| Relaciones expediente–actuaciones–adjuntos–remisiones | Consistentes en tests de integridad |
| Duplicados de radicación pública | Mitigados por idempotency key |
| Consistencia respuesta oficial | Una respuesta oficial; cierre exige evidencia |
| SLA preservado en mutaciones concurrentes | Verificado MySQL real |
| Recuperación ante error de adjuntos | Rollback BD + borrado de archivos del lote |
| Datos reales | **No utilizados** en esta auditoría |

---

## 7. Estado de permisos

| Capacidad | admin | auxiliar_pqrsd |
|-----------|-------|----------------|
| Bandeja / ver todos | Sí | Sí |
| Clasificar / gestionar / cerrar | Sí | Sí |
| Reportes / indicadores | Sí | Sí |
| Radicación manual | Sí | No |
| Usuarios | Sí | No |
| Catálogos / SLA | Sí | No |
| Cuentas correo (manage) | Sí | No |
| Asignar responsable | No (Policy global) | No (Policy global) |
| QR / formulario público (vista ops) | Sí | Sí |

Roles legacy (`lider_pqrsd`, `responsable_area`, `auditor`, `superadministrador`) existen solo por compatibilidad de pruebas; **no deben asignarse a operadores reales**.

---

## 8. Validación visual

Smoke en navegador local (`http://127.0.0.1:8000`), 2026-10-18:

| Pantalla | Escritorio | Móvil (~390px) | Claro | Oscuro | Logo Comité |
|----------|------------|----------------|-------|--------|-------------|
| Login / guest | OK (redirige a bandeja si hay sesión) | — | OK | OK | OK |
| Bandeja | OK | OK (menú colapsable) | OK | OK | OK |
| Expediente | OK (cerrado en solo lectura) | OK | OK | OK | OK |
| Formulario público | OK | OK | OK | OK | OK |
| Consulta pública | OK | OK | OK | OK | OK |
| QR | OK | — | OK | OK | OK |
| Indicadores | OK (meta 95 %, aviso denuncia jurídica) | — | OK | OK | OK |
| Reportes | OK | — | OK | OK | OK |
| Administración (menú) | Visible solo con permisos | — | — | — | — |

**Dashboard independiente:** no restaurado (comportamiento intencional).

---

## 9. Pruebas ejecutadas

| Suite | Resultado | Aserciones |
|-------|-----------|------------|
| Habitual (`php artisan test`) | **194 aprobadas** (tras +1 regresión alertas vitales; línea base previa 193/1.303) | **1.308** |
| Concurrencia MySQL (`phpunit.mysql-concurrency.xml`) | **6 aprobadas** | 40 |
| Filtro `SlaVencimientosTest` post-corrección | **10/10** | 30 |

Interpretación: las suites demuestran comportamiento reproducible en laboratorio (incl. dos procesos MySQL concurrentes). **No equivalen a** certificación de infraestructura productiva, UAT institucional ni piloto Gmail.

---

## 10. Bloqueantes de producción

### 10.1 Antes de go-live (independientes de Workspace)

1. Aprobación formal Jurídica/Calidad de plazos **ordinario** y **denuncia** (10 días hábiles).  
2. Acta de texto de tratamiento de datos / responsable del tratamiento.  
3. Servidor prod (SO, PHP 8.3, HTTPS, MySQL, usuario de servicio).  
4. Cron `schedule:run` + worker supervisado (evidencia en `ops/salud`).  
5. Secrets en vault; `APP_DEBUG=false`; sesión segura.  
6. Destino backup offsite + prueba de restauración.  
7. UAT con casos ficticios y acta de aceptación parcial/total.

### 10.2 Específicos de Google Workspace (fase siguiente — no activados)

Inventario de buzones, OAuth piloto, Gmail API, Pub/Sub, scopes SSI, buzón canónico. Ver `docs/fase4-dependencias-institucionales.md` y `docs/guia-admin-google-workspace.md`.

### 10.3 Parámetros que requieren aprobación formal Calidad / Jurídica

| Parámetro | Valor actual en sistema | Aprobador |
|-----------|-------------------------|-----------|
| `ORDINARIO_10DH` | 10 días hábiles | Jurídica + Calidad |
| `DENUNCIA_10DH` | 10 días hábiles (desarrollo) | Jurídica + Calidad |
| Meta oportunidad | 95 % | Calidad |
| Texto consentimiento Ley 1581 | Copy institucional en formulario | Jurídica / Habeas data |
| Destinatarios alertas vitales | admin + auxiliar_pqrsd (+ legacy) | Calidad / TI (confirmar canal de notificación real) |
| Retención logs y backups con PHI | Config pendiente | SSI |

---

## 11. Recomendaciones priorizadas

### P0 — Antes de Workspace o go-live

1. Obtener firmas de plazos ordinario/denuncia y texto de datos personales.  
2. Provisionar infraestructura prod según `docs/checklist-infraestructura-productiva.md`.  
3. Activar scheduler + worker como servicios (no consola Laragon).  
4. Confirmar que el canal de correo de notificaciones vitales no sea solo `log` en prod.

### P1 — Al iniciar Workspace (sin adelantar en esta auditoría)

5. Seguir guía de inventario de buzones y OAuth piloto con datos ficticios.  
6. Decidir institucionalmente si auxiliares deben conservar `correos.review`/`reply` antes del piloto.  
7. Ejecutar UAT trazador (`docs/plan-piloto-pqrsd.md`).

### P2 — Mejora continua (no bloqueante)

8. Quitar permiso Spatie residual `pqrsd.assign` del rol auxiliar (higiene; Policy ya bloquea).  
9. Hardening headers CSP/HSTS en reverse proxy.  
10. Ejercicio periódico de restore de backup.

---

## 12. Distinción laboratorio vs producción

| Verificado localmente | Aún no validado en producción |
|-----------------------|-------------------------------|
| Flujos PQRSD, permisos, SLA motor, indicadores, QR, temas, logo | HTTPS, dominio, cron real, worker supervisado |
| 194 tests (1.308 aserciones) + 6 MySQL concurrencia (40) | Carga real, HA, monitoreo 24/7 |
| Backup/comando verify en lab | Offsite institucional + RPO/RTO firmados |
| Driver Gmail en código + fakes | OAuth real, Pub/Sub, sync con buzones institucionales |
| Ops health CLI | Alertas operativas institucionales |

---

## 13. Conclusión para la siguiente fase

**Componentes realmente terminados (lab):** flujo ciudadano completo, operación de auxiliares en bandeja compartida, administración, motor SLA e indicadores 95 %, seguridad HTTP de expedientes/adjuntos/tokens, integridad concurrente, identidad visual del Comité.

**Componentes que requieren validación institucional antes/durante Workspace:** plazos ordinario y denuncia, texto de datos personales, infraestructura prod, backups offsite, UAT, y todo el bloque Gmail/OAuth/Pub/Sub.

**No se declara listo para producción** solo porque PHPUnit aprueba.  
**Sí se declara apto para planificar la integración Google Workspace** sobre una base funcional interna estable, cerrando en paralelo los bloqueantes P0 de Calidad/Jurídica/Infra/SSI.

---

*Documento generado en auditoría integral 2026-10-18. Referencias cruzadas: `docs/auditoria-integridad-expedientes-pqrsd.md`, `docs/fase4-dependencias-institucionales.md`, `docs/checklist-infraestructura-productiva.md`.*
