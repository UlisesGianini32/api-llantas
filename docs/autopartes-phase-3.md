# Autopartes — Fase 3: enriquecimiento asistido con OpenAI

## Alcance

Esta fase genera borradores estructurados en español para revisión humana. No modifica `automotive_parts`, no aprueba revisiones, no publica en Mercado Libre y no consulta catálogos externos. La única salida persistida se aplica a los campos de propuesta de `automotive_part_enrichment_reviews`.

## Arquitectura y flujo

1. El comando o un controlador autenticado selecciona revisiones `pending` que no sean manuales.
2. `AutomotivePartAiDispatchService` comprueba configuración, elegibilidad, límites y duplicados.
3. Se guarda un `AutomotivePartAiRun` en estado `queued` con un snapshot permitido y un fingerprint SHA-256.
4. `GenerateAutomotivePartEnrichmentWithAiJob` se encola en `autopartes-ai`, un producto por job.
5. El job vuelve a comprobar estado y fingerprint antes de llamar a OpenAI.
6. `OpenAiResponsesClient` usa `POST https://api.openai.com/v1/responses` con Structured Outputs estricto.
7. La respuesta pasa por validación independiente contra los datos de origen.
8. Solo una respuesta válida actualiza la propuesta; el estado de la revisión permanece `pending`.
9. Una edición humana posterior cambia `enrichment_source` a `manual` y bloquea automatizaciones futuras.

No hay una transacción de base de datos abierta durante la solicitud HTTP. Las transacciones se limitan a comprobaciones y aplicación local de resultados.

## Configuración

`config/autopartes_ai.php` lee exclusivamente estas variables de entorno:

- `AUTOPARTES_AI_ENABLED`
- `AUTOPARTES_AI_MODEL`
- `AUTOPARTES_AI_MAX_BATCH`
- `AUTOPARTES_AI_MAX_DAILY_ITEMS`
- `AUTOPARTES_AI_TIMEOUT`
- `AUTOPARTES_AI_MAX_RETRIES`
- `AUTOPARTES_AI_PROMPT_VERSION`
- `AUTOPARTES_AI_TITLE_MAX_CHARS`
- `OPENAI_API_KEY`

La integración inicia deshabilitada. El modelo es configurable y no existe fallback silencioso. La credencial nunca se envía al frontend, se guarda en la base de datos ni aparece en logs.

## Responses API y Structured Outputs

Cada solicitud envía solo los campos canónicos de un producto y los `issue_codes` de su revisión. No se envía el Excel completo, contenido de otros productos ni propuestas manuales.

El formato se envía en `text.format` con:

```json
{
  "type": "json_schema",
  "name": "automotive_part_enrichment_v1",
  "strict": true,
  "schema": {
    "type": "object",
    "additionalProperties": false,
    "required": [
      "language",
      "title_es",
      "description_es",
      "brand_normalized",
      "manufacturer_part_number",
      "category_suggestion",
      "compatibility",
      "attributes",
      "missing_facts",
      "warnings",
      "source_basis",
      "confidence"
    ],
    "properties": {
      "language": { "type": "string", "enum": ["es-MX"] },
      "title_es": { "type": ["string", "null"], "maxLength": "AUTOPARTES_AI_TITLE_MAX_CHARS" },
      "description_es": { "type": ["string", "null"], "maxLength": 3000 },
      "brand_normalized": { "type": ["string", "null"], "maxLength": 500 },
      "manufacturer_part_number": { "type": ["string", "null"], "maxLength": 500 },
      "category_suggestion": { "type": ["string", "null"], "maxLength": 500 },
      "compatibility": {
        "type": "array",
        "maxItems": 25,
        "items": {
          "type": "object",
          "additionalProperties": false,
          "required": ["make", "model", "year_from", "year_to", "notes"],
          "properties": {
            "make": { "type": ["string", "null"], "maxLength": 500 },
            "model": { "type": ["string", "null"], "maxLength": 500 },
            "year_from": { "type": ["integer", "null"], "minimum": 1900, "maximum": 2100 },
            "year_to": { "type": ["integer", "null"], "minimum": 1900, "maximum": 2100 },
            "notes": { "type": ["string", "null"], "maxLength": 1000 }
          }
        }
      },
      "attributes": {
        "type": "array",
        "maxItems": 30,
        "items": {
          "type": "object",
          "additionalProperties": false,
          "required": ["name", "value", "unit", "source_field"],
          "properties": {
            "name": { "type": "string", "minLength": 1, "maxLength": 150 },
            "value": { "type": "string", "minLength": 1, "maxLength": 500 },
            "unit": { "type": ["string", "null"], "maxLength": 50 },
            "source_field": {
              "type": "string",
              "enum": [
                "item_number", "manufacturer_part_number", "vendor", "vendor_normalized",
                "category", "subcategory", "description_original", "description_normalized",
                "quantity", "retail_price_original", "min_model_year", "average_model_year",
                "max_model_year", "prevalent_model", "applicable_models_text", "length_inches",
                "width_inches", "height_inches", "cubic_inches", "weight_pounds", "length_cm",
                "width_cm", "height_cm", "weight_kg", "lifecycle", "missing_fields", "issue_codes"
              ]
            }
          }
        }
      },
      "missing_facts": { "type": "array", "maxItems": 30, "items": { "type": "string", "maxLength": 500 } },
      "warnings": { "type": "array", "maxItems": 30, "items": { "type": "string", "maxLength": 1000 } },
      "source_basis": { "type": "array", "maxItems": 30, "items": { "type": "string", "maxLength": 500 } },
      "confidence": { "type": "number", "minimum": 0, "maximum": 1 }
    }
  }
}
```

