<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    protected $model = Plan::class;

    public function definition(): array
    {
        return [
            'slug' => fake()->unique()->slug(2),
            'name' => fake()->words(2, true),
            'description' => fake()->sentence(),
            'price_fcfa' => fake()->randomElement([0, 3000, 5000, 13500]),
            'features' => [],
            'is_active' => true,
            'sort_order' => 0,
            'quota_deterministic' => 5,
            'quota_ai' => 0,
            'quota_period' => 'monthly',
        ];
    }
}
