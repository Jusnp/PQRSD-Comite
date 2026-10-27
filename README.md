# Sistema PQRSD — Comité de Estudios Médicos

Plataforma Laravel para gestión integral de Peticiones, Quejas, Reclamos, Sugerencias y Denuncias.

## Stack

- Laravel 13 (PHP 8.3+) — compatible con Laragon
- MySQL / MariaDB
- Blade + Livewire (instalado)
- Spatie Laravel Permission
- Zona horaria: `America/Bogota`

## Requisitos locales (Laragon)

1. PHP 8.3+, Composer, MySQL en ejecución
2. Crear/usar BD `pqrsd_menteplena`
3. Configurar `.env` (ya apunta a MySQL local por defecto)

## Instalación

```bash
composer install
copy .env.example .env   # si aplica
php artisan key:generate
php artisan migrate --seed
npm install
npm run build
php artisan serve
```

El servidor de desarrollo queda en `http://127.0.0.1:8010` por `SERVER_PORT` en `.env`. También puede usarse la tarea de Cursor **PQRSD: servir en 8010**.

En Laragon también puede mapearse el virtual host `pqrsd.test` al directorio `public`.

## Usuarios de prueba (solo desarrollo)

Las cuentas de laboratorio usan correos `@menteplena.test`. Su contraseña no está en el repositorio: defina `PQRSD_LAB_PASSWORD` en el `.env` local antes de ejecutar `php artisan db:seed`. El sembrado no corre en producción, no asigna clave si esa variable está vacía y no modifica otras cuentas. Las pruebas generan una clave aleatoria en memoria.

No incluyen datos clínicos reales ni credenciales productivas.

## Implementado en esta fase

- Autenticación (Breeze) y roles/permisos
- Catálogos: sedes, aseguradoras, canales, tipos, motivos, reglas SLA versionadas
- Radicación manual con número único `PQRSD-YYYY-NNNNNN`
- Trazabilidad (actuaciones, historial de estados, auditoría)
- Dashboard básico
- Pruebas de radicación, SLA y permisos

## Interfaz (tema SIRUTA)

- CSS fuente copiado (sin modificar SIRUTA): `public/css/pqrsd-theme.css`
- Extensiones PQRSD: `public/css/pqrsd-app.css`
- Público: `/publico/pqrsd`, `/publico/consulta`, `/publico/qr`
- Admin: dashboard, bandeja, detalle/timeline, reportes Excel/PDF
- Menú lateral estilo SIRUTA + tema claro/oscuro

## Motor de vencimientos (SLA)

| Riesgo | Plazo |
|---|---|
| Vital menor (&lt;18 en recepción) | 8 h calendario |
| Vital general | 24 h calendario |
| Priorizado | 48 h calendario |
| Simple | 72 h calendario |
| Ordinario | 10 días hábiles CO (pendiente validación jurídica) |

- Conteo desde **fecha de recepción** (no se reinicia por reasignación/estado)
- Semáforo: dentro de plazo / próximo / vencido / oportuno / tardío
- Notificaciones vitales inmediatas + recordatorios (`config/pqrsd_sla.php`)
- Comando: `php artisan pqrsd:actualizar-cumplimiento`
- Bandejas: `/vencimientos/proximos` y `/vencimientos/vencidos`
- Demo: `php artisan db:seed --class=DemoVencimientosSeeder`

## Correo multicanal

- Driver por defecto: `mock` (fixtures en `storage/app/correo/mock_inbox.json`)
- Cuentas institucionales sembradas; secretos solo vía `credential_ref` → variables `.env`
- Comando: `php artisan pqrsd:sync-correos --sync`
- Scheduler: cada 5 minutos (`routes/console.php`)
- UI: Cuentas correo + Bandeja de revisión
- Proveedor real (IMAP / Microsoft Graph) cableado pero bloqueado hasta confirmar tenant/host

## Pendiente (fases siguientes)

- Activar IMAP/Graph con credenciales institucionales reales
- Formulario público / QR
- Motor completo de alertas y escalamiento
- Respuestas formales, reportes Excel/PDF y consulta ciudadana segura
