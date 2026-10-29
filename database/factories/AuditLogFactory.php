<?php

namespace Database\Factories;

use App\Models\AuditLog;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'actor_id' => null,
            'action' => 'product.updated',
            'auditable_type' => (new Product)->getMorphClass(),
            'auditable_id' => Product::factory(),
            'old_values' => ['name' => 'Old name'],
            'new_values' => ['name' => 'New name'],
            'ip_address' => fake()->ipv4(),
        ];
    }
}
