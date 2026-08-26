# Meli Price Manager — Fase 1: base de datos y modelos

Fecha: 26 de agosto de 2026.

Esta fase crea exclusivamente la base de persistencia del módulo Meli Price Manager. No contiene sincronización, llamadas HTTP, clasificación automática, cálculo de comisiones, cambios de precio, jobs, controladores, rutas ni interfaz.

## Modelos existentes reutilizados

- Cuenta de Mercado Libre: `App\Models\MeliAccount`, tabla `meli_accounts`.
- Usuario del sistema: `App\Models\User`, tabla `users`.

No se creó una segunda representación de cuentas o usuarios. `MeliAccount` recibió únicamente `HasFactory` y las relaciones inversas `priceManagerItems()` y `priceChangeBatches()`.

## Tablas

### `meli_brand_groups`

Agrupación comercial interna. Contiene nombre, slug único, descripción, activación y orden. No se incluyen marcas iniciales ni se ejecuta clasificación.

Índices:

- unique `slug`;
- `active`;
- `sort_order`.

### `meli_brand_aliases`

Alias explícitos asociados a una agrupación. La combinación `brand_group_id + normalized_alias` es única para impedir que el mismo alias normalizado se duplique dentro de una marca. `normalized_alias` también tiene un índice independiente para futuras búsquedas globales y `active` permite filtrar reglas vigentes.

La FK de marca usa `restrictOnDelete`: un grupo con reglas de alias no puede eliminarse accidentalmente.

### `meli_price_manager_items`

Snapshot local futuro de una publicación. Reutiliza `meli_accounts` y almacena `meli_item_id` como string. La combinación `meli_account_id + meli_item_id` es única; el mismo ID puede existir para cuentas diferentes.

Los campos monetarios usan `decimal(15, 2)`. `classification_confidence` usa `decimal(5, 4)`, suficiente para representar `0.0000` a `1.0000` sin usar float. Los atributos y la respuesta fuente se conservan como JSON nullable.

Índices:

- unique compuesto `meli_account_id + meli_item_id` —también cubre búsquedas por cuenta mediante su prefijo izquierdo—;
- `meli_item_id` para búsquedas entre cuentas;
- `sku`;
- `brand_group_id`;
- `classification_status`;
- `normalized_brand`;
- `status`;
- `current_price`;
- `last_synced_at`.

La cuenta usa `restrictOnDelete` para preservar snapshots. La marca usa `nullOnDelete`: una publicación se conserva si desaparece un grupo que ya no tiene alias dependientes.

### `meli_price_change_batches`

Encabezado auditable de una operación futura individual o masiva. Mantiene cuenta, marca opcional, creador opcional, tipo, estado, notas y contadores.

Índices: `meli_account_id`, `brand_group_id`, `created_by`, `type`, `status` y `created_at`.

La cuenta usa `restrictOnDelete`. La marca y el usuario usan `nullOnDelete` para preservar el batch histórico.

### `meli_price_changes`

Detalle histórico de una propuesta o cambio de precio. Conserva el `meli_item_id` además de la FK al item para que el identificador remoto quede explícito en el registro. Todos los importes son `decimal(15, 2)`.

Índices: `batch_id`, `price_manager_item_id`, `meli_item_id`, `status`, `changed_by` y `changed_at`.

El item usa `restrictOnDelete` para proteger el historial. Batch y usuario son opcionales y usan `nullOnDelete`.

## Relaciones Eloquent

```text
MeliBrandGroup
 ├─ aliases()              -> MeliBrandAlias[]
 ├─ items()                -> MeliPriceManagerItem[]
 └─ priceChangeBatches()   -> MeliPriceChangeBatch[]

MeliBrandAlias
 └─ brandGroup()           -> MeliBrandGroup

MeliPriceManagerItem
 ├─ meliAccount()          -> MeliAccount
 ├─ brandGroup()           -> MeliBrandGroup|null
 └─ priceChanges()         -> MeliPriceChange[]

MeliPriceChangeBatch
 ├─ meliAccount()          -> MeliAccount
 ├─ brandGroup()           -> MeliBrandGroup|null
 ├─ creator()              -> User|null
 └─ changes()              -> MeliPriceChange[]

MeliPriceChange
 ├─ batch()                -> MeliPriceChangeBatch|null
 ├─ item()                 -> MeliPriceManagerItem
 └─ changedBy()            -> User|null
```

## Estados y tipos

El proyecto no tenía una convención de enums PHP en `app/Enums`; sus módulos recientes usan constantes de modelo y enums de base de datos. Para mantener esa convención no se crearon enums PHP. Los valores aceptados están centralizados como constantes y restringidos por la migración:

- `MeliBrandAlias::MATCH_TYPES`: `exact`, `contains`, `starts_with`, `manual`.
- `MeliPriceManagerItem::CLASSIFICATION_STATUSES`: `categorized`, `suggested`, `uncategorized`, `ignored`.
- `MeliPriceChangeBatch::TYPES`: `individual`, `percentage`, `fixed`, `excel`.
- `MeliPriceChangeBatch::STATUSES`: `draft`, `preview`, `processing`, `completed`, `partial`, `failed`, `cancelled`.
- `MeliPriceChange::STATUSES`: `pending`, `processing`, `success`, `failed`, `cancelled`.

Defaults: `classification_status=uncategorized`, batch `status=draft` y cambio `status=pending`.

## Casts

- Booleanos: `active`.
- Enteros: orden, prioridad, cantidades y contadores.
- Arrays: `raw_attributes`, `raw_item`.
- Datetime: `last_synced_at`, `changed_at`.
- Decimal string: precios, confianza, cargos y neto estimado.

Los casts decimales de Eloquent devuelven strings con escala fija para evitar cálculos implícitos con float.

## Factories y pruebas

Hay factories para los cinco modelos nuevos y para el modelo existente `MeliAccount`, necesaria para construir items y batches independientes. Las pruebas usan SQLite `:memory:` y ejecutan solo esta migración sobre esquemas mínimos de `users` y `meli_accounts`; así no dependen del historial completo de migraciones.

Las pruebas cubren unicidad, enums de base de datos, relaciones, factories, JSON, casts decimales, IDs repetidos entre cuentas distintas, rechazo de duplicados dentro de una cuenta e índices principales.

## Alcance pendiente

La normalización y los campos de clasificación quedan preparados, pero ningún modelo normaliza o clasifica automáticamente. Tampoco se insertan seeds, se consultan publicaciones remotas ni se modifica precio alguno. Todo eso corresponde a fases posteriores.
