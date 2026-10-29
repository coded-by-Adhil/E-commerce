<?php

namespace Database\Factories;

use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'provider' => 'stripe',
            'provider_ref' => 'pi_'.fake()->unique()->bothify('????????????????'),
            'status' => PaymentStatus::Pending,
            'amount' => fake()->numberBetween(1000, 100000),
            'currency' => 'USD',
        ];
    }
}
