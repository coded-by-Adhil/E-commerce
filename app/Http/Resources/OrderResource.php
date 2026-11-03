<?php

namespace App\Http\Resources;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public representation of an order: the ULID number, never the auto-increment id (D2).
 * Expects items to be loaded. status_history and payment appear only when their relations
 * are loaded (the order detail); the checkout response does not load them.
 *
 * @mixin Order
 */
class OrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'number' => $this->number,
            'status' => $this->status->value,
            'currency' => $this->currency,
            'subtotal' => $this->subtotal,
            'shipping_total' => $this->shipping_total,
            'grand_total' => $this->grand_total,
            'items' => OrderItemResource::collection($this->items),
            'shipping_address' => $this->shipping_address,
            'placed_at' => $this->placed_at,
            // Oldest first. The reason and the actor are internal and never exposed.
            'status_history' => $this->whenLoaded('statusHistory', fn () => $this->statusHistory
                ->map(fn ($row) => ['status' => $row->to_status->value, 'at' => $row->created_at])
                ->values()),
            // The latest payment only; the provider reference is never exposed.
            'payment' => $this->whenLoaded('payments', fn () => $this->latestPaymentStatus()),
        ];
    }

    /**
     * @return array{status: string}|null
     */
    private function latestPaymentStatus(): ?array
    {
        $payment = $this->payments->first();

        return $payment === null ? null : ['status' => $payment->status->value];
    }
}
