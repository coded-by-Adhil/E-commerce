<?php

namespace App\Http\Resources;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the customer's order list. Expects an item_count aggregate (withSum).
 *
 * @mixin Order
 */
class OrderSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'number' => $this->number,
            'status' => $this->status->value,
            'placed_at' => $this->placed_at,
            'currency' => $this->currency,
            'grand_total' => $this->grand_total,
            'item_count' => (int) $this->getAttribute('item_count'),
        ];
    }
}
