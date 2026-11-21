# Fotografías de Mercado Libre en Órdenes de Compra (SBS)

## 1. Reglas de resolución y prevención de asignaciones incorrectas

El servicio `App\Services\Purchasing\PurchaseOrderMeliImageResolver` resuelve las fotografías únicamente a partir de datos locales/sincronizados en SBS (sin llamadas a la API de Mercado Libre y sin asignación automática por coincidencia de nombre):

1. **Prioridad 1 — Imagen manual / override en la partida (`photo_source_mode = 'manual'` o `'none'`)**
   - Permite subir una imagen local (JPG, PNG, WEBP, GIF hasta 5 MB almacenada en disco privado `local`) cuando no existe una publicación confiable en Mercado Libre (ej. sobres tipo burbuja).
   - También permite marcar explícitamente una partida sin foto (`photo_source_mode = 'none'`) o registrar MLMs rechazados (`rejected_mlms`, ej. `MLM3446472074`) para que no vuelvan a asignarse.

2. **Prioridad 2 — Publicación o variación aprobada manualmente por partida (`photo_source_mode = 'approved_ml'`)**
   - Almacena `approved_mlm`, `approved_variation_id` (opcional), `approved_meli_account_id`, `approved_photo_url`, `photo_notes`, `photo_updated_by` y `photo_updated_at`.
   - Cuando existe `approved_variation_id`, resuelve estrictamente `variation.picture_ids` contra `item.pictures[*].id` y **nunca** usa la portada del anuncio.
   - Permite desambiguar publicaciones compartidas (ej. `BSHC003`, `3783374192379`, `3783374192386`, `KSC02` Rose Gold/Silver) o variaciones con SKU temporal (`CHN-TMP-*`) de forma auditable.

3. **Prioridad 3 — Vínculo explícito (`inventory_channel_links`)**
   - Respeta cuenta (`account_key` validado estrictamente contra `meli_accounts.id`, `meli_user_id`, `nickname` o `default` únicamente si existe exactamente una cuenta predeterminada), publicación (`external_listing_id`) y variación (`external_variant_id`).
   - Consulta `meli_publications` y `meli_price_manager_items` por el MLM vinculado (omitiendo MLMs presentes en `rejected_mlms`).
   - Si existe vínculo explícito válido, prevalece de forma determinista y evita ambigüedades por SKU compartido.
   - Un `account_key` desconocido, ambiguo o que no coincida con `meli_account_id` es rechazado (`account_mismatch`).

4. **Prioridad 4 — Resolución unificada por SKU exacto (`meli_publications` + `meli_price_manager_items`)**
   - Para partidas sin override manual, aprobación explícita ni vínculo válido, indexa las filas masivas estrictamente por sus SKUs verificados en PHP (evitando que un MLM de otro SKU contamine diagnósticos no relacionados) y recolecta candidatos verificados en **ambas** tablas:
     - Coincidencia por columna `sku` (`meli_publications.sku` / `meli_price_manager_items.sku`) con verificación estricta en el payload `raw` / `raw_item`.
     - Coincidencia por variación secundaria dentro de `raw` / `raw_item` mediante consulta masiva acotada (sin N+1) verificada en PHP.
   - **Reconciliación de snapshots de la misma publicación (`mlm + variation_id`):**
     - Si la misma publicación MLM aparece en `meli_publications` y `meli_price_manager_items` (o en filas históricas `is_current=false` y vigentes `is_current=true`) con fotografías distintas, **no se marca conflicto automáticamente**: se evalúa su vigencia (`is_current`, fecha de sincronización `last_sync_at`/`last_synced_at` y estado `active > paused > otros`) y se toma el snapshot más vigente.
     - Solo si dos snapshots de la misma publicación empatan exactamente en vigencia y tienen URLs distintas se marca `conflicting_listing_snapshots` (`Requiere revisión`).
   - **Conflictos reales entre publicaciones distintas:**
     - **Entre tablas:** Si el mismo SKU aparece en `meli_publications` bajo `MLM-A` y en `meli_price_manager_items` bajo `MLM-B` con fotografías distintas (sin vínculo explícito), no se elige automáticamente la primera; se devuelve `url = null`, `ambiguous = true` y `reason = multiple_publications_conflicting_photos`.
     - **Publicación simple vs. variación secundaria de otra publicación:** Si el mismo SKU aparece como publicación simple (`MLM-SIMPLE`) y simultáneamente como variación secundaria dentro de otra publicación multi-variación (`MLM-MULTI`, variación `V`), ambos candidatos entran al mismo conjunto unificado:
       - Si ambas apuntan a la **misma URL** verificada, se resuelve limpiamente.
       - Si apuntan a **URLs distintas** y no hay aprobación o vínculo explícito, se rechaza la auto-selección (`multiple_publications_conflicting_photos`), `/compras/ordenes/{id}` muestra **«Requiere revisión»**, el PDF muestra **«Sin foto»** y el proxy `/foto` responde `404`.

