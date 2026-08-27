# Fase 7C: cambio individual confirmado de precio

## Alcance

Esta fase permite modificar únicamente el precio standard de una publicación individual administrable por Meli Price Manager. No incluye cambios masivos, promociones ni administración de automatizaciones de precio.

## Flujo de confirmación

1. El simulador calcula cargos con los servicios existentes.
2. El servidor guarda en cache un snapshot de la simulación y entrega un token aleatorio de 64 caracteres con vigencia de 10 minutos.
3. El snapshot liga usuario, cuenta, item local, MLM, precio observado, precio propuesto y el desglose completo de cargos. React no es fuente de verdad para esos cargos.
4. La UI muestra una segunda confirmación explícita antes de llamar a `PUT /meli-price-manager/items/{item}/price`.
5. El servicio adquiere un lock por cuenta e item, vuelve a comprobar `managedCatalog()`, ownership y estado, y crea la auditoría local en estado `processing`.
6. Si el item contiene el tag `dynamic_standard_price`, se bloquea. En otro caso se consulta `GET /pricing-automation/items/{ITEM_ID}/automation`: solamente un 404 permite continuar; una automatización existente o un error inesperado bloquean el cambio.
7. Se consulta `GET /items/{ITEM_ID}/prices` y se exige exactamente un precio `standard` aplicable a `channel_marketplace`. El valor debe coincidir con el snapshot de la simulación.
8. Justo antes de escribir se vuelve a ejecutar la barrera `managedCatalog()`.
9. Se envía `PUT /items/{ITEM_ID}` con únicamente `{ "price": ... }`.
10. Se inspeccionan errores y warnings, y se vuelve a consultar `/prices`. Solo si el precio standard confirmado coincide se actualiza `current_price`, se completa la auditoría y se consume el token.

## Auditoría e idempotencia

Cada intento que supera las barreras iniciales usa un `meli_price_change_batch` de tipo `individual` y un solo `meli_price_change`. Los cargos estimados provienen del snapshot server-side. `selling_fee`, `shipping_cost`, `tax_withholding`, `other_charges` y `estimated_net` reutilizan las columnas existentes; `tax_withholding` permanece `null` cuando es desconocido. El JSON completo de la simulación se conserva en `batch.notes.simulation_snapshot`, incluyendo costo de publicación, detalles, envío e indisponibilidad fiscal. Los errores se sanitizan antes de persistirse o devolverse.

Un `Cache::lock` de 60 segundos impide escrituras simultáneas sobre la misma publicación. El token se consume tras un éxito confirmado, por lo que no puede reutilizarse para un segundo PUT.

## Comportamiento conservador

- Una estructura ambigua de `/prices` bloquea el cambio.
- Una promoción nunca se modifica ni se interpreta como precio standard.
- Una automatización pausada o de estado desconocido se considera existente y bloquea el cambio.
- Un fallo al consultar automatización bloquea el cambio.
- Un HTTP 200 con `item.price.not_modifiable`, o sin confirmación posterior del nuevo precio, queda como fallo y no actualiza el snapshot local.

## Limitaciones

- La vigencia e idempotencia dependen de que el store de cache compartido soporte locks en todas las instancias de la aplicación.
- La API externa y la base local no comparten una transacción distribuida. Si Mercado Libre confirma el cambio y después falla la persistencia local, el token se invalida para evitar una segunda escritura; se requerirá resincronización o revisión operativa.
- La fase no crea, elimina, pausa ni modifica automatizaciones, promociones u ofertas.
