# Formulario público / QR y permisos PQRSD — Mente Plena

Fecha: 2026-10-15  
Línea base previa: 118 pruebas / 496 aserciones  
Resultado actual: **129 pruebas / 568 aserciones — todas aprobadas**

## 1. Estado inicial

Ya existían:

- Roles Spatie `admin` y `auxiliar_pqrsd` (más legacy solo para tests).
- Formulario público (`/publico/pqrsd`), confirmación, consulta con token y página QR.
- Radicación vía `PqrsdService::radicarManual`, canales `WEB` / `QR`.
- Descarga autorizada de adjuntos (`AdjuntoPqrsdController`) con auditoría.
- Catálogo de seis tipos de manifestación.

Faltaban (completado en esta entrega):

- CRUD de usuarios para el administrador.
- Enlace de menú **Formulario público / Código QR** para operadores.
- Carga de documentos en el formulario público (validación, disco privado, asociación).
- Aviso de QR no distribuible cuando la URL es local.
- Descarga SVG/PNG del QR y configuración `PQRSD_PUBLIC_URL`.
- Idempotencia ante doble envío.
- Riesgo forzado a `pendiente` en radicación ciudadana (sin fijar SLA vital desde el ciudadano).

## 2. Permisos definitivos

### Administrador (`admin`)

Todos los permisos Spatie, incluyendo:

| Capacidad | Permiso |
|-----------|---------|
| Usuarios / roles operativos | `usuarios.manage` |
| Catálogos (sedes, EPS, canales, tipos, motivos) | `*.manage` |
| Reglas SLA | `reglas_sla.manage` |
| Integraciones / cuentas correo / ops | `correos.manage` |
| Operación PQRSD completa | `pqrsd.*` |
| Reportes e indicadores | `reportes.view` |
| Auditoría | `auditoria.view` |

Rutas nuevas: `usuarios` (resource excepto show/destroy), protegidas con `permission:usuarios.manage`.

### Auxiliar PQRSD (`auxiliar_pqrsd`)

Operación diaria: bandeja completa (`pqrsd.view_all`), radicar, actualizar, clasificar/asignar (permisos), cerrar, reportes, revisión/respuesta correo, ver QR/formulario público.

**Denegado:** `usuarios.manage`, `catalogos.manage`, `sedes.manage`, `aseguradoras.manage`, `canales.manage`, `tipos.manage`, `motivos.manage`, `reglas_sla.manage`, `correos.manage`.

Las restricciones se aplican en middleware de rutas (no solo ocultando botones).

Cuentas iniciales de laboratorio (seeder no productivo): 1 admin + 2 auxiliares.

## 3. Formulario público implementado

- Ruta: `GET/POST /publico/pqrsd` (`publico.formulario` / `publico.store`)
- Sin autenticación Laravel.
- Secciones A–D (solicitante, paciente, manifestación, documentos).
- Honeypot, rate limit, CSRF, token de idempotencia.
- Confirmación con radicado, recepción, tipo y token de consulta (una vez).

## 4. Tipos de manifestaciones

Desde catálogo `tipos_pqrsd` (no hardcodeados en lógica de negocio):

1. Petición  
2. Queja  
3. Reclamo  
4. Sugerencia  
5. Denuncia  
6. Felicitación  

El tipo se persiste en `tipo_pqrsd_id`. El riesgo queda `pendiente` / `pendiente_clasificacion` hasta revisión del personal autorizado. El formulario no modifica reglas SLA.

## 5. Manejo de documentos

- Hasta 5 archivos, 10 MB c/u (configurable: `PQRSD_PUBLICO_MAX_FILES`, `PQRSD_PUBLICO_MAX_KB`).
- Extensiones: `pdf`, `doc`, `docx`, `jpg`, `jpeg`, `png`, `webp`.
- UI: listado con nombre, tamaño, tipo, estado y quitar antes de enviar.
- Asociación en `adjuntos_pqrsd` con tipo `soporte_publico`.
- Visible en bandeja (columna Adjuntos) y en detalle del expediente.

## 6. Seguridad de archivos

Implementado:

- Validación servidor (extensión, tamaño, MIME/finfo, coherencia extensión↔contenido, cabeceras PDF/DOCX).
- Disco privado (`config('pqrsd_correo.adjuntos_disk')` → `storage/app/private`).
- Nombres internos UUID.
- Descarga solo con `authorize('view', $pqrsd)` + auditoría `descargas_adjunto_pqrsd`.
- Sin URLs permanentes públicas a documentos de pacientes.
- Auditoría de carga (`adjunto.cargar`).

**No implementado:** escaneo antimalware / cuarentena. No se declara protección antivirus hasta que TI lo provea.

## 7. Código QR

