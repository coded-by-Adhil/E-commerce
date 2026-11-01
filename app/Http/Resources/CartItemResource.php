<?php

namespace App\Http\Resources;

use App\Models\CartItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CartItem
 */
class CartItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $product = $this->variant->product;
        $image = $product->images->first();

        return [
            'id' => $this->id,
            'product' => [
                'name' => $product->name,
                'slug' => $product->slug,
            ],
            'variant' => new VariantResource($this->variant),
            'image' => $image === null ? null : new ProductImageResource($image),
            'unit_price' => $this->variant->price,
            'quantity' => $this->quantity,
            'line_total' => $this->variant->price * $this->quantity,
        ];
    }
}
