<?php

namespace App\Http\Resources;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public representation of an order: the ULID number, never the auto-increment id (D2).
 * Expects items to be loaded.
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
        ];
    }
}
