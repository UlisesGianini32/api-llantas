# Fase 6: dashboard principal por marca

El dashboard es la entrada de Meli Price Manager y funciona exclusivamente como consulta y navegación. No contiene inputs ni acciones para modificar precios, stock, estado o publicaciones de Mercado Libre.

## Rutas y seguridad

Las rutas autenticadas son:

```text
GET  /meli-price-manager
POST /meli-price-manager/sync
```

`MeliPriceManagerDashboardController` sirve la pantalla y encola la sincronización. `DispatchMeliPriceManagerSyncRequest` valida que `meli_account_id` pertenezca al usuario autenticado. El parámetro `account` del dashboard aplica la misma comprobación y responde 404 para cuentas ajenas.

La cuenta se conserva en query string, por ejemplo:

```text
/meli-price-manager?account=2&brand=5
```

Los accesos a marcas y pendientes también propagan la cuenta seleccionada.

## Estadísticas

Un único agregado SQL por cuenta calcula:

- total local;
- categorizadas;
- sugeridas;
- sin categorizar;
- ignoradas;
- pendientes, como sugeridas más sin categorizar;
- última sincronización;
- sincronizadas recientemente;
- nunca sincronizadas;
- desactualizadas.

El número de marcas activas se obtiene de `meli_brand_groups`. Los conteos se calculan en backend y nunca mezclan cuentas.

## Resumen por marca

Todas las marcas activas se muestran, incluso sin publicaciones. Se ordenan por `sort_order` y `name`.

Cada marca incluye, limitado a la cuenta seleccionada:

- publicaciones categorizadas;
- sugerencias relacionadas;
- precio mínimo y máximo;
- stock total.

Los valores se obtienen mediante `withCount`, `withMin`, `withMax` y `withSum`. Seleccionar una marca agrega `brand` a la URL y limita la tabla a publicaciones categorizadas de esa marca. “Todas las marcas” elimina ese filtro, pero sigue excluyendo sugeridas, sin categorizar e ignoradas.

## Tabla, filtros y ordenamiento

La página `resources/js/Pages/MeliPriceManager/Index.jsx` muestra:

- thumbnail o placeholder;
- título, SKU, MLM y categoría;
- marca reportada por Mercado Libre;
- marca interna;
- precio actual y moneda;
- stock local;
- estado ML;
- última sincronización e indicador stale;
- permalink original.

La tabla incluye checkboxes solo como preparación visual para fases posteriores. No existe ninguna acción de precio.

La consulta selecciona únicamente las columnas visibles y carga `brandGroup` de forma anticipada. No carga `raw_item` ni `raw_attributes`.

Los filtros server-side son búsqueda por título/SKU/MLM/marca ML, marca interna, estado ML, categoría, precio mínimo/máximo, con o sin stock y sincronización reciente/stale/nunca. Los valores de estado y categoría se obtienen de los registros reales de la cuenta.

El ordenamiento usa una whitelist cerrada:

```text
title
sku
current_price
available_quantity
last_synced_at
```

La dirección solo admite ascendente o descendente. La paginación admite 25, 50 o 100 filas y usa 50 por defecto. `withQueryString()` conserva cuenta, marca, búsqueda, filtros, orden y tamaño de página.

## Sincronización

El botón “Sincronizar Mercado Libre” únicamente despacha `SyncMeliPriceManagerItemsJob` a la cola `meli`. La petición web nunca invoca `syncAccount()` ni espera la descarga.

El job conserva su implementación `ShouldBeUnique` por cuenta. Adicionalmente, el controlador consulta el lock único de Laravel y mantiene un indicador temporal por cuenta para mostrar que ya existe una sincronización en cola o proceso. El indicador se libera en `finally` al terminar y también en `failed()`.

La sincronización sigue siendo de lectura remota y escritura local. No reclasifica masivamente, no llama endpoints de fees y no escribe precio, stock o estado en Mercado Libre.

## Indicador stale

El umbral se define en:

```php
config('meli_price_manager.stale_after_hours', 24)
```

El default está en `config/meli_price_manager.php` y no requiere `.env`. Un item es visualmente desactualizado si nunca fue sincronizado o si `last_synced_at` es anterior al umbral. El filtro “Desactualizadas” separa registros con fecha antigua de “Nunca”, mientras la métrica general suma ambos grupos.

## Índice agregado

La migración `2026_08_26_000003_add_dashboard_index_to_meli_price_manager_items.php` agrega un único índice compuesto:

```text
(meli_account_id, classification_status, brand_group_id)
```

Este corresponde al patrón central del dashboard: toda tabla y agregado por marca parte de cuenta y clasificación. Se conservaron los índices individuales existentes para estado, precio y última sincronización; no se añadieron índices redundantes.

## Preparación para Fase 7

La tabla está separada de los agregados y filtros, por lo que podrá incorporar columnas de comisión, envío, retenciones y neto sin rehacer la consulta de navegación. Ninguno de esos cálculos ni endpoints se implementa en esta fase.
