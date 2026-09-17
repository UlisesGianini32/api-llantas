<?php

namespace Database\Factories;

use App\Models\MeliBrandGroup;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<\App\Models\MeliBrandAlias> */
class MeliBrandAliasFactory extends Factory
{
    public function definition(): array
    {
        return [
            'brand_group_id' => MeliBrandGroup::factory(),
            'alias' => fake()->unique()->company(),
            'normalized_alias' => fn (array $attributes) => Str::lower(Str::ascii($attributes['alias'])),
            'match_type' => 'exact',
            'priority' => 0,
            'active' => true,
        ];
    }
}
