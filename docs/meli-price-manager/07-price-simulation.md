# Fase 7A + 7B: simulación de precio

Esta fase permite consultar cargos hipotéticos de Mercado Libre sin guardar ni aplicar precios. No crea lotes o cambios de precio, no modifica stock y no envía `PUT` a Mercado Libre.

## Alcance protegido

El endpoint vuelve a cargar cada publicación con `MeliPriceManagerItem::managedCatalog()` y comprueba que su cuenta pertenezca al usuario autenticado. Por ello permanecen excluidas las publicaciones de llantas, productos compuestos, Syscom y autopartes. La sincronización general no cambia.

## Consultas

`MeliPriceSimulationService` reutiliza `MeliAccountApiClient` y realiza solamente:

1. `GET /sites/MLM/listing_prices`, con el precio hipotético y los datos reales de categoría, moneda, tipo de publicación y logística.
2. `GET /users/{meli_user_id}/shipping_options/free` solo cuando `raw_item.shipping.free_shipping` es verdadero.

Las dimensiones usan primero los cuatro atributos `SELLER_PACKAGE_*` y después los cuatro `PACKAGE_*`. Solo se envían cuando el conjunto está completo; cada valor positivo se redondea hacia arriba. Si faltan, la consulta de envío conserva `item_id` y los demás parámetros disponibles, sin inventarlas.

El resultado incluye cargo de venta, porcentaje y cargo fijo, costo y descuento de envío, total de cargos, monto estimado a recibir y porcentaje recibido. No se persiste.

## Endpoint y pantalla

`POST /meli-price-manager/items/{item}/simulate-price` requiere autenticación y un `price` numérico mayor que cero. Devuelve JSON y nunca modifica `current_price`.

El Dashboard ofrece `Simular precio`. El modal permite cambiar el precio y ejecutar exclusivamente `Calcular cargos`; muestra de forma destacada el monto estimado que recibe el vendedor y advierte que la publicación no fue modificada. No existen acciones para guardar, aplicar o publicar el precio.
