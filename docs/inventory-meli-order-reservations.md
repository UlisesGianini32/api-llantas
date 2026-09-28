# Inventory reservations from Mercado Libre orders

## Scope and local order data

`meli_orders.order_id` is the remote order ID and `meli_account_id` identifies the local Mercado Libre account. The current order sync stores Mercado Libre's order `status` unchanged. Each `meli_order_items` row stores the listing as `item_id`, the sold count as `quantity`, and now also stores `variation_id` plus `remote_line_key` (`MLM:variation`). Legacy order persistence remains independent; Ticket 13 optionally dispatches the reconciler after that persistence commits.

The policy currently classifies local `paid` as reservable and `cancelled` as release. Every other status is ignored because this application has no locally established safe reservation meaning for those states. Preview/apply reports these lines as `IGNORED_STATUS`; apply returns before any allocation transaction and leaves existing allocations untouched. Fulfillment/shipment statuses do not cause physical Inventory movements.

## Exact mapping and identity

The mapping uses the unique Inventory channel link matching `channel=mercado_libre`, `account_key=<meli_account_id>`, exact `external_listing_id=item_id`, and exact `external_variant_id=variation_id` (or SQL `NULL` when no variation was sold). SKU, title, and price are not mapping keys. The order reservation opt-in `order_reservation_enabled` defaults to false and is independent from `stock_sync_enabled`.

Rows written before the line-identity migration retain `remote_line_key=NULL`. They are intentionally not eligible for either reservation or release and report `LEGACY_LINE_IDENTITY_UNKNOWN`; no variation is inferred from SKU or descriptive fields. A normal subsequent order sync recreates those rows with identity. A new simple item has the explicit key `MLM:`. The inspected order payload handling exposes listing `item.id` and optional `variation_id`, but no stable distinct order-line ID is read or persisted; therefore `item_id + variation_id` is the line key. The migration keeps the key nullable for historical unknowns and adds a unique `(meli_order_id, remote_line_key)` constraint; SQL permits multiple NULL historical values.

An allocation key is SHA-256 over channel, account, remote order ID, and remote line key. The unique allocation identity plus a database row lock serializes concurrent processing. `NO_CHANGE` is accepted only when the locked allocation and its linked reservation are both active with the requested quantity. A stale allocation reports `STALE_ALLOCATION` in preview and is reconciled during apply. Reservation `external_key` values include the stable identity and a monotonically increasing version, preserving prior reservations when a changed quantity must replace an active reservation.

## Reservations, kits, and shared stock

Simple products use `InventoryReservationService`; kits use `InventoryKitService`, which reserves component stock and records a kit parent reservation. Neither path creates a movement or changes physical stock. Quantity replacement releases and creates within one database transaction; if the new quantity cannot be reserved, the transaction rolls back and the previous reservation remains active. Cancellation releases the exact linked reservation idempotently.

Links sharing one `remote_user_product_id` inside an account are checked for conflicting active Inventory product IDs. A conflict returns `REMOTE_USER_PRODUCT_CONFLICT`. A sibling publication is never used as a second reservation target.

Safe allocation diagnostics retain order/line identity, product/SKU, requested quantity, and available quantity. They do not store credentials or remote response bodies.

## Manual operation

Preview is the default and performs no HTTP requests or writes:

```sh
php artisan inventory:meli-order-reservations --account=12 --limit=50
php artisan inventory:meli-order-reservations --order=200000001 --status=paid
```

Mutation requires one explicit remote order ID:

```sh
php artisan inventory:meli-order-reservations --order=200000001 --apply
```

The command does not offer an all-orders apply mode. `--apply` is repeatable and safe for the same order.

## Ticket 13 automatic reconciliation

Automatic reconciliation is controlled only by `INVENTORY_MELI_ORDER_RESERVATIONS_AUTOMATIC` (config key `inventory.meli_order_reservations.automatic`) and defaults to `false`. When enabled, the existing order persistence boundaries register `ReconcileInventoryMeliOrderReservationsJob` with `DB::afterCommit`; the job receives only the local `MeliOrder` ID, reloads the current order and lines, and runs on the `meli` queue. No uniqueness lock is used because Ticket 12 idempotency already handles duplicate jobs and this avoids hiding a later cancellation behind an earlier queued job.

The dispatcher is best effort at the post-commit dispatch boundary: it logs local order ID, remote order ID, account, exception class, and a bounded message with authorization and token values redacted, without rethrowing into legacy order persistence. This also applies when the queue driver is `sync`, because that driver executes the job inside the after-commit callback. Unexpected exceptions from a direct worker invocation of the job are not swallowed and can be retried by the normal queue worker. Business outcomes such as ignored status, opt-out, insufficient inventory, unmatched links, and shared-stock conflicts finish normally with Ticket 12 diagnostics. Inventory automation never changes physical stock, fulfillment state, Mercado Libre status, or remote stock.

`inventory:rollout-check` reports the automation state, queue, enabled order-reservation links, and allocation count. It does not fail when automation is disabled. If automation is enabled while the allocation table, order opt-in column, or line identity columns (`variation_id`, `remote_line_key`) are missing, the check fails safely. Disable the flag to roll back the automation without changing the manual command or Ticket 12 allocations.

## Rollback and operations

To release an eligible cancelled order again, rerun its exact `--order` with `--apply`. To stop future reservations, disable the link's `order_reservation_enabled` flag; existing reservations remain traceable and can be released through cancellation processing. Reversing the migration removes the opt-in and allocation bridge. Its `down()` first checks for multiple rows sharing `(meli_order_id, item_id)`; if variations make the old identity ambiguous it aborts before dropping any table, index, or column. No production migration was run for this implementation.

Ticket 11 continues to own remote stock synchronization. A future ticket may add a controlled automatic invocation after its lifecycle and operational safeguards are defined.