5. **Capa de descubrimiento por título/nombre (solo propuesta, nunca auto-asignación)**
   - El modal administrativo en `/compras/ordenes/{id}` permite buscar candidatos por título, MLM o SKU en `meli_publications` y `meli_price_manager_items`, mostrando foto, título, MLM, variación/color y estado para aprobación manual.
   - Esta búsqueda profunda está **separada** de la resolución automática (`show()`, `pdf()`, `itemPhoto()` y auditoría rápida por defecto) para nunca bloquear la carga de una orden ni la generación de PDF.

6. **Optimización de rendimiento, índice auxiliar y control de recursos**
   - **Índice normalizado de SKU/variación con generaciones atómicas (`meli_listing_sku_index` + `meli_listing_sku_index_state`):**
     - Migración reversible `2026_11_21_000001_create_meli_listing_sku_index_table.php` con tabla de datos versionada por `generation` (índices B-tree compuestos `(generation, sku_normalized)`, `(generation, source_table, source_row_id)`, `(generation, mlm)` y unicidad `(generation, source_table, source_row_id, variation_key, sku_normalized)`) y tabla de estado persistente `meli_listing_sku_index_state`.
     - **Completitud verificable:** el resolver nunca infiere disponibilidad por la presencia de filas sueltas; únicamente consulta `meli_listing_sku_index` cuando `meli_listing_sku_index_state.active_generation > 0`, filtrando estrictamente `WHERE generation = active_generation`.
     - **Reconstrucción no destructiva:** `php artisan purchasing:sync-meli-photo-sku-index --chunk=200` construye siempre una nueva generación candidata sin tocar `active_generation`, absorbe cambios concurrentes (Eloquent y Query Builder) antes de publicar, conmuta `active_generation` en una transacción atómica y limpia generaciones obsoletas únicamente después del commit. Si ocurre cualquier fallo o interrupción, preserva íntegra la generación válida anterior (o permanece en `failed` / fallback si era la inicial).
     - **Sincronización incremental, reconciliación y diagnóstico solo lectura:**
       - Los hooks en `MeliPublication` y `MeliPriceManagerItem` escriben únicamente sobre generaciones activas/en construcción (nunca antes del backfill inicial) y registran advertencias + contadores de error en `meli_listing_sku_index_state` ante cualquier fallo sin romper los jobs de Mercado Libre.
       - Para procesos que escriben mediante Query Builder / `update()` masivo (`MeliPublishService`, `LlantaMergeService`, `AmsProductIssueController`, `MeliAccountPublicationSyncService::update(is_current)`), ejecutar periódicamente `php artisan purchasing:sync-meli-photo-sku-index --incremental --chunk=200`.
       - Para inspeccionar salud, generación activa, errores y deriva en modo solo lectura: `php artisan purchasing:meli-photo-sku-index-status --json`.
     - **Plan de despliegue/validación:** No aplicar esta migración directamente en producción sin validar primero en una base aislada de MariaDB la migración (`migrate` + `migrate:rollback`), el backfill, interrupciones simuladas, concurrencia y el `EXPLAIN` de las consultas indexadas.
   - **Fallback acotado y transparente (`scanVariationPublicationsByRaw` / `scanVariationPriceManagerItemsByRaw`):**
     - Solo se ejecuta si `meli_listing_sku_index` aún no existe o está vacío.
     - Recorre páginas por llave primaria (`WHERE id > $lastId ORDER BY id ASC LIMIT 101`, `VARIATION_SCAN_PAGE_SIZE = 100`, `VARIATION_SCAN_MAX_PAGES = 20`) sin filesort y libera cada página en memoria al finalizar.
     - Aplica techos operativos de páginas, tiempo (`VARIATION_SCAN_MAX_SECONDS = 5.0s`) y memoria (80 % de `memory_limit`). Si alcanza cualquier techo, **nunca trunca silenciosamente**: registra `Log::warning`, marca `variation_scan_truncated = true` con `truncation_reason` (`page_limit_reached`, `time_budget_exceeded` o `memory_budget_exceeded`) y expone `scan_diagnostics`.
     - Si la resolución en Pass 2 supera `SLOW_RESOLUTION_WARN_SECONDS = 2.5s`, emite un `Log::warning` con duración, memoria pico y si el índice auxiliar estuvo activo.
   - **Caché corta de resolución en peticiones web (`PurchaseOrderController`):**
     - `show()` y `pdf()` precargan en caché (TTL 120 s, llave versionada por `updated_at`, `photo_updated_at`, modo, MLM/variación aprobados y ruta manual) la resolución de todas las partidas de la orden para que las peticiones concurrentes a `/foto` (`itemPhoto()`) sirvan la imagen con **cero re-escaneos SQL**.
     - Las mutaciones administrativas (`approveItemPhoto`, `uploadItemManualPhoto`, `rejectItemPhoto`, `resetItemPhoto`) invalidan y refrescan la llave inmediatamente.
   - **Búsqueda administrativa sin filesort sobre JSON (`discoverCandidatesForItem`):**
     - Prioriza consultas indexadas por SKU/MLM e índice `meli_listing_sku_index`, busca primero en la columna compacta `meli_price_manager_items.title` antes que en `meli_publications.raw`, elimina `ORDER BY CASE WHEN status ...` en SQL (ordenando por `id DESC LIMIT 60` y puntuando en PHP), y aplica presupuesto de tiempo (`maxSeconds = 3.0s` en el endpoint web).
   - **Preservación de fotografía aprobada:**
     - Al aprobar una publicación o variación desde la interfaz administrativa, se guarda `approved_photo_url` (validado contra `*.mlstatic.com`). Aunque un snapshot posterior de Mercado Libre cambie sus fotos, se trunque o desaparezca, el resolver conserva la fotografía aprobada (`reason = resolved_approved_preserved`).
   - **Autorización estricta:**
     - `PurchaseOrderController::itemPhoto()` valida explícitamente `PurchaseOrderPolicy::view` y `PurchaseOrderPolicy::viewPhoto` antes de comprobar pertenencia de partida; la ruta pública firmada `/orden-compra/{purchaseOrder}/partidas/{item}/foto` exige firma relativa vigente (`signed:relative`) y rechaza firmas vencidas o alteradas con `403`.
     - Las imágenes manuales se guardan fuera de `public/` en el disco privado `local` (`storage/app/private/purchasing/manual-photos/...`).

