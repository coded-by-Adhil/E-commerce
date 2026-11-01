<?php

namespace App\Http\Resources;

use App\Models\Cart;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Totals are computed here from live variant prices (D1): integer minor units.
 * Expects items.variant.product.images to be eager loaded.
 *
 * @mixin Cart
 */
class CartResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'items' => CartItemResource::collection($this->items),
            'subtotal' => $this->items->sum(fn ($item) => $item->variant->price * $item->quantity),
            'currency' => config('shop.currency'),
            'item_count' => $this->items->sum('quantity'),
        ];
    }
}