- Menú: **Formulario público / Código QR** (`publico.qr`).
- Acciones: ver URL, abrir formulario, copiar enlace, visualizar QR, descargar SVG/PNG.
- Regeneración automática si cambia la URL base.
- Admin y auxiliares pueden ver/compartir/descargar.
- Solo el admin modifica catálogos/config crítica (no hay editor de “enlace oficial” aparte de env; la URL sale de `PQRSD_PUBLIC_URL` / `APP_URL`).

## 8. Ruta pública utilizada

| Recurso | Ruta |
|---------|------|
| Formulario | `/publico/pqrsd` |
| Formulario vía QR | `/publico/pqrsd?qr=1` |
| Confirmación | `/publico/confirmacion` |
| Consulta | `/publico/consulta` |
| QR | `/publico/qr` |
| Descarga QR | `/publico/qr/descargar?format=svg\|png` |

URL absoluta del QR: `PublicUrl::formulario(true)` usando `PQRSD_PUBLIC_URL` o `APP_URL`.

## 9. Flujo de radicación

1. Validar campos y adjuntos.  
2. Rechazar si honeypot / urgencia vital marcada / rate limit.  
3. Idempotencia por `idempotency_key` (caché 30 min).  
4. `PqrsdService::radicarManual` (radicado, recepción servidor, tipo, datos, auditoría).  
5. Canal `WEB` o `QR`, origen público.  
6. Guardar adjuntos privados y asociar.  
7. Token de consulta (hash SHA-256).  
8. Redirect a confirmación.  

Si falla el almacenamiento de adjuntos, se revierte la transacción y se borran archivos del lote.

## 10. Integración con la bandeja

Los casos públicos aparecen en la bandeja principal con radicado, recepción, solicitante, clasificación, sede, aseguradora, canal, estado, riesgo, plazo, responsable y cantidad de adjuntos. Estado inicial sin respuesta/cierre automático.

## 11. Archivos modificados / creados

**Nuevos:**  
`config/pqrsd_publico.php`, `app/Support/PublicUrl.php`, `app/Services/AdjuntoPqrsdService.php`, `app/Http/Controllers/UsuarioController.php`, `resources/views/usuarios/*`, `tests/Feature/FormularioPublicoAdjuntosYPermisosTest.php`, este documento.

**Actualizados:**  
`FormularioPublicoController`, `routes/web.php`, `PqrsdController`, layouts `pqrsd`/`publico`, vistas `publico/*`, `pqrsd/index`, `.env.example`.

## 12. Pruebas ejecutadas

Suite completa: `php artisan test`  
Incluye `FormularioPublicoAdjuntosYPermisosTest` (admin/auxiliar, formulario, adjuntos, doble envío, QR, consulta segura, bandeja) y regresiones previas.

## 13. Resultado de pruebas

```
Tests: 129 passed
Assertions: 568
```

## 14. Verificación manual en navegador

Recomendada en el entorno local:

1. `/publico/qr` — debe mostrar aviso de prueba si `APP_URL` es localhost.  
2. `/publico/pqrsd` — radicar con PDF/imagen y copiar radicado.  
3. Login auxiliar — menú QR visible; `/usuarios` → 403.  
4. Login admin — CRUD usuarios; bandeja con adjuntos; descarga de soporte.

## 15. Código QR — desarrollo y URL pública

`http://127.0.0.1:8010` es solo el servidor de desarrollo. La pantalla `/publico/qr` marca el código como **no apto para pacientes** cuando la URL es local, usa un dominio no resoluble o no es HTTPS definitivo.

Para preparar un QR imprimible, solo después de un despliegue autorizado:

1. Definir `PQRSD_PUBLIC_URL` con la URL pública HTTPS del formulario, sin barra final. Ejemplo de forma: `https://pqrsd.ejemplo-institucional.gov.co`.
2. No usar `127.0.0.1`, `localhost` ni el puerto 8010 en ese valor.
3. Reiniciar la aplicación para que tome la variable. No ejecutar `config:cache` en este entorno de desarrollo.
4. Abrir `/publico/qr` y comprobar que el aviso de prueba desaparece y que el enlace mostrado es exactamente esa URL HTTPS.
5. Descargar el PNG o el SVG desde esa misma pantalla. No distribuir un QR generado mientras el aviso de prueba siga visible.

Esta tarea no publica el sistema ni cambia dominios.

## 16. Pendientes para producción

- Definir `PQRSD_PUBLIC_URL=https://…` (HTTPS) antes de imprimir QR.
- Integrar escaneo antimalware / cuarentena si TI lo aprueba.
- Confirmar límites de tamaño con TI/infra.
- No activar Gmail/OAuth/Pub/Sub en esta fase (fuera de alcance).
- Capacitar operadores en clasificación de riesgo post-radicación pública.
