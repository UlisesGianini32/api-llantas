<?php

namespace Database\Factories;

use App\Models\MeliAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\MeliPriceManagerItem> */
class MeliPriceManagerItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'meli_account_id' => MeliAccount::factory(),
            'meli_item_id' => 'MLM'.fake()->unique()->numberBetween(100000000, 999999999),
            'sku' => fake()->optional()->bothify('SKU-####'),
            'title' => fake()->sentence(5),
            'category_id' => 'MLM'.fake()->numberBetween(1000, 999999),
            'listing_type_id' => 'gold_special',
            'catalog_product_id' => null,
            'meli_brand' => fake()->company(),
            'normalized_brand' => fn (array $attributes) => mb_strtolower($attributes['meli_brand']),
            'brand_group_id' => null,
            'classification_status' => 'uncategorized',
            'classification_source' => null,
            'classification_confidence' => null,
            'current_price' => fake()->randomFloat(2, 1, 999999),
            'original_price' => null,
            'available_quantity' => fake()->numberBetween(0, 100),
            'sold_quantity' => fake()->numberBetween(0, 1000),
            'currency_id' => 'MXN',
            'status' => 'active',
            'permalink' => fake()->url(),
            'thumbnail' => fake()->imageUrl(),
            'raw_attributes' => [['id' => 'BRAND', 'value_name' => 'Marca respaldada']],
            'raw_item' => ['source' => 'factory'],
            'last_synced_at' => now(),
        ];
    }
}
