# Meli Price Manager — Fase 3: clasificación de marcas

Fecha: 26 de agosto de 2026.

Esta fase incorpora un clasificador local, determinístico y auditable. No llama a Mercado Libre, no utiliza IA, no crea marcas reales, no modifica precios o stock y no altera `meli_brand` ni `normalized_brand`.

## Persistencia aditiva

La migración `2026_08_26_000002_add_brand_classification_audit_to_meli_price_manager_items.php` agrega:

- `suggested_brand_group_id`: FK nullable a `meli_brand_groups`. Solo representa una sugerencia; nunca equivale a una clasificación confirmada.
- `matched_brand_alias_id`: FK nullable a `meli_brand_aliases`. Identifica la regla que produjo el resultado.
- `classification_metadata`: JSON nullable con la explicación y candidatos relevantes.

Ambas FKs usan `nullOnDelete`. El JSON conserva los datos principales del alias incluso si posteriormente se elimina. La migración original de la Fase 1 no fue modificada.

La migración aditiva `2026_08_27_000001_add_title_contains_to_meli_brand_aliases.php` amplía el enum MySQL de `match_type` con `title_contains`. No transforma reglas existentes ni reclasifica publicaciones. Al revertir, las reglas de título se conservan como `manual` antes de reducir el enum.

## Normalización

`MeliBrandNormalizer` se reutiliza tanto al sincronizar como al clasificar. Aplica:

1. trim;
2. transliteración de acentos;
3. mayúsculas;
4. puntuación, guiones y separadores a espacios;
5. espacios consecutivos a uno.

Por ejemplo, `Álfaparf Milano`, `ALFAPARF-MILANO` y `alfaparf   milano` producen `ALFAPARF MILANO`.

La normalización facilita comparación, pero no cambia la marca original guardada en `meli_brand`.

## Reglas elegibles

El servicio carga una sola vez por ejecución los aliases que cumplen simultáneamente:

- alias activo;
- grupo de marca activo;
- `match_type` distinto de `manual`.

No se usa Redis. Los items se recorren por ID en chunks de 500.

## Algoritmo exacto

Las fuentes se evalúan por etapas. Una etapa posterior solo se consulta cuando la anterior no produjo candidatos:

1. `normalized_brand` contra aliases `exact`:
   - source: `brand_exact`;
   - confidence: `1.0000`.
2. BRAND contra aliases `starts_with`:
   - source: `brand_starts_with`;
   - confidence: `0.9500`.
3. BRAND contra aliases `contains`:
   - source: `brand_contains`;
   - confidence: `0.9000`.
4. TITLE contra aliases declarados explícitamente como `title_contains`, cuando BRAND no coincidió:
   - source: `title_contains`;
   - confidence: `0.8500`.

Los tipos `exact`, `starts_with` y `contains` se evalúan exclusivamente contra BRAND (`normalized_brand` o `meli_brand` normalizada). Nunca se reutilizan implícitamente sobre TITLE. Una coincidencia en el título solo existe cuando un operador crea o cambia una regla a `title_contains`; `manual` continúa fuera de toda clasificación automática.

Dentro de la primera etapa que tenga candidatos, se ordenan por:

1. mayor `priority`;
2. mayor especificidad —en título, igualdad completa antes que frase contenida—;
3. alias normalizado más largo;
4. menor ID de alias únicamente como orden estable y reproducible.

Si el candidato superior no empata en prioridad, especificidad y longitud con otro grupo, se guarda:

```text
classification_status = categorized
brand_group_id = grupo ganador
suggested_brand_group_id = null
matched_brand_alias_id = alias ganador
classification_source = fuente de la etapa
classification_confidence = confianza de la etapa
```

## Protección de aliases cortos

No se usa `str_contains()` libre. Las comparaciones `contains` y `title_contains` requieren límites de frase sobre el texto ya normalizado. `OI` coincide con `SHAMPOO OI 250 ML`, pero no con `MOISTURE`.

`starts_with` también exige que después del alias termine el texto o exista un espacio normalizado. Esta protección se aplica a todos los aliases, por lo que los aliases de dos o tres caracteres reciben una garantía más fuerte que el mínimo requerido.

## Conflictos y sugerencias

Existe conflicto cuando los mejores candidatos empatados en prioridad, especificidad y longitud pertenecen a grupos distintos. En ese caso:

```text
classification_status = suggested
brand_group_id = null
suggested_brand_group_id = candidato líder reproducible
matched_brand_alias_id = alias líder
classification_source = ambiguous_{fuente}
classification_confidence = confianza de la etapa
```

`suggested_brand_group_id` permite presentar una sugerencia, pero no se interpreta como asignación. `classification_metadata` guarda el motivo, cantidad total y hasta 25 candidatos ordenados con grupo, alias, match type, prioridad, fuente y confianza. Así el conflicto puede explicarse posteriormente sin abusar de `brand_group_id`.

## Sin coincidencia

Una evaluación automática sin candidatos limpia únicamente los campos automáticos:

```text
brand_group_id = null
suggested_brand_group_id = null
matched_brand_alias_id = null
classification_status = uncategorized
classification_source = null
classification_confidence = null
classification_metadata = null
```

`meli_brand` y `normalized_brand` permanecen intactos.

## Protección manual e ignored

Una fuente que comienza con `manual` se considera humana. Esto incluye `manual`, `manual_alias` y `manual_assignment`. Esos items nunca son modificados por el motor, incluso con `--all`.

Los items con `classification_status=ignored` tampoco se modifican automáticamente.

Una clasificación automática sí puede cambiar al ejecutar nuevamente el motor si las reglas activas cambiaron.

## Servicio

`MeliBrandClassificationService` expone:

```php
classifyItem(MeliPriceManagerItem $item, bool $dryRun = false)
classifyAccount(MeliAccount $account, bool $reclassifyAll = false, bool $dryRun = false)
classifyUncategorized(MeliAccount $account, bool $dryRun = false)
```

El resultado individual es `MeliBrandClassificationResult`, un value object inmutable. El resumen por cuenta incluye:

```text
processed
categorized
suggested
uncategorized
ignored
skipped_manual
changed
dry_run
```

Por defecto se evalúan `uncategorized` y `suggested`, además de contar los manuales e ignored preservados. Con reclasificación total se reconsideran resultados automáticos existentes, pero nunca manuales o ignored.

## Comando Artisan

Clasificación segura por defecto:

```bash
php artisan meli:price-manager-classify --account=1
```

Reconsiderar clasificaciones automáticas:

```bash
php artisan meli:price-manager-classify --account=1 --all
```

Vista previa sin escrituras:

```bash
php artisan meli:price-manager-classify --account=1 --dry-run
php artisan meli:price-manager-classify --account=1 --all --dry-run
```

Si se omite `--account`, solo se selecciona automáticamente cuando existe exactamente una cuenta. Con múltiples cuentas se exige el ID explícito.

## Alcance pendiente

No se incluyen marcas o aliases de producción, endpoints administrativos ni UI. La administración de reglas y confirmación humana corresponden a fases posteriores.
