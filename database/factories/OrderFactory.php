<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $subtotal = fake()->numberBetween(1000, 100000);
        $shipping = 500;
        $address = [
            'line1' => fake()->streetAddress(),
            'line2' => null,
            'city' => fake()->city(),
            'region' => 'California',
            'postal_code' => fake()->postcode(),
            'country' => 'US',
        ];

        return [
            'number' => (string) Str::ulid(),
            'user_id' => User::factory(),
            'status' => OrderStatus::Pending,
            'currency' => 'USD',
            'subtotal' => $subtotal,
            'shipping_total' => $shipping,
            'grand_total' => $subtotal + $shipping,
            'shipping_address' => $address,
            'billing_address' => $address,
            'idempotency_key' => (string) Str::uuid(),
            'placed_at' => now(),
        ];
    }
}
