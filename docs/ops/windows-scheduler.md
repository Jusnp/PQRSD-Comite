# Scheduler y worker en Windows (Laragon / desarrollo)

## Scheduler

Laravel no ejecuta el schedule solo. En desarrollo use una de estas opciones:

### Opción A — `schedule:work` (consola dedicada)

```powershell
cd C:\Users\Juan\Desktop\Trabajo\Pqrs
php artisan schedule:work
```

Deja la consola abierta. Al cerrar sesión se detiene.

### Opción B — Programador de tareas de Windows

1. Abrir **Programador de tareas**.
2. Crear tarea básica → desencadenador **Cada 1 minuto**.
3. Acción: iniciar programa
   - Programa: `C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.exe`
   - Argumentos: `artisan schedule:run`
   - Inicio en: `C:\Users\Juan\Desktop\Trabajo\Pqrs`
4. Marcar **Ejecutar tanto si el usuario inició sesión como si no** (requiere cuenta de servicio).

Evidencia esperada:

- `storage/logs/correo-sync.log`
- `storage/logs/sla-cumplimiento.log`
- Tabla `ejecuciones_programadas`

Verificación:

```powershell
php artisan schedule:list
php artisan schedule:run
php artisan pqrsd:ops-health --json
```

## Worker de colas

```powershell
php artisan queue:work database --queue=default --sleep=3 --tries=3 --timeout=120 --max-time=3600
```

Para persistencia en Windows evalúe **NSSM** o un servicio Windows que ejecute el comando anterior y reinicie ante fallos. No depender de una consola manual en producción.

Heartbeat: `storage/logs/queue-worker.heartbeat` (actualizado por jobs de sync).
