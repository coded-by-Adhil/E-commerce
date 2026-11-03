<?php

namespace App\Http\Resources;

use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * Names and prices are the order's snapshot (D3): they do not change when the product does.
 * The image is the only live data, and appears only when the variant relation is loaded
 * (the order detail); null when the variant or the product has no image any more.
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
            'image' => $this->whenLoaded('variant', fn () => $this->liveImage()),
        ];
    }

    /**
     * @return array{url: string, alt_text: string|null}|null
     */
    private function liveImage(): ?array
    {
        $image = $this->variant?->product?->images->first();

        return $image === null ? null : [
            'url' => Storage::disk('public')->url($image->path),
            'alt_text' => $image->alt_text,
        ];
    }
}
