# Respaldos y restauración PQRSD

## Qué había

`pqrsd:backup` generaba paquetes locales con checksum de `manifest.json`. Insuficiente para producción (sin cifrado offsite ni restore aislado documentado).

## Qué hay ahora

### A. Base de datos
- Dump lógico a `database.sql` (portable; no depende solo de mysqldump).
- Registro en `respaldos_pqrsd` (fecha, tamaño, checksum, estado).

### B. Archivos
- `adjuntos.zip` desde disco privado.
- Manifest con partes y checksums por archivo.

### C. Cifrado y externo
- `PQRSD_BACKUP_ENCRYPT=true` + `PQRSD_BACKUP_ENCRYPTION_KEY` (clave **fuera** del paquete; vault institucional).
- Formato `PQRSDBAK1` + AES-256-CBC.
- Adaptador `ExternalBackupStore`:
  - `null` → no inventa destino; marca `pendiente_destino`
  - `local_staging` → staging en host (no offsite)
  - `s3` → disk Laravel (requiere credenciales reales)

### D. Restauración aislada
```bash
php artisan pqrsd:backup-restore-isolated {id} --decrypt
```
Extrae a `storage/app/private/restore-lab/{id}`.  
**No importa SQL a MySQL de producción.** El import a laboratorio es manual/autorizado.

### E. Pruebas automatizadas
- Cifrado/descifrado OK  
- Detección de archivo corrupto  
- Destino `null` controlado  
- Verify checksum  

## RPO / RTO

`PQRSD_RPO_HOURS` / `PQRSD_RTO_HOURS` quedan vacíos hasta aprobación SSI.  
**No se inventan objetivos de recuperación.**

## Procedimiento restore (laboratorio)

1. Provisionar servidor/BD de prueba.  
2. `pqrsd:backup-restore-isolated` (+ decrypt).  
3. Importar `database.sql` en BD vacía de lab.  
4. Colocar adjuntos en disco privado de lab.  
5. Verificar radicado, auditoría y descarga autorizada.  
6. Comparar checksums del manifest.  

Nunca sobre prod. No subir respaldos a Git.

## Estado

| Ítem | Clasificación |
|------|---------------|
| Backup local + checksum + cifrado + staging | PREPARADO / tests VERIFICADOS |
| Offsite S3/institucional real | BLOQUEADO (destino no provisionado) |
| Restore completo E2E en lab con MySQL | BLOQUEADO (requiere entorno aislado autorizado) |
