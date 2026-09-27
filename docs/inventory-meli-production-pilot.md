# Piloto de stock Inventory → Mercado Libre

Este procedimiento está diseñado para probar un único vínculo. No habilita sincronización masiva ni scheduler.

## Preparación

1. Desplegar el código y ejecutar `php artisan migrate --force`.
2. Confirmar que los workers y la configuración de Mercado Libre estén disponibles.
3. Localizar el vínculo y mantener `stock_sync_enabled=false`.
4. Ejecutar el preview:

   `php artisan inventory:meli-stock-pilot --link=<LINK_ID>`

5. Revisar SKU, cuenta, MLM, variación, stock físico, reservado, disponible, target, ML actual, delta, ownership legacy y `user_product_id` si existe.
6. Los vínculos simples con el mismo `account_key + remote_user_product_id` comparten stock. Se elige como representante el vínculo activo y habilitado de ID menor; solo se envía un PUT y ambos usan el mismo lock.
7. Una variación nunca se agrupa en este flujo, aunque la respuesta incluya `user_product_id`.
8. Si distintos productos Inventory activos y habilitados apuntan al mismo grupo, se informa `REMOTE_USER_PRODUCT_CONFLICT` y no se escribe.
9. Delta cero genera auditoría `SUCCESS` con `no_change` y no envía PUT. Un sibling puede tardar en reflejar la propagación; se deja como pendiente/mismatch en metadata de la auditoría del piloto.
10. Un drift coincide con una escritura auditada reciente del mismo grupo y target se marca como explicado; otros casos siguen mostrando `REMOTE_DRIFT`.

## Aplicación controlada

1. Habilitar explícitamente únicamente el vínculo elegido.
2. Ejecutar nuevamente el preview y confirmar que la elegibilidad sea `READY`.
3. Copiar exactamente la identidad que recibirá el PUT (`Write identity`). En un grupo compartido puede diferir de `Requested identity`:
   - publicación simple: `MLM123`;
   - variación: `MLM123:987654321`.
4. Ejecutar:

   `php artisan inventory:meli-stock-pilot --link=<LINK_ID> --apply --confirm=<IDENTIDAD>`

5. Verificar el resultado PUT y la lectura posterior:
   - `VERIFIED`: ML coincide con el target;
   - `MISMATCH`: ML devolvió otra cantidad;
   - `UNVERIFIED`: el PUT pudo ejecutarse, pero la lectura posterior falló.
6. Consultar `inventory_channel_stock_syncs` para revisar `previous_known_quantity`, `target_quantity`, `verified_quantity`, `verification_status` y las marcas de tiempo.
7. Comprobar directamente la publicación en Mercado Libre y confirmar que el writer legacy la omita.

Las variaciones quedan bloqueadas si el writer legacy puede tocar el listing completo; no se intenta fingir aislamiento por variación.

El ownership de Inventory cubre únicamente `available_quantity`. El writer legacy puede continuar enviando precio o status mediante un PUT parcial. El writer de stock compartido es stock-only y sí omite por completo el vínculo administrado por Inventory.

Cuando un vínculo simple activo con stock habilitado posee un grupo conocido, los writers legacy omiten `available_quantity` para todos los siblings conocidos de la misma cuenta y `remote_user_product_id`, aunque el sibling tenga Inventory deshabilitado. No se hereda ownership entre cuentas, variaciones ni identidades remotas nulas.

## Contingencia

1. Desactivar `stock_sync_enabled` para el vínculo.
2. Detener nuevas escrituras de Inventory para ese vínculo.
3. Revisar `remote_before`, `target_sent` y `remote_after` en la auditoría.
4. Validar el stock físico real, ventas y reservas antes de corregir ML.
5. Confirmar el estado del writer legacy antes de devolver ownership.

No se guardan tokens ni secretos en esta documentación. El piloto no implementa restore automático: cualquier recuperación debe ser explícita y revisada por un administrador.

`remote_user_product_id` se descubre mediante GETs que ya realiza el piloto y se persiste en el vínculo. El importador permanece local/read-only y no rastrea publicaciones. Inventory posee únicamente `available_quantity` cuando `stock_sync_enabled=true`; precio y estado legacy conservan su flujo. El scheduler Inventory sigue desactivado.
