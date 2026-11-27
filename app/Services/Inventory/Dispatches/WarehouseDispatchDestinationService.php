<?php

namespace App\Services\Inventory\Dispatches;

use App\Models\InventoryDispatchDestination;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class WarehouseDispatchDestinationService
{
    public function __construct(
        private readonly WarehouseDispatchFeatureGuard $guard,
    ) {}

    public function create(array $data, User $actor): InventoryDispatchDestination
    {
        $this->guard->assertWritable();

        return DB::transaction(function () use ($data, $actor): InventoryDispatchDestination {
            $destination = new InventoryDispatchDestination;
            $destination->forceFill([
                'code' => strtoupper(trim((string) $data['code'])),
                'name' => trim((string) $data['name']),
                'destination_type' => (string) ($data['destination_type'] ?? InventoryDispatchDestination::TYPE_SALON),
                'contact_name' => $this->nullableTrim($data['contact_name'] ?? null),
                'phone' => $this->nullableTrim($data['phone'] ?? null),
                'address' => $this->nullableTrim($data['address'] ?? null),
                'customer_id' => ! empty($data['customer_id']) ? (int) $data['customer_id'] : null,
                'default_operation_type' => $this->nullableTrim($data['default_operation_type'] ?? null),
                'is_active' => true,
                'notes' => $this->nullableTrim($data['notes'] ?? null),
                'created_by' => $actor->getKey(),
            ]);
            $destination->save();

            return $destination->fresh();
        });
    }

    public function update(InventoryDispatchDestination $destination, array $data): InventoryDispatchDestination
    {
        $this->guard->assertWritable();

        return DB::transaction(function () use ($destination, $data): InventoryDispatchDestination {
            $locked = InventoryDispatchDestination::query()->lockForUpdate()->findOrFail($destination->getKey());

            $locked->forceFill([
                'code' => strtoupper(trim((string) $data['code'])),
                'name' => trim((string) $data['name']),
                'destination_type' => (string) ($data['destination_type'] ?? $locked->destination_type),
                'contact_name' => $this->nullableTrim($data['contact_name'] ?? null),
                'phone' => $this->nullableTrim($data['phone'] ?? null),
                'address' => $this->nullableTrim($data['address'] ?? null),
                'customer_id' => ! empty($data['customer_id']) ? (int) $data['customer_id'] : null,
                'default_operation_type' => $this->nullableTrim($data['default_operation_type'] ?? null),
                'notes' => $this->nullableTrim($data['notes'] ?? null),
            ]);
            $locked->save();

            return $locked->fresh();
        });
    }

    public function toggle(InventoryDispatchDestination $destination): InventoryDispatchDestination
    {
        $this->guard->assertWritable();

        return DB::transaction(function () use ($destination): InventoryDispatchDestination {
            $locked = InventoryDispatchDestination::query()->lockForUpdate()->findOrFail($destination->getKey());
            $locked->forceFill([
                'is_active' => ! (bool) $locked->is_active,
            ]);
            $locked->save();

            return $locked->fresh();
        });
    }

    private function nullableTrim(mixed $value): ?string
    {
        $trimmed = trim((string) ($value ?? ''));

        return $trimmed === '' ? null : $trimmed;
    }
}
