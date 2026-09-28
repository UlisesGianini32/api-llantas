# Inventory reservations from Mercado Libre orders

## Scope and local order data

`meli_orders.order_id` is the remote order ID and `meli_account_id` identifies the local Mercado Libre account. The current order sync stores Mercado Libre's order `status` unchanged. Each `meli_order_items` row stores the listing as `item_id`, the sold count as `quantity`, and now also stores `variation_id` plus `remote_line_key` (`MLM:variation`). The order sync still owns persistence of the legacy order and does not invoke Inventory reservations.

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

The command does not offer an all-orders apply mode. There is no webhook, queue, or scheduler integration for reservations in this ticket. `--apply` is repeatable and safe for the same order.

## Rollback and operations

To release an eligible cancelled order again, rerun its exact `--order` with `--apply`. To stop future reservations, disable the link's `order_reservation_enabled` flag; existing reservations remain traceable and can be released through cancellation processing. Reversing the migration removes the opt-in and allocation bridge. Its `down()` first checks for multiple rows sharing `(meli_order_id, item_id)`; if variations make the old identity ambiguous it aborts before dropping any table, index, or column. No production migration was run for this implementation.

Ticket 11 continues to own remote stock synchronization. A future ticket may add a controlled automatic invocation after its lifecycle and operational safeguards are defined.
