# Meli Price Manager — Fase 2: sincronización de publicaciones

Fecha: 26 de agosto de 2026.

Esta fase incorpora únicamente la lectura del catálogo de Mercado Libre y su persistencia idempotente en `meli_price_manager_items`. No clasifica marcas, no cambia precios ni stock, no pausa publicaciones y no elimina registros locales.

## Infraestructura existente reutilizada

- `App\Models\MeliAccount`: fuente del seller ID (`meli_user_id`), access token, refresh token y expiración.
- `App\Services\MeliOAuthService`: único flujo OAuth utilizado para renovar tokens mediante `/oauth/token`.
- Cuenta propietaria y compatibilidad: después de renovar un token se conserva el llamado existente a `User::syncMeliColumnsFromDefaultAccount()`.
- Cola existente `meli`, ya usada por otros trabajos de Mercado Libre.
- Laravel HTTP client y patrón de logging mediante `Log`.

El acceso autenticado que estaba dentro de `MeliAccountPublicationSyncService` se extrajo a `App\Services\MercadoLibre\MeliAccountApiClient`. El sincronizador anterior continúa utilizando la misma lógica a través de este cliente y Meli Price Manager la reutiliza; no existe un segundo sistema de autenticación.

## Endpoints utilizados

### Descubrimiento

```text
GET https://api.mercadolibre.com/users/{meli_user_id}/items/search
    ?search_type=scan
    &limit=100
    [&scroll_id=...]
```

El seller ID sale de `MeliAccount::meli_user_id`; no hay IDs hardcodeados ni una consulta de identidad adicional.

No se envía un filtro `status`. La intención es obtener el catálogo completo que el endpoint de scan exponga para el seller, en vez de limitar la sincronización a publicaciones activas. Se guarda literalmente el `status` que devuelve el detalle, incluidos estados como `active`, `paused`, `closed`, `under_review` o `inactive` cuando Mercado Libre los incluya.

La paginación usa el `scroll_id` retornado por Mercado Libre. Cada página admite hasta 100 IDs. El recorrido termina cuando la respuesta no contiene IDs, no entrega otro `scroll_id` o deja de aportar IDs nuevos; esto evita ciclos sin limitar el total del catálogo. Los IDs se deduplican antes de solicitar detalles.

### Detalle por lotes

```text
GET https://api.mercadolibre.com/items?ids=MLM1,MLM2,...
```

Los detalles se solicitan con multiget en lotes de 20 IDs, siguiendo la estrategia ya presente en el proyecto. Si un multiget completo falla después de los retries limitados, se intenta `GET /items/{id}` para cada elemento del lote. Así un item defectuoso no impide guardar los demás.

## Campos sincronizados

El upsert usa la llave única `meli_account_id + meli_item_id` y actualiza solo datos originados en Mercado Libre:

- título, categoría, tipo de publicación y producto de catálogo;
- precio actual y original;
- cantidades disponible y vendida;
- moneda, estado, permalink y thumbnail;
- SKU, marca y marca normalizada;
- atributos completos y snapshot del item;
- `last_synced_at`.

En una fila existente nunca se escriben `brand_group_id`, `classification_status`, `classification_source` ni `classification_confidence`. En una fila nueva opera el default de base de datos `classification_status=uncategorized`.

Una publicación que no aparezca en un scan no se borra ni se marca automáticamente. Una sincronización posterior puede actualizarla si vuelve a aparecer.

## SKU

`extractSku()` revisa, en este orden:

1. atributo superior `SELLER_SKU`;
2. atributo superior `SKU`;
3. `seller_custom_field` del item;
4. `SELLER_SKU`, `SKU` y `seller_custom_field` de variaciones.

Se conserva el primer valor no vacío. Si ninguno existe, se guarda `null`; no se genera un SKU ficticio.

## BRAND y normalización

La marca se obtiene exclusivamente del atributo superior con ID `BRAND`. Si no existe o está vacío, `meli_brand` y `normalized_brand` quedan en `null`.

`MeliBrandNormalizer` aplica solo una normalización reutilizable y no clasificatoria: trim, transliteración de acentos, espacios consecutivos a uno y mayúsculas. No crea grupos, alias ni sugerencias.

## Datos raw y secretos

`raw_attributes` conserva los atributos recibidos. `raw_item` conserva la respuesta completa mientras el JSON sanitizado no exceda 1 MB. Si excede ese tamaño, se guarda un marcador con tamaño, hash, ID y estado para evitar una fila desproporcionada.

Las claves sensibles (`access_token`, `refresh_token`, `authorization`, `client_secret`, `password`, `secret`) se redactan recursivamente y los patrones de tokens también se eliminan de strings. Los logs incluyen IDs, status HTTP, clase y mensaje de la excepción, pero no tokens.

## Refresh y retries

- Antes del scan se renueva el token si falta o expira dentro de cinco minutos y hay refresh token.
- Ante `401`, se usa una sola vez `MeliOAuthService::refreshAccessToken()` y se reintenta la petición. Un segundo `401` falla sin otro refresh.
- Ante `429`, se respeta `Retry-After` numérico o con fecha, limitado a 60 segundos por intento. Si no está presente, se usa backoff exponencial.
- Ante `500`, `502`, `503`, `504`, otros 5xx o fallos de conexión, se realizan hasta cinco intentos totales con backoff de 1, 2, 4 y 8 segundos.
- No hay loops infinitos de refresh ni de retries.

Los errores incluidos dentro de un multiget se contabilizan por item y no detienen el resto. El resumen devuelve `total_found`, `processed`, `created`, `updated`, `failed` y hasta diez `error_details`.

## Job y comando

`SyncMeliPriceManagerItemsJob` recibe `meli_account_id`, es único por cuenta durante 30 minutos y usa:

- queue: `meli`;
- tries: 3;
- backoff del job: 60 y 300 segundos;
- timeout: 1800 segundos.

Ejecución en cola:

```bash
php artisan meli:price-manager-sync --account=1
```

Ejecución síncrona para desarrollo y diagnóstico:

```bash
php artisan meli:price-manager-sync --account=1 --sync
```

Sin `--account`, el comando selecciona la cuenta únicamente cuando existe exactamente una cuenta con access token o refresh token. Si hay varias, exige el ID explícito; nunca sincroniza todas silenciosamente.

## Validación con una cuenta real

Antes de usar el resultado para decisiones comerciales debe validarse que el scan de la cuenta concreta devuelve todos los estados históricos esperados y que los SKUs usados por sus publicaciones siguen las fuentes contempladas. La fase no ejecuta escrituras remotas, por lo que esta validación es de solo lectura.
