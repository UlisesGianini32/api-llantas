# Rollout de Inventory en producción

Runbook para Tickets 1–10 en `/var/www/vhosts/mrpoolhmo.com/httpdocs`. El despliegue no habilita escrituras hacia Mercado Libre: todos los vínculos deben permanecer con `stock_sync_enabled=false` hasta una aprobación posterior.

## Fase A: preflight local

```powershell
git status
git log --oneline -10
php artisan test tests/Feature/InventoryRolloutCheckTest.php
php artisan test tests/Feature/InventoryMeliStockPilotTest.php
php artisan test tests/Feature/MeliSyncStockOwnershipTest.php
php artisan test tests/Feature/InventoryMeliStockSyncTest.php
php artisan test tests/Feature/InventoryMeliLinkImportTest.php
php artisan test tests/Feature/InventoryChannelLinksTest.php
php artisan test tests/Feature/InventoryKitsTest.php
php artisan test tests/Feature/InventoryReservationsTest.php
php artisan test tests/Feature/InventoryMovementsTest.php
php artisan test tests/Feature/InventoryProductsTest.php
npm.cmd run build
vendor\bin\pint --test
git diff --check
```

Tickets 1–9 no cambiaron `composer.json`, `composer.lock`, `package.json` ni `package-lock.json`. `public/build` está ignorado, por lo que debe confirmarse si el servidor construye assets o recibe un artefacto de CI.

## Fase B: backup y release

En el servidor, antes de modificar nada:

```bash
cd /var/www/vhosts/mrpoolhmo.com/httpdocs
git status
git branch --show-current
git rev-parse HEAD
git log --oneline -5
```

Guardar el hash como `<PREVIOUS_COMMIT>`. Respaldar DB mediante Plesk o un archivo seguro de credenciales; nunca poner contraseñas/tokens en el comando:

```bash
mysqldump --defaults-extra-file=/ruta/segura/mysqldump.cnf --single-transaction --routines --triggers NOMBRE_DB > /ruta/segura/inventory-pre-rollout.sql
cp .env /ruta/segura/env-pre-rollout.backup
```

Actualizar código solo al commit aprobado y con árbol limpio:

```bash
git fetch origin --prune
git status
git log --oneline -5 origin/feature/inventory-production-rollout
git merge --ff-only <APPROVED_COMMIT>
```

No usar `git reset --hard` como procedimiento normal. Si el commit cambia dependencias, usar `composer install --no-dev --optimize-autoloader`, nunca `composer update`. Si el servidor construye frontend:

```bash
npm ci
npm run build
```

## Fase C: migraciones y caché

```bash
php artisan migrate:status
php artisan migrate --force
php artisan migrate:status
```

Migraciones Inventory de Tickets 1–9:

- `2026_09_24_000001_create_inventory_products_table`
- `2026_09_24_000002_create_inventory_locations_table`
- `2026_09_24_000003_add_primary_location_id_to_inventory_products_table`
- `2026_09_24_000004_create_inventory_movements_table`
- `2026_09_24_000005_create_inventory_reservations_table`
- `2026_09_24_000006_add_product_type_to_inventory_products_table`
- `2026_09_24_000007_create_inventory_kit_components_table`
- `2026_09_24_000008_create_inventory_kit_reservations_table`
- `2026_09_25_000001_create_inventory_channel_links_table`
- `2026_09_25_000002_add_stock_sync_enabled_to_inventory_channel_links`
- `2026_09_25_000003_create_inventory_channel_stock_syncs_table`
- `2026_09_25_000004_add_verification_to_inventory_channel_stock_syncs`

No usar `migrate:refresh`, `migrate:reset` ni `migrate:rollback` global. Después, si las caches son compatibles:

```bash
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

## Fase D: preflight del servidor

```bash
php artisan inventory:rollout-check --verbose
php artisan queue:failed
php artisan schedule:list
php artisan route:list --path=almacen
php artisan help inventory:meli-stock-sync
php artisan help inventory:meli-stock-pilot
```

`inventory:rollout-check` es read-only y debe terminar en `PASS` o `WARN`, nunca `FAIL`. Comprueba DB, tablas, migraciones, columnas de verificación, conteos, cuentas referenciadas, cache configurada y ausencia de APPLY automático. No hace HTTP.

Ticket 10 no agrega scheduler. `meli:sync-stock` es el writer legacy existente; no debe confundirse con un scheduler de Inventory. No debe aparecer `inventory:meli-stock-sync --apply` ni `inventory:meli-stock-pilot --apply` programado.

Si se usan workers administrados por Supervisor/systemd/Plesk, reiniciarlos después del deploy solo con:

```bash
php artisan queue:restart
```

Esto no despacha trabajos.

## Fase E: validación e importación

Confirmar sin modificar datos:

```bash
php artisan tinker --execute="dump(App\\Models\\InventoryChannelLink::query()->where('stock_sync_enabled', true)->count());"
```

El resultado inicial esperado es `0`. Analizar primero `/almacen/canales/mercado-libre/importar` y revisar `MATCHED`, `ALREADY_LINKED`, `CONFLICT`, `AMBIGUOUS`, `PRODUCT_NOT_FOUND`, `MISSING_SKU` y `UNSUPPORTED`; no aplicar automáticamente.

Preview de stock, sin escribir:

```bash
php artisan inventory:meli-stock-sync --link=<ID>
php artisan inventory:meli-stock-pilot --link=<ID>
```

El primer comando es dry-run por defecto y el segundo solo hace preview remoto.

## Fase F: primer piloto posterior

Elegir un único producto SIMPLE, sin variación, sin kit, sin Syscom, sin llanta, con una cuenta y stock pequeño. Revisar SKU, Product ID, Link ID, Account ID, MLM, physical, reserved, available, target, remoto, delta y ownership legacy.

Solo tras aprobar el preview, un administrador habilita un único vínculo desde la UI. Después se vuelve a ejecutar el preview. El APPLY se documenta, pero no se ejecuta en Ticket 10:

```bash
php artisan inventory:meli-stock-pilot --link=<ID> --apply --confirm=<MLM>
```

Para una variación, confirmar exactamente `<MLM>:<VARIATION_ID>`. No usar `--all`, múltiples links ni el APPLY masivo.

## Fase G: observación y rollback

Auditar `previous_known_quantity`, `target_quantity`, `verified_quantity`, `verification_status`, `verified_at` y `error_message`. Esperar al menos un ciclo de `meli:sync-stock` y volver a leer el MLM: Inventory-owned no debe recibir `available_quantity` legacy; precio/status pueden continuar mediante PUT parcial. El writer de stock compartido es stock-only y omite completamente el vínculo.

Contingencia:

1. Desactivar `stock_sync_enabled` desde la UI.
2. Detener nuevas escrituras Inventory para el vínculo.
3. Leer el remoto y revisar la auditoría.
4. Comprobar physical/reserved/available.
5. Revisar el writer legacy.
6. Decidir manualmente el stock correcto.

No restaurar automáticamente `previous_known_quantity`, porque puede haber ventas o reservas posteriores.

Rollback de código es separado del rollback de datos. Usar el release aprobado y el `<PREVIOUS_COMMIT>` guardado; no ejecutar `php artisan migrate:rollback` a ciegas si ya existen datos Inventory. No se ejecuta ninguna acción contra producción desde este ticket.
