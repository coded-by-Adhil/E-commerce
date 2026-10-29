<?php

namespace Database\Factories;

use App\Models\WebhookEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WebhookEvent>
 */
class WebhookEventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider' => 'stripe',
            'event_id' => 'evt_'.fake()->unique()->bothify('????????????????'),
            'type' => 'payment_intent.succeeded',
            'payload' => ['id' => fake()->uuid()],
            'processed_at' => null,
        ];
    }
}
