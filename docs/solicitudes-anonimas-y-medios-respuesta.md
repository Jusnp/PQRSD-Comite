# Solicitudes anónimas y medios de respuesta — PQRSD Mente Plena

Fecha: 2026-10-15  
Estado: implementado en formulario público; **pendiente validación formal de Calidad y Jurídica** antes de uso productivo.

## 1. Objetivo

Permitir radicar PQRSD identificadas o, cuando corresponda, **sin revelar identidad**, conservando siempre un mecanismo para comunicar o consultar la respuesta (correo, correspondencia o consulta segura en portal).

## 2. Cambios de producto

- Texto de opción: *«Deseo presentar esta solicitud sin revelar mi identidad.»*
- Eliminado el checkbox *«Creo que esta situación implica urgencia vital»* (se conserva el aviso general de urgencias).
- Medios de respuesta obligatorios: correo, correspondencia física, consulta segura.
- Teléfono solo complementario (sin envío de información clínica por SMS).
- Token de consulta hasheado (SHA-256); radicado + token para consulta.
- Estados de respuesta: elaborada → aprobada (opcional) → puesta a disposición → enviada → consultada.
- Guardar respuesta **no** implica enviada ni recibida.

## 3. Supuestos legales a validar (Calidad / Jurídica)

| Tema | Implementación actual | Validación requerida |
|------|----------------------|----------------------|
| Anonimato en **quejas** y **denuncias** | Admitido de forma prioritaria | Confirmar alcance normativo (Ley 1751, Circular SuperSalud, política institucional) |
| Anonimato en petición, reclamo, sugerencia, felicitación | Permitido pero marcado `anonimo_pendiente_validacion_juridica` | Definir si se admite, se restringe o se exige identificación |
| Ausencia total de contacto | Permitida vía **consulta segura** | Definir procedimiento si la norma exige publicación o aviso por falta de destinatario |
| Correo como canal | Se registra consentimiento de preferencia; **sin envío automático** en esta fase | Protocolo de respuesta manual y evidencia |
| Correspondencia física | Se guarda dirección; evidencia de entrega manual | Formato de acuse / guía de envío |
| Datos clínicos en consulta pública | No se exponen adjuntos ni observaciones internas | Revisar texto mínimo de respuesta pública |
| Acuse de recibo | Consultar portal marca `consultada` como evidencia técnica, no como acuse formal automático | Definir si basta o se exige otro medio |

**No publicar** información clínica ni datos sensibles en avisos públicos sin evaluación legal.

## 4. Flujo operativo

1. Ciudadano radica (identificado o anónimo) + medio de respuesta.
2. Caso entra a bandeja con riesgo `pendiente` (SLA no fijado por el ciudadano).
3. Auxiliar/admin clasifica, responde y, si aplica, pone a disposición / registra envío verificable.
4. Ciudadano consulta con radicado + token; si hay texto a disposición, lo ve y se registra `consultada`.

## 5. Configuración

`config/pqrsd_publico.php`:

- `anonimo_tipos_prioritarios`: `QUEJA`, `DENUNCIA`
- `medios_respuesta`: email / correspondencia / consulta_segura

## 6. Restricciones de esta fase

- Sin Gmail API / OAuth / Pub/Sub / envío automático de correo.
- Sin cambio de reglas SLA ni meta del 95%.
