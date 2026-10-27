# Validación — Radicación anónima y consulta segura

Fecha: 2026-10-15  
Línea base: 143 pruebas / 734 aserciones  
Tras validación y correcciones menores de seguridad: **143 pruebas / 743 aserciones — OK**

## 1. Qué se comprobó en navegador / HTTP real

Servidor de aquella validación: `http://127.0.0.1:8000`. El entorno actual usa `http://127.0.0.1:8010`.

### Formulario `/publico/pqrsd` (UI)

| Comprobación | Resultado |
|--------------|-----------|
| Opción *«Deseo presentar esta solicitud sin revelar mi identidad.»* | OK |
| Aviso general de urgencias visible | OK |
| Checkbox «Creo que esta situación implica urgencia vital» ausente | OK |
| Botón «Personal» ausente | OK |
| Campo medio de respuesta (correo / correspondencia / consulta segura) | OK |
| Carga de adjuntos `adjuntos[]` (PDF/Word/imágenes) | OK |
| Campos de identidad se ocultan al marcar anónimo (JS) | Corregido y listo en vista |

### Recorridos de radicación (HTTP con sesión)

| Flujo | Resultado |
|-------|-----------|
| Identificada (petición + email) | 302 → confirmación; radicado `PQRSD-2026-000006`; token 40 chars; **sin token en URL** |
| Anónima con correo (queja) | 302 → confirmación; `PQRSD-2026-000007`; texto sin revelar identidad |
| Anónima sin contacto (denuncia + consulta segura) | 302 → confirmación; `PQRSD-2026-000008`; token 40 chars |
| Consulta POST con radicado+token correctos | 200; muestra radicado; sin adjuntos |
| Token incorrecto | 302 (rechazo) |
| Consulta GET con token en query | 302 a `/publico/consulta` (bloqueado) |
| Bandeja auxiliar (`auxiliar1@menteplena.test`) | 200; ve `PQRSD-2026-000008` |

Copiar radicado/código: botones presentes en la vista de confirmación (sesión flash).

## 2. Qué se validó con PHPUnit

Suite completa: `php artisan test` → **143 passed / 743 assertions**.

Incluye, entre otras:

- `AnonimoYMediosRespuestaTest` — identificada, anónima, email, dirección, consulta segura, respuesta a disposición, token incorrecto, adjuntos protegidos, bandeja, sin checkbox urgencia, SLA meta 95 / DENUNCIA_10DH, auxiliares sin admin.
- `InterfazPublicaReportesTest` — confirmación por sesión (sin query token), consulta POST.
- `FormularioPublicoAdjuntosYPermisosTest` — consulta segura POST y enumeración rechazada.

## 3. Fallos reales encontrados y corregidos

1. **Token en URL** en confirmación (`?token=`) y enlace de consulta GET → riesgo de historial/Referer/logs.  
   **Fix:** confirmación vía `session flash`; consulta operativa por **POST** (`publico.consulta.store`); GET con token redirige sin abrir expediente.
2. **Campos de identidad** solo atenuados con opacidad → ahora se **ocultan y limpian** al activar anónimo.
3. Honeypot ya no redirige a confirmación con token ficticio en query.

No se reconstruyeron módulos; no se tocaron Gmail/OAuth/Pub/Sub/SLA/indicadores.

## 4. Seguridad verificada

| Control | Estado |
|---------|--------|
| Token plano no se guarda en BD (solo `consulta_token_hash` SHA-256) | OK |
| Entropía token (`Str::random(40)`) | OK |
| Rate limit radicación / consulta | OK (middleware + RateLimiter) |
| Token no en URL de confirmación | OK (tras fix) |
| Consulta GET con token bloqueada | OK (tras fix) |
| Token incorrecto / radicado ajeno | No abre expediente |
| Respuesta guardada ≠ enviada/recibida | Estados explícitos (`elaborada` / `puesta_disposicion` / `consultada`) |
| Formulario no fija riesgo clínico | `nivel_riesgo = pendiente` |
| Adjuntos no en consulta pública | OK |

## 5. Pendiente

- Validación formal Calidad/Jurídica de supuestos de anonimato (ver `docs/solicitudes-anonimas-y-medios-respuesta.md`).
- En producción: HTTPS + `PQRSD_PUBLIC_URL`; no distribuir QR local.
- Envío real de correo / evidencia de correspondencia (manual; sin automatizar en esta fase).
- Captura visual adicional del menú de medios en navegador automatizado (el recorrido HTTP ya confirmó las tres opciones en código/config y el flujo de cada medio).

## 6. Cómo repetir la validación

```bash
php artisan serve
# Abrir /publico/pqrsd — radicar los 3 perfiles
# Login auxiliar1@menteplena.test — ver bandeja y responder
php artisan test
```
