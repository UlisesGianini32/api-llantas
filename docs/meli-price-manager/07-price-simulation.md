# Fase 7A + 7B: simulación de precio

Esta fase permite consultar cargos hipotéticos de Mercado Libre sin guardar ni aplicar precios. No crea lotes o cambios de precio, no modifica stock y no envía `PUT` a Mercado Libre.

## Alcance protegido

El endpoint vuelve a cargar cada publicación con `MeliPriceManagerItem::managedCatalog()` y comprueba que su cuenta pertenezca al usuario autenticado. Por ello permanecen excluidas las publicaciones de llantas, productos compuestos, Syscom y autopartes. La sincronización general no cambia.

## Consultas

`MeliPriceSimulationService` reutiliza `MeliAccountApiClient` y realiza solamente:

1. `GET /sites/MLM/listing_prices`, con el precio hipotético y los datos reales de categoría, moneda, tipo de publicación y logística.
2. `GET /users/{meli_user_id}/shipping_options/free` solo cuando `raw_item.shipping.free_shipping` es verdadero.

Las dimensiones usan primero los cuatro atributos `SELLER_PACKAGE_*` y después los cuatro `PACKAGE_*`. Solo se envían cuando el conjunto está completo; cada valor positivo se redondea hacia arriba. Si faltan, la consulta de envío conserva `item_id` y los demás parámetros disponibles, sin inventarlas.

El resultado conserva una estructura `charges` estable y mantiene los campos planos anteriores por compatibilidad. Incluye:

- cargo total por venta, porcentaje total, porcentaje de plataforma cuando ML lo entregue, cargo fijo, componente de cuotas y cargo bruto;
- costo de publicación, detalle fijo y costo bruto;
- costo de envío que paga el vendedor, tarifa original, tasa/tipo/monto del descuento, moneda, peso facturable y logística;
- campos numéricos futuros relacionados con cargos como información no sumada hasta que Mercado Libre documente su semántica;
- impuestos, IVA, ISR y retenciones como `null`, nunca como cero, porque no existe un cálculo oficial fiable previo a la venta para el vendedor.

`confirmed_charges_total` suma una sola vez los importes que absorbe el vendedor: `sale_fee_amount`, `listing_fee_amount` cuando está disponible y `shipping.list_cost`. Los componentes de detalle, porcentajes, montos brutos y tarifa original de envío son informativos y no vuelven a sumarse.

`financing_add_on_fee` se conserva con la unidad porcentual que devuelve el calculador —los ejemplos oficiales lo muestran como componente de `percentage_fee`— y no se interpreta como un segundo importe monetario. Su efecto ya está incluido en `sale_fee_amount`.

El neto se presenta como **“Recibes estimado antes de impuestos/retenciones”** y advierte que el monto final puede variar al procesarse la venta.

## Disponibilidad temporal de la información

### Antes de la venta

- `GET /sites/MLM/listing_prices`: cargos de venta y publicación y sus detalles. Documentación oficial: `https://developers.mercadolibre.com.mx/es_mx/comision-por-vender`.
- `GET /users/{USER_ID}/shipping_options/free`: estimación del costo del vendedor, descuento y peso facturable. Documentación oficial: `https://developers.mercadolibre.com.mx/es_ar/costos-de-envios`.

### Después de la venta

La orden, el pago y el envío ya creados permiten consultar importes reales asociados a esa operación. Esos recursos requieren una venta concreta y no sirven para simular de forma fiable IVA, ISR o retenciones antes de que exista.

### Facturación y provisiones

Los reportes `GET /billing/integration/...` contienen cobros, bonificaciones y detalles fiscales de períodos y documentos existentes. Mercado Libre los define como recursos de posventa para conciliación fiscal, no como fuente primaria de una simulación operacional. Por eso esta fase no hace llamadas de billing ni inventa una tasa fiscal.

## Endpoint y pantalla

`POST /meli-price-manager/items/{item}/simulate-price` requiere autenticación y un `price` numérico mayor que cero. Devuelve JSON y nunca modifica `current_price`.

El Dashboard ofrece `Simular precio`. El modal muestra el desglose oficial disponible, separa datos informativos de cargos sumados y deja visibles las limitaciones fiscales. La simulación por sí sola nunca envía un `PUT` ni modifica `current_price`.