7. **Categorías y modos de auditoría (`purchasing:audit-order-photos`)**
   - **Modo rápido (por defecto):** Ejecuta únicamente una pasada masiva `resolveForOrder()` y **omite** el descubrimiento profundo por título partida por partida (`audit_mode = "fast"`, `deep_discovery_skipped = true`, con nota explícita `deep_discovery_note`). Incluye el bloque `performance` (`duration_ms`, `queries_count`, `like_queries_count`, `peak_memory_mb`, `variation_index_used`, `variation_scan_truncated`).
   - **Modo exhaustivo opcional (`--deep --time-budget=20`):** Pensado para base aislada. Precarga `meli_accounts` una sola vez, omite re-escaneos `raw` de variación por partida (`skipVariationRawScan = true`), respeta el presupuesto total `--time-budget` y siempre emite JSON válido (indicando `deep_discovery_truncated = true` si se agotó el presupuesto).
   - Categorías reportadas:
     - `resolved_by_sku_or_link` (Resuelto por SKU/vínculo)
     - `resolved_by_variation` (Resuelto por variación)
     - `manual_override` (Imagen manual/override)
     - `pending_manual_approval` (Pendiente por aprobación manual: conflictos ambiguos o candidatos propuestos por título en `--deep`)
     - `pending_without_candidate` (Pendiente sin candidato)

---

## 2. Procedimiento de auditoría en solo lectura para `OC-20261113-0001` (entorno aislado)

