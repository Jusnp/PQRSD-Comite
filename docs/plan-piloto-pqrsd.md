# Plan piloto PQRSD — Gmail → cierre (datos ficticios)

**Precondiciones:** inventario del buzón piloto clasificado como `individual` (o alias con canónico), OAuth autorizado, `GMAIL_PILOT_MAILBOX` definido, `pqrsd:gmail-pilot-status` = listo, servidor de ensayo con HTTPS si aplica.

**Alcance:** un solo buzón. Sin importación masiva. Sin pacientes reales salvo autorización SSI/Calidad (este plan asume **ficticio**).

## Pasos

| # | Acción | Evidencia |
|---|--------|-----------|
| 1 | Enviar correo ficticio PQRSD al piloto | Message-ID / captura |
| 2 | Sync o notificación Pub/Sub | `sincronizaciones_correo` / `gmail_pubsub_events` |
| 3 | Revisar bandeja app → confirmar/radicar | Radicado generado |
| 4 | Clasificar riesgo / SLA | `fecha_limite`, regla |
| 5 | Asignar responsable | Actuación reasignación |
| 6 | Registrar seguimiento | Actuaciones |
| 7 | Responder (Gmail send) | `message_id` de aceptación Gmail |
| 8 | Distinguir: aceptado por API ≠ entregado al buzón destino | Log envío + (opcional) lectura de Sent |
| 9 | Cerrar con evidencia | `estado_operativo=cerrado` |
| 10 | Exportar trazabilidad | Historial + auditorías |
| 11 | Backup + restore aislado (lab) | ID `respaldos_pqrsd` + lab path |
| 12 | Verificar radicado y adjunto en lab | Descarga autorizada |

## Pruebas de fallo (ensayo)

| Escenario | Esperado |
|-----------|----------|
| Token OAuth inválido | Error visible; cursor no avanza indebidamente |
| Pub/Sub duplicado | Un solo procesamiento (`message_id` único) |
| Worker detenido | Jobs quedan pending; al reanudar no doble radicado |
| Scheduler caído | Ops health marca cron no fresco |
| Envío fallido | Error registrado; caso no cerrado sin evidencia |
| Usuario ajeno descarga adjunto | HTTP 403 |

## Criterio de éxito del piloto

Todos los pasos 1–12 con datos ficticios + al menos dos fallos recuperados sin doble radicado.  
Firma Calidad + TI en `acta-aceptacion-produccion.md` (sección piloto).
