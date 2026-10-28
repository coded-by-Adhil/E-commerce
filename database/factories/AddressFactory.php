<?php

namespace Database\Factories;

use App\Models\Address;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Address>
 */
class AddressFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'line1' => fake()->streetAddress(),
            'line2' => null,
            'city' => fake()->city(),
            'region' => fake()->randomElement(['California', 'New York', 'Texas', 'Florida']),
            'postal_code' => fake()->postcode(),
            'country' => 'US',
            'is_default' => false,
        ];
    }
}
