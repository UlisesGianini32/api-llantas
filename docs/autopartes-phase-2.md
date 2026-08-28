# Autopartes — Fase 2

## Objetivo

La Fase 2 agrega una bandeja de auditoría y enriquecimiento manual para detectar datos incompletos, preparar propuestas determinísticas y someterlas a revisión humana. Las propuestas aprobadas permanecen separadas de `automotive_parts`: esta fase no sobrescribe el catálogo original ni publica información en servicios externos.

## Estados

- `pending`: problema detectado y pendiente de atención.
- `in_review`: propuesta editada manualmente.
- `approved`: propuesta aprobada por un usuario autenticado.
- `rejected`: propuesta descartada con notas obligatorias.

Los estados se guardan como strings y se validan en la aplicación para conservar compatibilidad entre SQLite y MySQL.

## Issue codes

- `missing_compatibility`
- `missing_model_year`
- `invalid_model_year_range`
- `missing_vendor`
- `missing_mfg_part_number`
- `missing_description`
- `missing_dimensions`
- `missing_weight`
- `missing_price`
- `duplicate_source_key`
- `internal_category_requires_mapping`
- `needs_spanish_content`

La categoría interna se marca cuando el valor es `PDQ36`, `FNWI`, `ROTORS` o `WIRE`. Como el catálogo fuente completo está en inglés, todos sus productos reciben `needs_spanish_content` por defecto; la auditoría no depende de una lista parcial de palabras inglesas y no traduce ni inventa contenido.

`needs_spanish_content` solo se retira cuando la revisión tiene `enrichment_source=manual`, un título de al menos 10 caracteres, una descripción de al menos 40 caracteres y el texto combinado contiene señales determinísticas de español (acentos, `ñ`, signos españoles o vocabulario funcional frecuente). Un borrador de reglas, una propuesta incompleta o una revisión `pending` sin ambos textos suficientes conserva el issue. La regla mide suficiencia operativa, no sustituye la revisión humana ni un detector lingüístico especializado.

## Comando

```bash
php artisan autopartes:audit-enrichment
php artisan autopartes:audit-enrichment --limit=250
php artisan autopartes:audit-enrichment --part-id=123
php artisan autopartes:audit-enrichment --refresh-approved
```

La auditoría es idempotente gracias a la restricción única sobre `automotive_part_id`. Por defecto omite revisiones `approved` y `rejected`. `--refresh-approved` recalcula únicamente problemas y metadata de aprobadas; conserva su estado, propuestas, notas y revisor.

## Rutas autenticadas

- `GET /autopartes/enriquecimiento`: bandeja, filtros y totales.
- `POST /autopartes/enriquecimiento/auditar`: auditoría controlada, limitada a 250 por defecto y 1000 como máximo.
- `GET /autopartes/enriquecimiento/{review}`: comparación y edición.
- `PUT /autopartes/enriquecimiento/{review}`: guarda la propuesta y pasa a `in_review`.
- `POST /autopartes/enriquecimiento/{review}/aprobar`: aprueba sin modificar el catálogo.
- `POST /autopartes/enriquecimiento/{review}/rechazar`: rechaza; requiere notas.
- `POST /autopartes/enriquecimiento/{review}/pendiente`: regresa la revisión a pendiente.

## Flujo de revisión

1. Ejecutar la auditoría desde Artisan o desde la bandeja.
2. Filtrar por estado, problema, categoría o proveedor.
3. Comparar los datos originales con la propuesta.
4. Editar título, descripción, marca, categoría, compatibilidad, atributos y notas.
5. Guardar para pasar a `in_review`.
6. Aprobar, rechazar o regresar a pendiente.

El borrador inicial del título concatena únicamente descripción original, proveedor, MFG Part #, modelo prevalente y rango de años. Su fuente queda registrada como `rules`, nunca como IA.

## Limitaciones

- No se consulta OpenAI ni ninguna API o página externa.
- No se traduce automáticamente.
- No se inventan compatibilidades ni atributos.
- No se publica ni actualiza Mercado Libre.
- La detección de idioma es una heurística y requiere confirmación humana.
- Una aprobación conserva la propuesta separada; todavía no existe un proceso de aplicación o publicación.

## Preparación futura

La tabla reserva `enrichment_source=future_ai`, `confidence_score`, metadata y estructuras JSON para compatibilidad y atributos. Una integración posterior podrá generar nuevas propuestas, pero deberá mantener revisión humana, trazabilidad y separación del catálogo original antes de cualquier publicación en Mercado Libre.
