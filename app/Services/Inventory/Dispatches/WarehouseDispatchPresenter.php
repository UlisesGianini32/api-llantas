<?php

namespace App\Services\Inventory\Dispatches;

use App\Models\InventoryDispatch;
use App\Models\InventoryDispatchEvent;
use App\Models\InventoryDispatchItem;
use App\Models\User;
use App\Policies\WarehouseDispatchPolicy;

/**
 * Server-side serializer that strictly strips sensitive cost/price fields based on
 * WarehouseDispatchPolicy permissions (H06). Never relies on frontend/CSS hiding.
 */
class WarehouseDispatchPresenter
{
    public function __construct(
        private readonly WarehouseDispatchPolicy $policy,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function presentCapabilities(User $user, ?InventoryDispatch $dispatch = null): array
    {
        return [
            'can_create' => $this->policy->create($user),
            'can_create_retroactive' => $this->policy->createRetroactive($user),
            'can_view_costs' => $this->policy->viewCosts($user),
            'can_view_stylist_prices' => $this->policy->viewStylistPrices($user),
            'can_view_commercial_subtotals' => $this->policy->viewCommercialSubtotals($user),
            'can_manage_destinations' => $user->isAdmin() || $user->isOperations(),
            'can_link_provisional' => $this->policy->linkProvisional($user),
            'can_merge_provisional' => $this->policy->mergeProvisional($user),
            'can_export_summary' => $this->policy->exportSummary($user),
            'can_update_dispatch' => $dispatch ? $this->policy->update($user, $dispatch) : false,
            'can_cancel_draft' => $dispatch ? $this->policy->cancelDraft($user, $dispatch) : false,
            'can_cancel_confirmed_without_ledger' => $dispatch ? $this->policy->cancelConfirmedWithoutLedger($user, $dispatch) : false,
            'can_show_prices_in_pdf' => $dispatch ? $this->policy->showPricesInPdf($user, $dispatch) : false,
            'can_authorize_meli_sync' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentDispatchListItem(InventoryDispatch $dispatch, User $user): array
    {
        $data = [
            'id' => (int) $dispatch->getKey(),
            'dispatch_number' => (string) $dispatch->dispatch_number,
            'status' => (string) $dispatch->status,
            'operation_type' => (string) $dispatch->operation_type,
            'commercial_status' => (string) $dispatch->commercial_status,
            'destination_id' => (int) $dispatch->destination_id,
            'destination_code' => $dispatch->destination_code_snapshot ?: $dispatch->destination?->code,
            'destination_name' => $dispatch->destination_name_snapshot ?: $dispatch->destination?->name,
            'destination_type' => $dispatch->destination_type_snapshot ?: $dispatch->destination?->destination_type,
            'effective_at' => $dispatch->effective_at?->copy()->utc()->toIso8601String(),
            'is_retroactive' => (bool) $dispatch->is_retroactive,
            'confirmed_at' => $dispatch->confirmed_at?->copy()->utc()->toIso8601String(),
            'delivered_by_name' => $dispatch->delivered_by_name_snapshot ?: $dispatch->deliveredBy?->name,
            'received_by_name' => $dispatch->received_by_name,
            'total_lines_count' => (int) $dispatch->total_lines_count,
            'total_physical_units' => (int) $dispatch->total_physical_units,
            'unconverted_containers_count' => (int) $dispatch->unconverted_containers_count,
            'provisional_items_count' => (int) ($dispatch->provisional_items_count ?? 0),
            'discrepancy_items_count' => (int) ($dispatch->discrepancy_items_count ?? 0),
            'pending_apply_items_count' => (int) ($dispatch->pending_apply_items_count ?? 0),
        ];

        $this->appendAuthorizedHeaderFinancials($data, $dispatch, $user);

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public function presentDispatchDetail(InventoryDispatch $dispatch, User $user): array
    {
        $items = $dispatch->relationLoaded('items')
            ? $dispatch->items->map(fn (InventoryDispatchItem $item): array => $this->presentItem($item, $dispatch, $user))->values()->all()
            : [];

        $events = $dispatch->relationLoaded('events')
            ? $dispatch->events->map(fn (InventoryDispatchEvent $event): array => $this->presentEvent($event))->values()->all()
            : [];

        $hasAppliedItems = collect($items)->contains(
            fn (array $item): bool => $item['inventory_apply_status'] === InventoryDispatchItem::APPLY_APPLIED
        );

        $data = [
            'id' => (int) $dispatch->getKey(),
            'dispatch_number' => (string) $dispatch->dispatch_number,
            'draft_version' => (int) $dispatch->draft_version,
            'status' => (string) $dispatch->status,
            'operation_type' => (string) $dispatch->operation_type,
            'commercial_status' => (string) $dispatch->commercial_status,
            'destination_id' => (int) $dispatch->destination_id,
            'destination_code' => $dispatch->destination_code_snapshot ?: $dispatch->destination?->code,
            'destination_name' => $dispatch->destination_name_snapshot ?: $dispatch->destination?->name,
            'destination_type' => $dispatch->destination_type_snapshot ?: $dispatch->destination?->destination_type,
            'source_location_id' => $dispatch->source_location_id ? (int) $dispatch->source_location_id : null,
            'source_location_code' => $dispatch->sourceLocation?->code,
            'effective_at' => $dispatch->effective_at?->copy()->utc()->toIso8601String(),
            'is_retroactive' => (bool) $dispatch->is_retroactive,
            'retroactive_reason' => $dispatch->retroactive_reason,
            'captured_at' => $dispatch->captured_at?->copy()->utc()->toIso8601String(),
            'confirmed_at' => $dispatch->confirmed_at?->copy()->utc()->toIso8601String(),
            'cancelled_at' => $dispatch->cancelled_at?->copy()->utc()->toIso8601String(),
            'delivered_by_user_id' => (int) $dispatch->delivered_by_user_id,
            'delivered_by_name' => $dispatch->delivered_by_name_snapshot ?: $dispatch->deliveredBy?->name,
            'received_by_name' => $dispatch->received_by_name,
            'confirmed_by_name' => $dispatch->confirmed_by_name_snapshot ?: $dispatch->confirmedBy?->name,
            'cancelled_by_name' => $dispatch->cancelledBy?->name,
            'cancel_reason' => $dispatch->cancel_reason,
            'cancel_physical_situation' => $dispatch->cancel_physical_situation,
            'total_lines_count' => (int) $dispatch->total_lines_count,
            'total_physical_units' => (int) $dispatch->total_physical_units,
            'unconverted_containers_count' => (int) $dispatch->unconverted_containers_count,
            'total_units_returned' => (int) $dispatch->total_units_returned,
            'notes' => $dispatch->notes,
            'has_applied_ledger_items' => $hasAppliedItems,
            'items' => $items,
            'events' => $events,
        ];

        $this->appendAuthorizedHeaderFinancials($data, $dispatch, $user);

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public function presentItem(InventoryDispatchItem $item, InventoryDispatch $dispatch, User $user): array
    {
        $data = [
            'id' => (int) $item->getKey(),
            'line_order' => (int) $item->line_order,
            'inventory_product_id' => $item->inventory_product_id ? (int) $item->inventory_product_id : null,
            'provisional_item_id' => $item->provisional_item_id ? (int) $item->provisional_item_id : null,
            'provisional_code' => $item->relationLoaded('provisionalItem') ? $item->provisionalItem?->provisional_code : null,
            'master_sku' => $item->relationLoaded('product') ? $item->product?->sku : null,
            'master_name' => $item->relationLoaded('product') ? $item->product?->name : null,
            'inventory_location_id' => $item->inventory_location_id ? (int) $item->inventory_location_id : null,
            'location_code' => $item->location_code_snapshot ?: ($item->relationLoaded('location') ? $item->location?->code : null),
            'capture_method' => (string) $item->capture_method,
            'captured_barcode' => $item->captured_barcode,
            'captured_sku' => $item->captured_sku,
            'captured_name' => (string) $item->captured_name,
            'captured_brand' => $item->captured_brand,
            'captured_line' => $item->captured_line,
            'captured_tone' => $item->captured_tone,
            'captured_presentation' => $item->captured_presentation,
            'captured_quantity' => (int) $item->captured_quantity,
            'captured_unit_of_measure' => (string) $item->captured_unit_of_measure,
            'unit_conversion_factor' => $item->unit_conversion_factor !== null ? (int) $item->unit_conversion_factor : null,
            'normalized_physical_quantity' => $item->normalized_physical_quantity !== null ? (int) $item->normalized_physical_quantity : null,
            'quantity_returned' => (int) $item->quantity_returned,
            'line_notes' => $item->line_notes,
            'identification_status' => (string) $item->identification_status,
            'linked_at' => $item->linked_at?->copy()->utc()->toIso8601String(),
            'inventory_apply_status' => (string) $item->inventory_apply_status,
            'cutover_evaluated_at' => $item->cutover_evaluated_at?->copy()->utc()->toIso8601String(),
            'cutover_reference_type' => $item->cutover_reference_type,
            'cutover_reference_id' => $item->cutover_reference_id ? (int) $item->cutover_reference_id : null,
            'cutover_boundary_at' => $item->cutover_boundary_at?->copy()->utc()->toIso8601String(),
            'inventory_movement_id' => $item->inventory_movement_id ? (int) $item->inventory_movement_id : null,
            'inventory_applied_at' => $item->inventory_applied_at?->copy()->utc()->toIso8601String(),
            'inventory_resolution_notes' => $item->inventory_resolution_notes,
            'meli_sync_status' => (string) $item->meli_sync_status,
        ];

        if ($this->policy->viewCosts($user)) {
            $data['unit_cost_snapshot'] = $item->unit_cost_snapshot !== null ? (float) $item->unit_cost_snapshot : null;
        }

        if ($this->policy->viewStylistPrices($user) && $dispatch->operation_type === InventoryDispatch::OP_SALON_SALE) {
            $data['unit_price_stylist_snapshot'] = $item->unit_price_stylist_snapshot !== null ? (float) $item->unit_price_stylist_snapshot : null;
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public function presentEvent(InventoryDispatchEvent $event): array
    {
        // Strip any accidental financial keys defensively before returning event logs.
        $payload = is_array($event->payload) ? $event->payload : [];
        unset(
            $payload['unit_cost'],
            $payload['unit_cost_snapshot'],
            $payload['cost'],
            $payload['price_stylist'],
            $payload['unit_price_stylist_snapshot'],
            $payload['reference_subtotal_cost'],
            $payload['reference_subtotal_stylist'],
        );

        return [
            'id' => (int) $event->getKey(),
            'event_type' => (string) $event->event_type,
            'from_status' => $event->from_status,
            'to_status' => $event->to_status,
            'actor_name' => $event->relationLoaded('actor') ? $event->actor?->name : null,
            'reason' => $event->reason,
            'payload' => $payload,
            'occurred_at' => $event->occurred_at?->copy()->utc()->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function appendAuthorizedHeaderFinancials(array &$data, InventoryDispatch $dispatch, User $user): void
    {
        if ($this->policy->viewCosts($user)) {
            $data['reference_subtotal_cost'] = $dispatch->reference_subtotal_cost !== null
                ? (float) $dispatch->reference_subtotal_cost
                : null;
            $data['reference_cost_complete'] = (bool) $dispatch->reference_cost_complete;
        }

        if ($this->policy->viewStylistPrices($user) && $dispatch->operation_type === InventoryDispatch::OP_SALON_SALE) {
            $data['reference_subtotal_stylist'] = $dispatch->reference_subtotal_stylist !== null
                ? (float) $dispatch->reference_subtotal_stylist
                : null;
            $data['reference_stylist_complete'] = (bool) $dispatch->reference_stylist_complete;
        }
    }
}
