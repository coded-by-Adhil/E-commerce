<?php

namespace App\Http\Resources;

use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Snapshot fields only (D3): they do not change when the product does.
 *
 * @mixin OrderItem
 */
class OrderItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'sku' => $this->sku,
            'product_name' => $this->product_name,
            'variant_name' => $this->variant_name,
            'unit_price' => $this->unit_price,
            'quantity' => $this->quantity,
            'line_total' => $this->line_total,
        ];
    }
}
