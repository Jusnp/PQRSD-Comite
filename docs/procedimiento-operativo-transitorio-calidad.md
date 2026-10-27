# Procedimiento operativo transitorio — Calidad (mientras Gmail real no esté activo)

**Naturaleza:** temporal. **No** sustituye la automatización ni garantiza por sí solo el cumplimiento normativo.  
**Prohibición:** no operar con pacientes reales hasta autorización SSI + Calidad + TI.

## Objetivo

Mantener continuidad del proceso PQRSD usando radicación controlada en la plataforma mientras OAuth/piloto Gmail y el servidor definitivo se habilitan.

## Roles

| Rol | Responsabilidad |
|-----|-----------------|
| Calidad / PQRSD | Recepción, radicación, plazos, cierre con evidencia |
| Responsable de área | Gestión y respuesta de fondo |
| TI | Disponibilidad app, conciliación técnica, backups locales |
| SSI | Autorizar alcance de datos |

## Flujo diario

1. **Recepción** por canales actuales (correo webmail institucional, ventanilla, formulario público `/publico/pqrsd`).  
2. **Radicación manual** en la app (`/pqrsd-create`) con fecha de recepción real y descripción.  
3. **Clasificación de riesgo** según reglas vigentes (vital menor/general, priorizado, simple).  
   - Riesgo vital → activar **protocolo institucional inmediato** (fuera de este documento).  
4. **Asignación** de responsable.  
5. **Seguimiento** de vencimientos en bandeja / `/vencimientos/*` y panel ops.  
6. **Respuesta de fondo** + **cierre con evidencia** (obligatorio en sistema).  
7. **Conciliación diaria** (fin de jornada):  
   - Listar correos/solicitudes del día en el canal origen.  
   - Contrastar con radicados creados.  
   - Registrar “sin radicar” y causa (tabla o acta diaria).  
8. **Alertas críticas:** escalar a lider_pqrsd según protocolo interno.

## Evidencia mínima por caso

- Fecha recepción / radicado.  
- Canal y origen.  
- Clasificación y fecha límite.  
- Responsable.  
- Actuaciones y respuesta.  
- Cierre.  
- Soportes (adjuntos vía descarga autorizada).

## Límites explícitos

- No afirmar sincronización automática Gmail.  
- No conectar buzones reales sin inventario.  
- No usar este procedimiento como estado permanente post go-live.

## Cierre del régimen transitorio

Cuando el piloto Gmail y la infraestructura productiva cumplan `acta-aceptacion-produccion.md`, Calidad declara fin del régimen transitorio y pasa a operación asistida por sync/watch.
