<?php

namespace Database\Factories;

use App\Models\MeliAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\MeliPriceChangeBatch> */
class MeliPriceChangeBatchFactory extends Factory
{
    public function definition(): array
    {
        return [
            'meli_account_id' => MeliAccount::factory(),
            'brand_group_id' => null,
            'created_by' => User::factory(),
            'type' => 'individual',
            'status' => 'draft',
            'notes' => null,
            'total_items' => 0,
            'successful_items' => 0,
            'failed_items' => 0,
        ];
    }
}
