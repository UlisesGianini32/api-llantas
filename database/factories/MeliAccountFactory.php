<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\MeliAccount> */
class MeliAccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'meli_user_id' => (string) fake()->unique()->numberBetween(100000000, 999999999),
            'nickname' => fake()->userName(),
            'official_store_id' => null,
            'access_token' => null,
            'refresh_token' => null,
            'expires_at' => null,
            'is_default' => false,
        ];
    }
}
