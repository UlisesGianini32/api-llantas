<?php

namespace Database\Factories;

use App\Models\MeliPriceManagerItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\MeliPriceChange> */
class MeliPriceChangeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'batch_id' => null,
            'price_manager_item_id' => MeliPriceManagerItem::factory(),
            'meli_item_id' => fn (array $attributes) => MeliPriceManagerItem::query()
                ->findOrFail($attributes['price_manager_item_id'])->meli_item_id,
            'old_price' => 1000,
            'new_price' => 1100,
            'selling_fee' => null,
            'shipping_cost' => null,
            'tax_withholding' => null,
            'other_charges' => null,
            'estimated_net' => null,
            'status' => 'pending',
            'error_message' => null,
            'changed_by' => User::factory(),
            'changed_at' => null,
        ];
    }
}
