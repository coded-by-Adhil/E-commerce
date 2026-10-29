<?php

namespace Database\Factories;

use App\Enums\StockReason;
use App\Models\StockMovement;
use App\Models\Variant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockMovement>
 */
class StockMovementFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'variant_id' => Variant::factory(),
            'delta' => fake()->numberBetween(1, 50),
            'reason' => StockReason::Restock,
            'order_id' => null,
            'actor_id' => null,
        ];
    }
}
