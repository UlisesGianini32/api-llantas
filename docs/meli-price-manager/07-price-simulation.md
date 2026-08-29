# Fase 7A + 7B: simulación de precio

Esta fase permite consultar cargos hipotéticos de Mercado Libre sin guardar ni aplicar precios. No crea lotes o cambios de precio, no modifica stock y no envía `PUT` a Mercado Libre.

## Alcance protegido

El endpoint vuelve a cargar cada publicación con `MeliPriceManagerItem::managedCatalog()` y comprueba que su cuenta pertenezca al usuario autenticado. Por ello permanecen excluidas las publicaciones de llantas, productos compuestos, Syscom y autopartes. La sincronización general no cambia.

## Consultas

`MeliPriceSimulationService` reutiliza `MeliAccountApiClient` y realiza solamente:

1. `GET /sites/MLM/listing_prices`, con el precio hipotético y los datos reales de categoría, moneda, tipo de publicación y logística.
2. `GET /users/{meli_user_id}/shipping_options/free` al pulsar **Calcular cargos**, con `item_id`, el nuevo `item_price`, tipo de publicación, condición, modo, tipo logístico, indicador de envío gratis y dimensiones cuando estén completas.

Las dimensiones usan primero los cuatro atributos `SELLER_PACKAGE_*` y después los cuatro `PACKAGE_*`. El servicio lee `struct.number` y `struct.unit`, con `value_name` únicamente como fallback parseable; convierte `mm`, `cm` y `m` a centímetros y `mg`, `g` y `kg` a gramos enteros. Solo envía `height x width x length,weight` cuando las cuatro medidas tienen unidades reconocidas y valores positivos. Si falta o no puede normalizar alguna, la consulta conserva `item_id` y los demás parámetros disponibles sin inventar `dimensions`.

El resultado conserva una estructura `charges` estable y mantiene los campos planos anteriores por compatibilidad. Incluye:

- cargo total por venta, porcentaje total, porcentaje de plataforma cuando ML lo entregue, cargo fijo, componente de cuotas y cargo bruto;
- costo de publicación, detalle fijo y costo bruto;
- costo de envío que paga el vendedor, tarifa original, tasa/tipo/monto del descuento, moneda, peso facturable y logística;
- campos numéricos futuros relacionados con cargos como información no sumada hasta que Mercado Libre documente su semántica;
- retenciones fiscales estimadas únicamente cuando la cuenta tiene un perfil fiscal explícito, habilitado y vigente. Sin perfil, los importes permanecen `null`, nunca en cero.

`platform_charges_total` suma `sale_fee_amount` y `listing_fee_amount` cuando está disponible. `meli_charges_total` y `confirmed_charges_total` conservan por compatibilidad ese subtotal más `coverage.all_country.list_cost` únicamente cuando la cotización está disponible y usa la misma moneda de la publicación. Los componentes de detalle, porcentajes, montos brutos, `promoted_amount`, `rate` y `save` son informativos y no vuelven a sumarse ni se usan para inventar descuentos.

La semántica exacta de los totales es:

- `platform_charges_total`: cargos de venta y publicación, sin envío ni impuestos.
- `meli_charges_total`: campo histórico; cargos de plataforma más costo de envío vendedor disponible, sin impuestos.
- `confirmed_charges_total`: alias histórico de `meli_charges_total`, conservado para snapshots y auditoría.
- `shipping.cost`: `list_cost` compatible; `0` es válido y `null` significa no disponible.
- `taxes_total`: retenciones fiscales disponibles, separado del envío y la plataforma.
- `total_charges`: plataforma más envío válido más retenciones disponibles, cada concepto exactamente una vez.
- `estimated_receivable`: precio propuesto menos `total_charges`.

La cotización de envío diferencia explícitamente `0` de `null`: cero es un costo válido devuelto por Mercado Libre; `null` significa que no pudo determinarse. Un error de la cotización no descarta los cargos de venta ni las retenciones ya calculadas. En ese caso el resultado se etiqueta **“Recibes antes de envío”** y advierte que el neto todavía no descuenta ese concepto. Una moneda de envío distinta tampoco se mezcla ni se resta silenciosamente.

Cuando el envío está disponible, el neto se calcula así:

```text
recibes_estimado = precio_propuesto
    - cargos_plataforma
    - costo_envío_vendedor
    - retenciones_fiscales_disponibles
```

La tabla `meli_account_tax_profiles` mantiene una configuración única por `meli_account_id`; no existen tasas globales ni valores fiscales predeterminados. Cuando está habilitada, `MeliSellerTaxSimulationService` calcula sobre la base sin IVA:

```text
base = price / (1 + vat_included_rate / 100)
iva_retenido = round(base * vat_withholding_rate / 100, 2)
isr_retenido = round(base * income_tax_withholding_rate / 100, 2)
taxes_total = iva_retenido + isr_retenido
```

Cada retención se redondea por separado. El perfil completo, sus tasas, la base y los importes forman parte del snapshot server-side. Para $699 con tasas 16%, 8% y 2.5%, la base es $602.59, las retenciones son $48.21 y $15.06, y su total es $63.27.

`financing_add_on_fee` se conserva con la unidad porcentual que devuelve el calculador —los ejemplos oficiales lo muestran como componente de `percentage_fee`— y no se interpreta como un segundo importe monetario. Su efecto ya está incluido en `sale_fee_amount`.

El neto se presenta como **“Recibes estimado”** cuando incluye un perfil fiscal aplicable. Sin perfil se identifica expresamente como un neto sin retenciones fiscales y se advierte que el monto recibido puede ser menor.

## Disponibilidad temporal de la información

### Antes de la venta

- `GET /sites/MLM/listing_prices`: cargos de venta y publicación y sus detalles. Documentación oficial: `https://developers.mercadolibre.com.mx/es_mx/comision-por-vender`.
- `GET /users/{USER_ID}/shipping_options/free`: cotización aproximada del costo del vendedor antes de que exista un envío real. El costo principal es `coverage.all_country.list_cost`. El costo definitivo puede variar. Documentación oficial: `https://developers.mercadolibre.com.mx/es_ar/manejo-de-pagos/costos-de-envios`.

### Después de la venta

La orden, el pago y el envío ya creados permiten consultar importes reales asociados a esa operación. Para conciliar el envío definitivo se usa `GET /shipments/{shipment_id}/costs` y el costo del vendedor en `senders[].cost`. Esos recursos requieren una venta concreta; por eso no sustituyen `shipping_options/free` durante la simulación previa.

### Facturación y provisiones

Los reportes `GET /billing/integration/...` contienen cobros, bonificaciones y detalles fiscales de períodos y documentos existentes. Mercado Libre los define como recursos de posventa para conciliación fiscal, no como fuente primaria de una simulación operacional. Por eso esta fase no hace llamadas de billing ni inventa una tasa fiscal.

## Endpoint y pantalla

`POST /meli-price-manager/items/{item}/simulate-price` requiere autenticación y un `price` numérico mayor que cero. Devuelve JSON y nunca modifica `current_price`.

El Dashboard ofrece `Simular precio` y una configuración fiscal mínima por cuenta. Los porcentajes deben corresponder a la situación real del vendedor y no se obtienen automáticamente de Mercado Libre. El modal separa cargos de ML, retenciones estimadas desde el perfil local, total estimado y neto. La simulación por sí sola nunca envía un `PUT` ni modifica `current_price`.
