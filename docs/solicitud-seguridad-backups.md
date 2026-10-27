# Solicitud a Seguridad de la Información — respaldos externos y recuperación

## Contexto técnico ya disponible en la aplicación

- Comando `pqrsd:backup` (MySQL lógico + adjuntos + manifest + checksum).  
- Cifrado AES-256-CBC opcional (`PQRSD_BACKUP_ENCRYPT` + `PQRSD_BACKUP_ENCRYPTION_KEY`).  
- Adaptadores: `null` | `local_staging` | `s3` (Laravel disk).  
- Restore aislado: `pqrsd:backup-restore-isolated` (**no** escribe sobre MySQL prod).  
- RPO/RTO: variables vacías hasta aprobación formal.

## Lo que SSI debe definir (no inventado por desarrollo)

| Tema | Pregunta a responder | Respuesta SSI |
|------|----------------------|---------------|
| Destino externo | ¿Object storage institucional, NAS cifrado, otro? | |
| Acceso | ¿Cuentas de servicio, MFA, red privada? | |
| Cifrado en tránsito/reposo | ¿Clave en vault (HSM/KMS)? | |
| Custodia de claves | Separada del dataset de backup | |
| Retención | Días/meses; legal salud | |
| Eliminación segura | Procedimiento | |
| Alertas | Canal si falla backup/transfer | |
| **RPO** | Máxima pérdida de datos tolerable (horas) | |
| **RTO** | Tiempo máximo de recuperación (horas) | |
| Datos clínicos | Clasificación y restricciones de copia | |

## Prueba de aceptación (cuando exista destino)

1. Generar respaldo.  
2. Cifrar.  
3. Transferir.  
4. Verificar checksum remoto/local.  
5. Recuperar a laboratorio aislado.  
6. Descifrar.  
7. Restaurar MySQL lab + adjuntos.  
8. Abrir un radicado ficticio y descargar adjunto con autorización.  
9. Firmar evidencia.

Hasta completar el paso 8–9, el estado permanece: **BLOQUEADO / no verificado externamente**.

## Entregables hacia TI

- Nombre del destino y método de autenticación (sin pegar secretos en ticket público).  
- Valores aprobados de retención, RPO y RTO para cargar en `.env` prod (`PQRSD_RPO_HOURS`, `PQRSD_RTO_HOURS`, `PQRSD_BACKUP_EXTERNAL_DRIVER`, …).
