# Fase 7E: investigación de impuestos dinámicos

Fecha de investigación: 2026-08-27.

## Resultado y límite de esta ejecución

No se realizaron consultas autenticadas contra una cuenta real. El worktree y el repositorio principal no contienen `.env`, base SQLite, configuración cacheada ni variables `DB_*`/`MELI_*`; por ello no existe una forma segura de cargar un `MeliAccount` o su token desde este entorno. No se intentó una petición sin credenciales y no se imprimieron secretos.

La muestra real de esta ejecución es, por tanto:

- orders analizadas: 0;
- item_ids distintos: 0;
- categorías y precios comparados: 0;
- reglas fiscales derivadas: 0.

Este límite impide afirmar cómo responde MLM para la cuenta del proyecto, si los impuestos varían por producto o si una relación observada puede extrapolarse a otro precio. No se creó un detector de reglas ni se conectó historial fiscal al neto preventa.

## Endpoints oficiales revisados

La documentación oficial vigente describe los siguientes GET read-only relevantes:

- `GET /orders/search?seller={SELLER_ID}&order.status=paid&sort=date_desc` para localizar ventas recientes;
- `GET /orders/{ORDER_ID}` para los datos de una orden;
- `GET /billing/integration/group/ML/order/details?order_ids={ORDER_IDS}` para facturación por hasta 60 orders;
- `GET /billing/integration/periods/key/{KEY}/group/ML/details?document_type=BILL` para detalles por período;
- `GET /orders/{ORDER_ID}/discounts` y `GET /shipments/{SHIPMENT_ID}` para conciliación de descuentos y envío.

Fuentes:

- `https://developers.mercadolibre.com.mx/es_mx/provisiones`
- `https://developers.mercadolibre.com.mx/es_ar/api-docs-es/gestiona-ventas`
- `https://developers.mercadolibre.com.mx/es_ar/administra-proyectos-aplicaciones/buenas-practicas-para-el-consumo-de-las-apis-de-reportes-de-facturacion`

Ninguno de esos endpoints fue llamado contra la cuenta real durante esta ejecución. En particular, no se utilizó ningún POST requerido para generar reportes descargables.

## Estructura documentada de `tax_details`

El ejemplo oficial de `GET /billing/integration/group/ML/order/details` presenta:

```text
results[]
  order_id
  payment_info[]
    payment_id
    date_approved
    status
    tax_details[]
      from
      to
      original_amount
      refunded_amount
      mov_detail
      mov_financial_entity
      tax_id
      tax_status
  details[]
    items_info
```

Los identificadores mostrados por el ejemplo oficial incluyen valores como `tax_withholding`, `tax_withholding_collector`, `retencion_ganancias`, `retencion_iva`, `debitos_creditos` y `cordoba`. Estos valores pertenecen al ejemplo publicado y no se consideran hallazgos de la cuenta MLM.

En esa estructura documentada:

- sí existe importe mediante `original_amount` y un importe reembolsado separado;
- no aparece un porcentaje o alícuota dentro de `tax_details`;
- no aparece una base gravable dentro de `tax_details`;
- el impuesto está asociado al pago de la order, no inequívocamente a cada item de una order con varios productos.

Otra sección de facturación documenta `perception_info.aliquot` y `perception_info.taxable_amount`, pero no se debe asumir que esos campos aparecen en `payment_info.tax_details` ni que aplican a esta cuenta o a MLM sin observar una respuesta real.

## Base read-only implementada

`MeliHistoricalTaxDataService` consulta exclusivamente `GET /billing/integration/group/ML/order/details`, limita cada llamada a 60 orders, elimina duplicados de entrada y usa cache temporal separada por cuenta y conjunto de orders. Usa la entrada `getReadOnly()` de `MeliAccountApiClient`, que no intenta renovar el token mediante OAuth ante un 401; así una investigación con token vencido falla sin emitir un POST.

`MeliTaxDetailsNormalizer`:

- conserva únicamente keys fiscales documentados que realmente existan;
- no crea tasas, bases, nombres fiscales ni totales ausentes;
- omite `payer_id`, método de pago, títulos y otros datos innecesarios del comprador/producto;
- conserva la agrupación por order y payment;
- declara `attribution_scope = order_payment` para evitar atribuir impuestos de una order multi-item a una publicación concreta;
- devuelve `available = false`, `source = null` y `confidence = unknown` cuando billing no contiene detalles fiscales.

Esta capa no persiste historial, no requiere migración, no modifica Mercado Libre y no participa todavía en `MeliPriceSimulationService`.

## Inferencia y jerarquía de fuentes

La jerarquía objetivo se mantiene:

1. dato exacto de Mercado Libre para una operación;
2. regla estable de varias ventas de la misma publicación;
3. historial comparable, solo con evidencia adicional;
4. perfil manual de la cuenta;
5. desconocido.

La ejecución actual no permite habilitar los niveles 1 a 3 en una simulación futura. El perfil manual existente permanece sin cambios y no se autoactiva. Implementar una media global, una tasa por categoría o el benchmark 16%/8%/2.5% como regla automática sería inseguro.

## Siguiente investigación recomendada

En un entorno temporal con acceso seguro a la cuenta y token vigente:

1. consultar 10 orders pagadas recientes mediante `GET /orders/search`;
2. excluir de la muestra analítica cualquier item que pertenezca a catálogos externos;
3. consultar una sola vez billing para esos order_ids;
4. registrar únicamente order_id, item_id, categoría, precio y keys fiscales sanitizados;
5. comprobar si las orders son mono-item o multi-item antes de atribuir impuestos;
6. buscar al menos tres ventas del mismo item sin resultados contradictorios;
7. no extrapolar hasta observar tasa y relación de base estables dentro de tolerancia monetaria.

Si `tax_details` real continúa sin tasa ni base, los importes solo servirán para conciliación exacta de la venta histórica. Una tasa efectiva podría mostrarse como dato analítico `derived`, pero no como tasa oficial ni como regla preventa sin evidencia repetida.
