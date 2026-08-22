<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    public function definition(): array
    {
        return [
            'number' => 'INV-'.now()->year.'-'.str_pad((string) fake()->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT),
            'user_id' => User::factory(),
            'plan_id' => Plan::factory(),
            'subscription_id' => Subscription::factory(),
            'type' => 'subscription',
            'amount' => fake()->randomElement([3000, 5000, 13500]),
            'currency' => 'XAF',
            'status' => 'paid',
            'payment_method' => 'kpay',
            'reference' => fake()->uuid(),
            'paid_at' => now(),
            'period_start' => now()->subMonth(),
            'period_end' => now(),
            'metadata' => [],
        ];
    }
}
