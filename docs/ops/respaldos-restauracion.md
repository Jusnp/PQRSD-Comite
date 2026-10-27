# Respaldos y restauración PQRSD

## Generación

```bash
php artisan pqrsd:backup --only=all
php artisan pqrsd:backup-verify
```

Salida: `storage/app/private/backups/YYYYMMDD-HHMMSS/`

- `database.sql` (dump lógico)
- `adjuntos.zip`
- `manifest.json` + checksum SHA-256 en tabla `respaldos_pqrsd`

Programado diariamente 02:30 America/Bogota.

**No subir respaldos a Git.** Copiar a almacenamiento cifrado externo (política SSI).

## Restauración (ambiente aislado)

1. Provisionar servidor/BD de prueba (nunca producción).
2. Restaurar SQL en BD vacía de laboratorio.
3. Extraer `adjuntos.zip` sobre el disco privado configurado.
4. Verificar un expediente trazador: radicado, actuaciones, auditorías, descarga de adjunto.
5. Validar `php artisan pqrsd:backup-verify {id}` del paquete usado.

## Retención

`PQRSD_BACKUP_RETENTION_DAYS` (default 30). La purga automática de carpetas antiguas debe alinearse con política de SSI antes de activarse en prod.