El límite numérico real de `title_es` se sustituye con el valor configurado al construir cada solicitud.

## Reglas contra alucinaciones

Además del JSON Schema, el servidor comprueba:

- idioma `es-MX`, tipos, campos obligatorios, objetos cerrados y límites;
- longitud configurable del título y ausencia de HTML o Markdown;
- número de parte idéntico al original cuando existe;
- marca respaldada por `vendor` o `vendor_normalized`;
- marcas/modelos de compatibilidad presentes en `applicable_models_text` o `prevalent_model`;
- años dentro del mínimo y máximo suministrados, sin rangos invertidos;
- `source_field` permitido y con un valor de origen no vacío;
- ausencia de OEM, original, certificación, universalidad, garantía, GTIN, material, posición, lado o país de origen cuando el origen no lo respalda;
- ausencia de IDs de categoría de Mercado Libre e instrucciones externas.

Una respuesta que falla queda como `failed_validation`, conserva el payload estructurado seguro para auditoría y no cambia la revisión.

## Persistencia y estados

`automotive_part_ai_runs` guarda producto, revisión, estado, modelo, versión del prompt, fingerprint, snapshot, payload, response ID, tokens, intentos, error sanitizado y tiempos.

Estados:

- `queued`: esperando worker.
- `processing`: solicitud en curso.
- `completed`: propuesta validada y aplicada a la revisión.
- `failed`: error de configuración, conexión, HTTP o JSON.
- `failed_validation`: salida estructural o semánticamente inválida.
- `refused`: el modelo rechazó la solicitud.
- `skipped`: estado manual/final, datos insuficientes o fingerprint obsoleto.
- `cancelled`: reservado para cancelación operativa.

El fingerprint se calcula de forma determinista con el snapshot canónico, estado relevante de la revisión, modelo y versión del prompt. Evita duplicados en cola y base de datos. Una revisión que cambió desde la creación del job no acepta la respuesta. Una regeneración requiere una acción explícita o `--force`; nunca puede superar una propuesta manual ni una decisión final.

## Límites, reintentos y costos

El lote nunca supera `AUTOPARTES_AI_MAX_BATCH` y las ejecuciones del día nunca superan `AUTOPARTES_AI_MAX_DAILY_ITEMS`. Los tokens de entrada, salida y total quedan registrados por run para revisar costos.

Solo se reintentan timeouts y HTTP 408, 429, 500, 502, 503, 504, además de 409 con código transitorio reconocido. Se usa backoff exponencial con jitter y se respeta `Retry-After` cuando está disponible. No se reintentan 400, 401, 403, configuración inválida, refusal, validación fallida ni falta de datos.

## Operación

Vista previa de un producto:

```bash
php artisan autopartes:ai-enrich --review-id=123 --limit=1 --dry-run
```

Encolar un producto:

```bash
php artisan autopartes:ai-enrich --review-id=123 --limit=1
```

Encolar un lote filtrado:

```bash
php artisan autopartes:ai-enrich --issue=needs_spanish_content --limit=10
```

Reintentar de forma explícita una propuesta de reglas/IA o un run fallido:

```bash
php artisan autopartes:ai-enrich --review-id=123 --limit=1 --force
```

Worker dedicado:

```bash
php artisan queue:work --queue=autopartes-ai
```

La bandeja también permite encolar un lote pequeño con confirmación; la pantalla de detalle permite generar, regenerar y consultar las 20 ejecuciones más recientes. El endpoint autenticado de historial admite paginación de hasta 50 runs.

## Observabilidad y errores

Los logs contienen IDs locales, response ID, estado, modelo, versión, tokens, duración y código de error. No contienen credenciales, encabezados Authorization, prompt completo ni cuerpo HTTP sin sanitizar. Los mensajes persistidos eliminan valores asociados a password, token, secret, Authorization y API key.

Un modelo inexistente o no permitido no provoca fallback: el run falla con el código y mensaje sanitizados devueltos por OpenAI.

## Despliegue gradual

1. Mantener la integración deshabilitada.
2. Configurar credenciales manualmente fuera del repositorio.
3. Ejecutar un dry-run.
4. Habilitar la integración.
5. Procesar un solo producto.
6. Revisar salida, warnings, faltantes y tokens.
7. Procesar un lote de máximo 10.
8. Revisar costos y calidad.
9. Aumentar límites solo con aprobación.

Para deshabilitarla, establecer `AUTOPARTES_AI_ENABLED=false` y detener el worker dedicado. Los runs históricos permanecen disponibles.

## Rollback

1. Deshabilitar la integración y detener `autopartes-ai`.
2. Retirar las rutas, job, servicios y UI de Fase 3 mediante el mecanismo normal de despliegue.
3. Conservar `automotive_part_ai_runs` si se necesita auditoría. Si la migración de Fase 3 es la última aplicada y se aprueba perder ese historial, revertir únicamente esa migración.

El rollback no necesita tocar `automotive_parts` ni las migraciones de Fase 1 o Fase 2.

## Fuera de alcance

Esta fase no publica ni modifica Mercado Libre, no decide aprobaciones, no actualiza el catálogo canónico, no traduce marcas o números de parte, no consulta la web, no procesa el Excel completo y no obtiene información externa de compatibilidad.
