# Constancia — plazos de ordinario y denuncia pendientes de validación

Fecha: 2026-10-23  
Sistema: PQRSD del Comité de Estudios Médicos

El área responsable (jurídica o de calidad, según corresponda) **aún no ha validado** los plazos de las PQRSD ordinarias ni los de las denuncias. Esta constancia no los declara aprobados.

## Valores que el sistema tiene configurados hoy

No se modifican en esta constancia. Viven en el catálogo `reglas_sla` y en `config/pqrsd_sla.php`.

| Situación | Valor configurado | Forma de conteo |
|---|---|---|
| Riesgo vital en menores de 18 años | 8 horas | Calendario, incluye noche, fin de semana y festivo |
| Riesgo vital general | 24 horas | Calendario |
| Riesgo priorizado | 48 horas | Calendario |
| Riesgo simple | 72 horas | Calendario |
| Ordinario | 10 días | Hábiles: no cuentan sábado, domingo ni festivo cargado |
| Denuncia | 10 días | Hábiles, con la misma regla |

Los indicadores de oportunidad usan la meta institucional del 95 %. Esa meta tampoco se modifica aquí.

## Qué falta

- Visto bueno institucional del plazo de 10 días hábiles para ordinario.
- Visto bueno institucional del plazo de 10 días hábiles para denuncia.

Mientras esa validación no exista, las banderas siguen en verdadero:

- `ordinario_validacion_juridica_pendiente`
- `denuncia_aprobacion_juridica_produccion_pendiente`

El cálculo ya está implementado para poder operarlo en laboratorio. No equivale a una aprobación jurídica ni a una autorización de salida a producción.