Objetivo: ejecutar `purchasing:audit-order-photos OC-20261113-0001` contra datos reales **sin desplegar en producción, sin ejecutar migraciones, sin escribir en la base de datos y sin realizar llamadas a Mercado Libre**.

### Opción A — Snapshot / réplica local o de staging (recomendada)

1. Restaurar un respaldo reciente (solo lectura / base temporal `sbs_audit_ro`) en un servidor de staging o entorno aislado que no comparta almacenamiento ni colas con producción.
2. En un directorio aislado (sin tocar el código en producción), clonar o crear un worktree con la rama del PR #14:
   ```bash
   git fetch origin feature/purchasing-ml-sku-product-photos-audit
   git worktree add --detach /tmp/sbs-photo-audit origin/feature/purchasing-ml-sku-product-photos-audit
   cd /tmp/sbs-photo-audit
   composer install --no-dev --no-interaction --prefer-dist
   ```
3. Configurar un archivo `.env` temporal apuntando únicamente a la copia `sbs_audit_ro` con un usuario de base de datos con privilegio exclusivo `SELECT`, y deshabilitar caché/colas externas (`CACHE_STORE=array`, `SESSION_DRIVER=array`, `QUEUE_CONNECTION=sync`, `APP_ENV=local`).
4. Ejecutar la auditoría en modo solo lectura (tabla legible y respaldo JSON para revisión):
   ```bash
   php artisan purchasing:audit-order-photos OC-20261113-0001
   php artisan purchasing:audit-order-photos OC-20261113-0001 --json > /tmp/audit-OC-20261113-0001.json
   ```
5. Verificar en el reporte:
   - Total real de partidas y piezas solicitadas/recibidas de `OC-20261113-0001`.
   - Partidas con fotografía resuelta (`source`, `mlm`, `variation_id`, `meli_account_id`).
   - Partidas sin fotografía (`missing_skus` y motivo exacto).
   - Partidas ambiguas (`ambiguous_matches`) que requieren vínculo explícito en `inventory_channel_links`.
6. Eliminar el worktree y el `.env` temporal al terminar:
   ```bash
   rm -f /tmp/sbs-photo-audit/.env
   git worktree remove /tmp/sbs-photo-audit
   ```

### Opción B — Conexión directa de solo lectura (si no existe réplica)

1. Crear en MariaDB/MySQL un usuario temporal restringido únicamente a `SELECT` sobre las tablas consultadas por el comando (`purchase_orders`, `purchase_order_items`, `inventory_products`, `inventory_channel_links`, `meli_accounts`, `meli_publications`, `meli_price_manager_items`):
   ```sql
   CREATE USER 'sbs_po_audit_ro'@'127.0.0.1' IDENTIFIED BY '<clave-temporal>';
   GRANT SELECT ON <bd_sbs>.purchase_orders TO 'sbs_po_audit_ro'@'127.0.0.1';
   GRANT SELECT ON <bd_sbs>.purchase_order_items TO 'sbs_po_audit_ro'@'127.0.0.1';
   GRANT SELECT ON <bd_sbs>.inventory_products TO 'sbs_po_audit_ro'@'127.0.0.1';
   GRANT SELECT ON <bd_sbs>.inventory_channel_links TO 'sbs_po_audit_ro'@'127.0.0.1';
   GRANT SELECT ON <bd_sbs>.meli_accounts TO 'sbs_po_audit_ro'@'127.0.0.1';
   GRANT SELECT ON <bd_sbs>.meli_publications TO 'sbs_po_audit_ro'@'127.0.0.1';
   GRANT SELECT ON <bd_sbs>.meli_price_manager_items TO 'sbs_po_audit_ro'@'127.0.0.1';
   FLUSH PRIVILEGES;
   ```
2. Ejecutar desde el checkout aislado `/tmp/sbs-photo-audit` (sin ejecutar `migrate`, `config:cache` en producción ni reiniciar servicios):
   ```bash
   php artisan purchasing:audit-order-photos OC-20261113-0001 --json > /tmp/audit-OC-20261113-0001.json
   ```
3. Revocar y eliminar el usuario temporal `sbs_po_audit_ro` y borrar el directorio `/tmp/sbs-photo-audit` al finalizar.
